// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Technology components: an information manager records servers, virtual
 * machines, databases and devices, sees what runs on a component both ways,
 * moves a component through its lifecycle, and sees on an application in use
 * whether the components it runs on are still supported.
 *
 * Components are seeded through the OpenRegister objects API (the same call
 * the form makes) and removed afterwards; the Runs on panel scenario reads the
 * demo data (Database 01, end of support 2026-03-03). The scenarios carrying
 * `@e2e exclude` are covered by tests/Unit/Settings/TechnologyComponentDeclarationTest.php,
 * tests/vitest/technologyLifecycle.spec.js and tests/Unit/Service/EolSyncServiceTest.php.
 *
 * Base URL from the Playwright config (PLAYWRIGHT_BASE_URL and friends).
 *
 * @spec openspec/changes/archive/2026-11-24-operations-technology-components/specs/technology-components/spec.md
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { collectAppErrors, expectNoAppErrors, gotoAppRoute } from './_helpers.ts'

const OBJECTS = '/index.php/apps/openregister/api/objects/stackiq'
const RUN = Date.now().toString(36)

/**
 * The request token of the loaded app page, which every write needs.
 *
 * @param page The page.
 * @return The token.
 */
async function requestToken(page: Page): Promise<string> {
	return page.evaluate(
		() =>
			(window as unknown as { OC?: { requestToken?: string } }).OC
				?.requestToken ?? '',
	)
}

/**
 * The results of one objects API list call.
 *
 * @param page The page.
 * @param schema The schema slug.
 * @param query The query string, without the leading `?`.
 * @return The objects.
 */
async function list(page: Page, schema: string, query: string): Promise<Array<Record<string, unknown>>> {
	const res = await page.request.get(`${OBJECTS}/${schema}?${query}`)
	expect(res.ok(), await res.text()).toBe(true)
	const body = await res.json()
	return Array.isArray(body.results) ? body.results : []
}

/**
 * The id of an object as the objects API returns it.
 *
 * @param object The object.
 * @return The id.
 */
function idOf(object: Record<string, unknown>): string {
	const self = (object['@self'] ?? {}) as Record<string, unknown>
	return String(object.id ?? self.id ?? object.uuid ?? '')
}

/**
 * The organisation the components belong to: Gemeente Voorbeeld when the
 * instance has it, else the first organisation listed.
 *
 * @param page The page.
 * @return The organisation id.
 */
async function organisation(page: Page): Promise<string> {
	const named = await list(page, 'organization', '_limit=1&_search=Voorbeeld')
	const any = named.length > 0 ? named : await list(page, 'organization', '_limit=1')
	expect(any.length, 'the instance has an organisation to own the components').toBeGreaterThan(0)
	return idOf(any[0])
}

