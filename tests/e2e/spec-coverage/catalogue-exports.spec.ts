/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e — exporting the catalogue (change
 * insight-exports-and-custom-reports, REQ-CER-001 to REQ-CER-003).
 *
 * - The Applications page exports what the search leaves, as CSV, through
 *   `/api/catalog/module/export`, with the page's columns (REQ-CER-002).
 * - `/komplianties` and `/moduleversies` offer the library's Export menu
 *   (REQ-CER-001).
 * - The organisation detail page offers Export as ArchiMate to the admin and
 *   downloads XML; an ordinary account of no organisation does not see it and
 *   the export answers it 403 (REQ-CER-003).
 *
 * Fixtures are seeded as the admin through OpenRegister and removed again.
 *
 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md
 */
import type { APIRequestContext, Browser } from '@playwright/test'
import type { VoorzieningenConfig } from '../workflows/_fixtures.ts'

import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { BASE_URL } from '../base-url.ts'
import { createObject, deleteObject, newApiContext, resolveConfig, RUN_ID } from '../workflows/_fixtures.ts'
import { APP_BASE, dismissSupportDialog, dismissWalkthrough } from './_helpers.ts'

test.describe.configure({ mode: 'serial' })

let ctx: APIRequestContext
let config: VoorzieningenConfig
const created: Array<[string, string]> = []
const ids: Record<string, string> = {}

test.beforeAll(async () => {
	ctx = await newApiContext()
	config = await resolveConfig(ctx)
	ids.match = await createObject(ctx, config.register, 'module', { name: `${RUN_ID} export match` })
	created.push(['module', ids.match])
	ids.org = await createObject(ctx, config.register, 'organization', { name: `${RUN_ID} organisation`, type: 'Gemeente' })
	created.push(['organization', ids.org])
})

test.afterAll(async () => {
	for (const [schema, id] of created.reverse()) {
		await deleteObject(ctx, config.register, schema, id)
	}
	await ctx.dispose()
})

/**
 * Sign in as an account through the login form, in a fresh context.
 *
 * @param browser  The browser.
 * @param uid      The account.
 * @param password Its password.
 * @return The signed-in page.
 */
async function signIn(browser: Browser, uid: string, password: string) {
	const context = await browser.newContext({ baseURL: BASE_URL, storageState: { cookies: [], origins: [] } })
	const page = await context.newPage()
	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(uid)
	await page.locator('input[name="password"]').fill(password)
	await page.locator('button[type="submit"]').first().click()
	await page.waitForURL((url) => !/\/login(\?|$|\/)/.test(url.pathname), { timeout: 60_000 })
	return page
}

test('the Applications page exports the rows the search leaves, as CSV', async ({ page }) => {
	await page.goto(`${APP_BASE}/modules?_search=${encodeURIComponent(RUN_ID)}`)
	await dismissSupportDialog(page)
	await dismissWalkthrough(page)
	const main = page.locator('main').first()
	await expect(main.getByText(`${RUN_ID} export match`).first()).toBeVisible({ timeout: 30_000 })

	await main.getByRole('button', { name: 'Export' }).first().click()
	const request = page.waitForRequest((r) => r.url().includes('/api/catalog/module/export'))
	const download = page.waitForEvent('download')
	await page.getByRole('menuitem', { name: 'Export as CSV' }).click()
	const url = new URL((await request).url())
	expect(url.searchParams.get('format') ?? 'csv').toBe('csv')
	expect([...url.searchParams.values()].join(' ')).toContain(RUN_ID)

	const file = await download
	const text = (await (await file.createReadStream()).toArray()).map(String).join('')
	const lines = text.split(/\r?\n/).filter((line) => line.trim() !== '')
	expect(lines.length, 'a header and the matching row').toBeGreaterThanOrEqual(2)
	expect(text).toContain(`${RUN_ID} export match`)
})

test('the export refuses a schema other than applications and services', async () => {
	const res = await ctx.get('/index.php/apps/stackiq/api/catalog/contactpersoon/export')
	expect(res.status()).toBe(400)
})

for (const route of ['/komplianties', '/moduleversies']) {
	test(`${route} offers the Export menu`, async ({ page }) => {
		await page.goto(`${APP_BASE}${route}`)
		await dismissSupportDialog(page)
		await dismissWalkthrough(page)
		await expect(page.locator('main').first().getByRole('button', { name: /export/i }).first()).toBeVisible({ timeout: 30_000 })
	})
}

test('the admin exports an organisation as ArchiMate from its page', async ({ page }) => {
	await page.goto(`${APP_BASE}/organisaties/${ids.org}`)
	await dismissSupportDialog(page)
	await dismissWalkthrough(page)
	const action = page.getByTestId('organisation-export-archimate')
	await expect(action).toBeVisible({ timeout: 30_000 })
	const download = page.waitForEvent('download')
	await action.click()
	const file = await download
	const text = (await (await file.createReadStream()).toArray()).map(String).join('')
	expect(text).toMatch(/<\?xml|<model/)
})

test('an account of no organisation does not see the action and is refused the export', async ({ browser, playwright }) => {
	const uid = `cer-${RUN_ID}`.slice(0, 60)
	const password = `St-${randomBytes(18).toString('hex')}-Pw1!`
	const made = await ctx.post('/ocs/v2.php/cloud/users?format=json', { data: { password, userid: uid } })
	expect(made.ok(), `account: HTTP ${made.status()}`).toBe(true)
	try {
		const page = await signIn(browser, uid, password)
		await page.goto(`${APP_BASE}/organisaties/${ids.org}`)
		await dismissSupportDialog(page)
		await dismissWalkthrough(page)
		await expect(page.locator('main').first()).toBeVisible({ timeout: 30_000 })
		await expect(page.getByTestId('organisation-export-archimate')).toHaveCount(0)
		await page.context().close()

		const other = await playwright.request.newContext({
			baseURL: BASE_URL,
			extraHTTPHeaders: { 'OCS-APIREQUEST': 'true' },
			httpCredentials: { password, send: 'always', username: uid },
		})
		const refused = await other.get(`/index.php/apps/stackiq/api/archimate/export/organization/${ids.org}`)
		expect(refused.status()).toBe(403)
		await other.dispose()
	} finally {
		await ctx.delete(`/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}?format=json`).catch(() => undefined)
	}
})
