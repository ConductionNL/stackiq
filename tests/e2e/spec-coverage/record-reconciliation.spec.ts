// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Record reconciliation (operations-record-reconciliation, tasks 4 and 5).
 *
 * Surfaces under test:
 *   src/views/FacetedCatalogIndexView.vue  the Find duplicates button
 *     (`data-testid="find-duplicates"`) on /modules, shown when
 *     `GET /api/me` answers `mayFindDuplicates`, linking to OpenRegister's
 *     /duplicates page;
 *   src/components/merge/MergedRecordBanner.vue  the `md-merged` widget on
 *     ModuleDetail, "Merged into <name>" linking to the survivor.
 *
 * Seeds, through the objects API, one supplier and three applications
 * carrying this run's RUN_ID: an original, a likely duplicate of it (same
 * supplier and website, the name in another case), and a merged copy whose
 * `recordStatus` is Merged and whose `mergedInto` is the original, the state
 * CatalogueMergeRelinker leaves after OpenRegister's merge. Removes exactly
 * those rows, and the regular user the access test makes, afterwards.
 *
 * The two scenarios that run on OpenRegister's Duplicate candidates page and
 * its merge wizard are test.fixme: that page belongs to OpenRegister, so its
 * register and schema pickers, pair rows and wizard buttons cannot be read
 * from this repository.
 *
 * Written from the change's scenarios; not yet run against a live instance.
 *
 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-001-applications-services-and-organisations-shall-declare-duplicate-rules-and-applications-and-services-shall-declare-how-they-merge
 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-002-the-catalogue-pages-shall-lead-an-administrator-to-openregisters-duplicate-candidates
 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-003-after-openregister-merges-two-applications-or-services-every-catalogue-reference-shall-point-at-the-survivor
 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-004-merged-applications-and-services-shall-leave-the-lists-and-point-readers-to-the-survivor
 */
import type { APIRequestContext } from '@playwright/test'
import type { VoorzieningenConfig } from '../workflows/_fixtures.ts'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import {
	BASE_URL,
	createObject,
	deleteObject,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from '../workflows/_fixtures.ts'
import { APP_MAIN, dismissFirstRunWizard, gotoAppRoute, navClickTo } from './_helpers.ts'

const original = `${RUN_ID} Example application`
const duplicate = `${RUN_ID} example Application`
const merged = `${RUN_ID} Example application copy`
const website = `https://www.example.org/${RUN_ID}`

let api: APIRequestContext
let config: VoorzieningenConfig
const seeded: Array<[string, string]> = []
const ids: Record<string, string> = {}

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

test.beforeAll(async () => {
	api = await newApiContext()
	config = await resolveConfig(api)
	ids.supplier = await seed('organization', { name: `${RUN_ID} supplier`, type: 'Supplier', status: 'Active', contactsUid: `${RUN_ID}-supplier` })
	ids.original = await seed('module', { name: original, provider: ids.supplier, website, recordStatus: 'Active' })
	ids.duplicate = await seed('module', { name: duplicate, provider: ids.supplier, website, recordStatus: 'Active' })
	ids.merged = await seed('module', { name: merged, provider: ids.supplier, website, recordStatus: 'Merged', mergedInto: ids.original })
})

test.afterAll(async () => {
	if (!api) return
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(api, config.register, schema, id)
	}
	await api.dispose()
})

// @e2e record-reconciliation::a-duplicate-application-appears-as-a-candidate
test.fixme('the two look-alike applications are listed as a pair on the Duplicate candidates page', async () => {
	// Needs OpenRegister's /duplicates page: its register and schema pickers
	// and its pair rows live in OpenRegister, not in this repository. The pair
	// to look for is seeded above: `original` and `duplicate`, same supplier and
	// website.
})

