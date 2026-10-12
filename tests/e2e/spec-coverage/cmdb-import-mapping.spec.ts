// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * E2e coverage for openspec/changes/cmdb-import-mapping-view (the read-only
 * "Mapping (read-only)" block of the "CMDB import" settings section).
 *
 * Every scenario the delta spec tags
 * `@e2e tests/e2e/spec-coverage/cmdb-import-mapping.spec.ts` is driven here:
 * the endpoint as an admin, the block on the real settings page, the sheet
 * names in the help text (also with the endpoint answering 503, through a
 * route intercept, so no shipped file is broken on a shared instance), and
 * the refusal for a signed-in user who is not a Nextcloud admin.
 *
 * The shipped profile and packs are the expected values; nothing is written.
 */

import type { Page } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { BASE_URL, newApiContext, RUN_ID } from '../workflows/_fixtures.ts'

const MAPPING_PATH = '/index.php/apps/stackiq/api/settings/cmdb-import/mapping'
// The page builds its URL with generateUrl(), which drops `/index.php` on an
// instance with pretty URLs, so the browser-side matcher uses the path tail.
const MAPPING_ROUTE = '**/apps/stackiq/api/settings/cmdb-import/mapping'
const TARGETS = ['module', 'manufacturer', 'municipality', 'usage', 'businessOwner']
const SHIPPED_SHEETS = ['Onbeh Applicaties CMDB', 'Beheerde Applicaties CMDB']

/**
 * Close Nextcloud's first-run wizard when it shows, the way
 * cmdb-import.spec.ts does: mark it as seen and load the page again.
 *
 * @param page The page
 * @return True when the page was reloaded
 */
async function dismissFirstRunWizard(page: Page): Promise<boolean> {
	const wizard = page.locator('.first-run-wizard[role="dialog"]')
	try {
		await wizard.waitFor({ state: 'visible', timeout: 3000 })
	} catch {
		return false
	}
	await page.evaluate(async () => {
		const oc = (
			window as unknown as {
				OC: { requestToken: string; generateUrl: (u: string) => string }
			}
		).OC
		await fetch(oc.generateUrl('/apps/firstrunwizard/wizard'), {
			method: 'DELETE',
			headers: { requesttoken: oc.requestToken },
		})
	})
	await page.reload({ waitUntil: 'domcontentloaded' })
	return true
}

/**
 * Open stackiq's admin settings and return the CMDB import section.
 *
 * @param page The page
 */
async function gotoCmdbSection(page: Page) {
	await page.goto('/settings/admin/stackiq', { waitUntil: 'domcontentloaded' })
	const section = page.locator('[data-testid="cmdb-import"]')
	await expect(section).toBeVisible({ timeout: 30000 })
	if (await dismissFirstRunWizard(page)) {
		await expect(section).toBeVisible({ timeout: 30000 })
	}
	await section.scrollIntoViewIfNeeded()
	return section
}

