/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e — custom reports over OpenRegister export profiles
 * (change insight-exports-and-custom-reports, REQ-CER-004).
 *
 * - The Reports page has a Custom reports card that opens `/reports/custom`.
 * - A report of three fields in order, format CSV, filtered on bbnLevel BBN2,
 *   runs from its row: the header holds those fields in that order and every
 *   row is BBN2.
 * - Another account's report over the same register is not listed.
 * - Delete removes the report.
 *
 * The reports are created through OpenRegister's export-profile route, the one
 * the page's dialog posts to, so the field order and filter are exact.
 *
 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md
 */
import type { APIRequestContext } from '@playwright/test'
import type { VoorzieningenConfig } from '../workflows/_fixtures.ts'

import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { BASE_URL } from '../base-url.ts'
import { createObject, deleteObject, newApiContext, resolveConfig, RUN_ID } from '../workflows/_fixtures.ts'
import { APP_BASE, dismissSupportDialog, dismissWalkthrough } from './_helpers.ts'

test.describe.configure({ mode: 'serial' })

const PROFILES = '/index.php/apps/openregister/api/export-profiles'
const FIELDS = ['name', 'bbnLevel', 'description']

let ctx: APIRequestContext
let config: VoorzieningenConfig
let target = { registerId: 0, schemaId: 0 }
const modules: string[] = []
const profiles: string[] = []
const ids: Record<string, string> = {}

/**
 * The id of an object or profile in any of the shapes it comes back in.
 *
 * @param obj The decoded body.
 * @return Its id.
 */
function idOf(obj: any): string {
	return String(obj?.id ?? obj?.uuid ?? obj?.['@self']?.id ?? '')
}

test.beforeAll(async () => {
	ctx = await newApiContext()
	config = await resolveConfig(ctx)

	const register = await (await ctx.get(`/index.php/apps/openregister/api/registers/${config.register}`)).json()
	const schemas = await (await ctx.get('/index.php/apps/openregister/api/schemas', { params: { _limit: 500, register: String(register.id) } })).json()
	const module = (schemas.results ?? schemas).find((s: any) => s.slug === 'module')
	target = { registerId: Number(register.id), schemaId: Number(module?.id) }
	expect(target.schemaId, 'the catalogue register carries the module schema').toBeGreaterThan(0)

	for (const [n, level] of [[1, 'BBN2'], [2, 'BBN1']] as Array<[number, string]>) {
		modules.push(await createObject(ctx, config.register, 'module', {
			bbnLevel: level,
			description: `Report fixture ${n}`,
			name: `${RUN_ID} report module ${n}`,
		}))
	}

	const mine = await ctx.post(PROFILES, {
		data: { ...target, fields: FIELDS, filters: { bbnLevel: 'BBN2' }, format: 'csv', name: `${RUN_ID} BBN2 applications`, valueMode: 'raw' },
	})
	expect(mine.ok(), `profile: HTTP ${mine.status()} ${await mine.text()}`).toBe(true)
	ids.mine = idOf(await mine.json())
	profiles.push(ids.mine)
})

test.afterAll(async () => {
	for (const id of profiles) {
		await ctx.delete(`${PROFILES}/${id}`).catch(() => undefined)
	}
	for (const id of modules) {
		await deleteObject(ctx, config.register, 'module', id)
	}
	await ctx.dispose()
})

test('the Reports page opens the custom reports', async ({ page }) => {
	await page.goto(`${APP_BASE}/reports`)
	await dismissSupportDialog(page)
	await dismissWalkthrough(page)
	await page.locator('main').first().getByText('Custom reports').first().click()
	await expect(page).toHaveURL(/\/reports\/custom/, { timeout: 30_000 })
	await expect(page.getByTestId('custom-reports-list')).toBeVisible({ timeout: 30_000 })
})

test('a report runs with its fields in order and only BBN2 rows', async ({ page }) => {
	await page.goto(`${APP_BASE}/reports/custom`)
	const row = page.getByTestId('custom-report-row').filter({ hasText: `${RUN_ID} BBN2 applications` })
	await expect(row).toBeVisible({ timeout: 30_000 })

	const href = await row.getByTestId('custom-report-run').getAttribute('href')
	expect(href).toBeTruthy()
	const run = await ctx.get(String(href).replace(/^https?:\/\/[^/]+/, ''))
	expect(run.ok(), `run: HTTP ${run.status()}`).toBe(true)
	const lines = (await run.text()).split(/\r?\n/).filter((line) => line.trim() !== '')
	const header = lines[0].split(',').map((cell) => cell.replace(/^"|"$/g, '').trim())
	expect(header.slice(0, FIELDS.length).map((h) => h.toLowerCase())).toEqual(FIELDS.map((f) => f.toLowerCase()))

	const body = lines.slice(1)
	expect(body.some((line) => line.includes(`${RUN_ID} report module 1`))).toBe(true)
	expect(body.some((line) => line.includes(`${RUN_ID} report module 2`))).toBe(false)
	for (const line of body) {
		expect(line, 'every row is a BBN2 application').toContain('BBN2')
	}
})

test('another account\'s report is not listed', async ({ page, playwright }) => {
	const uid = `rep-${RUN_ID}`.slice(0, 60)
	const password = `St-${randomBytes(18).toString('hex')}-Pw1!`
	const made = await ctx.post('/ocs/v2.php/cloud/users?format=json', { data: { password, userid: uid } })
	expect(made.ok(), `account: HTTP ${made.status()}`).toBe(true)
	const other = await playwright.request.newContext({
		baseURL: BASE_URL,
		extraHTTPHeaders: { 'OCS-APIREQUEST': 'true' },
		httpCredentials: { password, send: 'always', username: uid },
	})
	try {
		const theirs = await other.post(PROFILES, {
			data: { ...target, fields: ['name'], format: 'csv', name: `${RUN_ID} someone else's report`, valueMode: 'raw' },
		})
		if (theirs.ok()) {
			profiles.push(idOf(await theirs.json()))
		}

		await page.goto(`${APP_BASE}/reports/custom`)
		await expect(page.getByTestId('custom-report-row').filter({ hasText: `${RUN_ID} BBN2 applications` })).toBeVisible({ timeout: 30_000 })
		await expect(page.getByTestId('custom-report-row').filter({ hasText: `${RUN_ID} someone else's report` })).toHaveCount(0)
	} finally {
		await other.dispose()
		await ctx.delete(`/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}?format=json`).catch(() => undefined)
	}
})

test('delete removes the report', async ({ page }) => {
	await page.goto(`${APP_BASE}/reports/custom`)
	const row = page.getByTestId('custom-report-row').filter({ hasText: `${RUN_ID} BBN2 applications` })
	await expect(row).toBeVisible({ timeout: 30_000 })
	await row.getByTestId('custom-report-delete').click()
	const confirm = page.getByRole('dialog').getByRole('button', { name: /delete|verwijderen/i })
	if (await confirm.count() > 0) {
		await confirm.first().click()
	}
	await expect(row).toHaveCount(0, { timeout: 30_000 })
	const gone = await ctx.get(`${PROFILES}/${ids.mine}`)
	expect(gone.status()).toBe(404)
})
