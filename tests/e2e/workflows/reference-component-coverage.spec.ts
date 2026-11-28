// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * GEMMA plate (board SkGemmaPlaat): two reference components, two
 * applications in use for the first, and the plate that shows the overlap
 * and the gap.
 *
 * Seeds the elements in the GEMMA register and an organisation, two modules
 * and two usages in the voorzieningen register, all carrying this run's
 * RUN_ID, and removes exactly those rows afterwards. The states are covered
 * by tests/Unit/Service/ReferenceComponentCoverageDerivationTest.php.
 *
 * The map scenario answers the two view requests with a view holding both
 * reference components (the shared instance's imported views hold none of
 * this run's elements); the coverage on its nodes is the real coverage of the
 * seeded rows. The portfolio scenario reads the real report.
 *
 * Run live 25 Nov 2026 on NC 34 + PostgreSQL + OpenRegister main.
 *
 * @spec openspec/specs/reference-component-coverage/spec.md#requirement-req-rcc-002-a-gemma-plate-shall-show-the-components-with-their-applications-with-filters-for-gaps-and-overlaps
 * @spec openspec/specs/reference-component-coverage/spec.md#requirement-req-rcc-003-the-coverage-shall-be-drawn-on-a-gemma-view-as-a-map
 * @spec openspec/specs/reference-component-coverage/spec.md#requirement-req-rcc-004-the-portfolio-rationalization-report-shall-show-overlapping-applications
 */
import type { APIRequestContext, Page, Route } from '@playwright/test'
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
// The register and element schema the GEMMA model is imported into, as the
// app's own AMEF mapping names them (the coverage reads exactly these). A
// fixed slug such as 'vng-gemma' can name a stale register the app no longer
// reads, and the plate then never shows this run's components.
let gemma: { register: string, element: string }
const seeded: Array<[string, string, string]> = []
const ids: Record<string, string> = {}
const organisation = `${RUN_ID} municipality`
const doubled = `${RUN_ID} case registration`
const missing = `${RUN_ID} knowledge base`
const VIEW_ID = `${RUN_ID}-coverage-view`
const viewName = `${RUN_ID} coverage view`

/**
 * Open a page of the app and pick this run's organisation in its picker.
 *
 * @param page The page.
 * @param route The app route.
 * @return Resolves once the organisation is picked.
 */
async function openFor(page: Page, route: string): Promise<void> {
	await gotoAppRoute(page, route)
	await dismissSupportDialog(page)
	await page.getByRole('combobox', { name: 'Organisation' }).fill(organisation)
	await page.locator('.vs__dropdown-option', { hasText: organisation }).first().click()
}

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

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	const amef = await apiCtx.get('/index.php/apps/stackiq/api/amef/config')
	expect(amef.ok(), `amef/config returned ${amef.status()}`).toBe(true)
	const amefConfig = (await amef.json())?.config ?? {}
	gemma = { register: String(amefConfig.register ?? ''), element: String(amefConfig.element_schema || 'element') }
	expect(gemma.register, 'stackiq has no AMEF register mapping').not.toBe('')
	ids.doubled = await seed(gemma.register, gemma.element, {
		identifier: `${RUN_ID}-doubled`,
		type: 'ApplicationComponent',
		properties: [],
		name: doubled,
		gemmaType: 'referentiecomponent',
		grouping: RUN_ID,
	})
	ids.missing = await seed(gemma.register, gemma.element, {
		identifier: `${RUN_ID}-missing`,
		type: 'ApplicationComponent',
		properties: [],
		name: missing,
		gemmaType: 'referentiecomponent',
		grouping: RUN_ID,
	})
	ids.org = await seed(cfg.register, 'organization', { name: organisation, type: 'Municipality' })
	ids.a = await seed(cfg.register, 'module', { name: `${RUN_ID} application A` })
	ids.b = await seed(cfg.register, 'module', { name: `${RUN_ID} application B` })
	await seed(cfg.register, 'usage', {
		consumer: ids.org,
		module: ids.a,
		status: 'In production',
		usedForReferenceComponents: [ids.doubled],
	})
	await seed(cfg.register, 'usage', {
		consumer: ids.org,
		module: ids.b,
		status: 'In production',
		usedForReferenceComponents: [ids.doubled],
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [register, schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e reference-component-coverage::an-information-manager-filters-on-overlap
test('the GEMMA plate shows the overlap with both applications and the gap', async ({
	page,
}) => {
	await openFor(page, '/gemma-plaat')

	const plate = page.getByTestId('gemma-plate-groups')
	const tile = plate.getByRole('button', { name: new RegExp(doubled) })
	await expect(tile).toHaveAttribute('data-state', 'overlap', { timeout: 30000 })
	await expect(tile).toContainText(`${RUN_ID} application A`)
	await expect(tile).toContainText(`${RUN_ID} application B`)
	await expect(
		plate.getByRole('button', { name: new RegExp(missing) }),
	).toHaveAttribute('data-state', 'gap')

	await page.getByTestId('gemma-plate-filter-overlap').click()
	await expect(
		plate.getByRole('button', { name: new RegExp(missing) }),
	).toHaveCount(0)
	await expect(
		plate.getByRole('button', { name: new RegExp(doubled) }),
	).toBeVisible()
})

// @e2e reference-component-coverage::an-information-manager-sees-their-applications-on-a-gemma-view
test('a GEMMA view draws the overlap with both application names and the gap', async ({
	page,
}) => {
	const node = (viewNodeId: string, identifier: string, name: string, x: number) => ({
		viewNodeId,
		elementRef: `id-${identifier}`,
		modelNodeId: `id-${identifier}`,
		name,
		x,
		y: 0,
		width: 200,
		height: 100,
	})
	await page.route('**/apps/stackiq/api/views', (route: Route) => route.fulfill({
		json: { success: true, views: [{ id: VIEW_ID, name: viewName }] },
	}))
	await page.route(`**/apps/stackiq/api/views/${VIEW_ID}*`, (route: Route) => route.fulfill({
		json: {
			success: true,
			view: {
				id: VIEW_ID,
				name: viewName,
				viewNodes: [
					node('n-doubled', `${RUN_ID}-doubled`, doubled, 0),
					node('n-missing', `${RUN_ID}-missing`, missing, 260),
				],
				viewRelationships: [],
			},
		},
	}))

	await openFor(page, '/gemma-plaat')
	const map = page.getByTestId('coverage-view-map')
	await expect(map).toBeVisible({ timeout: 30000 })
	await map.getByRole('combobox', { name: 'GEMMA view' }).click()
	await page.locator('.vs__dropdown-option', { hasText: viewName }).first().click()

	const overlap = map.locator('.coverage-view-map__node').filter({ hasText: doubled })
	await expect(overlap).toHaveAttribute('data-state', 'overlap', { timeout: 30000 })
	await expect(overlap).toContainText('Overlap')
	await expect(overlap).toContainText(`${RUN_ID} application A`)
	await expect(overlap).toContainText(`${RUN_ID} application B`)

	const gap = map.locator('.coverage-view-map__node').filter({ hasText: missing })
	await expect(gap).toHaveAttribute('data-state', 'gap')
	await expect(gap).toContainText('Gap')
})

// @e2e reference-component-coverage::the-portfolio-report-shows-overlap-next-to-ageing
test('the portfolio report lists the overlapping component and marks both rows', async ({
	page,
}) => {
	await openFor(page, '/portfolio-report')

	const section = page.getByTestId('pr-overlap')
	await expect(section).toBeVisible({ timeout: 30000 })
	const group = section.getByTestId('pr-overlap-row').filter({ hasText: doubled })
	await expect(group).toContainText(`${RUN_ID} application A`)
	await expect(group).toContainText(`${RUN_ID} application B`)
	await expect(section.getByTestId('pr-overlap-row').filter({ hasText: missing })).toHaveCount(0)

	for (const application of [`${RUN_ID} application A`, `${RUN_ID} application B`]) {
		const row = page.getByTestId('pr-row').filter({ hasText: application })
		await expect(row.getByTestId('pr-row-overlap')).toBeVisible()
	}
})
