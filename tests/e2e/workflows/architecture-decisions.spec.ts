// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Architecture decisions: recording a decision with a rejected alternative,
 * a second person accepting it through the declared lifecycle, and the
 * decisions about an application in use listed on its usage page.
 *
 * Seeds an organisation, an application, a usage and two architecture
 * decisions carrying this run's RUN_ID through the objects API, and creates a
 * reviewer account for the run (the review guard needs a reviewer other than
 * the author). Removes exactly those rows, the decision the record test
 * creates and the account afterwards. The guard and the notification rules
 * are covered by tests/Unit/Lifecycle/ArchitectureDecisionReviewGuardTest.php
 * and tests/Unit/Settings/ArchitectureDecisionRegisterShapeTest.php.
 *
 * Written from the change's scenarios; not yet run against a live instance.
 *
 * @spec openspec/changes/archive/2026-11-28-architecture-decision-register/specs/architecture-decision-register/spec.md#requirement-req-adreg-001-an-information-manager-shall-record-an-architecture-decision-with-its-context-options-and-consequences
 * @spec openspec/changes/archive/2026-11-28-architecture-decision-register/specs/architecture-decision-register/spec.md#requirement-req-adreg-002-a-decision-shall-be-reviewed-by-a-second-person-through-a-declared-lifecycle
 * @spec openspec/changes/archive/2026-11-28-architecture-decision-register/specs/architecture-decision-register/spec.md#requirement-req-adreg-004-a-decision-shall-link-to-the-applications-and-elements-it-affects-and-to-board-decisions
 */
