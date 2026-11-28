// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Portfolio report: a usage's row shows its yearly cost from its contracts
 * next to its business value and risk score.
 *
 * Seeds an organisation, an application, a usage scored business value 4,
 * technical fit 3 and risk 2, and a contract of 10,000 euro a year on that
 * usage, all carrying this run's RUN_ID, through the objects API, and removes
 * exactly those rows afterwards. The row shows the scores as
 * "value / fit / risk" and the cost in nl-NL euro formatting. The CSV columns
 * are covered by tests/Unit/Service/PortfolioReportServiceTest.php.
 *
 * The municipality is also an OpenRegister organisation (same uuid) and the
 * contract is moved into it, because the report costs only the contracts the
 * reporting organisation owns.
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/application-value-assessment/spec.md#requirement-the-portfolio-report-shows-each-usage-s-yearly-cost-beside-its-value-and-risk-scores-req-clr-016
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
const organisation = `${RUN_ID} municipality`
const application = `${RUN_ID} case application`

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
	// The report costs only the contracts the reporting organisation owns
	// (decision 409), so the municipality is an OpenRegister organisation too,
	// with the same uuid, and the contract is moved into it below.
	const created = await apiCtx.post('/index.php/apps/openregister/api/organisations', {
		data: { name: organisation },
	})
	if (!created.ok()) {
		throw new Error(`organisation ${created.status()}: ${await created.text()}`)
	}
	ids.tenant = String((await created.json()).organisation.uuid)
	ids.org = await seed('organization', { id: ids.tenant, name: organisation, type: 'Municipality' })
	ids.module = await seed('module', { name: application })
	ids.usage = await seed('usage', {
		consumer: ids.org,
		module: ids.module,
		status: 'In production',
		timeClassification: 'Invest',
		businessValue: 4,
		technicalFit: 3,
		riskScore: 2,
		scoredOn: '2026-10-01',
	})
	ids.contract = await seed('catalogContract', {
		usage: ids.usage,
		contractNumber: `${RUN_ID}-1`,
		contractType: 'Licence',
		startDate: '2026-01-01',
		cost: 10000,
		costPeriod: 'Annually',
		// No status: a contract becomes Active only through an approved
		// decision, and the report costs a contract whatever its status.
	})
	const stored = await apiCtx.get(
		`/index.php/apps/openregister/api/objects/${cfg.register}/catalogContract/${ids.contract}`,
	)
	const source = String((await stored.json())?.['@self']?.organisation ?? '')
	const moved = await apiCtx.post('/index.php/apps/stackiq/api/ownership-transfers/execute', {
		data: {
			objects: [{ schema: 'catalogContract', id: ids.contract }],
			sourceOrganisation: source,
			targetOrganisation: ids.tenant,
		},
	})
	if (!moved.ok()) {
		throw new Error(`moving the contract ${moved.status()}: ${await moved.text()}`)
	}
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	if (ids.tenant) {
		await apiCtx.delete(`/index.php/apps/openregister/api/organisations/${ids.tenant}`)
	}
	await apiCtx.dispose()
})

// @e2e application-value-assessment::an-information-manager-compares-cost-with-value
test('a usage row shows 10,000 euro a year beside business value 4 and risk 2', async ({
	page,
}) => {
	await gotoAppRoute(page, '/portfolio-report')
	await dismissSupportDialog(page)
	await page.getByLabel('Organisation').first().click()
	await page.locator('.vs__dropdown-option', { hasText: organisation }).first().click()

	const row = page.getByTestId('pr-row').filter({ hasText: application })
	await expect(row).toBeVisible({ timeout: 30000 })
	// Business value / technical fit / risk score.
	await expect(row).toContainText('4 / 3 / 2')
	await expect(row).toContainText('10.000')
})
