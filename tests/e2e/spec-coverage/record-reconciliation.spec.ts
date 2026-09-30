// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Record reconciliation: the demo duplicate pair shows up in OpenRegister's
 * duplicate candidates, an admin is led there from the Applications page and
 * a regular user is not, a merge in OpenRegister moves the usages and
 * connections of the duplicate to the original, and the duplicate leaves the
 * list while its page points to the original.
 *
 * Seeds two applications, a usage and a connection carrying this run's
 * RUN_ID, merges them through OpenRegister's merge API (the call its
 * Duplicate candidates page makes) and removes the rows afterwards. The
 * re-pointing runs as a background job, so the instance must run cron; the
 * test nudges it through cron.php and then waits for the result.
 *
 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md
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
import { dismissSupportDialog, gotoAppRoute } from './_helpers.ts'

const OR_API = '/index.php/apps/openregister/api/objects'

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
const seeded: Array<[string, string]> = []
const ids: Record<string, string> = {}
const original = `${RUN_ID} reconcile original`
const duplicate = `${RUN_ID} reconcile duplicate`

/**
 * Create a row and remember it for cleanup.
 *
 * @param schema The schema slug.
 * @param data The object.
 * @return The new id.
 */
async function seed(schema: string, data: Record<string, unknown>): Promise<string> {
	const id = await createObject(apiCtx, cfg.register, schema, data)
	seeded.push([schema, id])
	return id
}

/**
 * Read one object through the OpenRegister objects API.
 *
 * @param schema The schema slug.
 * @param id The object id.
 * @return The object.
 */
async function read(schema: string, id: string): Promise<Record<string, unknown>> {
	const res = await apiCtx.get(`${OR_API}/${cfg.register}/${schema}/${id}`)
	expect(res.ok(), await res.text()).toBe(true)
	return await res.json()
}

/**
 * The id a reference holds, whether stored as a uuid or as an object.
 *
 * @param ref The reference.
 * @return The id, or an empty string.
 */
function refId(ref: unknown): string {
	if (typeof ref === 'string') return ref
	const o = ref as { id?: string, uuid?: string } | null
	return String(o?.id ?? o?.uuid ?? '')
}

test.describe.configure({ mode: 'serial' })

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	ids.original = await seed('module', { name: original, website: `https://${RUN_ID}.example.org` })
	ids.duplicate = await seed('module', { name: duplicate, website: `https://${RUN_ID}.example.org` })
	ids.org = await seed('organization', { name: `${RUN_ID} municipality`, type: 'Municipality', status: 'Active' })
	ids.usage = await seed('usage', { consumer: ids.org, module: ids.duplicate, status: 'In production' })
	ids.connection = await seed('connection', { name: `${RUN_ID} connection`, moduleA: ids.duplicate })
})

test.afterAll(async () => {
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id).catch(() => {})
	}
	await apiCtx?.dispose()
})

// @e2e record-reconciliation::a-duplicate-application-appears-as-a-candidate
test('the demo duplicate pair is listed as a duplicate candidate for applications', async () => {
	const res = await apiCtx.get(`${OR_API}/duplicates/${cfg.register}/module`)
	expect(res.ok(), await res.text()).toBe(true)
	const text = JSON.stringify(await res.json())
	expect(text).toMatch(/Voorbeeld Name 1/)
	expect(text).toMatch(/Voorbeeld name 1/)
})

// @e2e record-reconciliation::a-functional-administrator-goes-to-the-candidates
test('Find duplicates on the Applications page opens the duplicate candidates', async ({ page }) => {
	await gotoAppRoute(page, '/modules')
	await dismissSupportDialog(page)
	const action = page.getByTestId('find-duplicates')
	await expect(action).toBeVisible({ timeout: 30000 })
	await action.click()
	await expect(page).toHaveURL(/\/apps\/openregister\/duplicates/, { timeout: 30000 })
})

// @e2e record-reconciliation::a-regular-user-does-not-see-the-action
test('a user who is neither admin nor functional administrator does not see Find duplicates', async ({ page }) => {
	// The roles come from the settings endpoint; answering it as a regular
	// user shows what that user gets without a second account.
	await page.route('**/apps/stackiq/api/settings', async (route) => {
		const response = await route.fetch()
		const body = await response.json()
		await route.fulfill({ response, json: { ...body, isAdmin: false, isFunctionalAdmin: false } })
	})
	await gotoAppRoute(page, '/modules')
	await dismissSupportDialog(page)
	await expect(page.getByRole('table').or(page.getByText(/no .*found/i)).first()).toBeVisible({ timeout: 30000 })
	await expect(page.getByTestId('find-duplicates')).toHaveCount(0)
})

// @e2e record-reconciliation::usages-and-connections-follow-the-survivor
test('after a merge the usage and the connection point at the original', async ({ page }) => {
	test.setTimeout(240000)
	const res = await apiCtx.post(`${OR_API}/merge/execute`, {
		data: { from: ids.duplicate, into: ids.original, reason: `${RUN_ID} e2e` },
	})
	expect(res.ok(), await res.text()).toBe(true)
	await apiCtx.get('/cron.php').catch(() => {})
	await expect
		.poll(async () => refId((await read('usage', ids.usage)).module), { timeout: 180000, intervals: [5000] })
		.toBe(ids.original)
	expect(refId((await read('connection', ids.connection)).moduleA)).toBe(ids.original)
	await gotoAppRoute(page, `/modules/${ids.original}`)
	await dismissSupportDialog(page)
	await expect(page.getByText(`${RUN_ID} connection`).first()).toBeVisible({ timeout: 30000 })
})

// @e2e record-reconciliation::a-reader-opens-an-old-link-to-a-merged-application
test('the merged duplicate points to the original and leaves the list', async ({ page }) => {
	await expect
		.poll(async () => (await read('module', ids.duplicate)).recordStatus, { timeout: 60000 })
		.toBe('Merged')
	await gotoAppRoute(page, `/modules/${ids.duplicate}`)
	await dismissSupportDialog(page)
	await expect(page.getByTestId('merged-record-banner')).toBeVisible({ timeout: 30000 })
	await page.getByTestId('merged-record-link').click()
	await expect(page).toHaveURL(new RegExp(`/modules/${ids.original}`), { timeout: 30000 })
	await gotoAppRoute(page, '/modules')
	await dismissSupportDialog(page)
	await expect(page.getByText(original).first()).toBeVisible({ timeout: 30000 })
	await expect(page.getByText(duplicate)).toHaveCount(0)
})
