// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * A supplier answers the usages claimed of its products
 * (supplier-accepts-claimed-usage, tasks 2 and 3; REQ-010).
 *
 * Surfaces under test:
 *   src/components/organisations/ClaimedUsageList.vue  "Claimed usage of our
 *     offering" on OrganisatieDetail, shown when the page's organisation is
 *     the user's active one, with Accept and Decline per claim and the
 *     DeclineClaimDialog confirmation;
 *   src/components/portfolio/ClaimAnswerNotice.vue  the declined notice on
 *     the claiming organisation's usage page;
 *   `PUT /api/aanbod/claimed/{uuid}/accept`, refused for any active
 *     organisation that is not the usage's provider.
 *
 * Seeds, through the objects API, a supplier and two municipalities (each
 * register organisation syncs to an OpenRegister organisation with the same
 * uuid), two applications of the supplier with one version each, and three
 * usages the municipalities register of them with the supplier as provider,
 * all carrying this run's RUN_ID. The logged-in administrator joins the three
 * organisations; the usages are created while a municipality is active, so
 * that municipality owns them, and the supplier is made active to answer.
 * Afterwards the seeded rows are deleted and the administrator's previous
 * active organisation is restored.
 *
 * The change's scenarios carry no `@e2e` line; this file covers the three
 * REQ-010 scenarios that tasks.md points at it.
 *
 * Written from the change's scenarios; not yet run against a live instance.
 *
 * @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
 */
import type { APIRequestContext } from '@playwright/test'
import type { VoorzieningenConfig } from '../workflows/_fixtures.ts'

import { expect, test } from '@playwright/test'
import {
	createObject,
	deleteObject,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from '../workflows/_fixtures.ts'
import { gotoAppRoute } from './_helpers.ts'

const OR_API = '/index.php/apps/openregister/api'
const CLAIMED = '/index.php/apps/stackiq/api/aanbod/claimed'
const supplierName = `${RUN_ID} supplier`
const northName = `${RUN_ID} municipality north`
const eastName = `${RUN_ID} municipality east`

let api: APIRequestContext
let config: VoorzieningenConfig
let previousActive = ''
const seeded: Array<[string, string]> = []
const ids: Record<string, string> = {}

// The tests share the seeded claims and the active organisation: in order, in one worker.
test.describe.configure({ mode: 'serial' })

/**
 * Create a row and remember it for cleanup.
 *
 * @param schema The schema slug.
 * @param data The object.
 * @return The new id.
 */
async function seed(schema: string, data: Record<string, unknown>): Promise<string> {
	const id = await createObject(api, config.register, schema, data)
	seeded.push([schema, id])
	return id
}

/**
 * Wait until a register organisation has synced to an OpenRegister organisation.
 *
 * @param uuid The organisation's uuid.
 * @return Nothing; throws when it never appears.
 */
async function awaitOrganisation(uuid: string): Promise<void> {
	for (let attempt = 0; attempt < 20; attempt++) {
		const res = await api.get(`${OR_API}/organisations/${uuid}`)
		if (res.ok()) return
		await new Promise((resolve) => setTimeout(resolve, 1000))
	}
	throw new Error(`organisation ${uuid} did not sync to OpenRegister`)
}

/**
 * Make the administrator a member of an organisation; already being one is fine.
 *
 * @param uuid The organisation's uuid.
 * @return Nothing.
 */
async function join(uuid: string): Promise<void> {
	const res = await api.post(`${OR_API}/organisations/${uuid}/join`, { data: {} })
	expect(res.ok() || res.status() === 409, `join ${uuid} returned ${res.status()}`).toBe(true)
}

/**
 * Make an organisation the administrator's active one.
 *
 * @param uuid The organisation's uuid.
 * @return Nothing; throws when refused.
 */
async function setActive(uuid: string): Promise<void> {
	const res = await api.post(`${OR_API}/organisations/${uuid}/set-active`, { data: {} })
	expect(res.ok(), `set-active ${uuid} returned ${res.status()}`).toBe(true)
}

/**
 * Read one usage through the objects API.
 *
 * @param id The usage's id.
 * @return The object.
 */
async function readUsage(id: string): Promise<Record<string, unknown>> {
	const res = await api.get(`${OR_API}/objects/${config.register}/usage/${id}`)
	expect(res.ok(), await res.text()).toBe(true)
	return await res.json()
}

test.beforeAll(async () => {
	api = await newApiContext()
	config = await resolveConfig(api)

	const me = await api.get('/index.php/apps/stackiq/api/me')
	if (me.ok()) {
		previousActive = (await me.json())?.organisations?.active?.uuid ?? ''
	}

	ids.supplier = await seed('organization', { name: supplierName, type: 'Supplier', status: 'Active', contactsUid: `${RUN_ID}-supplier` })
	ids.north = await seed('organization', { name: northName, type: 'Municipality', status: 'Active', contactsUid: `${RUN_ID}-north` })
	ids.east = await seed('organization', { name: eastName, type: 'Municipality', status: 'Active', contactsUid: `${RUN_ID}-east` })
	for (const uuid of [ids.supplier, ids.north, ids.east]) {
		await awaitOrganisation(uuid)
		await join(uuid)
	}

	await setActive(ids.supplier)
	ids.suite = await seed('module', { name: `${RUN_ID} case suite`, provider: ids.supplier })
	ids.suiteVersion = await seed('moduleVersion', { module: ids.suite, version: '10.4', status: 'in use' })
	ids.finance = await seed('module', { name: `${RUN_ID} finance suite`, provider: ids.supplier })
	ids.financeVersion = await seed('moduleVersion', { module: ids.finance, version: '2026.1', status: 'in use' })

	// Created while the municipality is active, so it owns the usage.
	await setActive(ids.north)
	ids.accepted = await seed('usage', { consumer: ids.north, provider: ids.supplier, module: ids.suite, moduleVersion: ids.suiteVersion, status: 'In production' })
	ids.refused = await seed('usage', { consumer: ids.north, provider: ids.supplier, module: ids.finance, moduleVersion: ids.financeVersion, status: 'In production' })
	await setActive(ids.east)
	ids.declined = await seed('usage', { consumer: ids.east, provider: ids.supplier, module: ids.finance, moduleVersion: ids.financeVersion, status: 'In production' })
})

test.afterAll(async () => {
	if (!api) return
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(api, config.register, schema, id)
	}
	if (previousActive !== '') {
		await api.post(`${OR_API}/organisations/${previousActive}/set-active`, { data: {} })
	}
	await api.dispose()
})

