// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Ask the catalogue (the assistant answers and action trail change, task 2): a
 * user opens Ask from the menu, asks which applications a supplier offers and
 * reads the reply with its linked Sources; a question no entry matches reads
 * "No catalogue entries matched this question." and leaves the assistant out.
 *
 * Seeds one supplier and three published applications of that supplier, all
 * carrying this run's RUN_ID, through the objects API, and removes exactly
 * those rows afterwards. Both tests need hermiq: `POST /api/ask` answers 503
 * before it searches when hermiq is absent, so they skip on an instance
 * without it. The reply itself comes from the assistant, so the test holds
 * the Sources list (the entries the reply cites, linked by stackiq) to the
 * three applications, and checks the reply names them as a soft assertion.
 *
 * The excluded scenarios (an unreadable entry is never sent, hermiq missing)
 * are covered by tests/Unit/Service/AskServiceTest.php and
 * tests/Unit/Controller/AskControllerTest.php.
 *
 * Written from the change's scenarios; not yet run against a live instance.
 *
 * @spec openspec/changes/archive/2026-11-24-insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-001-an-answer-lists-the-catalogue-entries-it-used
 * @spec openspec/changes/archive/2026-11-24-insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-002-the-ask-panel-is-only-offered-when-hermiq-is-installed
 */
import type { APIRequestContext, Page } from '@playwright/test'
import type { VoorzieningenConfig } from './_fixtures.ts'

import { expect, test } from '@playwright/test'
import {
	createObject,
	deleteObject,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from './_fixtures.ts'
import { dismissSupportDialog, navClickTo } from './_ui.ts'

const ASK_PATH = '/apps/stackiq/api/ask'

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
let hermiqEnabled = false
const seeded: Array<[string, string]> = []
const ids: Record<string, string> = {}
const supplierName = `${RUN_ID} supplier`
const applications = [
	`${RUN_ID} application one`,
	`${RUN_ID} application two`,
	`${RUN_ID} application three`,
]

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

/**
 * Whether a Nextcloud app is enabled on the instance.
 *
 * @param app The app id.
 * @return True when it is enabled.
 */
async function appEnabled(app: string): Promise<boolean> {
	const res = await apiCtx.get('/ocs/v2.php/cloud/apps?filter=enabled&format=json')
	if (!res.ok()) {
		return false
	}
	const list: string[] = (await res.json())?.ocs?.data?.apps ?? []
	return list.includes(app)
}

/**
 * Open Ask through its menu entry and submit a question.
 *
 * @param page The page.
 * @param question The question.
 * @return The JSON `POST /api/ask` answered with.
 */
async function ask(page: Page, question: string): Promise<Record<string, unknown>> {
	await navClickTo(page, 'Ask the catalogue')
	await dismissSupportDialog(page)
	await expect(page.getByRole('heading', { name: 'Ask the catalogue' }).first()).toBeVisible({ timeout: 30000 })
	await page.getByLabel('Your question').first().fill(question)
	const answered = page.waitForResponse((res) => res.url().includes(ASK_PATH) && res.request().method() === 'POST', { timeout: 120000 })
	await page.getByTestId('ask-submit').click()
	const response = await answered
	expect(response.status(), await response.text()).toBe(200)
	return await response.json()
}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	hermiqEnabled = await appEnabled('hermiq')
	ids.supplier = await seed('organization', {
		name: supplierName,
		type: 'Supplier',
		status: 'Active',
		contactsUid: `${RUN_ID}-supplier`,
	})
	const now = new Date().toISOString()
	for (const [index, name] of applications.entries()) {
		ids[`app${index}`] = await seed('module', {
			name,
			shortDescription: `${name} is offered by ${supplierName}`,
			provider: ids.supplier,
			publicationDate: now,
		})
	}
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e assistant-insight::a-user-asks-which-applications-a-supplier-offers
test('asking which applications a supplier offers lists its three applications as linked Sources', async ({ page }) => {
	test.skip(!hermiqEnabled, 'hermiq is not installed on this instance; POST /api/ask answers 503 without it')
	test.setTimeout(180000)

	const body = await ask(page, `Which applications does ${supplierName} offer?`)
	expect(Array.isArray(body.searched) && body.searched.length > 0, 'the catalogue search found the seeded entries').toBe(true)

	const answer = page.getByTestId('ask-answer')
	await expect(answer).toBeVisible({ timeout: 30000 })
	const sources = page.getByTestId('ask-sources')
	await expect(sources).toBeVisible()
	for (const [index, name] of applications.entries()) {
		const link = sources.getByRole('link', { name, exact: true })
		await expect(link).toBeVisible()
		await expect(link).toHaveAttribute('href', new RegExp(`/modules/${ids[`app${index}`]}$`))
		// The wording of the reply is the assistant's; the names should be in it.
		await expect.soft(answer.locator('.ask-reply')).toContainText(name)
	}

	await sources.getByRole('link', { name: applications[0], exact: true }).click()
	await expect(page).toHaveURL(new RegExp(`/modules/${ids.app0}`))
	await expect(page.getByText(applications[0]).first()).toBeVisible({ timeout: 30000 })
})

// @e2e assistant-insight::nothing-matched
test('a question no entry matches says so and the assistant is not asked', async ({ page }) => {
	test.skip(!hermiqEnabled, 'hermiq is not installed on this instance; POST /api/ask answers 503 without it')

	const body = await ask(page, `${RUN_ID} qqzx no catalogue entry holds this text`)
	// No entry searched means AskService returned before calling hermiq's converse.
	expect(body.searched).toEqual([])
	expect(body.sources).toEqual([])
	expect(body.reply).toBe('')

	await expect(page.getByTestId('ask-empty')).toHaveText('No catalogue entries matched this question.')
	await expect(page.getByTestId('ask-answer')).toHaveCount(0)
})