test.describe('CMDB import mapping (read-only)', () => {
	// @e2e cmdb-export-import::an-admin-reads-the-mapping-the-import-uses
	test('an admin reads the mapping the import uses', async () => {
		const ctx = await newApiContext()
		try {
			const res = await ctx.get(MAPPING_PATH)
			expect(res.status(), await res.text()).toBe(200)
			const body = await res.json()

			expect(Object.keys(body).sort()).toEqual(['packs', 'profile'])
			expect(
				body.profile.sheets.map((sheet: { name: string }) => sheet.name),
			).toEqual(SHIPPED_SHEETS)
			expect(body.profile.keyColumn).toBe('APPID')
			expect(body.profile.requiredColumns).toEqual([
				'APPID',
				'Applicatie Naam',
			])

			expect(
				body.packs.map((pack: { target: string }) => pack.target),
			).toEqual(TARGETS)
			for (const pack of body.packs) {
				expect(pack.id, pack.target).not.toBe('')
				expect(pack.name, pack.target).not.toBe('')
				expect(pack.version, pack.target).not.toBe('')
			}

			const usage = body.packs.find(
				(pack: { target: string }) => pack.target === 'usage',
			)
			const status = usage.fieldMappings.find(
				(mapping: { source: string }) =>
					mapping.source === 'Applicatie Status',
			)
			expect(status.target).toBe('status')
			expect(status.transform.type).toBe('lookup')
			expect(status.transform.map['In productie']).toBe('In production')
		} finally {
			await ctx.dispose()
		}
	})

	// @e2e cmdb-export-import::the-section-shows-one-table-per-pack
	test('the section shows one table per pack, without editing', async ({
		page,
	}) => {
		const section = await gotoCmdbSection(page)
		const block = section.locator('[data-testid="cmdb-import-mapping"]')
		const toggle = block.locator('[data-testid="cmdb-import-mapping-toggle"]')

		await expect(toggle).toHaveAttribute('aria-expanded', 'false')
		await toggle.click()
		await expect(toggle).toHaveAttribute('aria-expanded', 'true')

		for (const target of TARGETS) {
			await expect(
				block.locator(`[data-testid="cmdb-import-mapping-${target}"]`),
				target,
			).toBeVisible({ timeout: 30000 })
		}

		const statusRow = block
			.locator('[data-testid="cmdb-import-mapping-usage"]')
			.getByRole('row')
			.filter({ hasText: 'Applicatie Status' })
		await expect(statusRow).toContainText('status')
		await expect(statusRow).toContainText(/Lookup|Opzoektabel/)
		await expect(statusRow).toContainText('In productie → In production')

		// Read-only: the toggle is the block's only control.
		await expect(block.getByRole('button')).toHaveCount(1)
		await expect(block.locator('input, textarea, select')).toHaveCount(0)
	})

	// @e2e cmdb-export-import::the-sheet-names-in-the-help-come-from-the-server
	test('the help text names the sheets from the server, and the shipped ones when it fails', async ({
		page,
	}) => {
		// The endpoint answers other names: the help must follow it.
		await page.route(MAPPING_ROUTE, async (route) => {
			const response = await route.fetch()
			const body = await response.json()
			body.profile.sheets = [
				{ name: `Sheet one ${RUN_ID}`, constants: {}, absentColumns: [] },
				{ name: `Sheet two ${RUN_ID}`, constants: {}, absentColumns: [] },
			]
			await route.fulfill({ response, json: body })
		})
		await gotoCmdbSection(page)
		const help = page.locator('#cmdb-import-file-help')
		await expect(help).toContainText(`Sheet one ${RUN_ID}`)
		await expect(help).toContainText(`Sheet two ${RUN_ID}`)

		// The endpoint fails: the shipped names, and the block shows the error.
		await page.unroute(MAPPING_ROUTE)
		await page.route(MAPPING_ROUTE, (route) =>
			route.fulfill({
				status: 503,
				json: {
					success: false,
					error: 'MAPPING_UNAVAILABLE',
					message: 'The import mapping cannot be shown.',
					details: { reason: 'topdesk-usage.json: refused in this test' },
				},
			}),
		)
		const section = await gotoCmdbSection(page)
		for (const sheet of SHIPPED_SHEETS) {
			await expect(help).toContainText(sheet)
		}
		await section.locator('[data-testid="cmdb-import-mapping-toggle"]').click()
		const error = section.locator('[data-testid="cmdb-import-mapping-error"]')
		await expect(error).toBeVisible()
		await expect(error).toContainText('MAPPING_UNAVAILABLE')
		await expect(error).toContainText('topdesk-usage.json: refused in this test')
		await expect(
			section.locator('[data-testid="cmdb-import-mapping-usage"]'),
		).toHaveCount(0)
	})

	// @e2e cmdb-export-import::a-user-who-is-not-a-nextcloud-admin-cannot-read-the-mapping
	test('a signed-in user who is not a Nextcloud admin is refused', async () => {
		const ctx = await newApiContext()
		const userId = `cmdbmap-${RUN_ID}`
		const password = `Cmdbmap-${RUN_ID}-Pw!9`
		const created = await ctx.post('/ocs/v2.php/cloud/users?format=json', {
			form: { userid: userId, password },
		})
		expect(created.ok(), await created.text()).toBe(true)
		const user = await playwrightRequest.newContext({
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: { username: userId, password, send: 'always' },
			extraHTTPHeaders: { 'OCS-APIREQUEST': 'true' },
		})
		try {
			// Prove the context is that user, so a 403 is the rule and not a failed login.
			const whoami = await user.get('/ocs/v2.php/cloud/user?format=json')
			expect(whoami.status()).toBe(200)
			expect((await whoami.json()).ocs.data.id).toBe(userId)

			const res = await user.get(MAPPING_PATH)
			expect(res.status()).toBe(403)

			// The admin settings page that holds the section is not theirs either:
			// Nextcloud refuses or redirects it, it never renders it (2xx).
			const settings = await user.get('/index.php/settings/admin/stackiq', {
				maxRedirects: 0,
			})
			expect(settings.ok(), `status ${settings.status()}`).toBe(false)
		} finally {
			await user.dispose()
			await ctx.delete(`/ocs/v2.php/cloud/users/${userId}?format=json`)
			await ctx.dispose()
		}
	})
})
