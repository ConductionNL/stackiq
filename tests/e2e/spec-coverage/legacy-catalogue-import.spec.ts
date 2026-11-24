// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Bringing over what an organisation registered in the previous
 * Softwarecatalogus (board SkApplicaties; REQ-LCM-001 to REQ-LCM-003): the
 * Applications actions menu opens a three-step dialog (file, preview, result)
 * that reads the previous catalogue's export, shows per row whether supplier,
 * product and usage are matched or created, writes nothing before the user
 * confirms, and updates instead of duplicating on a second run.
 *
 * Built in tasks 1 to 4 of the change: the profile, `POST /api/legacy-import/preview`
 * and `/apply`, the dialog (`LegacyCatalogueImportModal.vue`) and the fixture
 * `tests/fixtures/cmdb/vng-swc-export.xlsx`.
 *
 * Seeds, carrying this run's RUN_ID, the organisation the user imports for,
 * and the fixture's first supplier with its application, so its first row
 * matches. The rows the import creates are found by their `legacyId` and
 * removed afterwards.
 *
 * Written from the change's scenarios; runs in the nightly, not yet run
 * against a live instance from the sandbox it was written in.
 *
 * @spec openspec/changes/archive/2026-11-24-landscape-migrate-from-previous-catalogue/specs/legacy-catalogue-migration/spec.md
 */
import type { APIRequestContext, Page } from '@playwright/test'
import type { VoorzieningenConfig } from '../workflows/_fixtures.ts'

import { expect, test } from '@playwright/test'
import * as path from 'path'
import {
	createObject,
	deleteObject,
	findAll,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from '../workflows/_fixtures.ts'
import { APP_MAIN, dismissSupportDialog, gotoAppRoute } from './_helpers.ts'

const FIXTURES = path.resolve(__dirname, '../../fixtures')
const EXPORT_FILE = path.join(FIXTURES, 'cmdb', 'vng-swc-export.xlsx')
const MENU_ITEM = 'Bring over from the previous Softwarecatalogus'

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
let organisationId = ''
let supplierId = ''
let moduleId = ''
const organisation = `${RUN_ID} Gemeente Zuiddrecht`

/**
 * Open the import dialog from the Applications actions menu.
 *
 * @param page The page.
 */
async function openImport(page: Page): Promise<void> {
	await gotoAppRoute(page, '/modules')
	await dismissSupportDialog(page)
	await page.locator(APP_MAIN).getByRole('button', { name: 'Actions' }).first().click()
	await page.getByRole('menuitem', { name: MENU_ITEM }).click()
	await expect(page.getByRole('dialog', { name: MENU_ITEM })).toBeVisible({ timeout: 30000 })
}

/**
 * Pick a file in the dialog's first step and go to the preview.
 *
 * @param page The page.
 * @param file The file to upload.
 */
async function pickFile(page: Page, file: string): Promise<void> {
	const dialog = page.getByRole('dialog', { name: MENU_ITEM })
	await dialog.locator('input[type="file"]').setInputFiles(file)
	await dialog.getByRole('button', { name: 'Preview' }).click()
}

/**
 * Usages of this run's organisation that came from the previous catalogue.
 *
 * @return The usages with a legacyId.
 */
async function importedUsages(): Promise<Array<Record<string, unknown>>> {
	const usages = await findAll(apiCtx, cfg.register, 'usage', '')
	return usages.filter((usage) => usage.consumer === organisationId && usage.legacyId)
}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	organisationId = await createObject(apiCtx, cfg.register, 'organization', { name: organisation, type: 'Municipality' })
	supplierId = await createObject(apiCtx, cfg.register, 'organization', { name: 'Voorbeeld Software B.V.', type: 'Supplier' })
	moduleId = await createObject(apiCtx, cfg.register, 'module', { name: 'Zaaksysteem Voorbeeld', provider: supplierId })
})

test.afterAll(async () => {
	if (!apiCtx) return
	if (organisationId) {
		for (const usage of await importedUsages()) {
			await deleteObject(apiCtx, cfg.register, 'usage', String(usage.id))
		}
		await deleteObject(apiCtx, cfg.register, 'organization', organisationId)
	}
	if (moduleId) await deleteObject(apiCtx, cfg.register, 'module', moduleId)
	if (supplierId) await deleteObject(apiCtx, cfg.register, 'organization', supplierId)
	await apiCtx.dispose()
})

// @e2e legacy-catalogue-migration::opening-the-import
test('the preview opens and nothing is written before the user confirms', async ({ page }) => {
	await openImport(page)
	await pickFile(page, EXPORT_FILE)

	const dialog = page.getByRole('dialog', { name: MENU_ITEM })
	await expect(dialog.getByTestId('legacy-import-preview')).toBeVisible({ timeout: 30000 })
	expect(await importedUsages()).toHaveLength(0)
})

// @e2e legacy-catalogue-migration::a-file-that-is-not-an-export
test('a spreadsheet without the expected columns is refused with their names', async ({ page }) => {
	await openImport(page)
	await pickFile(page, path.join(FIXTURES, 'cmdb', 'topdesk-export-anonymised.xlsx'))

	const dialog = page.getByRole('dialog', { name: MENU_ITEM })
	await expect(dialog.getByRole('alert')).toContainText('missing', { timeout: 30000 })
	await expect(dialog.getByTestId('legacy-import-preview')).toHaveCount(0)
	expect(await importedUsages()).toHaveLength(0)
})

// @e2e legacy-catalogue-migration::a-known-supplier-and-product
// @e2e legacy-catalogue-migration::an-unknown-product
test('the preview shows matched and created per row', async ({ page }) => {
	await openImport(page)
	await pickFile(page, EXPORT_FILE)

	const preview = page.getByRole('dialog', { name: MENU_ITEM }).getByTestId('legacy-import-preview')
	await expect(preview.getByText('matched').first()).toBeVisible({ timeout: 30000 })
	await expect(preview.getByText('create').first()).toBeVisible()
})

// @e2e legacy-catalogue-migration::the-first-run
// @e2e legacy-catalogue-migration::running-it-again
test('the result counts created and skipped rows, and a second run duplicates nothing', async ({ page }) => {
	await openImport(page)
	await pickFile(page, EXPORT_FILE)

	const dialog = page.getByRole('dialog', { name: MENU_ITEM })
	const rows = dialog.getByTestId('legacy-import-preview').getByRole('checkbox')
	await expect(rows.first()).toBeVisible({ timeout: 30000 })
	const total = await rows.count()
	await rows.first().uncheck()
	await dialog.getByRole('button', { name: 'Import' }).click()

	const result = dialog.getByTestId('legacy-import-result')
	await expect(result).toContainText(`created ${total - 1}`, { timeout: 60000 })
	await expect(result).toContainText('skipped 1')
	const first = await importedUsages()
	expect(first).toHaveLength(total - 1)

	// The same export again: updated, never duplicated.
	await openImport(page)
	await pickFile(page, EXPORT_FILE)
	await dialog.getByRole('button', { name: 'Import' }).click()
	await expect(dialog.getByTestId('legacy-import-result')).toContainText('created 0', { timeout: 60000 })
	const second = await importedUsages()
	expect(second.length).toBeLessThanOrEqual(total)
	expect(new Set(second.map((usage) => usage.legacyId)).size).toBe(second.length)
})
