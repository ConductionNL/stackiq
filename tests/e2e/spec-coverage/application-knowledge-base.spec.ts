// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Application knowledge base (the knowledge base change, tasks 1 and 2).
 *
 * Surfaces under test:
 *   src/manifest.json `ModuleDetail` widget `md-knowledge` (Knowledge
 *     articles, the library's CnCollectivesCard on OpenRegister's
 *     `collectives` leaf) next to `md-files` (Documentation), and the
 *     Collectives sidebar tab (CnCollectivesTab) that links, creates and
 *     unlinks pages;
 *   the src/manifest.d fragment of the change, page `KnowledgeBase` at
 *     /knowledge (a kb-search widget over OpenRegister's page search) and
 *     its menu entry `KnowledgeBaseMenu`, relocated under Applications by
 *     src/menu-layout.json.
 *
 * Seeds one application carrying this run's RUN_ID through the objects API.
 * On an instance with Collectives it also makes a collective "<RUN_ID>
 * Application knowledge" through the Collectives API and creates and links a
 * page "<RUN_ID> Restore procedure" through OpenRegister's
 * `.../collectives/new`. Afterwards it unlinks what it linked, deletes the
 * collective (and its trash entry) and deletes the application.
 *
 * The suite runs on both kinds of instance (the Risks of the change): the Collectives
 * scenarios skip without the app, and the set-up state scenario skips with it.
 *
 * Written from the change's scenarios; not yet run against a live instance.
 *
 * @spec openspec/changes/insight-knowledge-base/specs/application-knowledge-base/spec.md#requirement-req-akb-001-the-application-detail-page-shall-show-the-knowledge-articles-linked-to-the-application
 * @spec openspec/changes/insight-knowledge-base/specs/application-knowledge-base/spec.md#requirement-req-akb-002-an-application-owner-shall-link-an-existing-article-or-create-a-new-one-from-the-application-page
 * @spec openspec/changes/insight-knowledge-base/specs/application-knowledge-base/spec.md#requirement-req-akb-003-the-knowledge-base-page-shall-search-the-articles-the-user-can-read-and-open-them-in-collectives
 * @spec openspec/changes/insight-knowledge-base/specs/application-knowledge-base/spec.md#requirement-req-akb-004-without-the-collectives-app-the-knowledge-base-must-be-absent-not-broken
 */
import type { APIRequestContext, Locator, Page } from '@playwright/test'
import type { VoorzieningenConfig } from '../workflows/_fixtures.ts'

import { expect, test } from '@playwright/test'
import {
	createObject,
	deleteObject,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from '../workflows/_fixtures.ts'
import { appNav, gotoAppRoute } from './_helpers.ts'

const OBJECTS = '/index.php/apps/openregister/api/objects'
const PAGE_SEARCH = '/index.php/apps/openregister/api/integrations/collectives/available'
const COLLECTIVES_OCS = '/ocs/v2.php/apps/collectives/api/v1.0/collectives'
const COLLECTIVES_API = '/index.php/apps/collectives/_api/collectives'

const collectiveName = `${RUN_ID} Application knowledge`
const restoreTitle = `${RUN_ID} Restore procedure`
const onboardingTitle = `${RUN_ID} Onboarding new users`

let api: APIRequestContext
let config: VoorzieningenConfig
let collectivesEnabled = false
let collectiveId = ''
let appId = ''

// The tests share the seeded collective and its links: one worker, in order.
test.describe.configure({ mode: 'serial' })

interface LinkedPage {
	id: string
	title: string
	url: string
}

/**
 * Whether a Nextcloud app is enabled on the instance.
 *
 * @param app The app id.
 * @return True when it is enabled.
 */
async function appEnabled(app: string): Promise<boolean> {
	const res = await api.get('/ocs/v2.php/cloud/apps?filter=enabled&format=json')
	if (!res.ok()) {
		return false
	}
	const list: string[] = (await res.json())?.ocs?.data?.apps ?? []
	return list.includes(app)
}

/**
 * Create a collective through the Collectives API: the OCS API of current
 * releases, else the older `_api` routes.
 *
 * @param name The collective's name.
 * @return Its id.
 */
async function createCollective(name: string): Promise<string> {
	const ocs = await api.post(`${COLLECTIVES_OCS}?format=json`, { data: { name } })
	if (ocs.ok()) {
		return String((await ocs.json())?.ocs?.data?.collective?.id ?? '')
	}
	const legacy = await api.post(COLLECTIVES_API, { data: { name } })
	expect(legacy.ok(), `create collective: ${legacy.status()} ${await legacy.text()}`).toBe(true)
	return String((await legacy.json())?.data?.id ?? '')
}

/**
 * Delete a collective and empty it from the trash, with its team.
 *
 * @param id The collective's id.
 * @return Nothing.
 */
async function deleteCollective(id: string): Promise<void> {
	const ocs = await api.delete(`${COLLECTIVES_OCS}/${id}?format=json`)
	if (ocs.ok()) {
		await api.delete(`${COLLECTIVES_OCS}/trash/${id}?circle=1&format=json`).catch(() => undefined)
		return
	}
	await api.delete(`${COLLECTIVES_API}/${id}`).catch(() => undefined)
	await api.delete(`${COLLECTIVES_API}/trash/${id}?circle=1`).catch(() => undefined)
}

/**
 * The Collectives pages linked to the seeded application.
 *
 * @return The linked pages.
 */
async function linkedPages(): Promise<LinkedPage[]> {
	const res = await api.get(`${OBJECTS}/${config.register}/module/${appId}/integrations/collectives`)
	if (!res.ok()) {
		return []
	}
	const body = await res.json()
	const rows: Array<Record<string, unknown>> = Array.isArray(body) ? body : (body?.results ?? [])
	return rows.map((row) => ({
		id: String(row.id ?? (row.data as { id?: unknown } | undefined)?.id ?? ''),
		title: String(row.title ?? ''),
		url: String(row.url ?? ''),
	}))
}

/**
 * The pages whose title matches a term, across the caller's collectives.
 *
 * @param term The title term.
 * @return The matching pages.
 */
async function searchPages(term: string): Promise<LinkedPage[]> {
	const res = await api.get(`${PAGE_SEARCH}?search=${encodeURIComponent(term)}`)
	if (!res.ok()) {
		return []
	}
	const rows: Array<Record<string, unknown>> = (await res.json())?.results ?? []
	return rows.map((row) => ({ id: String(row.id ?? ''), title: String(row.title ?? ''), url: String(row.url ?? '') }))
}

/**
 * Open the application page and its Collectives sidebar tab.
 *
 * @param page The page.
 * @return Nothing.
 */
async function openCollectivesTab(page: Page): Promise<void> {
	await gotoAppRoute(page, `/modules/${appId}`)
	// The object sidebar opens collapsed.
	const tab = page.getByRole('tab', { name: 'Collectives' }).first()
	const openSidebar = page.getByRole('button', { name: 'Open sidebar' })
	await expect(tab.or(openSidebar).first()).toBeVisible({ timeout: 30000 })
	if (await openSidebar.isVisible()) {
		await openSidebar.click()
	}
	await tab.click({ timeout: 30000 })
}

/**
 * The Knowledge articles card on the application page, scrolled into view.
 *
 * The card sits at the end of the page and loads once it is in view, so it is
 * found by its widget group (`md-knowledge`) and scrolled to before use.
 *
 * @param page The page.
 * @return The card.
 */
async function knowledgeCard(page: Page): Promise<Locator> {
	const card = page.getByRole('group', { name: 'md-knowledge', exact: true })
	await card.scrollIntoViewIfNeeded({ timeout: 30000 })
	await expect(card.locator('.loading-icon')).toHaveCount(0, { timeout: 30000 })
	return card
}

test.beforeAll(async () => {
	api = await newApiContext()
	config = await resolveConfig(api)
	collectivesEnabled = await appEnabled('collectives')
	appId = await createObject(api, config.register, 'module', { name: `${RUN_ID} application` })
	if (!collectivesEnabled) {
		return
	}
	collectiveId = await createCollective(collectiveName)
	expect(collectiveId, 'the collective has an id').not.toBe('')
	const created = await api.post(`${OBJECTS}/${config.register}/module/${appId}/collectives/new`, {
		data: { collectiveId: Number(collectiveId), title: restoreTitle },
	})
	expect(created.status(), await created.text()).toBeLessThan(300)
})

test.afterAll(async () => {
	if (!api) return
	if (appId !== '') {
		for (const linked of await linkedPages()) {
			await api.delete(`${OBJECTS}/${config.register}/module/${appId}/collectives/${linked.id}`).catch(() => undefined)
		}
		await deleteObject(api, config.register, 'module', appId)
	}
	if (collectiveId !== '') {
		await deleteCollective(collectiveId)
	}
	await api.dispose()
})

// @e2e application-knowledge-base::an-information-manager-reads-the-articles-of-an-application
test('the application page lists its linked article with its collective, beside Documentation', async ({ page }) => {
	test.skip(!collectivesEnabled, 'the Collectives app is not installed on this instance')
	await gotoAppRoute(page, `/modules/${appId}`)

	const card = await knowledgeCard(page)
	await expect(card).toBeVisible({ timeout: 30000 })
	await expect(card.getByRole('link', { name: restoreTitle })).toBeVisible({ timeout: 30000 })
	await expect(card.getByText(collectiveName).first()).toBeVisible()
	await expect(page.getByText('Documentation', { exact: true }).first()).toBeVisible()
})

// @e2e application-knowledge-base::an-application-owner-writes-a-new-article-for-an-application
test('creating an article from the application page makes the page in Collectives and lists it', async ({ page }) => {
	test.skip(!collectivesEnabled, 'the Collectives app is not installed on this instance')
	await openCollectivesTab(page)

	await page.getByRole('button', { name: 'Create new page' }).first().click()
	const dialog = page.getByTestId('cn-collective-page-create')
	await expect(dialog).toBeVisible({ timeout: 30000 })
	await dialog.getByLabel('Collective', { exact: true }).click()
	await page.getByRole('option', { name: new RegExp(collectiveName) }).first().click()
	await dialog.getByLabel('Page title').fill(onboardingTitle)
	await dialog.getByRole('button', { name: 'Create page' }).click()
	await expect(dialog).toBeHidden({ timeout: 30000 })

	await expect.poll(async () => (await searchPages(onboardingTitle)).map((found) => found.title), { timeout: 30000 }).toContain(onboardingTitle)

	await gotoAppRoute(page, `/modules/${appId}`)
	await expect((await knowledgeCard(page)).getByRole('link', { name: onboardingTitle })).toBeVisible({ timeout: 30000 })
})

// @e2e application-knowledge-base::unlinking-keeps-the-article
test('unlinking an article removes it from the panel and the page still opens in Collectives', async ({ page }) => {
	test.skip(!collectivesEnabled, 'the Collectives app is not installed on this instance')
	const linked = (await linkedPages()).find((found) => found.title === restoreTitle)
	expect(linked, 'the seeded article is linked').toBeTruthy()

	await openCollectivesTab(page)
	const row = page.locator('.cn-collectives-tab__row').filter({ hasText: restoreTitle }).first()
	await expect(row).toBeVisible({ timeout: 30000 })
	await row.getByRole('button', { name: 'Actions' }).first().click()
	await page.locator('.v-popper__popper--shown').last().getByRole('menuitem', { name: 'Unlink page' }).click()
	await expect(page.locator('.cn-collectives-tab__row').filter({ hasText: restoreTitle })).toHaveCount(0, { timeout: 30000 })

	await gotoAppRoute(page, `/modules/${appId}`)
	const card = await knowledgeCard(page)
	await expect(card).toBeVisible({ timeout: 30000 })
	await expect(card.getByRole('link', { name: restoreTitle })).toHaveCount(0)

	await page.goto(String(linked?.url), { waitUntil: 'domcontentloaded' })
	await expect(page).toHaveURL(/\/apps\/collectives\//)
	await expect(page.getByText(restoreTitle).first()).toBeVisible({ timeout: 30000 })
})

// @e2e application-knowledge-base::an-information-manager-finds-an-article-by-title
test('typing on the Knowledge base page lists the article and the result opens it in Collectives', async ({ page }) => {
	test.skip(!collectivesEnabled, 'the Collectives app is not installed on this instance')
	await gotoAppRoute(page, '/knowledge')
	await expect(page.getByText('The search matches the titles of the articles', { exact: false }).first()).toBeVisible({ timeout: 30000 })

	// The run's token keeps the match to this run's page in a shared dataset.
	await page.getByLabel('Search the knowledge base').first().fill(`${RUN_ID} restore`)
	const result = page.locator('.cn-kb-search-widget__results').getByRole('link', { name: restoreTitle })
	await expect(result).toBeVisible({ timeout: 30000 })
	await expect(result).toHaveAttribute('href', /\/apps\/collectives\//)
	await expect(result).toHaveAttribute('target', '_blank')

	const opened = page.waitForEvent('popup')
	await result.click()
	const tab = await opened
	await tab.waitForLoadState('domcontentloaded')
	await expect(tab).toHaveURL(/\/apps\/collectives\//)
	await expect(tab.getByText(restoreTitle).first()).toBeVisible({ timeout: 30000 })
	await tab.close()
})

// @e2e application-knowledge-base::the-entry-sits-under-applications
test('Knowledge base is a child of Applications in the menu, not a top-level entry', async ({ page }) => {
	test.skip(!collectivesEnabled, 'the Collectives app is not installed on this instance')
	await gotoAppRoute(page, '/')
	const nav = appNav(page)
	await nav.waitFor({ state: 'visible', timeout: 30000 })

	const applications = nav.locator('li').filter({ has: page.getByRole('link', { name: 'Applications', exact: true }) }).first()
	const knowledge = nav.getByRole('link', { name: 'Knowledge base', exact: true })
	// The group opens collapsed, and a collapsed group renders no children.
	if (!(await knowledge.isVisible().catch(() => false))) {
		await applications.getByRole('button', { name: 'Open menu' }).first().click()
	}
	await expect(applications.getByRole('link', { name: 'Knowledge base', exact: true })).toHaveCount(1)
	// Nested one list deeper than the top-level entries.
	await expect(nav.locator('li li').filter({ has: page.getByRole('link', { name: 'Knowledge base', exact: true }) })).not.toHaveCount(0)

	await knowledge.click()
	await expect(page).toHaveURL(/\/knowledge$/)
})

// @e2e application-knowledge-base::a-nextcloud-admin-without-collectives-sees-a-set-up-state
test('without Collectives the panel shows a set-up state naming it and the menu has no Knowledge base', async ({ page }) => {
	test.skip(collectivesEnabled, 'the Collectives app is installed on this instance; this scenario needs an instance without it')
	await gotoAppRoute(page, `/modules/${appId}`)
	const card = await knowledgeCard(page)
	await expect(card.getByText('Collectives is not installed').first()).toBeVisible({ timeout: 30000 })
	await expect(page.getByText('Documentation', { exact: true }).first()).toBeVisible()

	await expect(appNav(page).getByRole('link', { name: 'Knowledge base', exact: true })).toHaveCount(0)
})