import type { APIRequestContext, Browser, Page } from '@playwright/test'
import type { VoorzieningenConfig } from './_fixtures.ts'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { BASE_URL } from '../base-url.ts'
import {
	createObject,
	deleteObject,
	findAll,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from './_fixtures.ts'
import { dismissSupportDialog, gotoAppRoute, openCreateDialog, revealSection } from './_ui.ts'

const OR = '/index.php/apps/openregister/api/objects'
const SCHEMA = 'architectureDecision'
const reviewer = `adr-${RUN_ID}`.slice(0, 60)
const password = `Ad-${randomBytes(18).toString('hex')}-Pw1!`

let apiCtx: APIRequestContext
let reviewerCtx: APIRequestContext
let cfg: VoorzieningenConfig
const seeded: Array<[string, string]> = []
const ids: Record<string, string> = {}
const organisation = `${RUN_ID} municipality`
const application = `${RUN_ID} case application`
const inReview = `${RUN_ID} one archive for all records`
const accepted = `${RUN_ID} one case system for all domains`

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
 * Move a decision to another status through a direct edit, as one account.
 *
 * @param ctx The account's API context.
 * @param id The decision.
 * @param status The status to reach.
 * @return Resolves once the status is stored.
 */
async function moveTo(ctx: APIRequestContext, id: string, status: string): Promise<void> {
	const res = await ctx.patch(`${OR}/${cfg.register}/${SCHEMA}/${id}`, { data: { status } })
	expect(res.ok(), `${status}: HTTP ${res.status()} ${await res.text()}`).toBe(true)
}

/**
 * The id of an object, whether given as a row or as an object.
 *
 * @param row The row.
 * @return The id, or an empty string.
 */
function idOf(row: Record<string, unknown>): string {
	return String(row.id ?? (row as { '@self'?: { id?: string } })['@self']?.id ?? '')
}

/**
 * A browser page logged in as the reviewer, from an empty storage state.
 *
 * @param browser The browser.
 * @return The logged-in page.
 */
async function reviewerPage(browser: Browser): Promise<Page> {
	const context = await browser.newContext({ baseURL: BASE_URL, storageState: { cookies: [], origins: [] } })
	// A fresh account gets the welcome tour and the support note; the shared
	// storage state that suppresses them belongs to the admin only.
	await context.addInitScript(() => {
		try {
			window.localStorage.setItem('cn-support-dialog-shown:stackiq', '1')
			window.localStorage.setItem('cn-walkthrough-seen:stackiq', '999.0.0')
		} catch {
			// No storage: the helpers dismiss them by hand.
		}
	})
	const page = await context.newPage()
	await page.goto('/index.php/login', { timeout: 60000 })
	const user = page.locator('input[autocomplete="username"], input[name="user"], input[id="user"]')
	await user.waitFor({ state: 'visible', timeout: 45000 })
	await user.fill(reviewer)
	await page.locator('input[autocomplete="current-password"], input[type="password"], input[name="password"]').fill(password)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.waitForFunction(() => !window.location.pathname.includes('/login'), { timeout: 30000 })
	return page
}

test.describe.configure({ mode: 'serial' })

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	const made = await apiCtx.post('/ocs/v2.php/cloud/users?format=json', {
		data: { userid: reviewer, password, groups: ['admin'] },
	})
	expect(made.ok(), `reviewer account: HTTP ${made.status()}`).toBe(true)
	reviewerCtx = await playwrightRequest.newContext({
		baseURL: BASE_URL,
		storageState: { cookies: [], origins: [] },
		httpCredentials: { username: reviewer, password, send: 'always' },
		extraHTTPHeaders: { 'OCS-APIREQUEST': 'true' },
	})

	ids.org = await seed('organization', { name: organisation, type: 'Municipality' })
	ids.module = await seed('module', { name: application })
	ids.usage = await seed('usage', {
		consumer: ids.org,
		module: ids.module,
		status: 'In production',
		startDateInProduction: '2022-01-01',
	})
	// The author (the admin) submits; the guard needs a reviewer other than the author.
	ids.inReview = await seed(SCHEMA, {
		title: inReview,
		context: `${RUN_ID} records are archived per department.`,
		decision: 'All records go to one archive.',
		category: 'data',
		impact: 'medium',
		reviewer,
		status: 'in review',
	})
	// Accepting is the reviewer's, so the reviewer moves it from in review to accepted.
	ids.accepted = await seed(SCHEMA, {
		title: accepted,
		context: `${RUN_ID} three domains each run their own case system.`,
		decision: 'One case system, domain by domain.',
		category: 'application',
		impact: 'high',
		reviewer,
		applications: [ids.usage],
	})
	await moveTo(apiCtx, ids.accepted, 'in review')
	await moveTo(reviewerCtx, ids.accepted, 'accepted')
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const row of await findAll(apiCtx, cfg.register, SCHEMA, RUN_ID)) {
		const id = idOf(row)
		if (id && !seeded.some(([, seededId]) => seededId === id)) {
			await deleteObject(apiCtx, cfg.register, SCHEMA, id)
		}
	}
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	await reviewerCtx?.dispose()
	await apiCtx.delete(`/ocs/v2.php/cloud/users/${encodeURIComponent(reviewer)}?format=json`).catch(() => undefined)
	await apiCtx.dispose()
})

