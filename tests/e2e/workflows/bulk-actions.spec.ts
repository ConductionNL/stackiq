// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Bulk delete on the Applications page: three selected applications are
 * deleted in one action, after a confirmation that lists how many go.
 *
 * Seeds three applications carrying this run's RUN_ID through the objects
 * API, ticks their rows on /modules, chooses "Delete selected" in the
 * selection strip and confirms in the mass-delete dialog. Afterwards the
 * seeded rows are deleted again; a row the test already removed answers 404,
 * which the cleanup ignores.
 *
 * Written from the change's scenarios; not yet run against a live instance.
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/open-data-publishing/spec.md#requirement-selected-entries-are-deleted-together-from-the-list-req-clr-011
 */
import type { APIRequestContext, Page } from '@playwright/test'
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
const apps = [
	`${RUN_ID} application one`,
	`${RUN_ID} application two`,
	`${RUN_ID} application three`,
]

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
 * Tick a list row's checkbox by the text it shows.
 *
 * @param page The page.
 * @param text The row's text.
 * @return Nothing.
 */
async function selectRow(page: Page, text: string): Promise<void> {
	const row = page.getByRole('row').filter({ hasText: text })
	await expect(row).toBeVisible({ timeout: 30000 })
	await row.getByRole('checkbox').first().check({ force: true })
}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	for (const name of apps) {
		await seed('module', { name })
	}
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e open-data-publishing::an-information-manager-deletes-three-entries-at-once
test('three selected applications are deleted together after a confirmation that lists them', async ({
	page,
}) => {
	await gotoAppRoute(page, `/modules?_search=${encodeURIComponent(RUN_ID)}`)
	await dismissSupportDialog(page)
	for (const name of apps) {
		await selectRow(page, name)
	}

	const strip = page.getByTestId('cn-selection-strip')
	await expect(strip).toContainText('3 selected')
	await strip.getByRole('button', { name: 'Delete selected' }).click()

	const review = page.locator('[data-testid-modal="cn-mass-delete-dialog"]')
	await expect(review).toBeVisible({ timeout: 30000 })
	await expect(review.locator('.cn-mass-delete__item')).toHaveCount(3)
	for (const name of apps) {
		await expect(review).toContainText(name)
	}
	await page.getByTestId('cn-mass-delete-dialog-confirm').click()

	for (const name of apps) {
		await expect(page.getByRole('row').filter({ hasText: name })).toHaveCount(0, { timeout: 30000 })
	}

	// The rows stay gone after a fresh load, so the delete reached the server.
	await gotoAppRoute(page, `/modules?_search=${encodeURIComponent(RUN_ID)}`)
	await dismissSupportDialog(page)
	for (const name of apps) {
		await expect(page.getByRole('row').filter({ hasText: name })).toHaveCount(0, { timeout: 30000 })
	}
})
