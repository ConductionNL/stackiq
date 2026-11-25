// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Behavioural e2e coverage for the Synchronisation page
 * (operations-sync-status-and-progress, task 5; REQ-SSP-004).
 *
 * Surface under test: `SyncStatusView` at /organisaties/synchronisation, a
 * child of Organisations whose menu entry carries permission `sync.view`
 * (DashboardController gives it to Nextcloud admins and functional
 * administrators only), reading GET /api/sync/status.
 *
 * - The schedule and the last run are read from the live endpoint.
 * - The running sync is driven by answering GET /api/sync/status in the
 *   browser (page.route): a real run is too short and too slow to start on
 *   demand to watch three-second polls, and the poll cadence is the
 *   behaviour under test, not the sync itself.
 * - The regular user is a fresh Nextcloud account in a context with an empty
 *   storage state, so none of the admin's cookies come along.
 *
 * @spec openspec/specs/sync-status-and-progress/spec.md#requirement-req-ssp-004-the-synchronisation-page-shall-show-the-schedule-the-last-run-and-a-running-sync-to-nextcloud-admins-and-functional-administr
 */
import type { Route } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { BASE_URL, newApiContext, RUN_ID } from '../workflows/_fixtures.ts'
import { APP_BASE, appNav, collectAppErrors, expectNoAppErrors, gotoAppRoute, navClickTo } from './_helpers.ts'

const STATUS_PATH = '/apps/stackiq/api/sync/status'
const PAGE_ROUTE = '/organisaties/synchronisation'

/**
 * A status answer.
 *
 * @param running The run in progress, or null.
 * @return The body.
 */
function statusBody(running: Record<string, unknown> | null): Record<string, unknown> {
	return {
		enabled: true,
		interval: 3600,
		current: running,
		lastRun: {
			finishedAt: '2026-11-17T06:00:00+00:00',
			startedAt: '2026-11-17T05:58:00+00:00',
			trigger: 'cron',
			errorCount: 0,
			stats: { organizationsProcessed: 12, entitiesCreated: 1, entitiesUpdated: 11, contactPersonsProcessed: 30, usersCreated: 0 },
		},
	}
}

test.describe('operations-sync-status-and-progress', () => {
	/**
	 * @e2e sync-status-and-progress::a-functional-administrator-sees-the-schedule-and-the-last-run
	 */
	test('an admin finds Synchronisation under Organisations with the schedule and the last run', async ({ page }) => {
		const bag = collectAppErrors(page)
		await navClickTo(page, 'Synchronisation')

		await expect(page).toHaveURL(new RegExp(`${PAGE_ROUTE}$`))
		const main = page.locator('main').first()
		await expect(main.getByRole('heading', { name: 'Synchronisation' }).first()).toBeVisible()
		await expect(main.getByText('Runs', { exact: true })).toBeVisible()
		await expect(main.getByText('Scheduled sync', { exact: true })).toBeVisible()
		await expect(main.getByText(/^(On|Off)$/).first()).toBeVisible()
		await expect(main.getByText(/^(Last run|The sync has not run yet\.)$/).first()).toBeVisible()

		expectNoAppErrors(bag)
	})

	/**
	 * @e2e sync-status-and-progress::a-running-sync-shows-its-phase-and-percentage
	 */
	test('a running sync is polled every three seconds and polling stops when it ends', async ({ page }) => {
		const polls: number[] = []
		let answers = 0
		await page.route(`**${STATUS_PATH}*`, async (route: Route) => {
			polls.push(Date.now())
			answers++
			// Two answers while running, then the run has ended.
			const running = answers <= 2
				? { phase: 'processing_contacts', percentage: 40, processed_items: 12, total_items: 30, trigger: 'manual' }
				: null
			await route.fulfill({ json: statusBody(running) })
		})

		await gotoAppRoute(page, PAGE_ROUTE)
		const main = page.locator('main').first()
		await expect(main.getByText('Processing contact persons')).toBeVisible()
		await expect(main.locator('[role="progressbar"][aria-valuenow="40"]').or(main.getByText(/40\s*%/)).first()).toBeVisible()
		await expect(main.getByText('12 of 30 items')).toBeVisible()

		// The run ends on the third answer; after that the page stops asking.
		await expect.poll(() => answers, { timeout: 15000 }).toBeGreaterThanOrEqual(3)
		await expect(main.getByText('Processing contact persons')).toHaveCount(0)
		const afterEnd = answers
		await page.waitForTimeout(7000)
		expect(answers, 'no poll after the run ended').toBe(afterEnd)

		const gaps = polls.slice(1, 3).map((at, i) => at - polls[i])
		for (const gap of gaps) {
			expect(gap, 'polls three seconds apart').toBeGreaterThanOrEqual(2500)
			expect(gap).toBeLessThan(6000)
		}
	})

	/**
	 * @e2e sync-status-and-progress::a-regular-user-does-not-see-the-synchronisation
	 */
	test('a regular user sees neither Synchronisation nor Integrations and the status is refused', async ({ browser }) => {
		const admin = await newApiContext()
		const userId = `syncstatus-${RUN_ID}`
		const password = `Syncstatus-${RUN_ID}-Pw!9`
		const created = await admin.post('/ocs/v2.php/cloud/users?format=json', { form: { userid: userId, password } })
		expect(created.ok(), await created.text()).toBe(true)

		const api = await playwrightRequest.newContext({
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: { username: userId, password, send: 'always' },
			extraHTTPHeaders: { 'OCS-APIREQUEST': 'true' },
		})
		// A browser session through the login form: Nextcloud's web pages do not
		// take basic auth and answer with the login page.
		const context = await browser.newContext({
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
		})
		try {
			// Prove the context is that user, so a 403 is the rule and not a failed login.
			const whoami = await api.get('/ocs/v2.php/cloud/user?format=json')
			expect(whoami.status()).toBe(200)
			expect((await whoami.json()).ocs.data.id).toBe(userId)
			expect((await api.get(`/index.php${STATUS_PATH}`)).status()).toBe(403)

			const page = await context.newPage()
			await page.goto('/index.php/login')
			await page.fill('#user', userId)
			await page.fill('#password', password)
			await page.click('button[type=submit]')
			await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30000 })
			await page.goto(`${APP_BASE}/`, { waitUntil: 'domcontentloaded' })
			const nav = appNav(page)
			await nav.waitFor({ state: 'visible', timeout: 30000 })
			await expect(nav.getByRole('link', { name: 'Synchronisation', exact: true })).toHaveCount(0)
			await expect(nav.getByRole('link', { name: 'Integrations', exact: true })).toHaveCount(0)
		} finally {
			await context.close()
			await api.dispose()
			await admin.delete(`/ocs/v2.php/cloud/users/${userId}?format=json`)
			await admin.dispose()
		}
	})
})
