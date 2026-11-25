// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Documentation screenshots: the pictures the feature pages under docs/features
 * refer to, captured from a running instance into docs/images/.
 *
 * Run on purpose, never in the regular suite (it writes into the repository):
 *
 *     PLAYWRIGHT_BASE_URL=http://localhost:8080 npx playwright test --project docs --output test-results/docs
 *
 * It seeds its own small scenario through the API first: one application with
 * two components, two APIs, three compliance documents and connections to two
 * other applications, one organisation that uses all three, and a
 * confirmation round over that organisation's usages. Every seeded name starts
 * with SEED_PREFIX, and an existing object with that name is reused, so a
 * second run does not duplicate anything.
 *
 * Needs the voorzieningen register imported (tests/e2e/ci-seed.sh) and,
 * for the confirmation round and the decision review, the users `anna` and
 * `bas` in the group software-catalog-users (password in DOCS_REVIEWER_PASS,
 * default Docs-shot-2026).
 *
 * @spec openspec/changes/archive/2026-11-25-landscape-application-components/tasks.md
 * @spec openspec/changes/archive/2026-11-25-connections-api-catalogue/tasks.md
 * @spec openspec/changes/sharing-compliance-documents/tasks.md
 * @spec openspec/changes/archive/2026-11-25-connections-diagram-and-graph-export/tasks.md
 * @spec openspec/changes/archive/2026-11-25-landscape-change-entry-type/tasks.md
 * @spec openspec/changes/archive/2026-11-25-landscape-move-between-organisations/tasks.md
 * @spec openspec/changes/archive/2026-11-25-landscape-completeness-score/tasks.md
 * @spec openspec/changes/archive/2026-11-25-landscape-owner-attestation/tasks.md
 * @spec openspec/changes/archive/2026-11-25-connections-derived-dependencies/tasks.md
 * @spec openspec/changes/archive/2026-11-25-sharing-generated-api-docs/tasks.md
 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/tasks.md
 * @spec openspec/changes/archive/2026-11-25-architecture-round-trip-check/tasks.md
 * @spec openspec/changes/architecture-decision-register/tasks.md
 * @spec openspec/changes/archive/2026-11-25-architecture-assistant-drafted-views/tasks.md
 * @spec openspec/changes/archive/2026-11-25-operations-sync-status-and-progress/tasks.md
 * @spec openspec/changes/archive/2026-11-25-architecture-reference-component-coverage/tasks.md
 */

import type {APIRequestContext, Locator, Page, Route} from '@playwright/test';

import { expect, request as playwrightRequest, test } from '@playwright/test'
import * as path from 'path'

const IMAGES = path.resolve(__dirname, '../../../docs/images')
const APP = '/index.php/apps/stackiq'
const OR_API = '/index.php/apps/openregister/api'
const OBJECTS = `${OR_API}/objects`
const SEED_PREFIX = 'Voorbeeldstad'
const NC_ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const NC_ADMIN_PASS = process.env.NC_ADMIN_PASS ?? 'admin'
// The reviewer `anna` must exist with this password (OC_PASS=... occ user:add anna).
const REVIEWER_PASSWORD = process.env.DOCS_REVIEWER_PASS ?? 'Docs-shot-2026'

type Obj = Record<string, unknown> & { id: string }

/** The voorzieningen register id, read from the app's own config. */
async function registerId(request: APIRequestContext): Promise<string> {
	const response = await request.get(`${APP}/api/voorzieningen/config`)
	expect(response.ok()).toBeTruthy()
	const body = await response.json()
	return String(body.config.register)
}

/**
 * Find an object of a schema by its key fields, or create it.
 *
 * @param request  The authenticated API context.
 * @param register The register id.
 * @param schema   The schema slug.
 * @param data     The object.
 * @param keys     The fields that identify it (default: name, else title).
 */
