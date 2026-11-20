// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Playwright e2e for sharing-compliance-documents (REQ-SCD-001, REQ-SCD-002):
 *
 *  - a supplier publishes a processing agreement for all government readers
 *    from the product page, and a municipal information manager sees it;
 *  - a pentest report shared with municipality A only is listed for A and
 *    not for municipality B;
 *  - the documents list filters on the documents that expire within 90 days.
 *
 * Fixtures, created as the admin and removed afterwards: a product with its
 * supplier; three OpenRegister organisations (the supplier, municipality A,
 * municipality B), each with one non-admin user in a catalogue group
 * (`aanbod-beheerder` for the supplier, `ambtenaar` for the municipalities)
 * whose active organisation it is. The users sign in with an empty storage
 * state. A named share lists the reader's OpenRegister organisation, which is
 * what the read rule's `$organisation` resolves to.
 *
 * @spec openspec/changes/sharing-compliance-documents/specs/shared-compliance-documents/spec.md
 */
import type { APIRequestContext, Browser, Page } from '@playwright/test'
import type { VoorzieningenConfig } from './_fixtures.ts'

import { expect, request, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import {
	BASE_URL,
	createObject,
	deleteObject,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from './_fixtures.ts'
import { dismissSupportDialog, gotoAppRoute } from './_ui.ts'

const OR = '/index.php/apps/openregister/api'
const OCS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }
const PASSWORD = `Sq-${randomBytes(10).toString('hex')}-Pw1!`
const USERS = {
	supplier: { uid: `${RUN_ID}-supplier`.slice(0, 60), group: 'aanbod-beheerder' },
	municipalityA: { uid: `${RUN_ID}-gemeente-a`.slice(0, 60), group: 'ambtenaar' },
	municipalityB: { uid: `${RUN_ID}-gemeente-b`.slice(0, 60), group: 'ambtenaar' },
} as const

type Who = keyof typeof USERS

let api: APIRequestContext
let cfg: VoorzieningenConfig
const seeded: Array<[string, string]> = []
const organisations: Partial<Record<Who, string>> = {}
const ids: Record<string, string> = {}

/**
 * Seed one catalogue object and remember it for cleanup.
 *
 * @param schema The schema slug.
 * @param data The fields.
 */
async function seed(schema: string, data: Record<string, unknown>): Promise<string> {
	const id = await createObject(api, cfg.register, schema, data)
	seeded.push([schema, id])
	return id
}

/**
 * An API context signed in as a fixture user.
 *
 * @param who The fixture user.
 */
async function apiAs(who: Who): Promise<APIRequestContext> {
	return request.newContext({
		baseURL: BASE_URL,
		httpCredentials: {
			username: USERS[who].uid,
			password: PASSWORD,
			send: 'always',
		},
		extraHTTPHeaders: OCS,
		storageState: { cookies: [], origins: [] },
	})
}

/**
 * A browser page signed in as a fixture user.
 *
 * @param browser The browser.
 * @param who The fixture user.
 */
async function pageAs(browser: Browser, who: Who): Promise<Page> {
	const context = await browser.newContext({
		baseURL: BASE_URL,
		httpCredentials: {
			username: USERS[who].uid,
			password: PASSWORD,
			send: 'always',
		},
		storageState: { cookies: [], origins: [] },
	})
	return context.newPage()
}

/**
 * The titles of the compliance documents a user may read for the product.
 *
 * @param who The fixture user.
 */
async function readableTitles(who: Who): Promise<string[]> {
	const ctx = await apiAs(who)
	try {
		const res = await ctx.get(
			`${OR}/objects/${cfg.register}/complianceDocument?module=${ids.app}&_limit=100`,
		)
		expect(res.status(), await res.text()).toBe(200)
		const body = await res.json()
		return (body.results ?? []).map((d: { title?: string }) =>
			String(d.title ?? ''),
		)
	} finally {
		await ctx.dispose()
	}
}

/**
 * The product page's compliance documents section.
 *
 * @param page A signed-in page.
 */
async function openProductDocuments(page: Page) {
	await gotoAppRoute(page, `/modules/${ids.app}`)
	await dismissSupportDialog(page)
	const section = page
		.locator('.cn-widget, section, .cn-dashboard-widget')
		.filter({ has: page.getByText('Compliance documents', { exact: true }) })
		.first()
	await expect(section).toBeVisible({ timeout: 30_000 })
	return section
}

test.describe.configure({ mode: 'serial' })

test.beforeAll(async () => {
	api = await newApiContext()
	cfg = await resolveConfig(api)

	for (const who of Object.keys(USERS) as Who[]) {
		const { uid, group } = USERS[who]
		await api.post('/ocs/v2.php/cloud/groups?format=json', {
			headers: OCS,
			data: { groupid: group },
		})
		const user = await api.post('/ocs/v2.php/cloud/users?format=json', {
			headers: OCS,
			data: { userid: uid, password: PASSWORD, groups: [group] },
		})
		expect(user.ok(), `provision ${uid}: ${await user.text()}`).toBe(true)

		const org = await api.post(`${OR}/organisations`, {
			headers: OCS,
			data: { name: `${RUN_ID} ${who}` },
		})
		expect(
			org.status(),
			`organisation for ${who}: ${await org.text()}`,
		).toBeLessThan(300)
		const body = await org.json()
		const uuid = String(body.organisation?.uuid ?? body.uuid ?? body.id)
		organisations[who] = uuid
		const joined = await api.post(`${OR}/organisations/${uuid}/join`, {
			headers: OCS,
			data: { userId: uid },
		})
		expect(joined.status(), `join ${uid}: ${await joined.text()}`).toBeLessThan(
			300,
		)
		const ctx = await apiAs(who)
		const active = await ctx.post(`${OR}/organisations/${uuid}/set-active`)
		expect(
			active.status(),
			`set-active ${uid}: ${await active.text()}`,
		).toBeLessThan(300)
		await ctx.dispose()
	}

	ids.supplier = await seed('organization', {
		name: `${RUN_ID} supplier`,
		type: 'Supplier',
		status: 'Active',
		contactsUid: `${RUN_ID}-supplier`,
	})
	ids.app = await seed('module', {
		name: `${RUN_ID} zaaksysteem`,
		shortDescription: `${RUN_ID} product with compliance documents`,
		provider: ids.supplier,
	})
})

test.afterAll(async () => {
	if (!api) return
	const docs = await api.get(
		`${OR}/objects/${cfg.register}/complianceDocument?module=${ids.app}&_limit=100`,
	)
	for (const doc of ((await docs.json().catch(() => ({}))).results
		?? []) as Array<{ id: string }>) {
		await deleteObject(api, cfg.register, 'complianceDocument', String(doc.id))
	}
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(api, cfg.register, schema, id)
	}
	for (const who of Object.keys(USERS) as Who[]) {
		await api
			.delete(`/ocs/v2.php/cloud/users/${USERS[who].uid}?format=json`, {
				headers: OCS,
			})
			.catch(() => undefined)
		if (organisations[who]) {
			await api
				.delete(`${OR}/organisations/${organisations[who]}`)
				.catch(() => undefined)
		}
	}
	await api.dispose()
})

