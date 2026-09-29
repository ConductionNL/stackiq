// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * The application page: its fields under the schema's current keys, its
 * usages and the contracts behind it, and opening it from the Applications list.
 *
 * Seeds one supplier, one municipality, one application, a usage of it, a
 * service that offers it and a contract on that usage and service, all
 * carrying this run's RUN_ID, and removes exactly those rows afterwards.
 *
 * @spec openspec/specs/application-page/spec.md
 */
import type { APIRequestContext } from '@playwright/test'
import type { VoorzieningenConfig } from './_fixtures.ts'

import { expect, test } from '@playwright/test'
import {
	createObject,
	deleteObject,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from './_fixtures.ts'
import { dismissSupportDialog, gotoAppRoute } from './_ui.ts'

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
const seeded: Array<[string, string]> = []
const ids: Record<string, string> = {}
const appName = `${RUN_ID} application`
const shortDescription = `${RUN_ID} short description`
const contractNumber = `${RUN_ID}-CON`

/**
 * Create a row and remember it for cleanup.
 *
 * @param schema The schema slug.
 * @param data The object.
 * @return The new id.
 */
async function seed(schema: string, data: Record<string, unknown>): Promise<string> {
	const id = await createObject(apiCtx, cfg.register, schema, data)
	seeded.push([schema, id])
	return id
}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	ids.supplier = await seed('organization', {
		name: `${RUN_ID} supplier`,
		type: 'Supplier',
		status: 'Active',
		contactsUid: `${RUN_ID}-supplier`,
	})
	ids.municipality = await seed('organization', {
		name: `${RUN_ID} municipality`,
		type: 'Municipality',
		status: 'Active',
		contactsUid: `${RUN_ID}-municipality`,
	})
	ids.app = await seed('module', {
		name: appName,
		shortDescription,
		provider: ids.supplier,
	})
	ids.usage = await seed('usage', {
		module: ids.app,
		consumer: ids.municipality,
		status: 'In production',
	})
	ids.service = await seed('catalogService', {
		name: `${RUN_ID} service`,
		provider: ids.supplier,
		type: ['Application management'],
		modules: [ids.app],
	})
	ids.contract = await seed('catalogContract', {
		contractNumber,
		contractType: 'Licence',
		status: 'Active',
		startDate: '2026-01-01',
		endDate: '2027-12-31',
		usage: ids.usage,
		service: ids.service,
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	await apiCtx.dispose()
})

test('a buyer reads the short description of a product on its page', async ({
	page,
}) => {
	await gotoAppRoute(page, `/modules/${ids.app}`)
	await dismissSupportDialog(page)
	await expect(page.getByText(shortDescription).first()).toBeVisible({
		timeout: 30000,
	})
})

test('the application page lists the usage and the contract, once, and the contract opens', async ({
	page,
}) => {
	await gotoAppRoute(page, `/modules/${ids.app}`)
	await dismissSupportDialog(page)
	await expect(page.getByRole('heading', { name: 'Usages' }).first()).toBeVisible({
		timeout: 30000,
	})
	await expect(page.getByText(`${RUN_ID} municipality`).first()).toBeVisible({
		timeout: 30000,
	})

	// The contract is reachable through both the usage and the service; it is listed once.
	const contractLink = page.getByRole('link', { name: contractNumber })
	await expect(contractLink).toHaveCount(1, { timeout: 30000 })
	await expect(page.getByText('2027-12-31').first()).toBeVisible()
	await contractLink.click()
	await expect(page).toHaveURL(new RegExp(`/contracten/${ids.contract}`))
})

test('a user opens an application from the Applications list', async ({ page }) => {
	await gotoAppRoute(page, '/modules')
	await dismissSupportDialog(page)
	const row = page.getByText(appName).first()
	await expect(row).toBeVisible({ timeout: 30000 })
	await row.click()
	await expect(page).toHaveURL(new RegExp(`/modules/${ids.app}`))
})
