// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Maintenance and roadmap: announcing a window on a product page, the
 * dashboard widget for an organisation that uses the product, the roadmap
 * on the product page and releasing a planned version.
 *
 * Seeds an organisation, a product with a roadmap statement and a planned
 * version, a usage and a maintenance window carrying this run's RUN_ID
 * through the objects API (the call the Add form makes), and removes exactly
 * those rows afterwards. The rules, the lifecycle and the owner resolution
 * are covered by tests/Unit/Settings/MaintenanceRoadmapFragmentTest.php,
 * tests/Unit/EventListener/MaintenanceRecipientsListenerTest.php and
 * tests/vitest/maintenance.spec.js.
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md
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
const product = `${RUN_ID} product X`
const seededWindow = `${RUN_ID} database upgrade`
const announced = `${RUN_ID} storage move`
const statement = `${RUN_ID} we move to a cloud edition next year`

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

/**
 * An ISO date-time a number of days from now.
 *
 * @param days Days ahead.
 * @param hour The hour of the day.
 * @return The date-time.
 */
function daysAhead(days: number, hour: number): string {
	const date = new Date()
	date.setDate(date.getDate() + days)
	date.setHours(hour, 0, 0, 0)
	return date.toISOString()
}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	ids.org = await seed('organization', { name: `${RUN_ID} municipality` })
	ids.x = await seed('module', { name: product, roadmapStatement: statement })
	ids.v3 = await seed('moduleVersion', {
		module: ids.x,
		version: '3.0',
		status: 'in development',
		dateInUse: daysAhead(150, 12),
	})
	ids.usage = await seed('usage', {
		consumer: ids.org,
		module: ids.x,
		status: 'In production',
	})
	ids.window = await seed('maintenanceWindow', {
		module: ids.x,
		title: seededWindow,
		startsAt: daysAhead(5, 8),
		endsAt: daysAhead(5, 12),
		impact: 'unavailable',
		status: 'planned',
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e maintenance-and-supplier-roadmap::a-supplier-announces-a-maintenance-window
test('announcing maintenance lists the window as planned on the product page', async ({
	page,
}) => {
	await gotoAppRoute(page, `/modules/${ids.x}`)
	await dismissSupportDialog(page)
	await page.getByRole('button', { name: 'Announce maintenance' }).first().click()
	const dialog = page.getByRole('dialog')
	await expect(dialog).toBeVisible({ timeout: 30000 })
	await dialog.getByLabel('Title').fill(announced)
	await dialog.getByLabel('Starts at').fill(daysAhead(9, 8).slice(0, 16))
	await dialog.getByLabel('Ends at').fill(daysAhead(9, 12).slice(0, 16))
	await dialog
		.getByRole('button', { name: /save|create/i })
		.last()
		.click()
	await expect(dialog).toBeHidden({ timeout: 30000 })
	const row = page.getByRole('row').filter({ hasText: announced })
	await expect(row).toContainText(/planned/i, { timeout: 30000 })
	const found = await apiCtx.get(
		`/index.php/apps/openregister/api/objects/${cfg.register}/maintenanceWindow?title=${encodeURIComponent(announced)}`,
	)
	for (const result of (await found.json()).results ?? []) {
		seeded.push(['maintenanceWindow', result.id ?? result['@self']?.id])
	}
})

// @e2e maintenance-and-supplier-roadmap::a-municipality-sees-the-window-on-its-dashboard
test('the dashboard lists the window with its impact', async ({ page }) => {
	await gotoAppRoute(page, '/')
	await dismissSupportDialog(page)
	const item = page
		.getByTestId('upcoming-maintenance-item')
		.filter({ hasText: seededWindow })
	await expect(item).toBeVisible({ timeout: 30000 })
	await expect(item).toContainText('Unavailable')
})

// @e2e maintenance-and-supplier-roadmap::a-buyer-reads-what-ships-next
test('the product page shows the roadmap statement and the planned version', async ({
	page,
}) => {
	await gotoAppRoute(page, `/modules/${ids.x}`)
	await dismissSupportDialog(page)
	await expect(page.getByTestId('product-roadmap-statement')).toContainText(
		statement,
		{ timeout: 30000 },
	)
	await expect(page.getByTestId('product-roadmap')).toContainText('3.0')
})

// @e2e maintenance-and-supplier-roadmap::a-supplier-releases-the-planned-version
test('Release moves the planned version into use', async ({ page }) => {
	await gotoAppRoute(page, `/moduleversies/${ids.v3}`)
	await dismissSupportDialog(page)
	await page
		.getByRole('button', { name: /release/i })
		.first()
		.click()
	await expect(page.getByText(/in use/i).first()).toBeVisible({ timeout: 30000 })
})
