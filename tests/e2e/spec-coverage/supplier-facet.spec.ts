// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * The Supplier facet on the Applications and Services pages
 * (the supplier facet change, task 3; REQ-SFC-002).
 *
 * Surface under test: `FacetedCatalogIndexView` on /modules and /diensten,
 * whose CnFacetSidebar ("GEMMA filters") gains a Supplier group fed by the
 * `supplier` bucket of `GET /api/facets/{schema}`; a choice is kept in the URL
 * under `_gf_supplier` and in a saved facet view.
 *
 * Seeds, through the objects API, two supplier organisations, three
 * applications (two of supplier A, one of supplier B) and two services (one
 * per supplier), all carrying this run's RUN_ID, and removes exactly those
 * rows and the saved view the test makes afterwards.
 *
 * Every visit opens the page with the run's token as the search
 * (`?_search=`): the list then holds only this run's rows, and the facet
 * counts are computed fresh rather than served from the TTL-only cache of the
 * unparameterised request (see gemma-faceted-search.spec.ts), which may
 * predate the seeded suppliers.
 *
 * Written from the change's scenarios; not yet run against a live instance.
 *
 * @spec openspec/changes/insight-supplier-facet/specs/supplier-facet/spec.md#requirement-req-sfc-002-the-applications-and-services-pages-shall-offer-supplier-as-a-facet-that-narrows-the-list
 */
import type { APIRequestContext, Page } from '@playwright/test'
import type { VoorzieningenConfig } from '../workflows/_fixtures.ts'