// @e2e record-reconciliation::a-functional-administrator-goes-to-the-candidates
test('Find duplicates on Applications opens OpenRegister\'s Duplicate candidates page', async ({ page }) => {
	await navClickTo(page, 'Applications')
	const find = page.getByTestId('find-duplicates')
	await expect(find).toBeVisible({ timeout: 30000 })
	await expect(find).toHaveAttribute('href', /\/apps\/openregister\/duplicates$/)
	await find.click()
	await expect(page).toHaveURL(/\/apps\/openregister\/duplicates/, { timeout: 30000 })
})

// @e2e record-reconciliation::a-regular-user-does-not-see-the-action
test('a user who is neither an admin nor a functional administrator gets no Find duplicates', async ({ browser }) => {
	const userId = `recon-${RUN_ID}`.slice(0, 60)
	const password = `Recon-${RUN_ID}-Pw!9`
	const made = await api.post('/ocs/v2.php/cloud/users?format=json', { form: { userid: userId, password } })
	expect(made.ok(), await made.text()).toBe(true)

	const userApi = await playwrightRequest.newContext({
		baseURL: BASE_URL,
		storageState: { cookies: [], origins: [] },
		httpCredentials: { username: userId, password, send: 'always' },
		extraHTTPHeaders: { 'OCS-APIREQUEST': 'true' },
	})
	const context = await browser.newContext({ baseURL: BASE_URL, storageState: { cookies: [], origins: [] } })
	try {
		const me = await userApi.get('/index.php/apps/stackiq/api/me')
		expect(me.status()).toBe(200)
		expect((await me.json())?.mayFindDuplicates).not.toBe(true)

		const page = await context.newPage()
		await page.goto('/index.php/login')
		await page.locator('#user').fill(userId)
		await page.locator('#password').fill(password)
		await page.locator('[type="submit"]').first().click()
		await page.waitForURL((url) => !url.pathname.includes('/login'))
		await gotoAppRoute(page, '/modules')
		if (await dismissFirstRunWizard(page)) {
			await gotoAppRoute(page, '/modules')
		}
		// The toolbar has rendered: its Export menu sits next to where Find duplicates would be.
		await expect(page.getByRole('button', { name: 'Export' }).first()).toBeVisible({ timeout: 30000 })
		await expect(page.getByTestId('find-duplicates')).toHaveCount(0)
		await expect(page.getByText('Find duplicates', { exact: true })).toHaveCount(0)
	} finally {
		await context.close()
		await userApi.dispose()
		await api.delete(`/ocs/v2.php/cloud/users/${userId}?format=json`)
	}
})

// @e2e record-reconciliation::usages-and-connections-follow-the-survivor
test.fixme('after a merge on OpenRegister\'s page the usage and the connection point at the original', async () => {
	// Needs OpenRegister's merge wizard on the /duplicates page to merge the
	// duplicate into the original; its markup is not in this repository, and
	// OpenRegister's merge endpoint is not called anywhere in stackiq to copy
	// from. The relinking is covered by
	// tests/Unit/EventListener/CatalogueMergeRelinkerTest.php.
})

// @e2e record-reconciliation::a-reader-opens-an-old-link-to-a-merged-application
test('an old link to a merged application shows Merged into with a link to the original, and the list leaves it out', async ({ page }) => {
	await gotoAppRoute(page, `/modules/${ids.merged}`)
	const banner = page.getByTestId('merged-record-banner')
	await expect(banner).toBeVisible({ timeout: 30000 })
	await expect(banner).toContainText('This entry was merged into another one.')
	const link = banner.getByRole('link', { name: `Merged into ${original}` })
	await expect(link).toBeVisible({ timeout: 30000 })
	await link.click()
	await expect(page).toHaveURL(new RegExp(`/modules/${ids.original}`))
	await expect(page.getByTestId('merged-record-banner')).toHaveCount(0)

	await gotoAppRoute(page, `/modules?_search=${encodeURIComponent(RUN_ID)}`)
	const main = page.locator(APP_MAIN).first()
	await expect(main.getByText(duplicate).first()).toBeVisible({ timeout: 30000 })
	await expect(main.getByText(merged)).toHaveCount(0)
})
