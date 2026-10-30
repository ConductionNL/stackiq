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
 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-002-a-coverage-page-shall-list-the-components-with-filters-for-gaps-and-overlaps
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

const GEMMA_REGISTER = 'vng-gemma'

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
const seeded: Array<[string, string, string]> = []
const ids: Record<string, string> = {}
const organisation = `${RUN_ID} municipality`
const doubled = `${RUN_ID} case registration`
const missing = `${RUN_ID} knowledge base`

/**
 * Create a row and remember it for cleanup.
 *
 * @param register The register slug.
 * @param schema The schema slug.
 * @param data The object.
 * @return The new id.
 */
async function seed(register: string, schema: string, data: Record<string, unknown>): Promise<string> {
	const id = await createObject(apiCtx, register, schema, data)
	seeded.push([register, schema, id])
	return id
}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	ids.doubled = await seed(GEMMA_REGISTER, 'element', { name: doubled, gemmaType: 'referentiecomponent', grouping: RUN_ID })
	ids.missing = await seed(GEMMA_REGISTER, 'element', { name: missing, gemmaType: 'referentiecomponent', grouping: RUN_ID })
	ids.org = await seed(cfg.register, 'organization', { name: organisation })
	ids.a = await seed(cfg.register, 'module', { name: `${RUN_ID} application A` })
	ids.b = await seed(cfg.register, 'module', { name: `${RUN_ID} application B` })
	await seed(cfg.register, 'usage', { consumer: ids.org, module: ids.a, status: 'In production', usedForReferenceComponents: [ids.doubled] })
	await seed(cfg.register, 'usage', { consumer: ids.org, module: ids.b, status: 'In production', usedForReferenceComponents: [ids.doubled] })
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
	await gotoAppRoute(page, '/gemma-plaat')
	await dismissSupportDialog(page)
	await page.getByRole('combobox', { name: 'Organisation' }).fill(organisation)
	await page.getByRole('option', { name: organisation }).click()

	const plate = page.getByTestId('gemma-plate-groups')
	const tile = plate.getByRole('button', { name: new RegExp(doubled) })
	await expect(tile).toHaveAttribute('data-state', 'overlap', { timeout: 30000 })
	await expect(tile).toContainText(`${RUN_ID} application A`)
	await expect(tile).toContainText(`${RUN_ID} application B`)
	await expect(plate.getByRole('button', { name: new RegExp(missing) })).toHaveAttribute('data-state', 'gap')

	await page.getByTestId('gemma-plate-filter-overlap').click()
	await expect(plate.getByRole('button', { name: new RegExp(missing) })).toHaveCount(0)
	await expect(plate.getByRole('button', { name: new RegExp(doubled) })).toBeVisible()
})
