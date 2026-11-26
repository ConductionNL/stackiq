// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Spec coverage: openspec/changes/archive/2026-11-25-landscape-change-entry-type/specs/entry-type-change/spec.md
 * (REQ-ETC-001, REQ-ETC-002)
 *
 * An application changes into a service from its own page: the Change type
 * dialog previews what carries over and what is dropped, and confirming moves
 * the object with OpenRegister's move, so the same identifier answers as a
 * catalogService afterwards with its history. The refused-change case is
 * PHPUnit's (`@e2e exclude` in the spec); the reverse direction has no page
 * to start from yet and goes through the API.
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
const ids: Record<string, string> = {}

/**
 * Seed one object and remember it for clean-up.
 *
 * @param schema The schema slug.
 * @param data The object.
 */
async function seed(schema: string, data: Record<string, unknown>): Promise<string> {
	const id = await createObject(apiCtx, cfg.register, schema, data)
	seeded.push([schema, id])
	return id
}

/**
 * Open an application's page and its Change type dialog, and pick Service.
 *
 * @param page The page.
 * @param id The application.
 */
async function pickService(page: Page, id: string): Promise<ReturnType<Page['getByRole']>> {
	await gotoAppRoute(page, `/modules/${id}`)
	await dismissSupportDialog(page)
	await page.getByRole('button', { name: 'Change type' }).first().click({ timeout: 30000 })

	const dialog = page.getByRole('dialog', { name: 'Change type' })
	await expect(dialog).toBeVisible({ timeout: 15000 })
	const preview = page.waitForResponse((resp) => resp.url().includes(`/api/entries/${id}/type-change`) && resp.request().method() === 'GET')
	await dialog.getByRole('combobox', { name: 'New type' }).click()
	await page.getByRole('option', { name: 'Service', exact: true }).click()
	expect((await preview).status()).toBe(200)

	return dialog
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
	ids.licensed = await seed('module', {
		name: `${RUN_ID} licensed application`,
		provider: ids.supplier,
		licentietype: 'Open source',
	})
	ids.hosting = await seed('module', {
		name: `${RUN_ID} Hosting en beheer`,
		shortDescription: `${RUN_ID} hosting, registered as an application by mistake`,
		provider: ids.supplier,
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	await deleteObject(apiCtx, cfg.register, 'catalogService', ids.hosting)
	await apiCtx.dispose()
})

// @e2e entry-type-change::the-preview-names-the-dropped-licence-field
test('the preview lists the licence type under the fields that will be dropped, with its value', async ({ page }) => {
	const dialog = await pickService(page, ids.licensed)

	await expect(dialog.getByRole('heading', { name: 'Dropped' })).toBeVisible({ timeout: 15000 })
	await expect(dialog).toContainText('licentietype')
	await expect(dialog).toContainText('Open source')
	await expect(dialog.getByRole('heading', { name: 'Carried over' })).toBeVisible()

	// Nothing has changed yet: the dialog is closed without confirming.
	await dialog.getByRole('button', { name: 'Cancel' }).click()
	const still = await apiCtx.get(`/index.php/apps/openregister/api/objects/${cfg.register}/module/${ids.licensed}`)
	expect(still.status()).toBe(200)
})

// @e2e entry-type-change::a-supplier-turns-an-application-into-a-service
test('a supplier turns an application into a service and it keeps its identifier and history', async ({ page }) => {
	const dialog = await pickService(page, ids.hosting)
	await expect(dialog).toContainText('The entry keeps its identity')

	const confirmButton = dialog.getByRole('button', { name: 'Change type' })
	await expect(confirmButton, 'a service needs a service type first').toBeDisabled()
	await dialog.getByRole('combobox', { name: 'Service type' }).click()
	await page.getByRole('option').first().click()
	await page.keyboard.press('Escape')

	const changed = page.waitForResponse((resp) => resp.url().includes(`/api/entries/${ids.hosting}/type-change`) && resp.request().method() === 'POST')
	await confirmButton.click()
	expect((await changed).status()).toBe(200)
	await expect(page.getByText('The type was changed.').first()).toBeVisible({ timeout: 15000 })

	// The same identifier now answers as a service, and no longer as an application.
	const asService = await apiCtx.get(`/index.php/apps/openregister/api/objects/${cfg.register}/catalogService/${ids.hosting}`)
	expect(asService.status()).toBe(200)
	const service = await asService.json()
	expect(service.id ?? service['@self']?.id).toBe(ids.hosting)
	expect(service.name).toBe(`${RUN_ID} Hosting en beheer`)
	// OpenRegister finds an object by its uuid whatever schema the address names, so the old
	// address may still answer: then it answers with the service, never with an application.
	const asModule = await apiCtx.get(`/index.php/apps/openregister/api/objects/${cfg.register}/module/${ids.hosting}`)
	if (asModule.status() === 200) {
		expect(String((await asModule.json())['@self']?.schema)).toBe(String(service['@self']?.schema))
	}

	// Its history came along: the audit trail of the moved object is not empty.
	const trail = await apiCtx.get(`/index.php/apps/openregister/api/objects/${cfg.register}/catalogService/${ids.hosting}/audit-trails`)
	expect(trail.status()).toBe(200)
	const entries = await trail.json()
	expect((entries.results ?? entries).length).toBeGreaterThan(0)
	seeded.splice(seeded.findIndex(([, id]) => id === ids.hosting), 1)
})
