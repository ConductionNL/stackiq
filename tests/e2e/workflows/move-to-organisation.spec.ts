// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Move to organisation: entries registered under the wrong organisation move
 * to the right one, keeping their identity, and the dry run names what stays
 * behind.
 *
 * Seeds two supplier organisations A and B carrying this run's RUN_ID (each
 * register organisation syncs to an OpenRegister organisation with the same
 * uuid), makes the signed-in administrator a member of both with A active,
 * and seeds two applications and a usage with one connection under A. All
 * set-up goes through the OpenRegister API; every assertion is read from the
 * rendered UI. Afterwards the seeded rows are deleted and the administrator's
 * previous active organisation is restored.
 *
 * The administrator here is the instance admin, whom REQ-MBO-002 allows like
 * an organisation administrator of both sides; the refusal of an
 * administrator of one side is a server guard, covered by
 * tests/Unit/Controller/OwnershipTransferControllerTest.php and the Newman
 * folder "13 - Move between organisations".
 *
 * @spec openspec/changes/archive/2026-11-25-landscape-move-between-organisations/specs/move-between-organisations/spec.md
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

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
const seeded: Array<[string, string]> = []
const ids: Record<string, string> = {}
let previousActive = ''
const orgA = `${RUN_ID} supplier A`
const orgB = `${RUN_ID} supplier B`
const app1 = `${RUN_ID} application one`
const app2 = `${RUN_ID} application two`
const usageApp = `${RUN_ID} application in use`
const connection = `${RUN_ID} connection`
const OR_API = '/index.php/apps/openregister/api'

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
 * Wait until a register organisation has synced to an OpenRegister organisation.
 *
 * @param uuid The organisation's uuid.
 * @return Nothing; throws when it never appears.
 */