// @e2e shared-compliance-documents::a-supplier-publishes-a-processing-agreement-for-all-government-readers
test('a supplier publishes a processing agreement for all government readers', async ({
	browser,
}) => {
	const title = `${RUN_ID} Verwerkersovereenkomst`
	const nextYear = `${new Date().getFullYear() + 1}-06-30`

	const supplier = await pageAs(browser, 'supplier')
	try {
		const section = await openProductDocuments(supplier)
		await section
			.getByRole('button', { name: /publish a compliance document/i })
			.click()
		const dialog = supplier.locator('[role="dialog"], .modal-container').first()
		await dialog.waitFor({ state: 'visible', timeout: 15_000 })

		await dialog.getByLabel('Document type').click()
		await supplier
			.locator('.vs__dropdown-option, [role="option"]', {
				hasText: 'processing agreement',
			})
			.first()
			.click()
		await dialog.getByLabel('Title', { exact: true }).fill(title)
		await dialog.getByLabel('Valid until').fill(nextYear)
		await dialog.getByLabel('Audience').click()
		await supplier
			.locator('.vs__dropdown-option, [role="option"]', {
				hasText: 'government',
			})
			.first()
			.click()
		await dialog.getByRole('button', { name: /^(create|save)$/i }).click()
		await expect(section.getByText(title)).toBeVisible({ timeout: 20_000 })
	} finally {
		await supplier.context().close()
	}

	expect(await readableTitles('municipalityA')).toContain(title)

	const reader = await pageAs(browser, 'municipalityA')
	try {
		const section = await openProductDocuments(reader)
		const row = section
			.locator('tr, li, [class*=row]')
			.filter({ hasText: title })
			.first()
		await expect(row).toBeVisible({ timeout: 20_000 })
		await expect(row).toContainText('processing agreement')
	} finally {
		await reader.context().close()
	}
})

