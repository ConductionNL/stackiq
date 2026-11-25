// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * The integration leaves on the catalogue's detail pages (change
 * adopt-integration-leaves, task 4.3): a contact person's Contacts tab shows
 * the linked contact by display name, a contract's Calendar tab lists the
 * end-date event the lifecycle listener writes, a pending review's Deck tab
 * lists its follow-up card, and an application's Bookmarks tab lists a linked
 * documentation link with its title and URL.
 *
 * Every object, contact, card and bookmark carries this run's RUN_ID and is
 * removed afterwards. The links are made through OpenRegister's own APIs
 * (contacts/new, integrations/deck, a bookmark tagged `or:<uuid>`), the same
 * ones the leaf's buttons call, so each test asserts what the tab renders, not
 * the library's dialogs. A leaf whose Nextcloud app is not installed on the
 * instance is skipped with that reason; the empty-leaf behaviour itself is
 * PHPUnit's.
 *
 * @spec openspec/specs/catalog-integration-leaves/spec.md
 */
import type { APIRequestContext, Page } from '@playwright/test'
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

const OBJECTS = '/index.php/apps/openregister/api/objects'

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
const seeded: Array<[string, string]> = []
const cleanups: Array<() => Promise<unknown>> = []
const ids: Record<string, string> = {}

const contactName = `${RUN_ID} Jan de Vries`
const contractNumber = `${RUN_ID}-CAL`
const cardTitle = `${RUN_ID} follow up the review`
const bookmarkTitle = `${RUN_ID} documentation`
const bookmarkUrl = `https://docs.example.org/${RUN_ID}/handleiding`

// The tests share the seeded catalogue rows: run them in order, in one worker.
test.describe.configure({ mode: 'serial' })

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
 * Whether a Nextcloud app is enabled on the instance.
 *
 * @param app The app id.
 */
async function appEnabled(app: string): Promise<boolean> {
	const res = await apiCtx.get(`/ocs/v2.php/cloud/apps?filter=enabled&format=json`)
	if (!res.ok()) {
		return false
	}
	const list: string[] = (await res.json())?.ocs?.data?.apps ?? []
	return list.includes(app)
}

/**
 * Open a detail page and its sidebar tab.
 *
 * @param page The page.
 * @param route The app route.
 * @param tab The tab label.
 */
