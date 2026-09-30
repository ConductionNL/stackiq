// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Value assessment: scoring a usage on its page and finding the usages whose
 * recorded TIME class differs from the class the scores suggest in the
 * portfolio report.
 *
 * Seeds an organisation, three applications and three usages carrying this
 * run's RUN_ID through the objects API (the call the edit form makes), and
 * removes exactly those rows afterwards. The calculation, the report row and
 * the CSV columns are covered by tests/Unit/Settings/ValueAssessmentFragmentTest.php
 * and tests/Unit/Service/PortfolioReportServiceTest.php; the plot, the filter
 * and the risk signals by tests/vitest/valueAssessment.spec.js.
 *
 * @spec openspec/specs/application-value-assessment/spec.md
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
const appX = `${RUN_ID} application X`
const appY = `${RUN_ID} application Y`
const appZ = `${RUN_ID} application Z`

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
	ids.org = await seed('organization', { name: organisation })
	ids.x = await seed('module', { name: appX })
	ids.y = await seed('module', { name: appY })
	ids.z = await seed('module', { name: appZ })
	ids.usageX = await seed('usage', {
		consumer: ids.org,
		module: ids.x,
		status: 'In production',
		timeClassification: 'Tolerate',
	})
	ids.usageY = await seed('usage', {
		consumer: ids.org,
		module: ids.y,
		status: 'In production',
		timeClassification: 'Tolerate',
		businessValue: 2,
		technicalFit: 1,
		scoredOn: '2026-09-01',
	})
	ids.usageZ = await seed('usage', {
		consumer: ids.org,
		module: ids.z,
		status: 'In production',
		timeClassification: 'Invest',
		businessValue: 5,
		technicalFit: 4,
		scoredOn: '2026-09-01',
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e application-value-assessment::an-information-manager-scores-an-application
test('scoring value 5 and fit 2 suggests Migrate and keeps Tolerate recorded', async ({
	page,
}) => {
	const res = await apiCtx.put(
		`/index.php/apps/openregister/api/objects/${cfg.register}/usage/${ids.usageX}`,
		{
			data: {
				consumer: ids.org,
				module: ids.x,
				status: 'In production',
				timeClassification: 'Tolerate',
				businessValue: 5,
				technicalFit: 2,
				scoredOn: '2026-09-30',
			},
		},
	)
	expect(res.ok()).toBe(true)
	await gotoAppRoute(page, `/gebruik/${ids.usageX}`)
	await dismissSupportDialog(page)
	await expect(page.getByText('Value assessment').first()).toBeVisible({
		timeout: 30000,
	})
	await expect(page.getByText('Migrate').first()).toBeVisible()
	await expect(page.getByText('Tolerate').first()).toBeVisible()
})

// @e2e application-value-assessment::finding-the-classes-to-revisit
test('the mismatch filter lists a usage whose scores contradict its class and leaves out one that agrees', async ({
	page,
}) => {
	await gotoAppRoute(page, '/portfolio-report')
	await dismissSupportDialog(page)
	await page.getByLabel('Organisation').first().click()
	await page.getByRole('option', { name: organisation }).first().click()
	await expect(page.getByTestId('pr-value-fit')).toBeVisible({ timeout: 30000 })
	await page.getByTestId('pr-mismatch-filter').click()
	const rows = page.getByTestId('pr-row')
	await expect(rows.filter({ hasText: appY })).toBeVisible({ timeout: 30000 })
	await expect(rows.filter({ hasText: appY })).toContainText('Eliminate')
	await expect(rows.filter({ hasText: appZ })).toHaveCount(0)
})
