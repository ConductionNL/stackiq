// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Spec coverage: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/reference-component-placement/spec.md
 * (REQ-CLR-007, a supplier picks the reference components an application fulfils on its form).
 *
 * Seeds one GEMMA reference component in the vng-gemma register and one
 * application, opens the application's edit form from the Applications index,
 * picks the component and saves. The field and its picker are the point; the
 * session is the suite's signed-in editor (the supplier's own write rights on
 * a module are pinned by the module schema's authorization tests).
 */
import type { APIRequestContext } from '@playwright/test'
import type { VoorzieningenConfig } from './_fixtures.ts'

import { expect, test } from '@playwright/test'
import { createObject, deleteObject, newApiContext, resolveConfig, RUN_ID } from './_fixtures.ts'
import { clickAction, dismissSupportDialog, gotoAppRoute, indexMain, openRowActions, showTable } from './_ui.ts'

const GEMMA_REGISTER = 'vng-gemma'
const COMPONENT = `${RUN_ID} Zaakregistratiecomponent`
const APPLICATION = `${RUN_ID} Zaaksysteem X`

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
const seeded: Array<[string, string, string]> = []
const ids: Record<string, string> = {}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	ids.component = await createObject(apiCtx, GEMMA_REGISTER, 'element', {
		identifier: `${RUN_ID}-zrc`,
		type: 'ApplicationComponent',
		properties: [],
		name: COMPONENT,
		gemmaType: 'referentiecomponent',
	})
	seeded.push([GEMMA_REGISTER, 'element', ids.component])
	ids.module = await createObject(apiCtx, cfg.register, 'module', { name: APPLICATION })
	seeded.push([cfg.register, 'module', ids.module])
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [register, schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/reference-component-placement/spec.md#scenario-a-supplier-places-an-application-on-a-reference-component
test('an application is placed on a reference component from its own form', async ({ page }) => {
	await gotoAppRoute(page, `/modules?_search=${encodeURIComponent(APPLICATION)}`)
	await dismissSupportDialog(page)
	await showTable(page)
	await expect(indexMain(page).getByText(APPLICATION).first()).toBeVisible({ timeout: 30_000 })
	await openRowActions(page, APPLICATION)
	await clickAction(page, 'Edit')

	const dialog = page.locator('[role="dialog"], .modal-container').first()
	await dialog.waitFor({ state: 'visible', timeout: 15_000 })
	const picker = dialog.getByRole('combobox', { name: /reference components/i })
	await expect(picker, 'the application form offers the reference components').toBeVisible()
	await picker.fill(RUN_ID)
	await page.getByRole('option', { name: new RegExp(COMPONENT) }).first().click()
	await dialog.getByRole('button', { name: /^(save|opslaan)$/i }).click()
	await expect(dialog).toBeHidden({ timeout: 15_000 })

	// Stored on the application, as a GEMMA element id.
	await expect
		.poll(async () => {
			const res = await apiCtx.get(`/index.php/apps/openregister/api/objects/${cfg.register}/module/${ids.module}`)
			const refs = ((await res.json())?.referenceComponents ?? []) as Array<string | { id?: string }>
			return refs.map((ref) => (typeof ref === 'string' ? ref : String(ref?.id ?? '')))
		})
		.toContain(ids.component)

	// And the application page shows it.
	await gotoAppRoute(page, `/modules/${ids.module}`)
	await dismissSupportDialog(page)
	await expect(page.getByText(COMPONENT).first()).toBeVisible({ timeout: 30_000 })
})
