// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Data quality (board SkDatakwaliteit): an organisation with one complete and
 * one incomplete application in use, and the page that names what to fix.
 *
 * Seeds an organisation, two applications, a contact person and two usages
 * carrying this run's RUN_ID through the objects API, and removes exactly
 * those rows afterwards. The counting is covered by
 * tests/Unit/Service/DataQualityDerivationTest.php.
 *
 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
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
const incomplete = `${RUN_ID} incomplete application`

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
	ids.owner = await seed('contactPerson', { firstName: 'Marloes', lastName: `${RUN_ID} Bakker`, organization: ids.org })
	ids.complete = await seed('module', { name: `${RUN_ID} complete application` })
	ids.incomplete = await seed('module', { name: incomplete })
	await seed('usage', {
		consumer: ids.org,
		module: ids.complete,
		status: 'In production',
		businessOwner: ids.owner,
		technicalOwner: ids.owner,
		lastConfirmedAt: new Date().toISOString(),
	})
	await seed('usage', { consumer: ids.org, module: ids.incomplete, status: 'In production' })
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e catalogue-data-quality::an-information-manager-finds-what-to-fix-first
test('the Data quality page names the least complete application and what it misses', async ({
	page,
}) => {
	await gotoAppRoute(page, '/datakwaliteit')
	await dismissSupportDialog(page)
	await page.getByRole('combobox', { name: 'Organisation' }).fill(organisation)
	await page.getByRole('option', { name: organisation }).click()

	await expect(page.getByTestId('data-quality-kpis')).toContainText('Register score', { timeout: 30000 })
	await expect(page.getByTestId('data-quality-rules')).toContainText('of 2 meet it')
	const firstRow = page.getByTestId('data-quality-lowest').getByRole('row').nth(1)
	await expect(firstRow).toContainText(incomplete)
	await expect(firstRow).toContainText('Business owner')
})
