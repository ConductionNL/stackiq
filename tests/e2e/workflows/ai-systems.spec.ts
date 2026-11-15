// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * AI systems: an AI feature listed on its application's page, the high-risk
 * quick filter, and the missing FRIA warning in the list and on the page.
 *
 * Seeds one application and three AI systems carrying this run's RUN_ID
 * through the objects API (the call the Add form makes), and removes exactly
 * those rows afterwards. The FRIA rule and the checklist logic are covered by
 * tests/vitest/aiSystems.spec.js.
 *
 * @spec openspec/specs/ai-system-inventory/spec.md
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
const chat = `${RUN_ID} chat assistant`
const scoring = `${RUN_ID} scoring model`
const checked = `${RUN_ID} checked model`

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
	ids.x = await seed('module', { name: `${RUN_ID} application X` })
	ids.chat = await seed('aiSystem', {
		name: chat,
		kind: 'AI feature',
		module: ids.x,
		aiActRiskCategory: 'minimal risk',
		status: 'in use',
	})
	ids.scoring = await seed('aiSystem', {
		name: scoring,
		kind: 'AI model',
		aiActRiskCategory: 'high risk',
		status: 'in use',
	})
	ids.checked = await seed('aiSystem', {
		name: checked,
		kind: 'AI model',
		aiActRiskCategory: 'high risk',
		friaDocumentRef: '/AI/fria.pdf',
		status: 'in use',
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e ai-system-inventory::an-information-manager-registers-a-chat-assistant
test('the application page lists its AI feature in the AI systems section', async ({
	page,
}) => {
	await gotoAppRoute(page, `/modules/${ids.x}`)
	await dismissSupportDialog(page)
	await expect(page.getByText('AI systems').first()).toBeVisible({
		timeout: 30000,
	})
	await expect(page.getByText(chat).first()).toBeVisible({ timeout: 30000 })
	await page.getByText(chat).first().click()
	await expect(page).toHaveURL(new RegExp(`/ai-systems/${ids.chat}`))
})

// @e2e ai-system-inventory::a-privacy-officer-lists-the-high-risk-systems
test('filtering on high risk leaves only the high-risk systems', async ({
	page,
}) => {
	await gotoAppRoute(page, '/ai-systems')
	await dismissSupportDialog(page)
	await expect(page.getByText(chat).first()).toBeVisible({ timeout: 30000 })
	await page.getByRole('tab', { name: 'High risk', exact: true }).click()
	await expect(page.getByText(scoring).first()).toBeVisible({ timeout: 30000 })
	await expect(page.getByText(chat)).toHaveCount(0)
})

// @e2e ai-system-inventory::the-missing-assessment-shows
test('a high-risk system without a FRIA is listed with a warning and its page marks FRIA missing', async ({
	page,
}) => {
	await gotoAppRoute(page, '/ai-systems')
	await dismissSupportDialog(page)
	await page
		.getByRole('tab', { name: 'High risk without FRIA', exact: true })
		.click()
	const row = page.getByRole('row').filter({ hasText: scoring })
	await expect(row).toContainText('FRIA missing', { timeout: 30000 })
	await expect(page.getByText(checked)).toHaveCount(0)

	await gotoAppRoute(page, `/ai-systems/${ids.scoring}`)
	await expect(page.getByTestId('ai-act-fria-warning')).toBeVisible({
		timeout: 30000,
	})
	await expect(page.getByTestId('ai-act-evidence-FRIA')).toHaveAttribute(
		'data-present',
		'false',
	)
})