async function ensure(request: APIRequestContext, register: string, schema: string, data: Record<string, unknown>, keys?: string[]): Promise<Obj> {
	const fields = keys ?? ['name' in data ? 'name' : 'title']
	const params: Record<string, string> = { _limit: '50' }
	fields.forEach((field) => { params[field] = String(data[field]) })
	const found = await request.get(`${OBJECTS}/${register}/${schema}`, { params })
	if (found.ok()) {
		const results = ((await found.json()).results ?? []) as Obj[]
		const idOf = (value: unknown): unknown => (value !== null && typeof value === 'object' ? (value as Obj).id : value)
		const match = results.find((o) => fields.every((field) => idOf(o[field]) === data[field]))
		if (match) {
			return match
		}
	}

	const created = await request.post(`${OBJECTS}/${register}/${schema}`, { data })
	expect(created.status(), `create ${schema} ${fields.map((f) => String(data[f])).join('/')}: ${await created.text()}`).toBeLessThan(300)
	return (await created.json()) as Obj
}

/** An API context with basic auth and no session cookie, so writes need no CSRF requesttoken. */
async function api(baseURL: string | undefined): Promise<APIRequestContext> {
	return playwrightRequest.newContext({
		baseURL,
		storageState: { cookies: [], origins: [] },
		httpCredentials: { username: NC_ADMIN_USER, password: NC_ADMIN_PASS, send: 'always' },
		extraHTTPHeaders: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
	})
}

/**
 * The uuid of the OpenRegister organisation with this name, created when missing.
 *
 * Suggested connections are shown to the usage's own organisation only: the
 * caller's active OpenRegister organisation must be the usage's consumer, so
 * the catalogue organisation shares this uuid.
 */
async function orOrganisation(request: APIRequestContext, name: string): Promise<string> {
	const list = await request.get(`${OR_API}/organisations`)
	expect(list.ok()).toBeTruthy()
	const found = (((await list.json()).results ?? []) as Array<{ uuid: string, name: string }>).find((o) => o.name === name)
	if (found) {
		return found.uuid
	}
	const created = await request.post(`${OR_API}/organisations`, { data: { name, description: 'Example organisation for the documentation screenshots.' } })
	expect(created.status(), await created.text()).toBeLessThan(300)
	const body = await created.json()
	return String((body.organisation ?? body).uuid)
}

/** An object with a given id, created with that id when missing. */
async function ensureById(request: APIRequestContext, register: string, schema: string, data: Record<string, unknown> & { id: string }): Promise<Obj> {
	const found = await request.get(`${OBJECTS}/${register}/${schema}/${data.id}`)
	if (found.ok()) {
		return (await found.json()) as Obj
	}
	const created = await request.post(`${OBJECTS}/${register}/${schema}`, { data })
	expect(created.status(), `create ${schema} ${data.id}: ${await created.text()}`).toBeLessThan(300)
	return (await created.json()) as Obj
}

/** Kill animations and the auto-opening support dialog so every shot is stable. */
async function settle(page: Page): Promise<void> {
	await page.addStyleTag({
		content: `*, *::before, *::after { animation-duration: 0s !important; transition-duration: 0s !important; caret-color: transparent !important; }
			[data-testid-modal="cn-support-dialog"], .cn-support-dialog, .toastify { display: none !important; }`,
	})
	await page.waitForLoadState('domcontentloaded')
	await expect(page.locator('.loading-icon, .cn-skeleton')).toHaveCount(0, { timeout: 20_000 }).catch(() => undefined)
}

/** Screenshot one element into docs/images. */
async function shoot(locator: Locator, file: string): Promise<void> {
	await locator.scrollIntoViewIfNeeded()
	await locator.screenshot({ path: path.join(IMAGES, file), animations: 'disabled' })
}

/** A manifest widget on a detail page, by its id (rendered as a group named after it). */
function widget(page: Page, id: string): Locator {
	return page.getByRole('group', { name: id, exact: true })
}

/** A custom body widget on a detail page, by its title: the innermost card holding its heading and region. */
function bodyWidget(page: Page, title: string): Locator {
	return page.locator('div, section')
		.filter({ has: page.getByRole('heading', { name: title, exact: true }) })
		.filter({ has: page.getByRole('region', { name: title, exact: true }) })
		.last()
}

/** Scroll a lazily rendered widget into view so it loads. */
async function reveal(locator: Locator): Promise<Locator> {
	await locator.scrollIntoViewIfNeeded()
	// A widget below the fold loads once it is in view: wait for its spinner to go.
	await expect(locator.locator('.loading-icon')).toHaveCount(0, { timeout: 20_000 })
	return locator
}

test.describe.configure({ mode: 'serial' })