// @e2e architecture-decision-register::an-information-manager-records-a-decision
test('a recorded decision is listed as draft and its page shows the rejected alternative', async ({ page }) => {
	const title = `${RUN_ID} new integrations only through APIs`
	await gotoAppRoute(page, '/architectuurbesluiten')
	await dismissSupportDialog(page)

	const dialog = await openCreateDialog(page, /Add/)
	await dialog.getByLabel('Title').fill(title)
	await dialog.getByLabel('Context').fill('File exchange over FTP cannot be traced.')
	// exact: "Board decisions" also contains "Decision".
	await dialog.getByLabel('Decision', { exact: true }).fill('A new integration uses a documented API.')
	await dialog.getByLabel('Category').click()
	await page.getByRole('option', { name: 'Integration', exact: true }).click()
	await dialog.getByLabel('Impact').click()
	await page.getByRole('option', { name: 'Medium', exact: true }).click()
	await dialog.getByLabel('Reviewer').fill(reviewer)
	// `alternatives` is an array of objects, which CnFormDialog edits as a
	// sub-objects table: Add row opens a nested dialog over the item schema.
	await dialog.getByTestId('cn-sub-objects-add').click()
	const row = page.getByRole('dialog').filter({ has: page.getByLabel('Reason rejected') }).last()
	await expect(row).toBeVisible({ timeout: 15000 })
	await row.getByLabel('Option').fill('File transfer')
	await row.getByLabel('Reason rejected').fill('Transfers cannot be traced.')
	await row.getByRole('button', { name: /Save|Create|Add/ }).last().click()
	await expect(dialog.getByRole('row').filter({ hasText: 'File transfer' })).toBeVisible({ timeout: 30000 })
	await dialog.getByRole('button', { name: /Save|Create/ }).last().click()
	await expect(dialog).toBeHidden({ timeout: 30000 })

	const listed = page.getByRole('row').filter({ hasText: title })
	await expect(listed).toContainText(/draft/i, { timeout: 30000 })
	await listed.click()
	await expect(page).toHaveURL(/\/architectuurbesluiten\//)
	await expect(page.getByText('File transfer').first()).toBeVisible({ timeout: 30000 })
	await expect(page.getByText('Transfers cannot be traced.').first()).toBeVisible()
})

// @e2e architecture-decision-register::a-reviewer-accepts-a-decision
test('the reviewer applies Accept and the History tab shows the transition', async ({ browser }) => {
	const page = await reviewerPage(browser)
	try {
		await gotoAppRoute(page, `/architectuurbesluiten/${ids.inReview}`)
		await dismissSupportDialog(page)
		await expect(page.getByText(inReview).first()).toBeVisible({ timeout: 30000 })

		// Accept is the reviewer's action in the page's Actions menu.
		await page.getByRole('button', { name: 'Actions' }).first().click()
		await page.getByRole('menuitem', { name: /Accept/ }).first().click()
		await expect(page.getByText('Accepted', { exact: true }).first()).toBeVisible({ timeout: 30000 })
		await page.getByRole('button', { name: 'Actions' }).first().click()
		await expect(page.getByRole('menuitem', { name: /Accept/ })).toHaveCount(0)
		await page.keyboard.press('Escape')

		const res = await apiCtx.get(`${OR}/${cfg.register}/${SCHEMA}/${ids.inReview}`)
		expect((await res.json()).status).toBe('accepted')

		// The sidebar reads the history when the page loads and does not refresh
		// it after a transition (reported for nextcloud-vue), so reload first.
		await page.reload()
		await expect(page.getByText(inReview).first()).toBeVisible({ timeout: 30000 })
		// The History tab lives in the record sidebar, which a fresh account starts closed.
		const openSidebar = page.getByRole('button', { name: /Open sidebar|Zijbalk openen/i })
		if (await openSidebar.isVisible().catch(() => false)) {
			await openSidebar.click()
		}
		// With one tab group showing, the sidebar draws History without a tab strip.
		const historyTab = page.getByRole('tab', { name: 'History' })
		if (await historyTab.isVisible().catch(() => false)) {
			await historyTab.click()
		}
		// The transition is the reviewer's own update entry.
		await expect(
			page.getByRole('region', { name: 'History' }).getByRole('link', { name: new RegExp(`${reviewer} update`) }).first(),
		).toBeVisible({ timeout: 30000 })
	} finally {
		await page.context().close()
	}
})

// @e2e architecture-decision-register::an-application-owner-sees-the-decisions-about-their-application
test('the usage page lists the accepted decision about it and opens the decision page', async ({ page }) => {
	await gotoAppRoute(page, `/gebruik/${ids.usage}`)
	await dismissSupportDialog(page)
	// The decisions widget sits below the fold and mounts when scrolled into view.
	await revealSection(page, 'Architecture decisions')
	const row = page.getByRole('row').filter({ hasText: accepted })
	await expect(row).toBeVisible({ timeout: 30000 })
	await expect(row).toContainText(/accepted/i)
	await expect(page.getByRole('row').filter({ hasText: inReview })).toHaveCount(0)

	await row.click()
	await expect(page).toHaveURL(new RegExp(`/architectuurbesluiten/${ids.accepted}`), { timeout: 30000 })
	await expect(page.getByText(accepted).first()).toBeVisible({ timeout: 30000 })
})