// @e2e aanbod-listings::a-supplier-accepts-a-claim
test('a member of the supplier accepts a claim on its organisation page and the claim leaves the list', async ({ page }) => {
	await setActive(ids.supplier)
	await gotoAppRoute(page, `/organisaties/${ids.supplier}`)

	const list = page.getByTestId('claimed-usage-list')
	await expect(list).toBeVisible({ timeout: 30000 })
	await expect(list).toContainText('Claimed usage of our offering')
	const claim = list.getByTestId(`claim-${ids.accepted}`)
	await expect(claim).toBeVisible({ timeout: 30000 })
	await expect(claim).toContainText(northName)
	await expect(claim).toContainText(`${RUN_ID} case suite`)
	await expect(claim).toContainText('10.4')
	await expect(claim).toContainText(/\d{4}-\d{2}-\d{2}/)

	await list.getByTestId(`claim-accept-${ids.accepted}`).click()
	await expect(list.getByTestId(`claim-${ids.accepted}`)).toHaveCount(0, { timeout: 30000 })

	const usage = await readUsage(ids.accepted)
	expect(usage.providerResponse).toBe('accepted')
	expect(String(usage.providerResponseAt ?? '')).not.toBe('')
	expect(String(usage.providerResponseBy ?? '')).not.toBe('')
})

// @e2e aanbod-listings::a-supplier-declines-a-claim
test('a declined claim stays the municipality\'s usage and its page says the supplier does not recognise it', async ({ page }) => {
	await setActive(ids.supplier)
	await gotoAppRoute(page, `/organisaties/${ids.supplier}`)

	const list = page.getByTestId('claimed-usage-list')
	await expect(list.getByTestId(`claim-${ids.declined}`)).toBeVisible({ timeout: 30000 })
	await list.getByTestId(`claim-decline-${ids.declined}`).click()
	const dialog = page.getByTestId('decline-claim-dialog')
	await expect(dialog).toBeVisible({ timeout: 15000 })
	await expect(dialog).toContainText(eastName)
	await page.getByTestId('decline-claim-confirm').click()
	await expect(list.getByTestId(`claim-${ids.declined}`)).toHaveCount(0, { timeout: 30000 })

	const usage = await readUsage(ids.declined)
	expect(usage.providerResponse).toBe('declined')
	expect((usage['@self'] as { organisation?: string } | undefined)?.organisation).toBe(ids.east)

	await setActive(ids.east)
	await gotoAppRoute(page, `/gebruik/${ids.declined}`)
	const notice = page.getByTestId('claim-declined-notice')
	await expect(notice).toBeVisible({ timeout: 30000 })
	await expect(notice).toContainText('The supplier does not recognise this usage')
	await expect(notice).toContainText(/\d{4}-\d{2}-\d{2}/)
})

// @e2e aanbod-listings::another-organisation-cannot-answer
test('accepting a claim with a municipality active is refused with 403 and nothing is written', async ({ page }) => {
	await setActive(ids.north)
	const res = await api.put(`${CLAIMED}/${ids.refused}/accept`)
	expect(res.status(), await res.text()).toBe(403)
	expect((await readUsage(ids.refused)).providerResponse ?? null).toBeNull()

	// Nor does the municipality's own page offer the supplier's list.
	await gotoAppRoute(page, `/organisaties/${ids.supplier}`)
	await expect(page.getByText(supplierName).first()).toBeVisible({ timeout: 30000 })
	await expect(page.getByTestId('claimed-usage-list')).toHaveCount(0)
})
