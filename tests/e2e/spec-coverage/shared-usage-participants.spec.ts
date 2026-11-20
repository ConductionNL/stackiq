// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * A participant sees the usages a cooperation shares with it, read only
 * (shared-usage-visible-to-participants REQ-DLG-010, task 3).
 *
 * Seeds, as admin over the API: three organisations (owner, participant,
 * outsider), a module and one usage the owner runs with the participant
 * listed. Then a fresh non-admin gebruik-beheerder of the participant, in a
 * context with an empty storage state, opens Applications in use and the usage:
 *   - the usage is listed, the Shared column reads "<owner>, 2", and the
 *     header counts it among the shared usages;
 *   - the Participants card names the owner and the participant, says the
 *     participant may not change it, and the page offers no Edit;
 *   - a direct PUT on the usage from that user is refused.
 * The outsider's user gets neither the list row nor the usage by id.
 *
 * Base URL and admin credentials come from the environment (`_fixtures.ts`).
 *
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
 */
import type { APIRequestContext, Browser } from '@playwright/test'
import type { VoorzieningenConfig } from '../workflows/_fixtures.ts'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { BASE_URL, createObject, deleteObject, newApiContext, resolveConfig, RUN_ID } from '../workflows/_fixtures.ts'
import { gotoAppRoute } from './_helpers.ts'

const PASSWORD = `Pw-${RUN_ID}-x9!`

let admin: APIRequestContext
let config: VoorzieningenConfig
const created: Array<{ schema: string, id: string }> = []
const users: string[] = []
let owner = ''
let participant = ''
let outsider = ''
let usage = ''

/**
 * Create a non-admin gebruik-beheerder whose account belongs to an organisation.
 *
 * @param organisation The organisation uuid stored on the account.
 * @param suffix A per-user suffix.
 * @return The user id.
 */
async function gebruikBeheerder(organisation: string, suffix: string): Promise<string> {
	const userId = `share-${suffix}-${RUN_ID}`.slice(0, 60)
	const res = await admin.post('/ocs/v2.php/cloud/users?format=json', {
		form: { userid: userId, password: PASSWORD, 'groups[]': 'gebruik-beheerder' },
	})
	expect(res.ok(), `create user ${userId}: ${res.status()}`).toBeTruthy()
	users.push(userId)
	const joined = await admin.post(`/index.php/apps/openregister/api/organisations/${organisation}/join`, {
		data: { userId },
	})
	expect(joined.ok(), `join ${organisation}: ${joined.status()}`).toBeTruthy()
	return userId
}

/**
 * A browser page logged in as the user, from an empty storage state.
 *
 * @param browser The browser.
 * @param userId The user id.
 * @return The page and its API context.
 */
async function loginAs(browser: Browser, userId: string) {
	const context = await browser.newContext({ baseURL: BASE_URL, storageState: { cookies: [], origins: [] } })
	const page = await context.newPage()
	await page.goto('/index.php/login')
	await page.locator('#user').fill(userId)
	await page.locator('#password').fill(PASSWORD)
	await page.locator('[type="submit"]').first().click()
	await page.waitForURL((url) => !url.pathname.includes('/login'))
	const api = await playwrightRequest.newContext({
		baseURL: BASE_URL,
		httpCredentials: { username: userId, password: PASSWORD, send: 'always' },
		extraHTTPHeaders: { 'OCS-APIREQUEST': 'true' },
		storageState: { cookies: [], origins: [] },
	})
	return { context, page, api }
}

/**
 * Seed one object and remember it for cleanup.
 *
 * @param schema The schema slug.
 * @param data The object.
 * @return Its id.
 */
async function seed(schema: string, data: Record<string, unknown>): Promise<string> {
	const id = await createObject(admin, config.register, schema, data)
	created.push({ schema, id })
	return id
}

test.beforeAll(async () => {
	admin = await newApiContext()
	config = await resolveConfig(admin)
	owner = await seed(config.organisatie_schema, { name: `Drechtsteden-Zuid ${RUN_ID}`, type: 'Collaboration' })
	participant = await seed(config.organisatie_schema, { name: `Alblasserdam ${RUN_ID}`, type: 'Municipality' })
	outsider = await seed(config.organisatie_schema, { name: `Oostmaas ${RUN_ID}`, type: 'Municipality' })
	const module = await seed(config.module_schema, { name: `Mozard ${RUN_ID}` })
	usage = await seed(config.gebruik_schema, { module, consumer: owner, participants: [participant], status: 'In production' })
})

test.afterAll(async () => {
	for (const { schema, id } of created.reverse()) {
		await deleteObject(admin, config.register, schema, id)
	}
	for (const userId of users) {
		await admin.delete(`/ocs/v2.php/cloud/users/${userId}?format=json`)
	}
	await admin.dispose()
})

test('a participant sees the shared usage, read only', async ({ browser }) => {
	const { context, page, api } = await loginAs(browser, await gebruikBeheerder(participant, 'alb'))

	await gotoAppRoute(page, '/gebruik')
	const row = page.locator('tr', { hasText: `Mozard ${RUN_ID}` })
	await expect(row).toBeVisible()
	await expect(row.locator('[data-testid="shared-usage-cell"]')).toHaveText(`Drechtsteden-Zuid ${RUN_ID}, 2`)
	await expect(page.getByText(/shared through a cooperation/i).first()).toBeVisible()

	await gotoAppRoute(page, `/gebruik/${usage}`)
	const card = page.locator('[data-testid="usage-participants"]')
	await expect(card.locator('[data-testid="usage-participants-owner"]')).toContainText(`Drechtsteden-Zuid ${RUN_ID}`)
	await expect(card.locator('[data-testid="usage-participant"]')).toContainText(`Alblasserdam ${RUN_ID}`)
	await expect(card.locator('[data-testid="usage-participants-read-only"]')).toBeVisible()
	await expect(page.getByRole('button', { name: /^Edit$/ })).toHaveCount(0)

	const put = await api.put(`/index.php/apps/openregister/api/objects/${config.register}/${config.gebruik_schema}/${usage}`, {
		data: { module: created[3].id, consumer: owner, participants: [participant], status: 'Phased out' },
	})
	expect([401, 403, 404], `PUT as participant returned ${put.status()}`).toContain(put.status())

	await api.dispose()
	await context.close()
})

test('an outsider sees nothing', async ({ browser }) => {
	const { context, page, api } = await loginAs(browser, await gebruikBeheerder(outsider, 'oos'))

	const list = await api.get('/index.php/apps/stackiq/api/gebruik?_limit=200')
	expect(list.ok(), `GET /api/gebruik ${list.status()}`).toBeTruthy()
	const ids = ((await list.json())?.results ?? []).map((u: Record<string, unknown>) => u.id ?? (u['@self'] as Record<string, unknown>)?.id)
	expect(ids).not.toContain(usage)

	const byId = await api.get(`/index.php/apps/openregister/api/objects/${config.register}/${config.gebruik_schema}/${usage}`)
	expect([403, 404], `GET by id as outsider returned ${byId.status()}`).toContain(byId.status())

	await gotoAppRoute(page, '/gebruik')
	await expect(page.locator('tr', { hasText: `Mozard ${RUN_ID}` })).toHaveCount(0)

	await api.dispose()
	await context.close()
})