test.describe('documentation screenshots', () => {
	let register: string
	let main: Obj
	let usage: Obj
	let organisation: Obj

	test.use({ viewport: { width: 1280, height: 900 } })

	test.beforeAll(async ({ baseURL }) => {
		const request = await api(baseURL)
		register = await registerId(request)

		const orgName = `Gemeente ${SEED_PREFIX}`
		organisation = await ensureById(request, register, 'organization', { id: await orOrganisation(request, orgName), name: orgName, type: 'Municipality' })
		const supplier = await ensure(request, register, 'organization', { name: `${SEED_PREFIX} Software B.V.`, type: 'Supplier' })
		const anna = await ensure(request, register, 'contactPerson', { contactsUid: 'anna', role: 'Information manager', organization: organisation.id }, ['contactsUid'])
		const bas = await ensure(request, register, 'contactPerson', { contactsUid: 'bas', role: 'Application manager', organization: organisation.id }, ['contactsUid'])

		main = await ensure(request, register, 'module', {
			name: `${SEED_PREFIX} Zaaksysteem`,
			shortDescription: 'Registers and follows cases from intake to archive.',
			licentietype: 'Closed source',
			provider: supplier.id,
			type: 'Application',
		})
		const documents = await ensure(request, register, 'module', { name: `${SEED_PREFIX} Documentbeheer`, shortDescription: 'Stores and archives documents.', provider: supplier.id, type: 'Application' })
		const contact = await ensure(request, register, 'module', { name: `${SEED_PREFIX} Klantcontactsysteem`, shortDescription: 'Records contact moments with residents.', provider: supplier.id, type: 'Application' })
		await ensure(request, register, 'module', { name: `${SEED_PREFIX} Zaaksysteem portaal`, shortDescription: 'Lets residents follow their own cases.', partOf: main.id, provider: supplier.id, type: 'Application' })
		await ensure(request, register, 'module', { name: `${SEED_PREFIX} Zaaksysteem koppelvlak`, shortDescription: 'Exposes cases to other applications.', partOf: main.id, provider: supplier.id, type: 'Application' })

		const zaken = await ensure(request, register, 'applicationInterface', { name: 'Zaken API', module: main.id, style: 'REST', version: '1.5.1', status: 'in development', specificationUrl: 'https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/' })
		await ensure(request, register, 'applicationInterface', { name: 'Zaakstatus berichten', module: main.id, style: 'event or message', version: '1.0', status: 'in development' })

		await ensure(request, register, 'connection', { name: 'Zaaksysteem naar Documentbeheer', moduleA: main.id, moduleB: documents.id, dataExchangeDirection: 'AtoB', type: 'api', status: 'in development', interface: zaken.id })
		await ensure(request, register, 'connection', { name: 'Klantcontact naar Zaaksysteem', moduleA: contact.id, moduleB: main.id, dataExchangeDirection: 'bi-directional', type: 'digikoppeling', status: 'in development' })

		const year = new Date().getFullYear()
		await ensure(request, register, 'complianceDocument', { title: 'DPIA Zaaksysteem', documentType: 'DPIA', module: main.id, publisher: organisation.id, audience: 'government', issuedOn: `${year}-02-01`, validUntil: `${year + 2}-02-01`, summary: 'Data protection impact assessment of case handling.' })
		await ensure(request, register, 'complianceDocument', { title: 'Pentest rapport', documentType: 'pentest report', module: main.id, publisher: supplier.id, audience: 'named', issuedOn: `${year}-05-15`, validUntil: `${year + 1}-05-15`, summary: 'Yearly penetration test.' })
		await ensure(request, register, 'complianceDocument', { title: 'Verwerkersovereenkomst', documentType: 'processing agreement', module: main.id, publisher: supplier.id, audience: 'public', issuedOn: `${year}-01-01`, validUntil: `${year + 3}-01-01` })

		// The organisation uses all three; only the documents usage records its
		// connection, so the case system's usage gets suggestions.
		usage = await ensure(request, register, 'usage', { consumer: organisation.id, module: main.id, status: 'Acquisition', businessOwner: anna.id, contactPerson: bas.id }, ['consumer', 'module'])
		await ensure(request, register, 'usage', { consumer: organisation.id, module: documents.id, status: 'Acquisition', businessOwner: anna.id }, ['consumer', 'module'])
		await ensure(request, register, 'usage', { consumer: organisation.id, module: contact.id, status: 'Acquisition' }, ['consumer', 'module'])

		const rounds = await request.get(`${OBJECTS}/${register}/attestationRound`, { params: { name: `${SEED_PREFIX} Q4`, _limit: '1' } })
		const existing = rounds.ok() ? (((await rounds.json()).results ?? []) as Obj[]).filter((r) => r.name === `${SEED_PREFIX} Q4`) : []
		if (existing.length === 0) {
			const started = await request.post(`${APP}/api/attestation-rounds`, {
				data: { organisation: organisation.id, scopeSchema: 'usage', deadline: `${year + 1}-01-15`, name: `${SEED_PREFIX} Q4` },
			})
			expect(started.status(), await started.text()).toBeLessThan(300)
		}

		await request.dispose()
	})

	test('application components', async ({ page }) => {
		await page.goto(`${APP}/modules/${main.id}`)
		await settle(page)
		const card = await reveal(widget(page, 'md-components'))
		await expect(card.getByText(`${SEED_PREFIX} Zaaksysteem portaal`)).toBeVisible()
		await shoot(card, 'application-components.png')
	})

	test('application APIs', async ({ page }) => {
		await page.goto(`${APP}/modules/${main.id}`)
		await settle(page)
		const card = await reveal(widget(page, 'md-apis'))
		await expect(card.getByText('Zaken API')).toBeVisible()
		await shoot(card, 'application-apis.png')
	})

	test('compliance documents', async ({ page }) => {
		await page.goto(`${APP}/modules/${main.id}`)
		await settle(page)
		const card = await reveal(widget(page, 'md-compliance-documents'))
		await expect(card.getByText('DPIA Zaaksysteem')).toBeVisible()
		await shoot(card, 'compliance-documents.png')
	})

	test('connection map', async ({ page }) => {
		await page.goto(`${APP}/modules/${main.id}`)
		await settle(page)
		const card = await reveal(bodyWidget(page, 'Connection map'))
		await expect(card.getByText('could not be loaded')).toHaveCount(0)
		await expect(card.getByText(`${SEED_PREFIX} Documentbeheer`).first()).toBeVisible()
		await shoot(card, 'connection-map.png')
	})

	test('change entry type', async ({ page }) => {
		await page.goto(`${APP}/modules/${main.id}`)
		await settle(page)
		const card = await reveal(bodyWidget(page, 'Entry type'))
		await card.getByRole('button', { name: 'Change type' }).click()
		const dialog = page.getByRole('dialog').last()
		await expect(dialog).toBeVisible()
		await settle(page)
		await shoot(dialog, 'change-entry-type.png')
	})

	test('move to organisation', async ({ page }) => {
		await page.goto(`${APP}/modules/${main.id}`)
		await settle(page)
		const direct = page.getByRole('button', { name: 'Move to organisation' })
		if (await direct.count() === 0) {
			await page.getByRole('button', { name: 'Actions' }).first().click()
			await page.getByRole('menuitem', { name: 'Move to organisation' }).click()
		} else {
			await direct.first().click()
		}

		const dialog = page.getByRole('dialog').last()
		await expect(dialog).toBeVisible()
		await settle(page)
		await shoot(dialog, 'move-to-organisation.png')
	})

	test('data quality report', async ({ page }) => {
		await page.goto(`${APP}/datakwaliteit`)
		await settle(page)
		// The report is per organisation: pick the example one.
		await page.getByRole('combobox').first().click()
		await page.keyboard.type(`Gemeente ${SEED_PREFIX}`)
		await expect(page.getByRole('option').first()).toBeVisible()
		await page.keyboard.press('Enter')
		await expect(page.getByText('Select an organisation')).toHaveCount(0)
		await expect(page.locator('main .loading-icon, #app-content-vue .loading-icon')).toHaveCount(0, { timeout: 30_000 })
		await expect(page.getByText('Business owner').first()).toBeVisible()
		await shoot(page.locator('#app-content, main').first(), 'data-quality-report.png')
	})

	test('confirmation round', async ({ page }) => {
		await page.goto(`${APP}/confirmation-rounds`)
		await settle(page)
		await page.getByRole('link', { name: `${SEED_PREFIX} Q4`, exact: true }).first().click()
		await page.waitForURL(/confirmation-rounds\/.+/)
		await settle(page)
		await shoot(page.locator('#app-content, main').first(), 'confirmation-round.png')
	})

	test('connection suggestions', async ({ page }) => {
		// Only the usage's own organisation sees its suggestions. OpenRegister keeps
		// the active organisation per session, so switch it in the browser's own.
		const setActive = (uuid: string) => page.evaluate(async (url) => {
			const response = await fetch(url, {
				method: 'POST',
				headers: { requesttoken: (window as unknown as { OC: { requestToken: string } }).OC.requestToken, 'OCS-APIRequest': 'true' },
			})
			return response.ok
		}, `${OR_API}/organisations/${uuid}/set-active`)

		await page.goto(`${APP}/`)
		const active = await page.evaluate(async (url) => (await (await fetch(url)).json()).activeOrganisation?.uuid ?? null, `${OR_API}/organisations/active`)
		expect(await setActive(organisation.id)).toBeTruthy()
		try {
			await page.goto(`${APP}/gebruik/${usage.id}`)
			await settle(page)
			const card = await reveal(bodyWidget(page, 'Suggested connections'))
			await expect(card.getByText('Klantcontact naar Zaaksysteem').first()).toBeVisible()
			await shoot(card, 'connection-suggestions.png')
		} finally {
			if (active) {
				await setActive(active)
			}
		}
	})

	test('faceted export menu', async ({ page }) => {
		await page.goto(`${APP}/modules`)
		await settle(page)
		await page.locator('main').getByRole('button', { name: 'Export', exact: true }).first().click()
		await expect(page.getByRole('menuitem').first()).toBeVisible()
		await page.screenshot({ path: path.join(IMAGES, 'export-menu.png'), clip: { x: 310, y: 50, width: 960, height: 560 }, animations: 'disabled' })
	})

	test('organisation ArchiMate export action', async ({ page }) => {
		await page.goto(`${APP}/organisaties/${organisation.id}`)
		await settle(page)
		await expect(page.getByText('Export as ArchiMate', { exact: true }).first()).toBeVisible()
		await page.screenshot({ path: path.join(IMAGES, 'organisation-archimate-export.png'), clip: { x: 310, y: 50, width: 960, height: 260 }, animations: 'disabled' })
	})

	test('custom reports', async ({ page, baseURL }) => {
		// One report over Applications, made the way the New report dialog saves it.
		const reportName = `${SEED_PREFIX} applications`
		const request = await api(baseURL)
		const config = (await (await request.get(`${APP}/api/voorzieningen/config`)).json()).config
		const profiles = await (await request.get(`${OR_API}/export-profiles`)).json()
		const list = (Array.isArray(profiles) ? profiles : (profiles.results ?? [])) as Array<{ name: string }>
		if (!list.some((profile) => profile.name === reportName)) {
			const created = await request.post(`${OR_API}/export-profiles`, {
				data: { name: reportName, registerId: Number(config.register), schemaId: Number(config.module_schema), fields: ['name', 'licentietype', 'bbnLevel'], valueMode: 'rendered', format: 'csv', filters: [] },
			})
			expect(created.status(), await created.text()).toBeLessThan(300)
		}
		await request.dispose()

		await page.goto(`${APP}/reports/custom`)
		await settle(page)
		await expect(page.getByText(reportName).first()).toBeVisible()
		await shoot(page.locator('#app-content, main').first(), 'custom-reports.png')
	})

	test('model round-trip check', async ({ page }) => {
		await page.goto('/index.php/settings/admin/stackiq')
		await settle(page)
		const section = page.locator('div, section')
			.filter({ has: page.getByText('Check a model file', { exact: true }) })
			.filter({ has: page.getByRole('button', { name: 'Check', exact: true }) })
			.last()
		await section.scrollIntoViewIfNeeded()
		await section.locator('input[type="file"]').setInputFiles(path.resolve(__dirname, '../../fixtures/archimate/big1.xml'))
		await section.getByRole('button', { name: 'Check', exact: true }).click()
		await expect(section.getByText(/No losses found|Losses in/).first()).toBeVisible({ timeout: 60_000 })
		await shoot(section, 'archimate-round-trip-check.png')
	})

	test('architecture decision, its review and the list on a usage', async ({ page, browser, baseURL }) => {
		// A decision by admin, reviewed by Anna, about the case system's usage.
		const request = await api(baseURL)
		const active = (await (await request.get(`${OR_API}/organisations/active`)).json()).activeOrganisation?.uuid
		await request.post(`${OR_API}/organisations/${active}/join`, { data: { userId: 'anna' } })
		const title = `${SEED_PREFIX}: one case system for all case types`
		let decision = await ensure(request, register, 'architectureDecision', {
			title,
			context: 'Three departments run their own case handling, each with its own links to the document store.',
			decision: 'All case types move to the one case system; the department systems are phased out by the end of next year.',
			consequences: 'One set of connections to maintain; the departments migrate their open cases.',
			alternatives: [{ option: 'Keep the department systems', reasonRejected: 'Three times the connections and licences.' }],
			category: 'application',
			impact: 'high',
			reviewer: 'anna',
			applications: [usage.id],
			status: 'draft',
		})
		if (decision.status === 'draft') {
			const submitted = await request.patch(`${OBJECTS}/${register}/architectureDecision/${decision.id}`, { data: { status: 'in review' } })
			expect(submitted.status(), await submitted.text()).toBeLessThan(300)
			decision = (await submitted.json()) as Obj
		}
		await request.dispose()

		await page.goto(`${APP}/architectuurbesluiten/${decision.id}`)
		await settle(page)
		await expect(page.getByText(title).first()).toBeVisible()
		await shoot(page.locator('#app-content, main').first(), 'architecture-decision.png')

		// The review step, as the reviewer.
		const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] }, viewport: { width: 1280, height: 900 } })
		const reviewer = await context.newPage()
		await reviewer.goto('/login')
		await reviewer.fill('#user', 'anna')
		await reviewer.fill('#password', REVIEWER_PASSWORD)
		await reviewer.click('button[type=submit]')
		await reviewer.waitForURL(/apps/)
		await reviewer.goto(`${APP}/architectuurbesluiten/${decision.id}`)
		await settle(reviewer)
		await expect(reviewer.getByText(title).first()).toBeVisible()
		// Accept and Reject are the reviewer's actions in the page's Actions menu.
		await reviewer.getByRole('button', { name: 'Actions' }).first().click()
		await expect(reviewer.getByRole('menuitem', { name: /Accept/ }).first()).toBeVisible()
		await reviewer.screenshot({ path: path.join(IMAGES, 'architecture-decision-review.png'), clip: { x: 310, y: 50, width: 960, height: 420 }, animations: 'disabled' })
		await context.close()

		// The list on the application in use it names.
		await page.goto(`${APP}/gebruik/${usage.id}`)
		await settle(page)
		const card = await reveal(widget(page, 'gb-decisions'))
		await expect(card.getByText(title).first()).toBeVisible()
		await shoot(card, 'usage-architecture-decisions.png')
	})

	test('a view drafted by an assistant', async ({ page, baseURL }) => {
		// Drafted through the real stackiq.draftView tool on OpenRegister's MCP
		// endpoint, as Hermiq does in the signed-in user's session.
		const request = await api(baseURL)
		const mcp = `${OR_API}/mcp`
		const accept = { Accept: 'application/json, text/event-stream', 'Content-Type': 'application/json' }
		const init = await request.post(mcp, { headers: accept, data: { jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'docs', version: '1.0.0' } } } })
		expect(init.ok()).toBeTruthy()
		const session = init.headers()['mcp-session-id']
		const called = await request.post(mcp, {
			headers: { ...accept, ...(session ? { 'Mcp-Session-Id': session } : {}) },
			data: {
				jsonrpc: '2.0',
				id: 2,
				method: 'tools/call',
				params: {
					name: 'stackiq.draftView',
					arguments: {
						name: `${SEED_PREFIX}: how the case system talks to document management`,
						description: 'Drafted from: draw how our case system talks to document management.',
						elements: [
							{ key: 'case', type: 'ApplicationComponent', name: `${SEED_PREFIX} Zaaksysteem` },
							{ key: 'docs', type: 'ApplicationComponent', name: `${SEED_PREFIX} Documentbeheer` },
							{ key: 'api', type: 'ApplicationInterface', name: 'Zaken API' },
						],
						relations: [
							{ source: 'case', target: 'api', type: 'Composition' },
							{ source: 'api', target: 'docs', type: 'Serving' },
						],
					},
				},
			},
		})
		const raw = (await called.text()).trim()
		const message = JSON.parse(raw.startsWith('{') ? raw : (raw.split('\n').filter((line) => line.startsWith('data:')).pop() ?? '').slice(5))
		const draft = JSON.parse(message.result.content.find((part: { type: string }) => part.type === 'text').text)
		expect(draft.uuid, JSON.stringify(draft)).toBeTruthy()
		await request.dispose()

		await page.goto(`${APP}/views/${draft.uuid}`)
		await settle(page)
		await expect(page.getByText(/Drafted by an assistant for/).first()).toBeVisible({ timeout: 30_000 })
		await shoot(page.locator('#app-content, main').first(), 'architecture-view-drafted.png')
	})

	test('synchronisation status: the last run and a running sync', async ({ page, baseURL }) => {
		// A new active organisation and a sync started by hand, so the last run
		// shows real work: the organisation processed and its entity created.
		const request = await api(baseURL)
		// An organisation starts as Draft and is activated through its lifecycle.
		const created = await request.post(`${OBJECTS}/${register}/organization`, { data: { name: `Gemeente ${SEED_PREFIX}-Zuid ${Date.now()}`, type: 'Municipality' } })
		expect(created.status(), await created.text()).toBeLessThan(300)
		const activated = await request.patch(`${OBJECTS}/${register}/organization/${(await created.json()).id}`, { data: { status: 'Active' } })
		expect(activated.status(), await activated.text()).toBeLessThan(300)
		const synced = await request.post(`${APP}/api/settings/sync`, { data: { minutesBack: 5 } })
		expect(synced.status(), await synced.text()).toBeLessThan(300)
		await request.dispose()

		await page.goto(`${APP}/organisaties/synchronisation`)
		await settle(page)
		const main = page.locator('main').first()
		await expect(main.getByText('Last run', { exact: true }).first()).toBeVisible({ timeout: 30_000 })
		await shoot(page.locator('#app-content, main').first(), 'sync-status-last-run.png')

		// A run lasts seconds, so the running state is shown by adding a running
		// operation to the page's real status answer.
		await page.route('**/apps/stackiq/api/sync/status*', async (route: Route) => {
			const real = await (await route.fetch()).json()
			await route.fulfill({ json: { ...real, current: { phase: 'processing_contacts', percentage: 40, processed_items: 12, total_items: 30, trigger: 'manual' } } })
		})
		await page.reload()
		await settle(page)
		await expect(main.getByText('Processing contact persons')).toBeVisible({ timeout: 30_000 })
		await shoot(page.locator('#app-content, main').first(), 'sync-status-running.png')
	})

	test('GEMMA plate, coverage on a view and the overlap in the portfolio report', async ({ page, baseURL }) => {
		// A second municipality whose usages are in production and name the
		// reference components they are used for: two applications for
		// document registration (overlap), one for case registration
		// (covered), and a contact system whose module declares the customer
		// interaction component its usage does not name (a fillable gap).
		const request = await api(baseURL)
		const amef = (await (await request.get(`${APP}/api/amef/config`)).json()).config
		const element = (name: string, identifier: string, grouping: string): Promise<Obj> => ensure(request, String(amef.register), String(amef.element_schema || 'element'), {
			identifier, type: 'ApplicationComponent', properties: [], name, gemmaType: 'referentiecomponent', grouping,
		})
		const zrc = await element('Zaakregistratiecomponent', 'e2e-gemma-referentiecomponent-zaakregistratie', 'Mid-office')
		const drc = await element('Documentregistratiecomponent', 'voorbeeldstad-documentregistratiecomponent', 'Back-office')
		const kic = await element('Klantinteractiecomponent', 'voorbeeldstad-klantinteractiecomponent', 'Front-office')
		const orgName = `Gemeente ${SEED_PREFIX}-Noord`
		const north = await ensure(request, register, 'organization', { name: orgName, type: 'Municipality' })
		const byName = async (name: string): Promise<Obj> => ensure(request, register, 'module', { name })
		const documents = await byName(`${SEED_PREFIX} Documentbeheer`)
		const contact = await byName(`${SEED_PREFIX} Klantcontactsysteem`)
		await request.patch(`${OBJECTS}/${register}/module/${contact.id}`, { data: { referenceComponents: [kic.id] } })
		// A usage starts in Acquisition (which does not count); the administrator moves it into production.
		const inProduction = async (module: Obj, components: string[]): Promise<void> => {
			const row = await ensure(request, register, 'usage', { consumer: north.id, module: module.id, status: 'Acquisition', usedForReferenceComponents: components }, ['consumer', 'module'])
			if (row.status !== 'In production') {
				const moved = await request.patch(`${OBJECTS}/${register}/usage/${row.id}`, { data: { status: 'In production' } })
				expect(moved.status(), await moved.text()).toBeLessThan(300)
			}
		}
		await inProduction(main, [zrc.id, drc.id])
		await inProduction(documents, [drc.id])
		await inProduction(contact, [])
		await request.dispose()

		const pick = async (): Promise<void> => {
			await page.getByRole('combobox', { name: 'Organisation' }).fill(orgName)
			await page.locator('.vs__dropdown-option', { hasText: orgName }).first().click()
		}

		// The view the map draws on: this instance imports no GEMMA views, so
		// the view list and the view are answered here; the coverage on its
		// nodes is the real coverage of the rows above.
		const viewId = 'voorbeeldstad-zaakgericht-werken'
		const viewName = 'Zaakgericht werken (example)'
		const node = (id: string, identifier: string, name: string, x: number): Record<string, unknown> => ({
			viewNodeId: id, elementRef: `id-${identifier}`, modelNodeId: `id-${identifier}`, name, x, y: 0, width: 220, height: 110,
		})
		await page.route('**/apps/stackiq/api/views', (route: Route) => route.fulfill({ json: { success: true, views: [{ id: viewId, name: viewName }] } }))
		await page.route(`**/apps/stackiq/api/views/${viewId}*`, (route: Route) => route.fulfill({
			json: {
				success: true,
				view: {
					id: viewId,
					name: viewName,
					viewNodes: [
						node('n-kic', 'voorbeeldstad-klantinteractiecomponent', 'Klantinteractiecomponent', 0),
						node('n-zrc', 'e2e-gemma-referentiecomponent-zaakregistratie', 'Zaakregistratiecomponent', 280),
						node('n-drc', 'voorbeeldstad-documentregistratiecomponent', 'Documentregistratiecomponent', 560),
					],
					viewRelationships: [],
				},
			},
		}))

		await page.goto(`${APP}/gemma-plaat`)
		await settle(page)
		await pick()
		const plate = page.getByTestId('gemma-plate-groups')
		await expect(plate.getByRole('button', { name: /Documentregistratiecomponent/ })).toHaveAttribute('data-state', 'overlap', { timeout: 30_000 })
		await expect(plate.getByRole('button', { name: /Documentregistratiecomponent/ })).toContainText(`${SEED_PREFIX} Documentbeheer`)
		await shoot(page.locator('#app-content, main').first(), 'gemma-plate-coverage.png')

		const map = page.getByTestId('coverage-view-map')
		await map.getByRole('combobox', { name: 'GEMMA view' }).click()
		await page.locator('.vs__dropdown-option', { hasText: viewName }).first().click()
		await expect(map.locator('.coverage-view-map__node').filter({ hasText: 'Documentregistratiecomponent' })).toHaveAttribute('data-state', 'overlap', { timeout: 30_000 })
		await shoot(map, 'gemma-plate-coverage-map.png')

		await page.goto(`${APP}/portfolio-report`)
		await settle(page)
		await pick()
		const overlap = page.getByTestId('pr-overlap')
		await expect(overlap.getByTestId('pr-overlap-row').filter({ hasText: 'Documentregistratiecomponent' })).toBeVisible({ timeout: 30_000 })
		await shoot(overlap, 'portfolio-report-overlap.png')
	})

	test('API documentation', async ({ page }) => {
		await page.goto(`${APP}/api-docs`)
		await settle(page)
		await shoot(page.locator('#app-content, main').first(), 'api-documentation.png')
	})
})
