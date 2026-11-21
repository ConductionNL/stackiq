// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Suggested connections (connections-derived-dependencies): a municipality's
 * new usage gets the known connection to an application it already uses
 * suggested and accepts it, and a usage of a new version of an application
 * gets the connections of the earlier version suggested as carried over.
 *
 * Seeds a municipality carrying this run's RUN_ID (it syncs to an OpenRegister
 * organisation with the same uuid), makes the logged-in administrator a member
 * with that municipality active, because the suggestion endpoints answer only
 * the organisation that owns the usage, and seeds the applications, versions,
 * a national provision, the connections and the usages through the objects
 * API. Setup and teardown use the API; every assertion is made on the rendered
 * page. Afterwards the seeded rows are deleted and the administrator's previous
 * active organisation is restored.
 *
 * The scenarios marked `@e2e exclude` are covered by
 * tests/Unit/Service/ConnectionSuggestionServiceTest.php and
 * tests/Unit/Controller/ConnectionSuggestionControllerTest.php.
 *
 * Written from the change's scenarios; not yet run against a live instance.
 *
 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-001-stackiq-suggests-the-known-connections-of-an-application-inside-the-organisations-landscape
 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-002-a-replacing-usage-is-offered-the-connections-of-the-usage-it-replaces
 */
import type { APIRequestContext, Locator, Page } from '@playwright/test'
import type { VoorzieningenConfig } from './_fixtures.ts'

import { expect, test } from '@playwright/test'
import {
	createObject,
	deleteObject,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from './_fixtures.ts'
import { dismissSupportDialog, gotoAppRoute } from './_ui.ts'

interface AmefConfig {
	register: string
	element_schema: string
}

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
let amef: AmefConfig
const seeded: Array<[string, string, string]> = []
const ids: Record<string, string> = {}
let previousActive = ''
const OR_API = '/index.php/apps/openregister/api'
const municipality = `${RUN_ID} suggesting municipality`
const appX = `${RUN_ID} intake application`
const appY = `${RUN_ID} archive application`
const linkXY = `${RUN_ID} intake to archive`
const appV = `${RUN_ID} permits application`
const appW = `${RUN_ID} payments application`
const provision = `${RUN_ID} national register`
const linkVW = `${RUN_ID} permits to payments`
const linkVNational = `${RUN_ID} permits to national register`

test.describe.configure({ mode: 'serial' })

/**
 * Create a row and remember it for cleanup.
 *
 * @param register The register id.
 * @param schema The schema slug.
 * @param data The object.
 * @return The new id.
 */
async function seed(register: string, schema: string, data: Record<string, unknown>): Promise<string> {
	const id = await createObject(apiCtx, register, schema, data)
	seeded.push([register, schema, id])
	return id
}

/**
 * Read the AMEF register mapping, where the national provisions live.
 *
 * @param ctx An admin API context.
 * @return The register and the element schema.
 */
async function resolveAmefConfig(ctx: APIRequestContext): Promise<AmefConfig> {
	const res = await ctx.get('/index.php/apps/stackiq/api/amef/config')
	expect(res.ok(), `amef/config returned ${res.status()}`).toBe(true)
	const config = (await res.json())?.config ?? {}
	const register = String(config.register ?? config.register_id ?? '')
	expect(register, 'stackiq has no AMEF register mapping').not.toBe('')
	return { register, element_schema: String(config.element_schema || 'element') }
}

/**
 * Wait until a register organisation has synced to an OpenRegister organisation.
 *
 * @param uuid The organisation's uuid.
 * @return Nothing; throws when it never appears.
 */
async function awaitOrganisation(uuid: string): Promise<void> {
	for (let attempt = 0; attempt < 20; attempt++) {
		const res = await apiCtx.get(`${OR_API}/organisations/${uuid}`)
		if (res.ok()) return
		await new Promise((resolve) => setTimeout(resolve, 1000))
	}
	throw new Error(`organisation ${uuid} did not sync to OpenRegister`)
}

/**
 * The Suggested connections panel of a usage page.
 *
 * @param page The page.
 * @return The panel.
 */
function suggestionsPanel(page: Page): Locator {
	return page.locator('.connection-suggestions').first()
}

/**
 * One suggestion in the panel, by the connection's name.
 *
 * @param page The page.
 * @param name The connection's name.
 * @return The suggestion's list item.
 */
function suggestion(page: Page, name: string): Locator {
	return suggestionsPanel(page).locator('li', { hasText: name })
}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	amef = await resolveAmefConfig(apiCtx)
	const reg = cfg.register
	const published = new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString()

	const me = await apiCtx.get('/index.php/apps/stackiq/api/me')
	if (me.ok()) {
		previousActive = (await me.json())?.organisations?.active?.uuid ?? ''
	}

	ids.org = await seed(reg, 'organization', {
		name: municipality,
		type: 'Municipality',
		status: 'Active',
		contactsUid: `${RUN_ID}-suggesting-municipality`,
	})
	await awaitOrganisation(ids.org)
	const join = await apiCtx.post(`${OR_API}/organisations/${ids.org}/join`, { data: {} })
	if (!join.ok() && join.status() !== 409) {
		console.warn(`join ${ids.org} returned ${join.status()}`)
	}
	const active = await apiCtx.post(`${OR_API}/organisations/${ids.org}/set-active`, { data: {} })
	expect(active.ok(), `set-active returned ${active.status()}`).toBe(true)

	// Shared landscape: a published connection X-Y, the municipality uses Y,
	// and the usage of X is what it records now.
	ids.x = await seed(reg, 'module', { name: appX })
	ids.y = await seed(reg, 'module', { name: appY })
	ids.linkXY = await seed(reg, 'connection', {
		name: linkXY,
		moduleA: ids.x,
		moduleB: ids.y,
		type: 'api',
		dataExchangeDirection: 'AtoB',
		status: 'in use',
		publicationDate: published,
	})
	ids.usageY = await seed(reg, 'usage', { consumer: ids.org, module: ids.y, status: 'In production' })
	ids.usageX = await seed(reg, 'usage', { consumer: ids.org, module: ids.x, status: 'In production' })

	// Carry-over: a usage of V at version 1 with connections to W and to a
	// national provision, and a usage of V at version 2.
	ids.v = await seed(reg, 'module', { name: appV })
	ids.w = await seed(reg, 'module', { name: appW })
	ids.v1 = await seed(reg, 'moduleVersion', { module: ids.v, version: '1', status: 'in use' })
	ids.v2 = await seed(reg, 'moduleVersion', { module: ids.v, version: '2', status: 'in use' })
	ids.provision = await seed(amef.register, amef.element_schema, {
		identifier: `id-${RUN_ID}-national-register`,
		type: 'ApplicationComponent',
		name: provision,
		gemmaType: 'Buitengemeentelijke voorziening',
		properties: [],
	})
	ids.linkVW = await seed(reg, 'connection', {
		name: linkVW,
		moduleA: ids.v,
		moduleB: ids.w,
		type: 'api',
		dataExchangeDirection: 'AtoB',
		status: 'in use',
		publicationDate: published,
	})
	ids.linkVNational = await seed(reg, 'connection', {
		name: linkVNational,
		moduleA: ids.v,
		nonMunicipalProvision: ids.provision,
		type: 'digikoppeling',
		dataExchangeDirection: 'AtoB',
		status: 'in use',
		publicationDate: published,
	})
	ids.usageV1 = await seed(reg, 'usage', {
		consumer: ids.org,
		module: ids.v,
		moduleVersion: ids.v1,
		status: 'In production',
		koppelingen: [ids.linkVW, ids.linkVNational],
	})
	ids.usageV2 = await seed(reg, 'usage', {
		consumer: ids.org,
		module: ids.v,
		moduleVersion: ids.v2,
		status: 'Planned',
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [register, schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, register, schema, id)
	}
	if (previousActive !== '') {
		await apiCtx.post(`${OR_API}/organisations/${previousActive}/set-active`, { data: {} })
	}
	await apiCtx.dispose()
})

