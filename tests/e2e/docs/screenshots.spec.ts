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
 * for the confirmation round, the users `anna` and `bas`.
 *
 * @spec openspec/changes/landscape-application-components/tasks.md
 * @spec openspec/changes/connections-api-catalogue/tasks.md
 * @spec openspec/changes/sharing-compliance-documents/tasks.md
 * @spec openspec/changes/connections-diagram-and-graph-export/tasks.md
 * @spec openspec/changes/landscape-change-entry-type/tasks.md
 * @spec openspec/changes/landscape-move-between-organisations/tasks.md
 * @spec openspec/changes/landscape-completeness-score/tasks.md
 * @spec openspec/changes/landscape-owner-attestation/tasks.md
 * @spec openspec/changes/connections-derived-dependencies/tasks.md
 * @spec openspec/changes/sharing-generated-api-docs/tasks.md
 */

import type {APIRequestContext, Locator, Page} from '@playwright/test';

import { expect, request as playwrightRequest, test } from '@playwright/test'
import * as path from 'path'

const IMAGES = path.resolve(__dirname, '../../../docs/images')
const APP = '/index.php/apps/stackiq'
const OR_API = '/index.php/apps/openregister/api'
const OBJECTS = `${OR_API}/objects`
const SEED_PREFIX = 'Voorbeeldstad'
const NC_ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const NC_ADMIN_PASS = process.env.NC_ADMIN_PASS ?? 'admin'

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
			[data-testid-modal="cn-support-dialog"], .cn-support-dialog { display: none !important; }`,
	})
	await page.waitForLoadState('networkidle')
	await expect(page.locator('.loading-icon, .cn-skeleton').first()).toBeHidden({ timeout: 20_000 }).catch(() => undefined)
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
	await locator.page().waitForLoadState('networkidle')
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

	test('API documentation', async ({ page }) => {
		await page.goto(`${APP}/api-docs`)
		await settle(page)
		await shoot(page.locator('#app-content, main').first(), 'api-documentation.png')
	})
})