import { expect, test } from '@playwright/test'
import {
	createObject,
	deleteObject,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from '../workflows/_fixtures.ts'
import { APP_MAIN, gotoAppRoute } from './_helpers.ts'

const VIEWS = '/index.php/apps/openregister/api/views'
const supplierA = `${RUN_ID} supplier A`
const supplierB = `${RUN_ID} supplier B`
const appA1 = `${RUN_ID} application A one`
const appA2 = `${RUN_ID} application A two`
const appB = `${RUN_ID} application B`
const serviceA = `${RUN_ID} service A`
const serviceB = `${RUN_ID} service B`
const viewName = `${RUN_ID} supplier A services`

let api: APIRequestContext
let config: VoorzieningenConfig
const seeded: Array<[string, string]> = []
const ids: Record<string, string> = {}

/**
 * Create a row and remember it for cleanup.
 *
 * @param schema The schema slug.
 * @param data The object.
 * @return The new id.
 */
async function seed(schema: string, data: Record<string, unknown>): Promise<string> {
	const id = await createObject(api, config.register, schema, data)
	seeded.push([schema, id])
	return id
}

/**
 * Escape a literal for use in a regular expression.
 *
 * @param text The literal.
 * @return The escaped text.
 */
function escapeRegExp(text: string): string {
	return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * Open a faceted list with the run's token as the search.
 *
 * @param page The page.
 * @param route The list route.
 * @return Nothing.
 */
async function openList(page: Page, route: string): Promise<void> {
	await gotoAppRoute(page, `${route}?_search=${encodeURIComponent(RUN_ID)}`)
	await expect(page.locator('.cn-facet-sidebar').first()).toBeVisible({ timeout: 30000 })
}

/**
 * Choose a supplier in the Supplier facet.
 *
 * @param page The page.
 * @param supplier The supplier's name.
 * @return Nothing.
 */
async function chooseSupplier(page: Page, supplier: string): Promise<void> {
	// NcSelect splits a long label in two spans around a middle ellipsis, which
	// puts a space in the option's accessible name; its text content stays whole.
	const group = page.locator('.cn-facet-sidebar__group').filter({ hasText: 'Supplier' }).first()
	await expect(group).toBeVisible({ timeout: 30000 })
	await group.getByLabel('Supplier', { exact: true }).click()
	await page.getByRole('option').filter({ hasText: supplier }).first().click()
	await page.keyboard.press('Escape')
}

test.beforeAll(async () => {
	api = await newApiContext()
	config = await resolveConfig(api)
	ids.supplierA = await seed('organization', { name: supplierA, type: 'Supplier', status: 'Active', contactsUid: `${RUN_ID}-a` })
	ids.supplierB = await seed('organization', { name: supplierB, type: 'Supplier', status: 'Active', contactsUid: `${RUN_ID}-b` })
	ids.appA1 = await seed('module', { name: appA1, provider: ids.supplierA })
	ids.appA2 = await seed('module', { name: appA2, provider: ids.supplierA })
	ids.appB = await seed('module', { name: appB, provider: ids.supplierB })
	ids.serviceA = await seed('catalogService', { name: serviceA, provider: ids.supplierA, type: ['Application management'], modules: [ids.appA1] })
	ids.serviceB = await seed('catalogService', { name: serviceB, provider: ids.supplierB, type: ['Application management'], modules: [ids.appB] })
})

test.afterAll(async () => {
	if (!api) return
	const views = await api.get(VIEWS)
	if (views.ok()) {
		const rows: Array<{ id?: unknown, uuid?: unknown, name?: unknown }> = (await views.json())?.results ?? []
		for (const view of rows.filter((row) => row.name === viewName)) {
			await api.delete(`${VIEWS}/${String(view.id ?? view.uuid)}`).catch(() => undefined)
		}
	}
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(api, config.register, schema, id)
	}
	await api.dispose()
})

// @e2e supplier-facet::an-information-manager-narrows-applications-to-one-supplier
test('choosing a supplier on Applications leaves only its applications and puts _gf_supplier in the URL', async ({ page }) => {
	await openList(page, '/modules')
	const main = page.locator(APP_MAIN).first()
	await expect(main.getByText(appB).first()).toBeVisible({ timeout: 30000 })

	// The facet lists each supplier by name with its count, not by id.
	const group = page.locator('.cn-facet-sidebar__group').filter({ hasText: 'Supplier' }).first()
	await group.getByLabel('Supplier', { exact: true }).click()
	await expect(page.getByRole('option').filter({ hasText: supplierA }).first()).toContainText('2')
	await expect(page.getByRole('option').filter({ hasText: supplierB }).first()).toContainText('1')
	await expect(page.getByRole('option', { name: new RegExp(ids.supplierA) })).toHaveCount(0)
	await page.keyboard.press('Escape')

	await chooseSupplier(page, supplierA)
	await expect(page).toHaveURL(/_gf_supplier/, { timeout: 30000 })
	await expect(main.getByText(appA1).first()).toBeVisible({ timeout: 30000 })
	await expect(main.getByText(appA2).first()).toBeVisible()
	await expect(main.getByText(appB)).toHaveCount(0)

	// A reload restores the choice from the URL.
	await page.reload({ waitUntil: 'domcontentloaded' })
	await expect(page.locator(APP_MAIN).first().getByText(appA1).first()).toBeVisible({ timeout: 30000 })
	await expect(page.locator(APP_MAIN).first().getByText(appB)).toHaveCount(0)
})

// @e2e supplier-facet::supplier-combines-with-a-gemma-facet
test.fixme('with a reference component chosen, the Supplier counts cover only its applications', async () => {
	// Not seedable: a reference component reaches a module only as a link to an
	// AMEF `element` object; the objects API drops bare strings in
	// referenceComponents (see gemma-faceted-search.spec.ts), and this suite
	// builds no element and relation fixtures. FacetServiceTest covers the
	// disjunctive count.
})

// @e2e supplier-facet::a-saved-view-keeps-the-supplier
test('a saved Services view applied on a fresh page chooses the supplier again and narrows the list', async ({ page }) => {
	await openList(page, '/diensten')
	const main = page.locator(APP_MAIN).first()
	await expect(main.getByText(serviceB).first()).toBeVisible({ timeout: 30000 })
	await chooseSupplier(page, supplierA)
	await expect(page).toHaveURL(/_gf_supplier/, { timeout: 30000 })
	await expect(main.getByText(serviceB)).toHaveCount(0, { timeout: 30000 })

	// With no saved view yet NcActions holds one action and shows it inline;
	// once views exist it is a "Saved views" menu.
	const saveInline = page.getByRole('button', { name: 'Save current filters as view' }).first()
	if (await saveInline.isVisible().catch(() => false)) {
		await saveInline.click()
	} else {
		await page.getByRole('button', { name: 'Saved views' }).first().click()
		await page.locator('.v-popper__popper--shown').last().getByRole('menuitem', { name: 'Save current filters as view' }).click()
	}
	const dialog = page.getByRole('dialog').filter({ hasText: 'Save as view' })
	await expect(dialog).toBeVisible({ timeout: 15000 })
	await dialog.getByLabel('View name').fill(viewName)
	await dialog.getByRole('button', { name: 'Save view' }).click()
	await expect(dialog).toBeHidden({ timeout: 30000 })

	// Fresh: no selection in the URL.
	await gotoAppRoute(page, '/diensten')
	await expect(page).not.toHaveURL(/_gf_supplier/)
	await page.getByRole('button', { name: 'Saved views' }).first().click()
	await page.locator('.v-popper__popper--shown').last().getByRole('menuitem', { name: viewName }).click()

	await expect(page).toHaveURL(/_gf_supplier/, { timeout: 30000 })
	const group = page.locator('.cn-facet-sidebar__group').filter({ hasText: 'Supplier' }).first()
	await expect(group).toContainText(supplierA, { timeout: 30000 })
	const list = page.locator(APP_MAIN).first()
	await expect(list.getByText(serviceA).first()).toBeVisible({ timeout: 30000 })
	await expect(list.getByText(serviceB)).toHaveCount(0)
})
