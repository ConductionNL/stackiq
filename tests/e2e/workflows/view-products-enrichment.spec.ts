// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * The Product filter on a GEMMA view (view-products-enrichment): the coverage
 * map's "Show products" switch fetches the view with include_products=true,
 * lists each node's linked products, and drops the parameter again when it is
 * switched off.
 *
 * The plate needs an organisation with a usage, so this seeds an
 * organisation, a reference component, a module and a usage carrying this
 * run's RUN_ID, and removes exactly those rows afterwards. A GEMMA view only
 * arrives through an ArchiMate import, so the two view reads are answered by
 * the test; what is asserted is the request the UI sends and what it draws.
 * The server half (linked products on their nodes, other organisations'
 * products left out) is covered by
 * tests/Unit/Controller/ViewControllerProductsEnrichmentTest.php.
 *
 * @spec openspec/changes/view-products-enrichment/specs/view-enrichment-api/spec.md#requirement-product-filter-enrichment-must-return-real-product-data
 */
import type { APIRequestContext, Route } from '@playwright/test'
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

const GEMMA_REGISTER = 'vng-gemma'
const VIEW_ID = `${RUN_ID}-view`

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
const seeded: Array<[string, string, string]> = []
const organisation = `${RUN_ID} products municipality`
const component = `${RUN_ID} products component`

/**
 * Create a row and remember it for cleanup.
 *
 * @param register The register slug.
 * @param schema The schema slug.
 * @param data The object.
 * @return The new id.
 */
async function seed(
	register: string,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const id = await createObject(apiCtx, register, schema, data)
	seeded.push([register, schema, id])
	return id
}

/**
 * One view node; with products when the view was asked for them.
 *
 * @param withProducts Whether the request carried include_products=true.
 * @return The view.
 */
function view(withProducts: boolean): Record<string, unknown> {
	const linked = { viewNodeId: 'n-linked', elementRef: 'id-linked', modelNodeId: 'id-linked', name: 'Zaken', x: 0, y: 0, width: 160, height: 80 }
	const bare = { viewNodeId: 'n-bare', elementRef: 'id-bare', modelNodeId: 'id-bare', name: 'Documenten', x: 200, y: 0, width: 160, height: 80 }
	if (withProducts) {
		Object.assign(linked, { products: [{ name: `${RUN_ID} zaakdienst` }] })
		Object.assign(bare, { products: [] })
	}
	return { id: VIEW_ID, name: `${RUN_ID} view`, viewNodes: [linked, bare], viewRelationships: [] }
}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	const element = await seed(GEMMA_REGISTER, 'element', {
		name: component,
		gemmaType: 'referentiecomponent',
		grouping: RUN_ID,
	})
	const org = await seed(cfg.register, 'organization', { name: organisation })
	const module = await seed(cfg.register, 'module', { name: `${RUN_ID} products application` })
	await seed(cfg.register, 'usage', {
		consumer: org,
		module,
		status: 'In production',
		usedForReferenceComponents: [element],
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [register, schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e view-enrichment-api::product-filter-is-enabled-and-linked-products-exist
// @e2e view-enrichment-api::product-filter-is-enabled-and-no-products-are-linked
test('Show products fetches the view with include_products and lists the products per node', async ({
	page,
}) => {
	const viewRequests: URL[] = []
	await page.route('**/apps/stackiq/api/views', (route: Route) => route.fulfill({
		json: { success: true, views: [{ id: VIEW_ID, name: `${RUN_ID} view` }] },
	}))
	await page.route(`**/apps/stackiq/api/views/${VIEW_ID}*`, (route: Route) => {
		const url = new URL(route.request().url())
		viewRequests.push(url)
		return route.fulfill({
			json: { success: true, view: view(url.searchParams.get('include_products') === 'true') },
		})
	})

	await gotoAppRoute(page, '/gemma-plaat')
	await dismissSupportDialog(page)
	await page.getByRole('combobox', { name: 'Organisation' }).fill(organisation)
	await page.getByRole('option', { name: organisation }).click()

	const map = page.getByTestId('coverage-view-map')
	await expect(map).toBeVisible({ timeout: 30000 })
	await map.getByRole('combobox', { name: 'GEMMA view' }).click()
	await page.getByRole('option', { name: `${RUN_ID} view` }).click()
	await expect(map.getByText('Zaken')).toBeVisible()
	expect(viewRequests.at(-1)?.searchParams.has('include_products')).toBe(false)
	await expect(map.getByTestId('coverage-view-map-node-products')).toHaveCount(0)

	await map.getByTestId('coverage-view-map-products').getByText('Show products').click()
	const products = map.getByTestId('coverage-view-map-node-products')
	await expect(products).toHaveCount(2)
	expect(viewRequests.at(-1)?.searchParams.get('include_products')).toBe('true')
	await expect(products.first()).toHaveText(`Products (1): ${RUN_ID} zaakdienst`)
	await expect(products.nth(1)).toHaveText('No product')

	await map.getByTestId('coverage-view-map-products').getByText('Show products').click()
	await expect(products).toHaveCount(0)
	expect(viewRequests.at(-1)?.searchParams.has('include_products')).toBe(false)
})