// @e2e shared-compliance-documents::a-pentest-report-stays-with-the-municipalities-it-was-shared-with
test('a pentest report shared with municipality A is not listed for municipality B', async ({
	browser,
}) => {
	const title = `${RUN_ID} Pentestrapport 2026`
	const supplierApi = await apiAs('supplier')
	try {
		const res = await supplierApi.post(
			`${OR}/objects/${cfg.register}/complianceDocument`,
			{
				data: {
					module: ids.app,
					documentType: 'pentest report',
					title,
					summary: 'Black-box test of the citizen portal',
					issuedOn: '2026-10-01',
					audience: 'named',
					sharedWithOrganisations: [organisations.municipalityA],
				},
			},
		)
		expect(res.status(), await res.text()).toBeLessThan(300)
	} finally {
		await supplierApi.dispose()
	}

	expect(
		await readableTitles('supplier'),
		'the publisher reads its own document',
	).toContain(title)
	expect(await readableTitles('municipalityA')).toContain(title)
	expect(await readableTitles('municipalityB')).not.toContain(title)

	const outsider = await pageAs(browser, 'municipalityB')
	try {
		const section = await openProductDocuments(outsider)
		await expect(section.getByText(title)).toHaveCount(0)
	} finally {
		await outsider.context().close()
	}

	const insider = await pageAs(browser, 'municipalityA')
	try {
		const section = await openProductDocuments(insider)
		await expect(section.getByText(title)).toBeVisible({ timeout: 20_000 })
	} finally {
		await insider.context().close()
	}
})

// REQ-SCD-001: "a documents list SHALL filter ... on documents that expire within 90 days"
test('the documents list keeps only what expires within 90 days', async ({
	page,
}) => {
	const day = (offset: number) =>
		new Date(Date.now() + offset * 86_400_000).toISOString().slice(0, 10)
	const soon = `${RUN_ID} Certificaat ISO 27001`
	const later = `${RUN_ID} Assurancerapport ISAE 3402`
	for (const [title, documentType, validUntil] of [
		[soon, 'certificate', day(30)],
		[later, 'assurance report', day(400)],
	]) {
		const res = await api.post(
			`${OR}/objects/${cfg.register}/complianceDocument`,
			{
				data: {
					module: ids.app,
					documentType,
					title,
					validUntil,
					audience: 'public',
				},
			},
		)
		expect(res.status(), await res.text()).toBeLessThan(300)
		const body = await res.json()
		expect(body.expiresWithin90Days, `${title} is materialised on save`).toBe(
			validUntil === day(30),
		)
	}

	await gotoAppRoute(
		page,
		`/compliance-documents?_search=${encodeURIComponent(RUN_ID)}`,
	)
	await dismissSupportDialog(page)
	await expect(page.getByText(later).first()).toBeVisible({ timeout: 30_000 })
	await page.getByText('Expiring within 90 days', { exact: true }).first().click()
	await expect(page.getByText(soon).first()).toBeVisible({ timeout: 20_000 })
	await expect(page.getByText(later)).toHaveCount(0)
})