async function openTab(page: Page, route: string, tab: string): Promise<void> {
	await gotoAppRoute(page, route)
	await dismissSupportDialog(page)
	await page.getByRole('tab', { name: tab }).first().click({ timeout: 30000 })
}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	ids.municipality = await seed('organization', {
		name: `${RUN_ID} municipality`,
		type: 'Municipality',
		status: 'Active',
		contactsUid: `${RUN_ID}-municipality`,
	})
	ids.app = await seed('module', {
		name: `${RUN_ID} application`,
		website: 'https://www.example.org',
	})
	ids.usage = await seed('usage', {
		module: ids.app,
		consumer: ids.municipality,
		status: 'In production',
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const cleanup of cleanups.reverse()) {
		await cleanup().catch(() => undefined)
	}
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e catalog-integration-leaves::contact-role-detail-shows-the-linked-nextcloud-contact
test('a contact person shows the linked contact by its display name', async ({ page }) => {
	test.skip(!(await appEnabled('contacts')), 'the Contacts app is not installed on this instance')
	ids.person = await seed('contactPerson', {
		firstName: 'Jan',
		lastName: `${RUN_ID} de Vries`,
		organization: ids.municipality,
	})
	const linked = await apiCtx.post(
		`${OBJECTS}/${cfg.register}/contactPerson/${ids.person}/contacts/new`,
		{ data: { fullName: contactName, email: `${RUN_ID}@example.org` } },
	)
	expect(linked.status(), await linked.text()).toBeLessThan(300)
	const contactUid = String((await linked.json())?.contactUid ?? '')
	if (contactUid !== '') {
		cleanups.push(() => apiCtx.delete(`${OBJECTS}/${cfg.register}/contactPerson/${ids.person}/contacts/${contactUid}`))
	}

	await openTab(page, `/contactpersonen/${ids.person}`, 'Contacts')
	await expect(page.getByText(contactName).first()).toBeVisible({ timeout: 30000 })
	if (contactUid !== '') {
		await expect(page.getByText(contactUid, { exact: true })).toHaveCount(0)
	}
})

// @e2e catalog-integration-leaves::setting-a-contract-end-date-creates-the-leaf-event
test('a contract with an end date lists its end-date event in the Calendar tab', async ({ page }) => {
	test.skip(!(await appEnabled('calendar')), 'the Calendar app is not installed on this instance')
	ids.contract = await seed('catalogContract', {
		contractNumber,
		contractType: 'Licence',
		status: 'Active',
		startDate: '2026-01-01',
		endDate: '2027-03-31',
		usage: ids.usage,
	})

	// The lifecycle listener writes the event when the contract is saved.
	await expect
		.poll(
			async () => {
				const res = await apiCtx.get(`${OBJECTS}/${cfg.register}/catalogContract/${ids.contract}/integrations/calendar`)
				return res.ok() ? JSON.stringify(await res.json()) : ''
			},
			{ timeout: 30000 },
		)
		.toContain(`Contract ends: ${contractNumber}`)

	await openTab(page, `/contracten/${ids.contract}`, 'Calendar')
	await expect(page.getByText(`Contract ends: ${contractNumber}`).first()).toBeVisible({ timeout: 30000 })
})

// @e2e catalog-integration-leaves::a-pending-review-gets-a-follow-up-card
test('a pending review lists its follow-up card in the Deck tab', async ({ page }) => {
	test.skip(!(await appEnabled('deck')), 'the Deck app is not installed on this instance')
	ids.review = await seed('software-review', {
		name: `${RUN_ID} review`,
		rating: 3,
		status: 'pending',
		modules: [ids.app],
	})

	const deck = '/index.php/apps/deck/api/v1.0/boards'
	const board = await apiCtx.post(deck, { data: { title: `${RUN_ID} reviews`, color: '0082c9' } })
	expect(board.status(), await board.text()).toBeLessThan(300)
	const boardId = Number((await board.json()).id)
	cleanups.push(() => apiCtx.delete(`${deck}/${boardId}`))
	const stack = await apiCtx.post(`${deck}/${boardId}/stacks`, { data: { title: 'To do', order: 0 } })
	expect(stack.status(), await stack.text()).toBeLessThan(300)
	const stackId = Number((await stack.json()).id)

	const card = await apiCtx.post(
		`${OBJECTS}/${cfg.register}/software-review/${ids.review}/integrations/deck`,
		{ data: { boardId, stackId, title: cardTitle } },
	)
	expect(card.status(), await card.text()).toBeLessThan(300)

	await openTab(page, `/reviews/${ids.review}`, 'Deck')
	await expect(page.getByText(cardTitle).first()).toBeVisible({ timeout: 30000 })
	await expect(page.getByText(`${RUN_ID} reviews`).first()).toBeVisible()
})

// @e2e catalog-integration-leaves::an-application-accumulates-documentation-links
test('an application lists a linked bookmark with its title and URL', async ({ page }) => {
	test.skip(!(await appEnabled('bookmarks')), 'the Bookmarks app is not installed on this instance')
	const created = await apiCtx.post('/index.php/apps/bookmarks/public/rest/v2/bookmark', {
		data: { url: bookmarkUrl, title: bookmarkTitle, tags: [`or:${ids.app}`] },
	})
	expect(created.status(), await created.text()).toBeLessThan(300)
	const bookmarkId = Number((await created.json())?.item?.id ?? 0)
	if (bookmarkId > 0) {
		cleanups.push(() => apiCtx.delete(`/index.php/apps/bookmarks/public/rest/v2/bookmark/${bookmarkId}`))
	}

	await openTab(page, `/modules/${ids.app}`, 'Bookmarks')
	await expect(page.getByText(bookmarkTitle).first()).toBeVisible({ timeout: 30000 })
	await expect(page.getByText(bookmarkUrl).first()).toBeVisible()

	const module = await apiCtx.get(`${OBJECTS}/${cfg.register}/module/${ids.app}`)
	expect((await module.json()).website).toBe('https://www.example.org')
})
