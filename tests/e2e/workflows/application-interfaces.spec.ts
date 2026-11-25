// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * APIs an application offers (connections-api-catalogue): a supplier adds a
 * REST API from the APIs section of its application's page and finds it on
 * the APIs list, and an architect reads both APIs of an application with
 * their style and status.
 *
 * Seeds a supplier organisation, two applications and two APIs of the second
 * application carrying this run's RUN_ID through the objects API (the call the
 * Add form makes). Removes exactly those rows and the API the first test adds
 * through the form afterwards. Setup and teardown use the API; every assertion
 * is made on the rendered page.
 *
 * The scenario marked `@e2e exclude` (a connection naming its API) is covered
 * by tests/Unit/Settings/ApplicationInterfaceFragmentTest.php.
 *
 * Written from the change's scenarios; not yet run against a live instance.
 *
 * @spec openspec/changes/archive/2026-11-25-connections-api-catalogue/specs/application-interfaces/spec.md#requirement-req-aif-001-a-user-can-record-an-api-that-an-application-offers
 * @spec openspec/changes/archive/2026-11-25-connections-api-catalogue/specs/application-interfaces/spec.md#requirement-req-aif-002-the-application-page-lists-its-apis
 */
import type { APIRequestContext, Locator, Page } from '@playwright/test'
import type { VoorzieningenConfig } from './_fixtures.ts'

import { expect, test } from '@playwright/test'
import {
	createObject,
	deleteObject,
	findAll,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from './_fixtures.ts'
import { dismissSupportDialog, gotoAppRoute } from './_ui.ts'

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
const seeded: Array<[string, string]> = []
const ids: Record<string, string> = {}
const supplier = `${RUN_ID} API supplier`
const appX = `${RUN_ID} case application`
const appY = `${RUN_ID} messaging application`
const addedApi = `${RUN_ID} cases API`
const restApi = `${RUN_ID} records API`
const eventApi = `${RUN_ID} records events`

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
 * The titled card of a list widget on a detail page.
 *
 * @param page The page.
 * @param title The card's title.
 * @return The card.
 */
function catalogCard(page: Page, title: string): Locator {
	return page
		.locator('.cn-detail-page__catalog-card')
		.filter({ has: page.getByText(title, { exact: true }) })
		.first()
}

/**
 * Click a list card's Add: the title-row button in the board look, else the
 * item in the card's Actions menu. Returns the create dialog.
 *
 * @param page The page.
 * @param card The card.
 * @param label The Add label.
 * @return The dialog.
 */
async function addFromCard(page: Page, card: Locator, label: string): Promise<Locator> {
	const button = card.getByTestId('cn-detail-widget-catalog-add')
	if (await button.count()) {
		await button.first().click()
	} else {
		await card.getByRole('button', { name: 'Actions' }).first().click()
		await page.locator('.v-popper__popper--shown').last().getByRole('menuitem', { name: label }).first().click()
	}
	const dialog = page.locator('[role="dialog"], .modal-container').first()
	await dialog.waitFor({ state: 'visible', timeout: 15000 })
	return dialog
}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	ids.supplier = await seed('organization', {
		name: supplier,
		type: 'Supplier',
		status: 'Active',
		contactsUid: `${RUN_ID}-api-supplier`,
	})
	ids.x = await seed('module', { name: appX, provider: ids.supplier })
	ids.y = await seed('module', { name: appY, provider: ids.supplier })
	ids.rest = await seed('applicationInterface', {
		name: restApi,
		module: ids.y,
		style: 'REST',
		version: '2.0',
		status: 'in use',
	})
	ids.event = await seed('applicationInterface', {
		name: eventApi,
		module: ids.y,
		style: 'event or message',
		version: '1.0',
		status: 'in development',
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	// The API the first test added through the form.
	for (const row of await findAll(apiCtx, cfg.register, 'applicationInterface', addedApi)) {
		const id = String((row as { id?: string }).id ?? (row as { '@self'?: { id?: string } })['@self']?.id ?? '')
		if (id && JSON.stringify(row).includes(RUN_ID)) {
			await deleteObject(apiCtx, cfg.register, 'applicationInterface', id)
		}
	}
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e application-interfaces::a-supplier-adds-a-rest-api-to-its-application
test('an API added from the application page is listed there and on the APIs list', async ({
	page,
}) => {
	await gotoAppRoute(page, `/modules/${ids.x}`)
	await dismissSupportDialog(page)
	const apis = catalogCard(page, 'APIs')
	await expect(apis).toBeVisible({ timeout: 30000 })

	const dialog = await addFromCard(page, apis, 'Add API')
	await dialog.getByLabel('Name', { exact: true }).first().fill(addedApi)
	await dialog.getByLabel('Style', { exact: true }).first().click()
	await page.getByRole('option', { name: 'REST', exact: true }).first().click()
	await dialog.getByLabel('Version', { exact: true }).first().fill('1.2')
	await dialog.getByLabel('Specification', { exact: true }).first().fill('https://example.org/openapi.yaml')
	await dialog.getByRole('button', { name: /save|create/i }).last().click()
	await expect(dialog).toBeHidden({ timeout: 30000 })

	const row = apis.getByRole('row').filter({ hasText: addedApi })
	await expect(row).toBeVisible({ timeout: 30000 })
	await expect(row).toContainText('REST')
	await expect(row).toContainText('1.2')

	await gotoAppRoute(page, '/apis')
	await dismissSupportDialog(page)
	const search = page.getByRole('main').getByRole('textbox', { name: /search/i }).first()
	if (await search.count()) {
		await search.fill(addedApi)
	}
	const listed = page.getByRole('row').filter({ hasText: addedApi })
	await expect(listed).toBeVisible({ timeout: 30000 })
	await expect(listed).toContainText('REST')
	await expect(listed).toContainText(appX)
})

// @e2e application-interfaces::an-architect-checks-what-an-application-offers
test('the application page lists its REST and event APIs with style and status', async ({
	page,
}) => {
	await gotoAppRoute(page, `/modules/${ids.y}`)
	await dismissSupportDialog(page)
	const apis = catalogCard(page, 'APIs')
	await expect(apis).toBeVisible({ timeout: 30000 })

	const rest = apis.getByRole('row').filter({ hasText: restApi })
	await expect(rest).toBeVisible({ timeout: 30000 })
	await expect(rest).toContainText('REST')
	await expect(rest).toContainText('in use')

	const event = apis.getByRole('row').filter({ hasText: eventApi })
	await expect(event).toBeVisible({ timeout: 30000 })
	await expect(event).toContainText('event or message')
	await expect(event).toContainText('in development')

	// Each row opens the API's own page.
	await rest.getByText(restApi).first().click()
	await expect(page).toHaveURL(new RegExp(`/apis/${ids.rest}`), { timeout: 30000 })
	await expect(page.getByText(restApi).first()).toBeVisible({ timeout: 30000 })
})
