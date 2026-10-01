// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * E2e coverage for openspec/changes/cmdb-export-import (the "CMDB import"
 * section of stackiq's Nextcloud admin settings).
 *
 * Every scenario the spec tags `@e2e tests/e2e/spec-coverage/cmdb-import.spec.ts`
 * is driven here through the REAL settings page: the NcSelect municipality
 * chooser, the real file input (`setInputFiles`), the Import button and the
 * rendered report. The API is used for setup (a run-unique municipality),
 * for the "no object written" checks, and for cleanup.
 *
 * The municipality is created per run (`Gemeente Voorbeeldstad <RUN_ID>`),
 * because the import's match key is scoped to the municipality: a fixed name
 * would make the first import of a second run report `unchanged`, not
 * `created`.
 *
 * The anonymised fixtures come from the backend task
 * (tests/fixtures/cmdb/, see its README). When one is absent the test that
 * needs it is skipped with a message naming the missing file.
 *
 * Owner contacts the import creates in the admin's Nextcloud address book
 * are not removed by the cleanup below; the OpenRegister objects are.
 *
 * The last test checks, without signing in, that the imported owners are
 * not readable anonymously: neither through OpenRegister's objects API nor
 * in an OpenCatalogi search hit (skipped when OpenCatalogi is not installed).
 */