async function awaitOrganisation(uuid: string): Promise<void> {
	for (let attempt = 0; attempt < 20; attempt++) {
		const res = await apiCtx.get(`${OR_API}/organisations/${uuid}`)
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
	const res = await apiCtx.post(`${OR_API}/organisations/${uuid}/join`, { data: {} })
	if (!res.ok() && res.status() !== 409) {
		console.warn(`join ${uuid} returned ${res.status()}`)
	}
}

/**
 * Make an organisation the administrator's active one.
 *
 * @param uuid The organisation's uuid.
 * @return Nothing; throws when refused.
 */
async function setActive(uuid: string): Promise<void> {
	const res = await apiCtx.post(`${OR_API}/organisations/${uuid}/set-active`, { data: {} })
	if (!res.ok()) {
		throw new Error(`set-active ${uuid} returned ${res.status()}`)
	}
}

/**
 * Tick a list row's checkbox by the text it shows.
 *
 * @param page The page.
 * @param text The row's text.
 * @return Nothing.
 */
async function selectRow(page: Page, text: string): Promise<void> {
	const row = page.getByRole('row').filter({ hasText: text })
	await expect(row).toBeVisible({ timeout: 30000 })
	await row.getByRole('checkbox').first().check({ force: true })
}

/**
 * Pick the target organisation in the open dialog and ask for the dry run.
 *
 * @param page The page.
 * @param target The target organisation's name.
 * @return The dialog.
 */
async function previewMoveTo(page: Page, target: string) {
	const dialog = page.getByRole('dialog').filter({ hasText: 'Move to organisation' })
	await expect(dialog).toBeVisible({ timeout: 30000 })
	await dialog.getByRole('combobox').first().click()
	await page.getByRole('option', { name: target }).first().click()
	await dialog.getByRole('button', { name: 'Preview' }).click()
	await expect(dialog.getByRole('heading', { name: 'Moving' })).toBeVisible({ timeout: 30000 })
	return dialog
}

/**
 * Escape a literal for use in a regular expression.
 *
 * @param text The literal.
 * @return The escaped text.
 */
function escapeRegExp(text: string): string {
	return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)

	const me = await apiCtx.get('/index.php/apps/stackiq/api/me')
	if (me.ok()) {
		previousActive = (await me.json())?.organisations?.active?.uuid ?? ''
	}

	ids.orgA = await seed('organization', { name: orgA, type: 'Supplier', status: 'Active', contactsUid: `${RUN_ID}-a` })
	ids.orgB = await seed('organization', { name: orgB, type: 'Supplier', status: 'Active', contactsUid: `${RUN_ID}-b` })
	for (const uuid of [ids.orgA, ids.orgB]) {
		await awaitOrganisation(uuid)
		await join(uuid)
	}
	await setActive(ids.orgA)

	// Created while A is active, so A owns them (`@self.organisation`), and A supplies them.
	ids.app1 = await seed('module', { name: app1, provider: ids.orgA })
	ids.app2 = await seed('module', { name: app2, provider: ids.orgA })
	ids.usageModule = await seed('module', { name: usageApp, provider: ids.orgA })
	ids.connection = await seed('connection', { name: connection })
	ids.usage = await seed('usage', {
		consumer: ids.orgA,
		module: ids.usageModule,
		status: 'In production',
		koppelingen: [ids.connection],
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	if (previousActive !== '') {
		await apiCtx.post(`${OR_API}/organisations/${previousActive}/set-active`, { data: {} })
	}
	await apiCtx.dispose()
})

// @e2e move-between-organisations::a-functional-administrator-moves-two-applications
test('an administrator of both organisations moves two applications and they keep their pages', async ({
	page,
}) => {
	await gotoAppRoute(page, `/modules?_search=${encodeURIComponent(RUN_ID)}`)
	await dismissSupportDialog(page)
	await selectRow(page, app1)
	await selectRow(page, app2)
	await page.getByRole('button', { name: 'Move to organisation' }).first().click()

	const dialog = await previewMoveTo(page, orgB)
	const moving = dialog.locator('section').filter({ has: page.getByRole('heading', { name: 'Moving' }) })
	await expect(moving).toContainText(app1)
	await expect(moving).toContainText(app2)
	await dialog.getByRole('button', { name: 'Move', exact: true }).click()
	await expect(dialog).toContainText('2 entries moved.', { timeout: 30000 })
	await dialog.getByRole('button', { name: 'Close' }).click()

	// The same addresses open, now showing supplier B, with their history.
	for (const [id, name] of [[ids.app1, app1], [ids.app2, app2]]) {
		await gotoAppRoute(page, `/modules/${id}`)
		await dismissSupportDialog(page)
		await expect(page.getByText(name).first()).toBeVisible({ timeout: 30000 })
		await expect(page.getByText(orgB).first()).toBeVisible({ timeout: 30000 })
		await page.getByRole('tab', { name: 'History' }).first().click()
		await expect(page.getByRole('tabpanel').first()).not.toBeEmpty()
	}
})

// @e2e move-between-organisations::connections-that-stay-behind-are-named
test('the dry run for a usage names the connection that stays with organisation A', async ({
	page,
}) => {
	await gotoAppRoute(page, `/gebruik/${ids.usage}`)
	await dismissSupportDialog(page)
	await page.getByRole('button', { name: 'Move to organisation' }).first().click()

	const dialog = await previewMoveTo(page, orgB)
	const moving = dialog.locator('section').filter({ has: page.getByRole('heading', { name: 'Moving' }) })
	// A usage has no name of its own: the dialog labels it by its name or its id.
	await expect(moving).toContainText(new RegExp(`${escapeRegExp(usageApp)}|${ids.usage}`))
	const staying = dialog.locator('section').filter({ has: page.getByRole('heading', { name: `Staying with ${orgA}` }) })
	await expect(staying).toContainText(connection)
	await expect(moving).not.toContainText(connection)

	// A dry run moves nothing: cancel and the usage still belongs to A.
	await dialog.getByRole('button', { name: 'Cancel' }).click()
	await expect(dialog).toBeHidden({ timeout: 30000 })
	await page.reload()
	await dismissSupportDialog(page)
	await expect(page.getByText(orgA).first()).toBeVisible({ timeout: 30000 })
})
