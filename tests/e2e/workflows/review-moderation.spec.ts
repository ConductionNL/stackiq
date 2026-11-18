/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/catalog-ratings/spec.md
 * (REQ-CLR-008, a pending review is readable only by its author and the moderators).
 *
 * Two throwaway catalogue users in `software-catalog-users`: an author and a
 * colleague. Each acts in its own context with an empty storage state, never
 * the admin session. The admin context only creates and removes the users and
 * the review.
 */

import type { APIRequestContext, Browser, Page } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { BASE_URL, deleteObject, newApiContext, resolveConfig, RUN_ID } from './_fixtures.ts'
import { indexMain, openCreateDialog } from './_ui.ts'

const SCHEMA = 'software-review'
const GROUP = 'software-catalog-users'
const PASSWORD = `E2e-${RUN_ID}-Pw!9`
const AUTHOR = `${RUN_ID}-author`.slice(0, 60)
const COLLEAGUE = `${RUN_ID}-colleague`.slice(0, 60)
const REVIEW_NAME = `Review ${RUN_ID}`

/**
 * Create one Nextcloud user in the catalogue users group.
 *
 * @param admin The admin API context.
 * @param userId The user id.
 */
async function createUser(admin: APIRequestContext, userId: string): Promise<void> {
	const created = await admin.post('/ocs/v2.php/cloud/users?format=json', {
		form: { userid: userId, password: PASSWORD, 'groups[]': GROUP },
	})
	expect(created.ok(), `creating ${userId}: ${await created.text()}`).toBe(true)
}

/**
 * An API context acting as one user, with no session of anyone else.
 *
 * @param userId The user id.
 * @return The context; the caller disposes it.
 */
async function userApi(userId: string): Promise<APIRequestContext> {
	return await playwrightRequest.newContext({
		baseURL: BASE_URL,
		storageState: { cookies: [], origins: [] },
		httpCredentials: { username: userId, password: PASSWORD, send: 'always' },
		extraHTTPHeaders: { 'OCS-APIREQUEST': 'true' },
	})
}

/**
 * A browser page signed in as one user, starting from an empty storage state.
 *
 * @param browser The browser.
 * @param userId  The user id.
 * @return The signed-in page.
 */
async function userPage(browser: Browser, userId: string): Promise<Page> {
	const context = await browser.newContext({ baseURL: BASE_URL, storageState: { cookies: [], origins: [] } })
	const page = await context.newPage()
	await page.goto('/index.php/login', { timeout: 60_000 })
	const user = page.locator('input[autocomplete="username"], input[name="user"], input[id="user"]')
	await user.waitFor({ state: 'visible', timeout: 45_000 })
	await user.fill(userId)
	await page.locator('input[autocomplete="current-password"], input[type="password"], input[name="password"]').fill(PASSWORD)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.waitForFunction(() => !window.location.pathname.includes('/login'), { timeout: 30_000 })

	return page
}

/**
 * The names of the reviews one context can list.
 *
 * @param ctx      The API context.
 * @param register The catalogue register.
 * @return The review names.
 */
async function listedNames(ctx: APIRequestContext, register: string): Promise<string[]> {
	const res = await ctx.get(`/index.php/apps/openregister/api/objects/${register}/${SCHEMA}?_limit=500`)
	expect(res.status()).toBe(200)
	const body = await res.json()

	return ((body?.results ?? []) as Array<Record<string, unknown>>).map((row) => String(row.name ?? ''))
}

/**
 * Open the Reviews index and wait until it has rendered.
 *
 * @param page The page.
 */
async function openReviews(page: Page): Promise<void> {
	await page.goto('/index.php/apps/stackiq/reviews', { waitUntil: 'domcontentloaded' })
	await expect(indexMain(page)).toBeVisible({ timeout: 30_000 })
	await expect(indexMain(page).getByRole('button', { name: /add|nieuw|toevoegen/i }).first()).toBeVisible({ timeout: 30_000 })
}

test.describe('review moderation', () => {
	let admin: APIRequestContext
	let register = ''
	let reviewId = ''

	test.beforeAll(async () => {
		admin = await newApiContext()
		register = (await resolveConfig(admin)).register
		await createUser(admin, AUTHOR)
		await createUser(admin, COLLEAGUE)

		// The author writes a review the way the Reviews form saves it: no status.
		const author = await userApi(AUTHOR)
		const created = await author.post(`/index.php/apps/openregister/api/objects/${register}/${SCHEMA}`, {
			data: { name: REVIEW_NAME, rating: 8, shortDescription: 'E2E pending review' },
		})
		expect(created.ok(), await created.text()).toBe(true)
		const body = await created.json()
		reviewId = String(body?.id ?? body?.['@self']?.id ?? '')
		expect(body.status).toBe('pending')
		await author.dispose()
	})

	test.afterAll(async () => {
		if (reviewId !== '') {
			await deleteObject(admin, register, SCHEMA, reviewId)
		}
		for (const userId of [AUTHOR, COLLEAGUE]) {
			await admin.delete(`/ocs/v2.php/cloud/users/${userId}?format=json`)
		}
		await admin.dispose()
	})

	// @e2e openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/catalog-ratings/spec.md#scenario-a-colleague-does-not-see-a-pending-review
	test('a colleague does not see a pending review, its author and the admins do', async ({ browser }) => {
		const colleague = await userApi(COLLEAGUE)
		expect(await listedNames(colleague, register)).not.toContain(REVIEW_NAME)
		const direct = await colleague.get(`/index.php/apps/openregister/api/objects/${register}/${SCHEMA}/${reviewId}`)
		expect([403, 404]).toContain(direct.status())
		await colleague.dispose()

		const author = await userApi(AUTHOR)
		expect(await listedNames(author, register)).toContain(REVIEW_NAME)
		await author.dispose()

		expect(await listedNames(admin, register)).toContain(REVIEW_NAME)

		// And the Reviews index the colleague opens does not list it.
		const page = await userPage(browser, COLLEAGUE)
		await openReviews(page)
		await expect(indexMain(page).getByText(REVIEW_NAME)).toHaveCount(0)
		await page.context().close()
	})

	// @e2e openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/catalog-ratings/spec.md#scenario-the-author-cannot-approve-their-own-review
	test('the review form does not offer the status', async ({ browser }) => {
		const page = await userPage(browser, AUTHOR)
		await openReviews(page)
		const dialog = await openCreateDialog(page, /add|nieuw|toevoegen/i)

		await expect(dialog.getByLabel(/name|naam/i).first()).toBeVisible()
		await expect(dialog.getByLabel(/^status$/i)).toHaveCount(0)
		await expect(dialog.getByText(/^status$/i)).toHaveCount(0)
		await page.context().close()
	})
})
