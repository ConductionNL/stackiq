// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * SaaS subscriptions on the License posture page: a department's own
 * subscription with its yearly cost under Bought outside IT, and an
 * on-premises application left out of the list.
 *
 * Seeds an organisation, a supplier, two applications, two usages and a
 * contract carrying this run's RUN_ID through the objects API, and removes
 * exactly those rows afterwards. The derivation is covered by
 * tests/vitest/saasSubscriptions.spec.js and the section by
 * tests/vitest/licensePostureSaas.spec.js.
 *
 * Written from the change's scenarios; not yet run against a live instance.
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/saas-subscriptions/spec.md#requirement-the-licences-page-lists-saas-subscriptions-with-their-yearly-cost-including-ones-bought-outside-it-req-clr-001
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
const subscription = `${RUN_ID} polling tool`
const onPremises = `${RUN_ID} file server`
const department = `${RUN_ID} Communications`

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
	ids.org = await seed('organization', { name: `${RUN_ID} municipality`, type: 'Municipality' })
	ids.supplier = await seed('organization', { name: `${RUN_ID} supplier`, type: 'Supplier' })
	ids.saasModule = await seed('module', { name: subscription, provider: ids.supplier })
	ids.onPremModule = await seed('module', { name: onPremises })
	ids.saasUsage = await seed('usage', {
		consumer: ids.org,
		module: ids.saasModule,
		status: 'In production',
		cloudDienstverleningsmodel: ['SaaS'],
		buyingDepartment: department,
		boughtOutsideIt: true,
	})
	ids.onPremUsage = await seed('usage', {
		consumer: ids.org,
		module: ids.onPremModule,
		status: 'In production',
		cloudDienstverleningsmodel: ['On-premises (self-managed)'],
	})
	await seed('catalogContract', {
		usage: ids.saasUsage,
		contractNumber: `${RUN_ID}-1`,
		contractType: 'Licence',
		startDate: '2026-01-01',
		cost: 1200,
		costPeriod: 'Annually',
		status: 'Active',
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e saas-subscriptions::a-departments-own-subscription-shows-on-the-list
test('a subscription bought outside IT shows with its yearly cost and department', async ({ page }) => {
	await gotoAppRoute(page, '/license-posture')
	await dismissSupportDialog(page)
	await page.getByTestId('posture-saas-outside-it').click()

	const row = page.getByTestId('posture-saas-row').filter({ hasText: subscription })
	await expect(row).toBeVisible({ timeout: 30000 })
	await expect(row).toContainText(department)
	await expect(row).toContainText('1.200')
	await expect(row).toContainText('Yes')
})

// @e2e saas-subscriptions::an-on-premises-application-is-not-a-subscription
test('an on-premises application is not listed as a subscription', async ({ page }) => {
	await gotoAppRoute(page, '/license-posture')
	await dismissSupportDialog(page)

	const section = page.getByTestId('posture-saas')
	await expect(section.getByTestId('posture-saas-row').filter({ hasText: subscription })).toBeVisible({ timeout: 30000 })
	await expect(section.getByTestId('posture-saas-row').filter({ hasText: onPremises })).toHaveCount(0)
})
