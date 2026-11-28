// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Architecture view export: a drawn view downloaded as an SVG image from the
 * view page's Export menu, and the Word and PowerPoint actions disabled with
 * the reason on an instance without filinq while Download SVG still works.
 *
 * Seeds one drawn view with three nodes in the AMEF register the app maps
 * (`/api/amef/config`), carrying this run's RUN_ID, through the objects API,
 * and removes exactly that view afterwards. The SVG rendering rules, the
 * filinq gateway and the document route are covered by
 * tests/Unit/Service/ViewImageServiceTest.php,
 * tests/Unit/Controller/ViewControllerImageTest.php,
 * tests/Unit/Service/ViewDocumentGatewayTest.php,
 * tests/Unit/Controller/ViewControllerDocumentTest.php and
 * tests/vitest/viewExport.spec.js.
 *
 * The Export menu is src/components/architecture/ViewExportMenu.vue in the
 * header of ArchitectureViewEditor.vue (tests/vitest/viewExportMenu.spec.js).
 *
 * Written from the change's scenarios; not yet run against a live instance.
 *
 * @spec openspec/specs/architecture-view-office-export/spec.md#requirement-req-avo-001-stackiq-shall-draw-a-view-as-a-standalone-svg-from-its-stored-geometry
 * @spec openspec/specs/architecture-view-office-export/spec.md#requirement-req-avo-003-the-document-actions-shall-say-why-they-are-unavailable-when-filinq-cannot-take-the-request
 */
import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { readFile } from 'node:fs/promises'
import {
	createObject,
	deleteObject,
	newApiContext,
	RUN_ID,
} from './_fixtures.ts'
import { dismissSupportDialog, gotoAppRoute } from './_ui.ts'

let apiCtx: APIRequestContext
let register = ''
const seeded: Array<[string, string]> = []
const ids: Record<string, string> = {}
const viewName = `${RUN_ID} case handling today`
const nodeNames = [`${RUN_ID} case system`, `${RUN_ID} document store`, `${RUN_ID} case register`]

/**
 * Create a row in the AMEF register and remember it for cleanup.
 *
 * @param schema The schema slug.
 * @param data The object.
 * @return The new id.
 */
async function seed(schema: string, data: Record<string, unknown>): Promise<string> {
	const id = await createObject(apiCtx, register, schema, data)
	seeded.push([schema, id])
	return id
}

/**
 * Open a view and its Export menu.
 *
 * @param page The page.
 * @param id The view's uuid.
 * @return The editor.
 */
async function openExportMenu(page: Page, id: string): Promise<Locator> {
	await gotoAppRoute(page, `/views/${id}`)
	await dismissSupportDialog(page)
	const editor = page.getByTestId('architecture-view-editor')
	await expect(editor).toBeVisible({ timeout: 30000 })
	await editor.getByRole('button', { name: 'Export', exact: true }).click()
	return editor
}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	const res = await apiCtx.get('/index.php/apps/stackiq/api/amef/config')
	expect(res.ok(), `amef/config returned ${res.status()}`).toBe(true)
	const config = (await res.json())?.config ?? {}
	register = String(config.register ?? config.register_id ?? '')
	expect(register, 'stackiq maps an AMEF register').not.toBe('')

	const nodes = nodeNames.map((name, index) => ({
		viewNodeId: `id-${RUN_ID}-node-${index}`,
		parent: null,
		elementRef: `id-${RUN_ID}-element-${index}`,
		x: 40 + index * 200,
		y: 80,
		width: 120,
		height: 55,
		name,
		type: 'ApplicationComponent',
	}))
	ids.view = await seed('view', {
		identifier: `id-${RUN_ID}-export-view`,
		name: viewName,
		origin: 'drawn',
		status: 'draft',
		nodes,
		connections: [
			{
				viewRelationshipId: `id-${RUN_ID}-conn-0`,
				modelRelationshipId: `id-${RUN_ID}-rel-0`,
				sourceId: nodes[0].viewNodeId,
				targetId: nodes[1].viewNodeId,
				type: 'Flow',
				bendpoints: [],
			},
		],
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e architecture-view-office-export::an-application-owner-downloads-a-view-as-svg
// The Export menu is not mounted in ArchitectureViewEditor.vue; src/services/viewExport.js holds its actions only.
test('Export, then Download SVG, gives an SVG file named after the view holding its nodes', async ({ page }) => {
	await openExportMenu(page, ids.view)
	const downloading = page.waitForEvent('download')
	await page.getByRole('menuitem', { name: 'Download SVG' }).click()
	const download = await downloading
	expect(download.suggestedFilename()).toMatch(/\.svg$/)
	expect(download.suggestedFilename()).toContain(RUN_ID)

	const path = await download.path()
	const svg = await readFile(String(path), 'utf8')
	expect(svg).toContain('<svg')
	for (const name of nodeNames) {
		expect(svg).toContain(name)
	}
	expect(svg).not.toContain('<script')
	expect(svg).not.toContain('foreignObject')
})

// @e2e architecture-view-office-export::without-filinq-the-image-still-downloads
// The Export menu is not mounted in ArchitectureViewEditor.vue; src/services/viewExport.js holds its actions only.
test('without filinq the document actions are disabled with the reason and Download SVG is enabled', async ({ page }) => {
	await openExportMenu(page, ids.view)
	const word = page.getByRole('menuitem', { name: 'Create Word document' })
	const slide = page.getByRole('menuitem', { name: 'Create PowerPoint slide' })
	await expect(word).toBeDisabled()
	await expect(slide).toBeDisabled()
	await expect(page.getByText('Word and PowerPoint need the document app filinq').first()).toBeVisible()
	await expect(page.getByRole('menuitem', { name: 'Download SVG' })).toBeEnabled()
})
