// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Applications in use: the list with version and status, adding an
 * application from its page, both owners on the usage page, and going live.
 *
 * Seeds an organisation, two contact persons, two applications with versions
 * and two usages carrying this run's RUN_ID through the objects API (the call
 * the Add form makes), and removes exactly those rows afterwards. The schema,
 * the lifecycle and the pages are covered by
 * tests/Unit/Settings/UsageSchemaTest.php and tests/vitest/usages.spec.js.
 *
 * @spec openspec/specs/application-usage-pages/spec.md
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
const appX = `${RUN_ID} application X`
const appY = `${RUN_ID} application Y`
const anna = `${RUN_ID}-anna`
const bram = `${RUN_ID}-bram`

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
	ids.org = await seed('organization', { name: `${RUN_ID} municipality` })
	ids.anna = await seed('contactPerson', {
		contactsUid: anna,
		organization: ids.org,
	})
	ids.bram = await seed('contactPerson', {
		contactsUid: bram,
		organization: ids.org,
	})
	ids.x = await seed('module', { name: appX })
	ids.y = await seed('module', { name: appY })
	ids.x20 = await seed('moduleVersion', {
		module: ids.x,
		version: '2.0',
		status: 'in use',
	})
	ids.x21 = await seed('moduleVersion', {
		module: ids.x,
		version: '2.1',
		status: 'in use',
	})
	ids.usageX = await seed('usage', {
		consumer: ids.org,
		module: ids.x,
		moduleVersion: ids.x21,
		status: 'In production',
	})
	ids.usageY = await seed('usage', {
		consumer: ids.org,
		module: ids.y,
		status: 'Planned',
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e application-usage-pages::an-information-manager-lists-the-organisation-s-applications
test('Applications in use lists both applications with version and status', async ({
	page,
}) => {
	await gotoAppRoute(page, '/gebruik')
	await dismissSupportDialog(page)
	const rowX = page.getByRole('row').filter({ hasText: appX })
	await expect(rowX).toContainText('2.1', { timeout: 30000 })
	await expect(rowX).toContainText('In production')
	await expect(page.getByRole('row').filter({ hasText: appY })).toContainText(
		'Planned',
	)
})

// @e2e application-usage-pages::adding-an-application-with-its-version
test('adding an application from its page creates a usage with the version picked', async ({
	page,
}) => {
	await gotoAppRoute(page, `/modules/${ids.y}`)
	await dismissSupportDialog(page)
	await page.getByRole('button', { name: 'Add to our landscape' }).first().click()
	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible({ timeout: 30000 })
	await dialog
		.getByRole('button', { name: /save|create/i })
		.last()
		.click()
	await expect(dialog).toBeHidden({ timeout: 30000 })
	await gotoAppRoute(page, '/gebruik')
	await expect(page.getByText(appY).first()).toBeVisible({ timeout: 30000 })
})

// @e2e application-usage-pages::setting-both-owners
test('the usage page shows the business owner and the technical owner', async ({
	page,
}) => {
	const res = await apiCtx.put(
		`/index.php/apps/openregister/api/objects/${cfg.register}/usage/${ids.usageX}`,
		{
			data: {
				consumer: ids.org,
				module: ids.x,
				moduleVersion: ids.x21,
				status: 'In production',
				businessOwner: ids.anna,
				technicalOwner: ids.bram,
			},
		},
	)
	expect(res.ok()).toBe(true)
	await gotoAppRoute(page, `/gebruik/${ids.usageX}`)
	await dismissSupportDialog(page)
	await expect(page.getByText('Business owner').first()).toBeVisible({
		timeout: 30000,
	})
	await expect(page.getByText(anna).first()).toBeVisible()
	await expect(page.getByText(bram).first()).toBeVisible()
})

// @e2e application-usage-pages::going-live
test('Go live moves a planned usage to In production', async ({ page }) => {
	await gotoAppRoute(page, `/gebruik/${ids.usageY}`)
	await dismissSupportDialog(page)
	await page
		.getByRole('button', { name: /go live/i })
		.first()
		.click()
	await expect(page.getByText('In production').first()).toBeVisible({
		timeout: 30000,
	})
})