import type { APIRequestContext, Locator, Page, Response } from '@playwright/test'
import type { VoorzieningenConfig } from '../workflows/_fixtures.ts'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import {
	BASE_URL,
	createObject,
	deleteObject,
	findAll,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from '../workflows/_fixtures.ts'

const FIXTURES_DIR = path.resolve(__dirname, '../../fixtures/cmdb')
const EXPORT_FIXTURE = path.join(FIXTURES_DIR, 'topdesk-export-anonymised.xlsx')
const MISSING_COLUMN_FIXTURE = path.join(FIXTURES_DIR, 'topdesk-missing-appid.xlsx')
// The owner values the anonymised export holds (tests/fixtures/cmdb/README.md).
const OWNER_VALUES = ['Achternaam', 'Voornaam', 'Teamleider Applicatiebeheer']

type UploadFile = Parameters<Locator['setInputFiles']>[0]

const MUNICIPALITY_NAME = `Gemeente Voorbeeldstad ${RUN_ID}`
const IMPORT_PATH = '/index.php/apps/stackiq/api/cmdb-import'
// The page builds its URL with generateUrl(), which drops `/index.php` on an
// instance with pretty URLs, so the browser-side matchers use the path tail.
const IMPORT_ROUTE = '**/apps/stackiq/api/cmdb-import'

/**
 * Whether a response is the answer to the import upload.
 *
 * @param response The response
 */
function isImportAnswer(response: Response): boolean {
	return (
		new URL(response.url()).pathname.endsWith('/apps/stackiq/api/cmdb-import')
		&& response.request().method() === 'POST'
	)
}

let config: VoorzieningenConfig
let municipalityUuid = ''

/**
 * Skip the calling test when a fixture is not there yet.
 *
 * @param file The fixture path
 */
function requireFixture(file: string): void {
	test.skip(
		!fs.existsSync(file),
		`Fixture ${path.relative(process.cwd(), file)} is missing; it is produced by Task 1 of openspec/changes/cmdb-export-import (tests/fixtures/cmdb/build-fixtures.py).`,
	)
}

/**
 * All objects of a schema whose data mentions the run's municipality: its
 * usages (consumer), modules (externalKey) and contact persons (organization).
 *
 * @param ctx The API context
 * @param schema The schema id
 */
async function objectsOfMunicipality(
	ctx: APIRequestContext,
	schema: string,
): Promise<Array<Record<string, unknown>>> {
	const rows = await findAll(ctx, config.register, schema)
	return rows.filter((row) => JSON.stringify(row).includes(municipalityUuid))
}

/**
 * Count the objects the import can write for the run's municipality.
 *
 * @param ctx The API context
 */
async function countWritten(ctx: APIRequestContext): Promise<{
	modules: number
	usages: number
	contactPersons: number
	municipalities: number
}> {
	const municipalities = (
		await findAll(ctx, config.register, config.organisatie_schema)
	).filter((org) => org.type === 'Municipality')
	return {
		modules: (await objectsOfMunicipality(ctx, config.module_schema)).length,
		usages: (await objectsOfMunicipality(ctx, config.gebruik_schema)).length,
		contactPersons: (
			await objectsOfMunicipality(ctx, config.contactpersoon_schema)
		).length,
		municipalities: municipalities.length,
	}
}

/**
 * Get Nextcloud's own first-run wizard out of the way.
 *
 * It opens on an admin's first visit to a fresh instance, and its modal mask
 * intercepts every click, so the chooser below would time out on a click
 * that reads like a broken select. It has no close button and ignores
 * Escape until its last slide, so it is marked as seen through its own
 * route (what finishing it does) and the page is loaded again. A bounded
 * wait, because the wizard mounts after the page.
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

/**
 * Pick the run's municipality in the chooser, the way an admin does.
 *
 * @param page The page
 */
async function chooseMunicipality(page: Page): Promise<void> {
	const input = page.locator('#cmdb-import-municipality')
	await input.click()
	await input.fill(MUNICIPALITY_NAME)
	await page
		.getByRole('option')
		// hasText, not the accessible name: NcSelect splits a long option
		// into two spans for its middle ellipsis.
		.filter({ hasText: MUNICIPALITY_NAME })
		.first()
		.click()
	await expect(
		page.locator('[data-testid="cmdb-import-municipality"] .vs__selected'),
	).toContainText(MUNICIPALITY_NAME)
}

/**
 * Read one summary count from the rendered report.
 *
 * @param page The page
 * @param key The summary key (created, unchanged, …)
 */
function summaryValue(page: Page, key: string) {
	return page.locator(
		`[data-testid="cmdb-import-summary-${key}"] .cmdb-import__tile-value`,
	)
}

/**
 * The rendered report rows.
 *
 * @param page The page
 */
function reportRows(page: Page) {
	return page.locator(
		'[data-testid="cmdb-import-rows"] [data-testid="cn-object-row"]',
	)
}

/**
 * Choose a file, press Import and wait for the import request to answer.
 *
 * @param page The page
 * @param file The file to upload
 */
async function runImport(page: Page, file: UploadFile) {
	await page.locator('[data-testid="cmdb-import-file"]').setInputFiles(file)
	const answer = page.waitForResponse(isImportAnswer, { timeout: 120000 })
	await page.locator('[data-testid="cmdb-import-start"]').click()
	return await answer
}

test.describe.serial('CMDB import section', () => {
	test.beforeAll(async () => {
		const ctx = await newApiContext()
		try {
			config = await resolveConfig(ctx)
			municipalityUuid = await createObject(
				ctx,
				config.register,
				config.organisatie_schema,
				{ name: MUNICIPALITY_NAME, type: 'Municipality', status: 'Active' },
			)
		} finally {
			await ctx.dispose()
		}
	})

	test.afterAll(async () => {
		if (!municipalityUuid) {
			return
		}
		const ctx = await newApiContext()
		try {
			for (const schema of [
				config.gebruik_schema,
				config.contactpersoon_schema,
				config.module_schema,
			]) {
				for (const row of await objectsOfMunicipality(ctx, schema)) {
					const id = String(
						row.id
							?? (row['@self'] as { id?: string } | undefined)?.id
							?? '',
					)
					if (id !== '') {
						await deleteObject(ctx, config.register, schema, id)
					}
				}
			}
			await deleteObject(
				ctx,
				config.register,
				config.organisatie_schema,
				municipalityUuid,
			)
		} finally {
			await ctx.dispose()
		}
	})

	// @e2e cmdb-export-import::the-admin-runs-an-import-from-the-settings-page
	// @e2e cmdb-export-import::the-admin-picks-an-existing-municipality
	// @e2e cmdb-export-import::upload-with-a-per-row-report
	test('an admin imports the anonymised export for an existing municipality', async ({
		page,
	}) => {
		requireFixture(EXPORT_FIXTURE)
		const ctx = await newApiContext()
		const before = await countWritten(ctx)

		const section = await gotoCmdbSection(page)
		await chooseMunicipality(page)

		// Hold the import request for a moment so the running state is
		// observable. route.continue() forwards the original multipart body;
		// route.fetch() would re-send it without the file.
		await page.route(IMPORT_ROUTE, async (route) => {
			await new Promise((resolve) => setTimeout(resolve, 1500))
			await route.continue()
		})
		await page
			.locator('[data-testid="cmdb-import-file"]')
			.setInputFiles(EXPORT_FIXTURE)
		const answer = page.waitForResponse(isImportAnswer, { timeout: 120000 })
		await page.locator('[data-testid="cmdb-import-start"]').click()

		// A progress bar shows while the import runs.
		const progress = section.locator('[data-testid="cmdb-import-progress"]')
		await expect(progress).toBeVisible()
		await expect(progress.getByRole('progressbar')).toBeVisible()
		await expect(
			section.locator('[data-testid="cmdb-import-cancel"]'),
		).toBeVisible()

		const response = await answer
		expect(response.status()).toBe(200)
		await page.unroute(IMPORT_ROUTE)
		await expect(progress).toBeHidden({ timeout: 30000 })

		// Summary: 2 rows read, 2 created.
		await expect(
			section.locator('[data-testid="cmdb-import-summary"]'),
		).toBeVisible()
		await expect(summaryValue(page, 'rowsRead')).toHaveText('2')
		await expect(summaryValue(page, 'created')).toHaveText('2')

		// The report lists exactly the two data rows, not the hundreds of
		// formatted but empty rows below them, each created with a module link.
		const rows = reportRows(page)
		await expect(rows).toHaveCount(2)
		for (const [sheet, appId, name] of [
			['Onbeh Applicaties CMDB', '1234', 'Aangetekend Mailen'],
			['Beheerde Applicaties CMDB', '2', 'naamtest123'],
		]) {
			const row = rows.filter({ hasText: name })
			await expect(row).toHaveCount(1)
			await expect(row).toContainText(sheet)
			await expect(row.locator('td').nth(1)).toHaveText('2')
			await expect(row.locator('td').nth(2)).toHaveText(appId)
			await expect(row.locator('[data-outcome="created"]')).toBeVisible()
			await expect(
				row.locator('[data-testid="cmdb-import-module-link"]'),
			).toHaveAttribute('href', /\/apps\/stackiq\/modules\/[0-9a-f-]{36}$/)
		}

		// Filtering the table on `created` shows the two imported rows.
		await section.locator('#cmdb-import-outcome-filter').click()
		await page
			.getByRole('option')
			.filter({ hasText: /^\s*(Created|Aangemaakt)\s*$/ })
			.first()
			.click()
		await expect(rows).toHaveCount(2)

		// Both usages point at the chosen municipality, and no new
		// municipality was created.
		const after = await countWritten(ctx)
		expect(after.usages - before.usages).toBe(2)
		expect(after.modules - before.modules).toBe(2)
		expect(after.municipalities).toBe(before.municipalities)
		const usages = await objectsOfMunicipality(ctx, config.gebruik_schema)
		for (const usage of usages) {
			expect(String(usage.consumer)).toContain(municipalityUuid)
		}
		await ctx.dispose()
	})

	// @e2e cmdb-export-import::re-importing-the-same-export-creates-no-duplicates
	test('importing the same export again reports both rows unchanged', async ({
		page,
	}) => {
		requireFixture(EXPORT_FIXTURE)
		const ctx = await newApiContext()
		const before = await countWritten(ctx)
		test.skip(
			before.modules === 0,
			'The first import (previous test) wrote nothing for this municipality, so there is nothing to re-import.',
		)

		const section = await gotoCmdbSection(page)
		await chooseMunicipality(page)
		const response = await runImport(page, EXPORT_FIXTURE)
		expect(response.status()).toBe(200)

		await expect(summaryValue(page, 'rowsRead')).toHaveText('2')
		await expect(summaryValue(page, 'created')).toHaveText('0')
		await expect(summaryValue(page, 'unchanged')).toHaveText('2')
		await expect(
			reportRows(page).locator('[data-outcome="unchanged"]'),
		).toHaveCount(2)
		await expect(
			section.locator('[data-testid="cmdb-import-error"]'),
		).toHaveCount(0)

		// Same number of modules, usages, contact persons and municipalities.
		expect(await countWritten(ctx)).toEqual(before)
		await ctx.dispose()
	})

	// @e2e cmdb-export-import::a-missing-required-column-is-named-in-the-422-response
	test('an export without a required column names the column and the sheet', async ({
		page,
	}) => {
		requireFixture(MISSING_COLUMN_FIXTURE)
		const ctx = await newApiContext()
		const before = await countWritten(ctx)

		const section = await gotoCmdbSection(page)
		await chooseMunicipality(page)
		const response = await runImport(page, MISSING_COLUMN_FIXTURE)

		expect(response.status()).toBe(422)
		const body = await response.json()
		expect(body.error).toBe('MISSING_COLUMN')
		expect(body.details).toEqual({
			sheet: 'Beheerde Applicaties CMDB',
			column: 'APPID',
		})

		const error = section.locator('[data-testid="cmdb-import-error"]')
		await expect(error).toBeVisible()
		await expect(error).toContainText('"Beheerde Applicaties CMDB"')
		await expect(error).toContainText('"APPID"')
		await expect(error).toContainText('MISSING_COLUMN')
		await expect(
			section.locator('[data-testid="cmdb-import-report"]'),
		).toHaveCount(0)

		expect(await countWritten(ctx)).toEqual(before)
		await ctx.dispose()
	})

	// @e2e cmdb-export-import::a-file-that-is-not-xlsx-is-rejected
	test('a CSV, or a text file named .xlsx, is rejected as not xlsx', async ({
		page,
	}) => {
		const ctx = await newApiContext()
		const before = await countWritten(ctx)
		const csv = {
			name: 'applications.csv',
			mimeType: 'text/csv',
			buffer: Buffer.from('APPID;Applicatie Naam\n2;naamtest123\n'),
		}
		const textAsXlsx = {
			name: 'export.xlsx',
			mimeType:
				'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			buffer: Buffer.from('APPID;Applicatie Naam\n2;naamtest123\n'),
		}

		// The endpoint answers 400 NOT_XLSX for both.
		for (const file of [csv, textAsXlsx]) {
			const res = await ctx.post(IMPORT_PATH, {
				multipart: {
					cmdbFile: file,
					municipalityUuid,
				},
			})
			expect(res.status(), file.name).toBe(400)
			expect((await res.json()).error, file.name).toBe('NOT_XLSX')
		}

		// The section shows the NOT_XLSX message for both: for the CSV before
		// anything is sent, for the text file from the server's answer.
		const section = await gotoCmdbSection(page)
		await chooseMunicipality(page)
		const error = section.locator('[data-testid="cmdb-import-error"]')

		await page.locator('[data-testid="cmdb-import-file"]').setInputFiles(csv)
		await expect(error).toBeVisible()
		await expect(error).toContainText('NOT_XLSX')
		await expect(error).toContainText('.xlsx')

		const response = await runImport(page, textAsXlsx)
		expect(response.status()).toBe(400)
		await expect(error).toBeVisible()
		await expect(error).toContainText('NOT_XLSX')

		// No module, usage, contact person or municipality was written.
		expect(await countWritten(ctx)).toEqual(before)
		await ctx.dispose()
	})

	// @e2e cmdb-export-import::imported-owners-are-never-readable-anonymously
	test('the imported owners are not readable without signing in', async () => {
		requireFixture(EXPORT_FIXTURE)
		const ctx = await newApiContext()
		const written = await countWritten(ctx)
		await ctx.dispose()
		test.skip(
			written.contactPersons === 0,
			'The first import (first test) wrote no owner for this municipality, so there is nothing to look for.',
		)

		// Inside the test runner a new request context inherits the project's
		// `use` options, including the admin storageState; clear it explicitly.
		const anonymous = await playwrightRequest.newContext({
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
		})
		try {
			// Prove the context is anonymous before trusting an empty answer.
			const whoami = await anonymous.get(
				'/ocs/v2.php/cloud/user?format=json',
				{
					headers: { 'OCS-APIRequest': 'true' },
				},
			)
			expect(whoami.status(), 'the context must not be signed in').toBe(401)

			// OpenRegister: no contact person and no usage for an anonymous caller.
			for (const schema of [
				config.contactpersoon_schema,
				config.gebruik_schema,
			]) {
				const res = await anonymous.get(
					`/index.php/apps/openregister/api/objects/${config.register}/${schema}?_limit=200`,
				)
				if (res.ok()) {
					const body = await res.json()
					expect(
						body.total ?? (body.results ?? []).length,
						`schema ${schema}`,
					).toBe(0)
				} else {
					expect([401, 403], `schema ${schema}`).toContain(res.status())
				}
			}

			// OpenCatalogi: a search hit for an imported module names nobody.
			const search = await anonymous.get(
				'/index.php/apps/opencatalogi/api/search?_search=naamtest123&_limit=50',
			)
			test.skip(
				search.status() === 404,
				'OpenCatalogi is not installed on this instance.',
			)
			expect(search.ok()).toBe(true)
			const hits = ((await search.json()).results ?? []) as Array<
				Record<string, unknown>
			>
			for (const hit of hits) {
				const text = JSON.stringify(hit)
				for (const value of OWNER_VALUES) {
					expect(text, `search hit ${String(hit.id)}`).not.toContain(value)
				}
				for (const field of ['contactPerson', 'usages']) {
					const value = hit[field]
					const ids = Array.isArray(value) ? value : [value]
					for (const id of ids) {
						expect(
							id === null
								|| id === undefined
								|| typeof id === 'string',
							`${field} of search hit ${String(hit.id)} is an id or empty`,
						).toBe(true)
					}
				}
			}
		} finally {
			await anonymous.dispose()
		}
	})
})
