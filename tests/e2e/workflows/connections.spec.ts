// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * The Connections pages: the list, a type filter, the detail page with its
 * release transition, and both connection lists on the application page.
 *
 * Seeds a supplier, two applications and two connections carrying this run's
 * RUN_ID, and removes exactly those rows afterwards.
 *
 * @spec openspec/specs/catalogue-connection-pages/spec.md
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
const apiName = `${RUN_ID} api connection`
const fileName = `${RUN_ID} file connection`

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
	ids.x = await seed('module', {
		name: `${RUN_ID} application X`,
		provider: ids.supplier,
	})
	ids.y = await seed('module', {
		name: `${RUN_ID} application Y`,
		provider: ids.supplier,
	})
	// X is application A in the api connection and application B in the file connection.
	ids.api = await seed('connection', {
		name: apiName,
		type: 'api',
		status: 'in development',
		moduleA: ids.x,
		moduleB: ids.y,
		dataExchangeDirection: 'AtoB',
	})
	ids.file = await seed('connection', {
		name: fileName,
		type: 'file transfer',
		status: 'in use',
		moduleA: ids.y,
		moduleB: ids.x,
		dataExchangeDirection: 'AtoB',
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	await apiCtx.dispose()
})

test('the connections list shows both connections and opens one', async ({
	page,
}) => {
	await gotoAppRoute(page, '/koppelingen')
	await dismissSupportDialog(page)
	await expect(page.getByText(apiName).first()).toBeVisible({ timeout: 30000 })
	await expect(page.getByText(fileName).first()).toBeVisible()
	await page.getByText(apiName).first().click()
	await expect(page).toHaveURL(new RegExp(`/koppelingen/${ids.api}`))
})

test('filtering on type api leaves only the api connection', async ({ page }) => {
	await gotoAppRoute(page, '/koppelingen?type=api')
	await dismissSupportDialog(page)
	await expect(page.getByText(apiName).first()).toBeVisible({ timeout: 30000 })
	await expect(page.getByText(fileName)).toHaveCount(0)
})

test('the application page lists the connection from it and the connection to it', async ({
	page,
}) => {
	await gotoAppRoute(page, `/modules/${ids.x}`)
	await dismissSupportDialog(page)
	await expect(
		page.getByText('Connections from this application').first(),
	).toBeVisible({ timeout: 30000 })
	await expect(page.getByText(apiName).first()).toBeVisible({ timeout: 30000 })
	await expect(page.getByText(fileName).first()).toBeVisible()
	await page.getByText(fileName).first().click()
	await expect(page).toHaveURL(new RegExp(`/koppelingen/${ids.file}`))
})

test('a connection in development can be released from its page', async ({
	page,
}) => {
	await gotoAppRoute(page, `/koppelingen/${ids.api}`)
	await dismissSupportDialog(page)
	const release = page.getByRole('button', { name: /release/i }).first()
	await expect(release).toBeVisible({ timeout: 30000 })
	await release.click()
	const url = `/index.php/apps/openregister/api/objects/${cfg.register}/connection/${ids.api}`
	await expect
		.poll(async () => (await (await apiCtx.get(url)).json())?.status, {
			timeout: 30000,
		})
		.toBe('in use')
})