// @e2e derived-connections::a-municipality-gets-its-connections-suggested
test('the usage of X is offered the connection to Y and accepting adds it to the usage', async ({
	page,
}) => {
	await gotoAppRoute(page, `/gebruik/${ids.usageX}`)
	await dismissSupportDialog(page)
	await expect(page.getByText('Suggested connections').first()).toBeVisible({ timeout: 30000 })
	const item = suggestion(page, linkXY)
	await expect(item).toBeVisible({ timeout: 30000 })
	await expect(item).toContainText('Both applications are in your landscape')

	await item.getByRole('button', { name: `Accept ${linkXY}` }).click()
	await expect(page.getByText('Connections added: 1').first()).toBeVisible({ timeout: 30000 })
	await expect(suggestion(page, linkXY)).toHaveCount(0)

	// After a reload it is one of the usage's connections, no longer a suggestion.
	await page.reload()
	await dismissSupportDialog(page)
	await expect(page.getByText('Suggested connections').first()).toBeVisible({ timeout: 30000 })
	await expect(suggestionsPanel(page).locator('li').first().or(suggestionsPanel(page).getByText(/No suggestions/))).toBeVisible({ timeout: 30000 })
	await expect(suggestion(page, linkXY)).toHaveCount(0)
	await expect(page.getByText(linkXY).first()).toBeVisible({ timeout: 30000 })
})

// @e2e derived-connections::a-new-version-keeps-its-connections
test('the usage of version 2 is offered both connections of version 1 as carried over', async ({
	page,
}) => {
	await gotoAppRoute(page, `/gebruik/${ids.usageV2}`)
	await dismissSupportDialog(page)
	await expect(page.getByText('Suggested connections').first()).toBeVisible({ timeout: 30000 })
	for (const name of [linkVW, linkVNational]) {
		const item = suggestion(page, name)
		await expect(item).toBeVisible({ timeout: 30000 })
		await expect(item).toContainText('Carried over from another version')
	}
})
