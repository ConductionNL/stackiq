// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * A contract's supplier: the contract page shows the supplier of the service
 * the contract is for, linking to that supplier's organisation page.
 *
 * Seeds a supplier organisation, a service it provides and a contract for
 * that service, all carrying this run's RUN_ID, through the objects API, and
 * removes exactly those rows afterwards.
 *
 * catalogContract points at its service only; the contract page's Supplier
 * list (ct-supplier in src/manifest.json) holds that service and reads its
 * provider, and its row opens the supplier's organisation page.
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/vendor-product-master-data/spec.md#requirement-a-contract-shows-its-supplier-through-the-service-it-is-for-req-clr-014
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

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
const seeded: Array<[string, string]> = []
const ids: Record<string, string> = {}
const supplier = `${RUN_ID} supplier`
const service = `${RUN_ID} hosting service`

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

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	ids.supplier = await seed('organization', { name: supplier, type: 'Supplier' })
	ids.service = await seed('catalogService', {
		name: service,
		provider: ids.supplier,
		type: ['Technical management'],
	})
	// A contract is for an application in use (catalogContract requires usage).
	ids.municipality = await seed('organization', { name: `${RUN_ID} Gemeente`, type: 'Municipality' })
	ids.module = await seed('module', { name: `${RUN_ID} Zaaksysteem X`, provider: ids.supplier })
	ids.usage = await seed('usage', { consumer: ids.municipality, module: ids.module, status: 'Acquisition' })
	ids.contract = await seed('catalogContract', {
		contractNumber: `${RUN_ID}-1`,
		contractType: 'SLA',
		// A new contract is in negotiation; Active needs an approved decision (contract-approval guard).
		status: 'In negotiation',
		startDate: '2026-01-01',
		cost: 5000,
		costPeriod: 'Annually',
		service: ids.service,
		usage: ids.usage,
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e vendor-product-master-data::a-contract-manager-reads-a-contract-s-supplier
test('the contract page shows the supplier of its service, linking to the organisation page', async ({
	page,
}) => {
	await gotoAppRoute(page, `/contracten/${ids.contract}`)
	await dismissSupportDialog(page)
	await expect(page.getByText(service).first()).toBeVisible({ timeout: 30000 })

	// The Supplier list holds the contract's service; its row names the supplier and opens its page.
	const list = page.getByRole('region', { name: 'Supplier' })
	await list.scrollIntoViewIfNeeded()
	const row = list.getByRole('row').filter({ hasText: supplier })
	await expect(row).toBeVisible({ timeout: 30000 })
	await expect(row).toContainText(service)
	await row.click()
	await expect(page).toHaveURL(new RegExp(`/organisaties/${ids.supplier}`), { timeout: 30000 })
	await expect(page.getByText(supplier).first()).toBeVisible({ timeout: 30000 })
})