test.describe('technology components', () => {
	const created: string[] = []

	/**
	 * Create one technology component and remember it for cleanup.
	 *
	 * @param page The page.
	 * @param fields The component.
	 * @return Its id.
	 */
	async function createComponent(page: Page, fields: Record<string, unknown>): Promise<string> {
		const res = await page.request.post(`${OBJECTS}/technologyComponent`, {
			headers: { requesttoken: await requestToken(page) },
			data: { status: 'In use', organization: await organisation(page), ...fields },
		})
		expect(res.ok(), await res.text()).toBe(true)
		const id = idOf(await res.json())
		expect(id).not.toBe('')
		created.push(id)
		return id
	}

	test.afterEach(async ({ page }) => {
		const token = await requestToken(page).catch(() => '')
		for (const id of created.splice(0).reverse()) {
			await page.request
				.delete(`${OBJECTS}/technologyComponent/${id}`, { headers: { requesttoken: token } })
				.catch(() => {})
		}
	})

	// @e2e technology-components::an-information-manager-registers-a-database-server
	test('a database server shows its class, owner and what it runs on', async ({ page }) => {
		const bag = collectAppErrors(page)
		await gotoAppRoute(page, '/technologie')
		const host = await createComponent(page, { name: `App server E2E ${RUN}`, class: 'Virtual machine' })
		const db = await createComponent(page, { name: `Database E2E ${RUN}`, class: 'Database', runsOn: [host] })

		await gotoAppRoute(page, `/technologie/${db}`)
		const main = page.locator('main')
		await expect(main.getByText(`Database E2E ${RUN}`).first()).toBeVisible()
		await expect(main.getByText('Database', { exact: true }).first()).toBeVisible()
		await expect(main.getByText(`App server E2E ${RUN}`).first()).toBeVisible()
		expectNoAppErrors(bag)
	})

	// @e2e technology-components::an-information-manager-registers-a-laptop
	test('a laptop shows its hardware fields', async ({ page }) => {
		await gotoAppRoute(page, '/technologie')
		const laptop = await createComponent(page, {
			name: `Laptop E2E ${RUN}`,
			class: 'End-user device',
			manufacturer: 'Dell',
			serialNumber: `SN-${RUN}`,
			location: 'Stadhuis',
		})

		await gotoAppRoute(page, `/technologie/${laptop}`)
		const main = page.locator('main')
		await expect(main.getByText(`Laptop E2E ${RUN}`).first()).toBeVisible()
		await expect(main.getByText('Dell', { exact: true }).first()).toBeVisible()
		await expect(main.getByText(`SN-${RUN}`).first()).toBeVisible()
		await expect(main.getByText('Stadhuis', { exact: true }).first()).toBeVisible()
	})

	// @e2e technology-components::a-component-moves-through-its-lifecycle
	test('Phase out moves a component in use to To be phased out', async ({ page }) => {
		await gotoAppRoute(page, '/technologie')
		const id = await createComponent(page, { name: `Phase out E2E ${RUN}`, class: 'Physical server' })

		await gotoAppRoute(page, `/technologie/${id}`)
		const direct = page.getByTestId('cn-lifecycle-action-phase-out')
		if (!(await direct.isVisible().catch(() => false))) {
			await page.locator('main').getByRole('button', { name: /actions/i }).first().click()
		}
		await page.getByRole('menuitem', { name: 'Phase out' }).or(direct).first().click()
		const confirm = page.getByRole('dialog').getByRole('button', { name: 'Phase out' })
		if (await confirm.isVisible().catch(() => false)) {
			await confirm.click()
		}

		await expect.poll(async () => {
			const res = await page.request.get(`${OBJECTS}/technologyComponent/${id}`)
			return (await res.json()).status
		}, { timeout: 15000 }).toBe('To be phased out')
	})

	// @e2e technology-components::an-information-manager-sees-what-runs-on-a-host
	test('a host lists what runs on it, and a component lists the applications in use on it', async ({ page }) => {
		await gotoAppRoute(page, '/technologie')
		const host = await createComponent(page, { name: `Host E2E ${RUN}`, class: 'Physical server' })
		await createComponent(page, { name: `VM E2E ${RUN}`, class: 'Virtual machine', runsOn: [host] })

		await gotoAppRoute(page, `/technologie/${host}`)
		const runsHere = page.locator('main').locator('section, div', { hasText: 'Runs on this component' }).last()
		await expect(page.locator('main').getByText('Runs on this component').first()).toBeVisible()
		await expect(runsHere.getByText(`VM E2E ${RUN}`).first()).toBeVisible()

		// The demo App server 01 has an application in use running on it.
		const servers = await list(page, 'technologyComponent', '_limit=1&_search=App%20server%2001')
		test.skip(servers.length === 0, 'needs the demo data import (App server 01)')
		await gotoAppRoute(page, `/technologie/${idOf(servers[0])}`)
		await expect(page.locator('main').getByText('Applications in use here').first()).toBeVisible()
		const usageLink = page.locator('main a[href*="/gebruik/"]').first()
		await expect(usageLink).toBeVisible()
		await usageLink.click()
		await expect(page).toHaveURL(/\/gebruik\//)
	})

	// @e2e technology-components::an-application-runs-on-a-database-past-its-end-of-support
	test('the Runs on panel lists Database 01 as ended', async ({ page }) => {
		await gotoAppRoute(page, '/technologie')
		const databases = await list(page, 'technologyComponent', '_limit=1&_search=Database%2001')
		test.skip(databases.length === 0, 'needs the demo data import (Database 01)')
		const usages = await list(page, 'usage', `_limit=1&runsOn=${encodeURIComponent(idOf(databases[0]))}`)
		test.skip(usages.length === 0, 'needs the demo application in use running on Database 01')

		await gotoAppRoute(page, `/gebruik/${idOf(usages[0])}`)
		const panel = page.getByTestId('usage-runs-on')
		await expect(panel).toBeVisible()
		const row = panel.locator('tr', { hasText: 'Database 01' }).first()
		await expect(row).toHaveAttribute('data-state', 'ended')
		await expect(row.getByText('Support ended')).toBeVisible()
		await expect(page.getByTestId('usage-runs-on-worst')).toContainText('past its end of support')
	})
})
