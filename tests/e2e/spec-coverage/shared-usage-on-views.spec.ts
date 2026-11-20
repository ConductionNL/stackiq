/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * E2e coverage for openspec/changes/architecture-shared-usage-on-views: the
 * "Show applications" control on a read-only GEMMA view, the own and shared
 * application nodes inside a reference component, and the legend.
 *
 * Every scenario the spec tags `@e2e tests/e2e/spec-coverage/shared-usage-on-views.spec.ts`
 * is covered here. The view is a real imported GEMMA view of the instance,
 * read by a regular user with an empty storage state. Which applications an
 * organisation runs itself and which it uses through a partner depends on the
 * organisation that installed the app, so no fixture can promise both on one
 * component: the spec keeps the view the API answers and adds one own
 * ("Suite A") and one shared ("Suite B", from "Gemeente Utrecht") usage to its
 * first component, in the enrichment shape `GET /api/views/{id}` gives with
 * `include_gebruik` and `include_deelnames_gebruik`. The test asserts the
 * page sends exactly the flags of the switches that are on.
 */

import type { APIRequestContext, Browser, Locator, Page, Route } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { BASE_URL, newApiContext, RUN_ID } from '../workflows/_fixtures.ts'
import { dismissFirstRunWizard, gotoAppRoute } from './_helpers.ts'

const PARTNER = 'Gemeente Utrecht'
const OWN = { id: `own-${RUN_ID}`, module: `module-a-${RUN_ID}`, moduleName: 'Suite A' }
const SHARED = { id: `shared-${RUN_ID}`, module: `module-b-${RUN_ID}`, moduleName: 'Suite B', _sourceOrganization: PARTNER }

interface ImportedView {
	id: string
	component: string
	componentName: string
}

/**
 * The first imported GEMMA view with a component node, read as `user`.
 *
 * @param user The signed-in user's API context.
 */
async function importedView(user: APIRequestContext): Promise<ImportedView | null> {
	const list = await user.get('/index.php/apps/stackiq/api/views')
	expect(list.ok(), `the views list: ${list.status()}`).toBe(true)
	const body = await list.json()
	const views: Array<Record<string, any>> = body.views ?? body.results ?? (Array.isArray(body) ? body : [])
	for (const view of views.slice(0, 25)) {
		const id = String(view.id ?? view['@self']?.id ?? view.uuid ?? '')
		if (id === '' || !(view.origin === undefined || view.origin === null || view.origin === '' || view.origin === 'imported')) {
			continue
		}
		const one = await user.get(`/index.php/apps/stackiq/api/views/${encodeURIComponent(id)}`)
		if (!one.ok()) {
			continue
		}
		const nodes: Array<Record<string, any>> = (await one.json()).view?.viewNodes ?? []
		const named = nodes.filter((node) => (node.viewNodeId ?? node.identifier) && (node.name ?? node.label))
		// A reference component where the type says so, else the first named element of the view.
		const component = named.find((node) => /Component/i.test(String(node.type ?? node.elementType ?? node['xsi:type'] ?? ''))) ?? named[0]
		if (component) {
			return { id, component: String(component.viewNodeId ?? component.identifier), componentName: String(component.name ?? component.label ?? '') }
		}
	}
	return null
}

/**
 * Answer the view with the two usages on its first component, and record the
 * include flags each request sent.
 *
 * @param page The page.
 * @param view The view.
 * @param flags The include flags of every view request, in order.
 */
async function enrichView(page: Page, view: ImportedView, flags: Array<Record<string, string>>): Promise<void> {
	await page.route(new RegExp(`/apps/stackiq/api/views/${view.id}(\\?|$)`), async (route: Route) => {
		const url = new URL(route.request().url())
		const sent = { gebruik: url.searchParams.get('include_gebruik') ?? '', deelnames: url.searchParams.get('include_deelnames_gebruik') ?? '' }
		flags.push(sent)
		const response = await route.fetch()
		const body = await response.json()
		for (const node of body.view?.viewNodes ?? []) {
			if (String(node.viewNodeId ?? node.identifier) === view.component) {
				node.usage = sent.gebruik === 'true' ? [OWN] : []
				node.deelnamesGebruik = sent.deelnames === 'true' ? [SHARED] : []
			}
		}
		await route.fulfill({ response, json: body })
	})
}

/**
 * The drawn node of an application.
 *
 * @param page The page.
 * @param name The application name.
 */
function applicationNode(page: Page, name: string): Locator {
	return page.locator('.view-editor__usage-node').filter({ has: page.locator('.view-editor__usage-name', { hasText: name }) })
}

/**
 * A switch of the "Show applications" control.
 *
 * @param page The page.
 * @param label The switch label.
 */
function usageSwitch(page: Page, label: string): Locator {
	return page.getByTestId('view-editor-usage-control').getByRole('checkbox', { name: label })
}

