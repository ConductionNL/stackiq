// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Service desk exchange, the part a browser sees: the CMDB page says what
 * stackiq records and what it does not, and Applications in use shows the
 * service desk record of a usage.
 *
 * Seeds one supplier, one application and one usage carrying this run's
 * RUN_ID and a service desk reference, through the objects API, and removes
 * exactly those rows afterwards. The flows themselves run server-side; their
 * set-up is covered by tests/Unit/Service/ItsmExchangeServiceTest.php and the
 * live run recorded in the pull request.
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md
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
const recordId = `${RUN_ID}-A-123`

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
	const supplier = await seed('organization', {
		name: `${RUN_ID} supplier`,
		type: 'Supplier',
	})
	const consumer = await seed('organization', {
		name: `${RUN_ID} municipality`,
		type: 'Municipality',
	})
	const module = await seed('module', {
		name: `${RUN_ID} application`,
		provider: supplier,
	})
	await seed('usage', {
		module,
		consumer,
		status: 'In production',
		serviceDeskSystem: 'topdesk',
		serviceDeskRecordId: recordId,
		serviceDeskUrl: `https://desk.example.nl/tas/secure/assetmgmt/card.html?unid=${recordId}`,
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e itsm-exchange::an-information-manager-opens-the-cmdb-page
test('the CMDB page (CmdbOverview) names what stackiq records and what it does not', async ({
	page,
}) => {
	await gotoAppRoute(page, '/cmdb')
	await dismissSupportDialog(page)
	const records = page.getByTestId('cmdb-records')
	await expect(records).toBeVisible({ timeout: 30000 })
	for (const label of [
		'Applications in use',
		'Applications and their components',
		'Connections',
		'Licences and contracts',
	]) {
		await expect(records.getByRole('link', { name: label })).toBeVisible()
	}
	await expect(page.getByTestId('cmdb-not-recorded')).toContainText(
		'does not discover hardware',
	)
	await expect(page.getByTestId('cmdb-exchange')).toBeVisible()
	await records.getByRole('link', { name: 'Licences and contracts' }).click()
	await expect(page).toHaveURL(/\/contracten/)
})

// @e2e itsm-exchange::a-service-desk-employee-finds-the-catalogue-entry-and-back
test('Applications in use shows the service desk record of a usage', async ({
	page,
}) => {
	await gotoAppRoute(page, '/gebruik')
	await dismissSupportDialog(page)
	await expect(page.getByText(recordId).first()).toBeVisible({ timeout: 30000 })
})
