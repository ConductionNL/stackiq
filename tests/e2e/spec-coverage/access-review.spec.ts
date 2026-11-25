// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * The Access review page under Organisations (change
 * organisations-role-mapping-and-access-review, REQ-RMA-003 and REQ-RMA-004).
 *
 * Seeds one municipality and two colleagues with Nextcloud accounts that were
 * never reviewed: one to confirm, one holding Gebruik-beheerder on their
 * contact person to withdraw it from. The administrator opens the page, picks
 * the organisation, and finds both due; Keep access makes a row current and
 * names the reviewer; Remove role takes Gebruik-beheerder away, from the row,
 * the contact person and the group. A regular user's menu has no Access
 * review entry.
 *
 * A seeded account has never signed in, so "last sign-in fourteen months ago"
 * is exercised as "never signed in": both are due because they were never
 * reviewed. The refusals (another organisation, reviewing oneself) are
 * PHPUnit's (AccessReviewControllerTest).
 *
 * Everything carries this run's RUN_ID and is removed afterwards.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md
 */
import type { APIRequestContext, Page } from '@playwright/test'
import type { VoorzieningenConfig } from '../workflows/_fixtures.ts'

import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import {
	createObject,
	deleteObject,
	NC_ADMIN_USER,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from '../workflows/_fixtures.ts'
import { dismissSupportDialog, gotoAppRoute } from './_helpers.ts'

const OBJECTS = '/index.php/apps/openregister/api/objects'
const REVIEW = '/index.php/apps/stackiq/api/access-review'
const OCS_USERS = '/ocs/v2.php/cloud/users'

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
const seeded: Array<[string, string]> = []
const accounts: string[] = []
const ids: Record<string, string> = {}

const organisationName = `${RUN_ID} Leverancier Voorbeeld`
const password = `Sq-${randomBytes(12).toString('hex')}-Pw1!`
const keeper = `${RUN_ID}-keeper`.slice(0, 60)
const buyer = `${RUN_ID}-buyer`.slice(0, 60)
const regular = `${RUN_ID}-regular`.slice(0, 60)

test.describe.configure({ mode: 'serial' })

/**
 * Create a Nextcloud account and remember it for cleanup.
 *
 * @param userid The account id.
 * @param groups Groups to put it in.
 */
async function account(userid: string, groups: string[] = []): Promise<void> {
	const res = await apiCtx.post(`${OCS_USERS}?format=json`, { data: { userid, password, groups } })
	expect(res.ok(), await res.text()).toBe(true)
	accounts.push(userid)
}

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
 * The organisation's review rows, by contact person id.
 */
async function reviewRows(): Promise<Record<string, any>> {
	const res = await apiCtx.get(`${REVIEW}/${ids.organisation}`)
	expect(res.status(), await res.text()).toBe(200)
	const body = await res.json()
	const rows: any[] = body?.results ?? body?.rows ?? body ?? []
	return Object.fromEntries(rows.map((row) => [String(row.id), row]))
}

/**
 * Open the page and pick the seeded organisation.
 *
 * @param page The page.
 */
async function openReview(page: Page): Promise<void> {
	await gotoAppRoute(page, '/organisaties/access-review')
	await dismissSupportDialog(page)
	const picker = page.getByTestId('access-review-organisation')
	await expect(picker).toBeVisible({ timeout: 30000 })
	await picker.locator('input').fill(organisationName)
	// The picker highlights the typed text, which splits the option's accessible name: match on its text.
	await page.getByRole('option').filter({ hasText: organisationName }).first().click()
	await expect(page.getByTestId('access-review-table')).toBeVisible({ timeout: 30000 })
}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	// A Supplier: its type grants Aanbod-beheerder, so both people are listed,
	// while Gebruik-beheerder comes only from the buyer's contact person (a
	// Municipality would grant Gebruik-beheerder itself, and withdrawing it
	// from the contact person could never take the buyer out of the group).
	ids.organisation = await seed('organization', {
		name: organisationName,
		type: 'Supplier',
		status: 'Active',
		contactsUid: `${RUN_ID}-municipality`,
	})
	await account(keeper)
	await account(buyer, ['gebruik-beheerder'])
	await account(regular)
	// A row names the person by their account (display name, then uid): the
	// contactPerson schema has no name fields of its own, and `username` is
	// the link to the account, which only an admin writes directly.
	ids.keeper = await seed('contactPerson', {
		username: keeper,
		organization: ids.organisation,
	})
	ids.buyer = await seed('contactPerson', {
		username: buyer,
		organization: ids.organisation,
		roles: ['Gebruik-beheerder'],
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	for (const userid of accounts) {
		await apiCtx.delete(`${OCS_USERS}/${userid}?format=json`).catch(() => undefined)
	}
	await apiCtx.dispose()
})

// @e2e role-mapping-and-access-review::an-information-manager-finds-who-has-not-signed-in-for-a-year
test('both colleagues are listed as never signed in and due', async ({ page }) => {
	await openReview(page)
	const table = page.getByTestId('access-review-table')
	for (const name of [keeper, buyer]) {
		const row = table.getByRole('row').filter({ hasText: name })
		await expect(row).toBeVisible()
		await expect(row).toContainText('Never')
		await expect(row).toContainText('Due')
	}
})

// @e2e role-mapping-and-access-review::a-reviewer-confirms-a-colleague
test('Keep access makes the row current, reviewed today by the administrator', async ({ page }) => {
	await openReview(page)
	await page.getByTestId(`access-review-keep-${ids.keeper}`).click()

	const row = page.getByTestId('access-review-table').getByRole('row').filter({ hasText: keeper })
	await expect(row).toContainText('Current', { timeout: 15000 })
	await expect(row).toContainText(NC_ADMIN_USER)
	await expect(row).not.toContainText('Due')

	const stored = (await reviewRows())[ids.keeper]
	expect(stored.due).toBe(false)
	expect(String(stored.accessReviewedAt).slice(0, 10)).toBe(new Date().toISOString().slice(0, 10))

	const person = await apiCtx.get(`${OBJECTS}/${cfg.register}/contactPerson/${ids.keeper}/audit-trails`)
	if (person.ok()) {
		expect(JSON.stringify(await person.json())).toContain('accessReviewedAt')
	}
})

// @e2e role-mapping-and-access-review::a-reviewer-withdraws-a-role
test('Remove role takes Gebruik-beheerder from the row, the contact person and the group', async ({ page }) => {
	await openReview(page)
	const role = page.getByTestId('access-review-table')
		.getByRole('row')
		.filter({ hasText: buyer })
		.getByTestId('access-review-role-Gebruik-beheerder')
	await expect(role).toBeVisible()
	await role.getByRole('button', { name: /Remove/ }).click()
	await expect(role).toHaveCount(0, { timeout: 15000 })

	const person = await apiCtx.get(`${OBJECTS}/${cfg.register}/contactPerson/${ids.buyer}`)
	expect((await person.json()).roles ?? []).not.toContain('Gebruik-beheerder')
	const groups = await apiCtx.get(`${OCS_USERS}/${buyer}/groups?format=json`)
	expect((await groups.json())?.ocs?.data?.groups ?? []).not.toContain('gebruik-beheerder')
})

// @e2e role-mapping-and-access-review::a-regular-user-does-not-see-the-entry
test('a regular user has no Access review entry in the menu', async ({ browser }) => {
	// Nextcloud pages do not take basic auth: sign in through the login form.
	const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
	// A fresh browser: keep the first-open overlays shut, as global setup does for the admin.
	await context.addInitScript(() => {
		try {
			window.localStorage.setItem('cn-support-dialog-shown:stackiq', '1')
			window.localStorage.setItem('cn-walkthrough-seen:stackiq', '999.0.0')
		} catch {
			// No storage: the helpers dismiss them by hand.
		}
	})
	const page = await context.newPage()
	await page.goto('/index.php/login')
	await page.locator('#user').fill(regular)
	await page.locator('#password').fill(password)
	await page.locator('[type="submit"]').first().click()
	await page.waitForURL((url) => !url.pathname.includes('/login'))
	await gotoAppRoute(page, '/organisaties')
	// Open the Organisations group, so a missing child means hidden, not folded away.
	const group = page.locator('#app-navigation-vue li').filter({ has: page.getByRole('link', { name: 'Organisations', exact: true }) }).first()
	const toggle = group.getByRole('button', { name: 'Open menu' }).first()
	if (await toggle.count() > 0) {
		await toggle.click()
	}
	await expect(page.getByRole('link', { name: 'My confirmation requests' })).toBeVisible({ timeout: 15000 })
	await expect(page.getByRole('link', { name: 'Access review' })).toHaveCount(0)
	await context.close()
})