test.describe('Shared usage on GEMMA views', () => {
	let admin: APIRequestContext
	let user: APIRequestContext
	const userId = `suv-${RUN_ID}`
	const password = `Suv-${RUN_ID}-Pw!9`
	let view: ImportedView | null = null

	test.beforeAll(async () => {
		admin = await newApiContext()
		const created = await admin.post('/ocs/v2.php/cloud/users?format=json', { form: { userid: userId, password } })
		expect(created.ok(), await created.text()).toBe(true)
		user = await playwrightRequest.newContext({
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: { username: userId, password, send: 'always' },
			extraHTTPHeaders: { 'OCS-APIREQUEST': 'true' },
		})
		view = await importedView(user)
	})

	test.afterAll(async () => {
		await user?.dispose()
		await admin?.delete(`/ocs/v2.php/cloud/users/${userId}?format=json`)
		await admin?.dispose()
	})

	/**
	 * Sign the regular user in, in a context without the admin's state, and
	 * open the view.
	 *
	 * @param browser The browser.
	 * @param flags The include flags each view request sent.
	 */
	async function openView(browser: Browser, flags: Array<Record<string, string>>) {
		const context = await browser.newContext({ baseURL: BASE_URL, storageState: { cookies: [], origins: [] } })
		const page = await context.newPage()
		await page.goto('/index.php/login')
		await page.locator('#user').fill(userId)
		await page.locator('#password').fill(password)
		await page.locator('#password').press('Enter')
		await page.waitForURL((url) => !url.pathname.includes('/login'))
		await dismissFirstRunWizard(page)
		await enrichView(page, view!, flags)
		await gotoAppRoute(page, `/views/${encodeURIComponent(view!.id)}`)
		await expect(page.getByTestId('view-editor-usage-control')).toBeVisible()
		return { context, page }
	}

	// @e2e shared-usage-on-views::the-view-opens-without-overlays
	test('the view opens without overlays', async ({ browser }) => {
		test.skip(view === null, 'The instance has no imported GEMMA view with a component.')
		const flags: Array<Record<string, string>> = []
		const { context, page } = await openView(browser, flags)
		await expect(usageSwitch(page, 'Our applications')).not.toBeChecked()
		await expect(usageSwitch(page, 'Shared with partners')).not.toBeChecked()
		await expect(page.locator('.view-editor__usage-node')).toHaveCount(0)
		await expect(page.getByTestId('usage-overlay-legend')).toHaveCount(0)
		await context.close()
	})

	// @e2e shared-usage-on-views::an-information-manager-shows-the-shared-applications
	// @e2e shared-usage-on-views::own-and-shared-look-different-on-the-same-component
	// @e2e shared-usage-on-views::a-screen-reader-user-hears-who-shares-the-application
	test('own and shared applications are drawn apart inside their component', async ({ browser }) => {
		test.skip(view === null, 'The instance has no imported GEMMA view with a component.')
		const flags: Array<Record<string, string>> = []
		const { context, page } = await openView(browser, flags)

		// "Shared with partners" alone asks for the partners' usage only.
		await usageSwitch(page, 'Shared with partners').check({ force: true })
		const shared = applicationNode(page, 'Suite B')
		await expect(shared).toBeVisible()
		expect(flags.at(-1)).toEqual({ gebruik: '', deelnames: 'true' })
		await expect(shared).toContainText('Shared')
		await expect(applicationNode(page, 'Suite A')).toHaveCount(0)

		// It is drawn inside its component.
		const component = page.locator('.vue-flow__node').filter({ has: page.locator('.view-editor__node') }).filter({ hasText: view!.componentName }).first()
		const outer = await component.boundingBox()
		const inner = await shared.boundingBox()
		expect(outer && inner).toBeTruthy()
		expect(inner!.x).toBeGreaterThanOrEqual(outer!.x - 1)
		expect(inner!.y).toBeGreaterThanOrEqual(outer!.y - 1)
		expect(inner!.x + inner!.width).toBeLessThanOrEqual(outer!.x + outer!.width + 1)
		expect(inner!.y + inner!.height).toBeLessThanOrEqual(outer!.y + outer!.height + 1)

		// Both on: own is solid without the badge, shared is dashed with it.
		await usageSwitch(page, 'Our applications').check({ force: true })
		const own = applicationNode(page, 'Suite A')
		await expect(own).toBeVisible()
		expect(flags.at(-1)).toEqual({ gebruik: 'true', deelnames: 'true' })
		await expect(own).not.toContainText('Shared')
		expect(await own.evaluate((node) => getComputedStyle(node).borderTopStyle)).toBe('solid')
		expect(await shared.evaluate((node) => getComputedStyle(node).borderTopStyle)).toBe('dashed')
		const legend = page.getByTestId('usage-overlay-legend')
		await expect(legend).toContainText('Our application')
		await expect(legend).toContainText('Shared with a partner')

		// A screen reader hears who shares it, once keyboard focus reaches the node.
		await expect(page.getByLabel(`Suite B, shared by ${PARTNER}`)).toBeVisible()
		const wrapper = page.locator('.vue-flow__node').filter({ has: page.getByLabel(`Suite B, shared by ${PARTNER}`) })
		await wrapper.focus()
		expect(await page.evaluate(() => document.activeElement?.querySelector('[aria-label]')?.getAttribute('aria-label') ?? document.activeElement?.getAttribute('aria-label'))).toBe(`Suite B, shared by ${PARTNER}`)

		// A click on an application opens its usage.
		await shared.click()
		await page.waitForURL(new RegExp(`/gebruik/${SHARED.id}`))
		await context.close()
	})
})
