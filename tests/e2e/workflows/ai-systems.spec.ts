// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * AI systems: an AI feature listed on its application's page, the high-risk
 * quick filter, the missing FRIA warning in the list and on the page, and an
 * AI agent linked to the business process it supports.
 *
 * Seeds one application and three AI systems carrying this run's RUN_ID
 * through the objects API (the call the Add form makes), and removes exactly
 * those rows afterwards. The FRIA rule and the checklist logic are covered by
 * tests/vitest/aiSystems.spec.js.
 *
 * @spec openspec/specs/ai-system-inventory/spec.md
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/ai-system-inventory/spec.md#requirement-an-ai-system-names-the-business-processes-it-supports-req-clr-006
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
import { dismissSupportDialog, gotoAppRoute, revealSection } from './_ui.ts'

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
const seeded: Array<[string, string]> = []
const ids: Record<string, string> = {}
const chat = `${RUN_ID} chat assistant`
const scoring = `${RUN_ID} scoring model`
const checked = `${RUN_ID} checked model`
const agent = `${RUN_ID} file assistant`
const caseProcess = `${RUN_ID} case handling`

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
	ids.agent = await seed('aiSystem', {
		name: agent,
		kind: 'AI agent',
		aiActRiskCategory: 'minimal risk',
		status: 'in use',
	})
	ids.process = await seed('process', { name: caseProcess, status: 'active' })
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
	await revealSection(page, 'AI systems')
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

// @e2e ai-system-inventory::an-information-manager-links-an-ai-agent-to-a-process
test('an AI agent linked to a business process is listed on the process page', async ({
	page,
}) => {
	await gotoAppRoute(page, `/ai-systems/${ids.agent}`)
	await dismissSupportDialog(page)
	await page.getByRole('button', { name: 'Edit', exact: true }).first().click()
	const dialog = page.locator('[role="dialog"], .modal-container').first()
	await dialog.waitFor({ state: 'visible', timeout: 15000 })
	const picker = dialog.getByLabel('Business processes').first()
	await picker.click()
	await picker.fill(caseProcess)
	await page.getByRole('option', { name: new RegExp(caseProcess) }).first().click()
	await dialog.getByRole('button', { name: /save/i }).last().click()
	await expect(dialog).toBeHidden({ timeout: 30000 })
	await expect(page.getByText(caseProcess).first()).toBeVisible({ timeout: 30000 })

	await gotoAppRoute(page, `/processen/${ids.process}`)
	await revealSection(page, 'AI systems')
	await expect(page.getByText(agent).first()).toBeVisible({ timeout: 30000 })
})
