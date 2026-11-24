// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Dependent field options: the edit form of an application offers no licence
 * while its licence type is Closed source, and the five open-source licences
 * once the licence type is Open source.
 *
 * Seeds one closed-source application carrying this run's RUN_ID through the
 * objects API and removes it afterwards. The narrowing itself is covered by
 * tests/vitest/dependentFieldOptionsForm.spec.js and the save rule by
 * tests/Unit/Settings/DependentFieldOptionsTest.php.
 *
 * Written from the change's scenario; not yet run against a live instance.
 *
 * @spec openspec/changes/archive/2026-11-24-landscape-dependent-field-options/specs/dependent-field-options/spec.md#requirement-req-dfo-003-the-form-offers-only-the-allowed-options
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

const OPEN_SOURCE_LICENCES = [
	'MIT License',
	'GNU General Public License (GPL)',
	'Apache License 2.0',
	'BSD License (Berkeley Software Distribution)',
	'European Union Public Licence (EUPL), version 1.2',
]

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
let moduleId = ''

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	moduleId = await createObject(apiCtx, cfg.register, 'module', {
		name: `${RUN_ID} closed application`,
		licentietype: 'Closed source',
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	if (moduleId) {
		await deleteObject(apiCtx, cfg.register, 'module', moduleId)
	}
	await apiCtx.dispose()
})

// @e2e dependent-field-options::switching-an-application-to-open-source-opens-the-licence-list
test('switching an application to open source opens the licence list', async ({ page }) => {
	await gotoAppRoute(page, `/modules/${moduleId}`)
	await dismissSupportDialog(page)
	await page.getByRole('button', { name: 'Edit', exact: true }).first().click()
	const dialog = page.locator('[role="dialog"], .modal-container').first()
	await dialog.waitFor({ state: 'visible', timeout: 15000 })

	const licence = dialog.getByLabel('License', { exact: true }).first()
	await licence.click()
	await expect(page.getByRole('option')).toHaveCount(0)
	await page.keyboard.press('Escape')

	const licenceType = dialog.getByLabel('License type').first()
	await licenceType.click()
	await page.getByRole('option', { name: 'Open source', exact: true }).first().click()

	await licence.click()
	const options = page.getByRole('option')
	await expect(options).toHaveCount(OPEN_SOURCE_LICENCES.length, { timeout: 15000 })
	for (const name of OPEN_SOURCE_LICENCES) {
		await expect(page.getByRole('option', { name, exact: true })).toBeVisible()
	}
})
