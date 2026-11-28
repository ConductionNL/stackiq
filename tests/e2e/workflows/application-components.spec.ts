// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Application components (landscape-application-components): a supplier adds
 * a component from the Components section of its application's page and the
 * component names the application it is part of, and a buyer reads the
 * components of an application while the Applications list shows whole
 * applications until "All, including components" is picked.
 *
 * Seeds a supplier organisation, two applications and two components of the
 * second one carrying this run's RUN_ID through the objects API (the call the
 * Add form makes). Removes exactly those rows and the component the first test
 * adds through the form afterwards. Setup and teardown use the API; every
 * assertion is made on the rendered page.
 *
 * The scenario marked `@e2e exclude` (a cycle is refused) is covered by
 * tests/Unit/Service/ModuleComponentGuardTest.php and
 * tests/Unit/EventListener/ModuleComponentListenerTest.php.
 *
 * Written from the change's scenarios; not yet run against a live instance.
 *
 * @spec openspec/changes/archive/2026-11-25-landscape-application-components/specs/application-components/spec.md#requirement-req-acm-001-an-application-can-name-the-application-it-is-a-component-of
 * @spec openspec/changes/archive/2026-11-25-landscape-application-components/specs/application-components/spec.md#requirement-req-acm-002-the-application-page-lists-its-components
 */
import type { APIRequestContext, Locator, Page } from '@playwright/test'
import type { VoorzieningenConfig } from './_fixtures.ts'

import { expect, test } from '@playwright/test'
import {
	createObject,
	deleteObject,
	findAll,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from './_fixtures.ts'
import { dismissSupportDialog, gotoAppRoute, revealSection } from './_ui.ts'

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
const seeded: Array<[string, string]> = []
const ids: Record<string, string> = {}
const supplier = `${RUN_ID} component supplier`
// Names are chosen so none is a substring of another: a text match on the
// application never also matches one of its components.
const records = `${RUN_ID} records suite`
const addedComponent = `${RUN_ID} citizen portal`
const caseSuite = `${RUN_ID} case suite`
const casePortal = `${RUN_ID} case portal`
const documentStore = `${RUN_ID} document store`

test.describe.configure({ mode: 'serial' })

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
 * The titled card of a list widget on a detail page.
 *
 * @param page The page.
 * @param title The card's title.
 * @return The card.
 */
function catalogCard(page: Page, title: string): Locator {
	return page
		.locator('.cn-detail-page__catalog-card')
		.filter({ has: page.getByText(title, { exact: true }) })
		.first()
}

/**
 * Click a list card's Add: the title-row button in the board look, else the
 * item in the card's Actions menu. Returns the create dialog.
 *
 * @param page The page.
 * @param card The card.
 * @param label The Add label.
 * @return The dialog.
 */
async function addFromCard(page: Page, card: Locator, label: string): Promise<Locator> {
	const button = card.getByTestId('cn-detail-widget-catalog-add')
	if (await button.count()) {
		await button.first().click()
	} else {
		await card.getByRole('button', { name: 'Actions' }).first().click()
		await page.locator('.v-popper__popper--shown').last().getByRole('menuitem', { name: label }).first().click()
	}
	const dialog = page.locator('[role="dialog"], .modal-container').first()
	await dialog.waitFor({ state: 'visible', timeout: 15000 })
	return dialog
}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	ids.supplier = await seed('organization', {
		name: supplier,
		type: 'Supplier',
		status: 'Active',
		contactsUid: `${RUN_ID}-component-supplier`,
	})
	ids.records = await seed('module', { name: records, provider: ids.supplier })
	ids.caseSuite = await seed('module', { name: caseSuite, provider: ids.supplier })
	ids.casePortal = await seed('module', { name: casePortal, provider: ids.supplier, partOf: ids.caseSuite })
	ids.documentStore = await seed('module', { name: documentStore, provider: ids.supplier, partOf: ids.caseSuite })
})

test.afterAll(async () => {
	if (!apiCtx) return
	// The component the first test added through the form; a component goes
	// before the application it is part of.
	for (const row of await findAll(apiCtx, cfg.register, 'module', addedComponent)) {
		const id = String((row as { id?: string }).id ?? (row as { '@self'?: { id?: string } })['@self']?.id ?? '')
		if (id && JSON.stringify(row).includes(RUN_ID)) {
			await deleteObject(apiCtx, cfg.register, 'module', id)
		}
	}
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e application-components::a-supplier-records-a-component
test('a component added from the application page names that application as Part of', async ({
	page,
}) => {
	await gotoAppRoute(page, `/modules/${ids.records}`)
	await dismissSupportDialog(page)
	await revealSection(page, 'Components')
	const components = catalogCard(page, 'Components')
	await expect(components).toBeVisible({ timeout: 30000 })

	// The create form seeds Part of from the list's filter.
	const dialog = await addFromCard(page, components, 'Add component')
	await dialog.getByLabel('Name', { exact: true }).first().fill(addedComponent)
	await dialog.getByRole('button', { name: /save|create/i }).last().click()
	await expect(dialog).toBeHidden({ timeout: 30000 })

	const row = components.getByRole('row').filter({ hasText: addedComponent })
	await expect(row).toBeVisible({ timeout: 30000 })
	await row.getByText(addedComponent).first().click()
	await expect(page).toHaveURL(/\/modules\/[0-9a-f-]{36}/, { timeout: 30000 })
	await expect(page).not.toHaveURL(new RegExp(`/modules/${ids.records}`))
	await dismissSupportDialog(page)
	await expect(page.getByText(addedComponent).first()).toBeVisible({ timeout: 30000 })
	await expect(page.getByText('Part of', { exact: true }).first()).toBeVisible({ timeout: 30000 })
	await expect(page.getByText(records, { exact: true }).first()).toBeVisible({ timeout: 30000 })
})

// @e2e application-components::a-buyer-reads-what-an-application-is-made-of
test('the application page lists both components and the list hides them until All, including components', async ({
	page,
}) => {
	await gotoAppRoute(page, `/modules/${ids.caseSuite}`)
	await dismissSupportDialog(page)
	await revealSection(page, 'Components')
	const components = catalogCard(page, 'Components')
	await expect(components).toBeVisible({ timeout: 30000 })
	await expect(components.getByRole('row').filter({ hasText: casePortal })).toBeVisible({ timeout: 30000 })
	await expect(components.getByRole('row').filter({ hasText: documentStore })).toBeVisible({ timeout: 30000 })

	await gotoAppRoute(page, `/modules?_search=${encodeURIComponent(RUN_ID)}`)
	await dismissSupportDialog(page)
	// The list opens on Whole applications.
	await expect(page.getByRole('row').filter({ hasText: caseSuite })).toBeVisible({ timeout: 30000 })
	await expect(page.getByRole('row').filter({ hasText: casePortal })).toHaveCount(0)
	await expect(page.getByRole('row').filter({ hasText: documentStore })).toHaveCount(0)

	await page.getByRole('tab', { name: 'All, including components', exact: true }).click()
	await expect(page.getByRole('row').filter({ hasText: casePortal })).toBeVisible({ timeout: 30000 })
	await expect(page.getByRole('row').filter({ hasText: documentStore })).toBeVisible({ timeout: 30000 })
	await expect(page.getByRole('row').filter({ hasText: caseSuite })).toBeVisible()
})
