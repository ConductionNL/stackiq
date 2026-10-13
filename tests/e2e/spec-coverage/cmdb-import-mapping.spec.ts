// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * E2e coverage for openspec/changes/cmdb-import-mapping-view: the "Mapping"
 * block of the "CMDB import" settings section.
 *
 * Every scenario the delta spec tags
 * `@e2e tests/e2e/spec-coverage/cmdb-import-mapping.spec.ts` is driven here
 * on the real settings page: one line per pack with its source, its version
 * and the link to its editor in OpenRegister, the link on the error of a
 * broken stored pack, and the sheet names in the help text. The error states
 * come from a route intercept, so no pack is broken on a shared instance.
 * The endpoint itself, the import and the 403 for a non-admin are driven
 * through the API in cmdb-import-packs.spec.ts.
 *
 * Nothing is written.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { newApiContext, RUN_ID } from '../workflows/_fixtures.ts'

const MAPPING_PATH = '/index.php/apps/stackiq/api/settings/cmdb-import/mapping'
// The page builds its URL with generateUrl(), which drops `/index.php` on an
// instance with pretty URLs, so the browser-side matcher uses the path tail.
const MAPPING_ROUTE = '**/apps/stackiq/api/settings/cmdb-import/mapping'
const SHEET_PACKS = ['module', 'manufacturer', 'usage', 'businessOwner']
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

test.describe('CMDB import mapping block', () => {
	// @e2e cmdb-export-import::the-section-links-each-pack-to-its-editor
	test('the section links each pack to its editor in OpenRegister', async ({
		page,
	}) => {
		const ctx = await newApiContext()
		let packIds: Record<string, number> = {}
		try {
			const res = await ctx.get(MAPPING_PATH)
			expect(res.status(), await res.text()).toBe(200)
			packIds = Object.fromEntries(
				(
					(await res.json()).packs as Array<{
						target: string
						packId: number | null
					}>
				).map((pack) => [pack.target, pack.packId ?? 0]),
			)
		} finally {
			await ctx.dispose()
		}

		const section = await gotoCmdbSection(page)
		const block = section.locator('[data-testid="cmdb-import-mapping"]')
		const toggle = block.locator('[data-testid="cmdb-import-mapping-toggle"]')
		await expect(toggle).toHaveAttribute('aria-expanded', 'false')
		await toggle.click()
		await expect(toggle).toHaveAttribute('aria-expanded', 'true')

		for (const target of SHEET_PACKS) {
			const line = block.locator(
				`[data-testid="cmdb-import-mapping-${target}"]`,
			)
			await expect(line, target).toBeVisible({ timeout: 30000 })
			await expect(
				line.locator('[data-testid="cmdb-import-mapping-source"]'),
				target,
			).toHaveText('OpenRegister')
			await expect(
				line.locator('[data-testid="cmdb-import-mapping-version"]'),
				target,
			).toContainText(/Version|Versie/)
			const edit = line.locator('[data-testid="cmdb-import-mapping-edit"]')
			await expect(edit, target).toBeVisible()
			await expect(edit, target).toHaveAttribute(
				'href',
				new RegExp(`/apps/openregister/migration-packs/${packIds[target]}$`),
			)
		}

		// The municipality pack maps the import options, not a column: not listed.
		await expect(
			block.locator('[data-testid="cmdb-import-mapping-municipality"]'),
		).toHaveCount(0)
		// The columns are edited in OpenRegister: the block has no field.
		await expect(block.locator('input, textarea, select')).toHaveCount(0)

		// A broken stored pack: the error links to that pack's editor.
		await page.route(MAPPING_ROUTE, (route) =>
			route.fulfill({
				status: 503,
				json: {
					success: false,
					error: 'MAPPING_UNAVAILABLE',
					message: 'The import mapping cannot be shown.',
					details: {
						pack: 'stackiq-topdesk-usage',
						packId: packIds.usage,
						editUrl: `/index.php/apps/openregister/migration-packs/${packIds.usage}`,
						reason: `OpenRegister migration pack stackiq-topdesk-usage (id ${packIds.usage}) breaks a rule of the CMDB import: refused in this test`,
					},
				},
			}),
		)
		const broken = await gotoCmdbSection(page)
		await broken.locator('[data-testid="cmdb-import-mapping-toggle"]').click()
		const error = broken.locator('[data-testid="cmdb-import-mapping-error"]')
		await expect(error).toBeVisible()
		await expect(error).toContainText('refused in this test')
		await expect(
			error.locator('[data-testid="cmdb-import-mapping-error-edit"]'),
		).toHaveAttribute(
			'href',
			new RegExp(`/apps/openregister/migration-packs/${packIds.usage}$`),
		)
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
})
