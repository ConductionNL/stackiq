/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e — drawing and keeping architecture views
 * (change architecture-views-editor, REQ-AVE-001 to REQ-AVE-005).
 *
 * Fixtures go straight into the AMEF register the app maps (`/api/amef/config`)
 * as the admin and are removed in afterAll by this run's token. The pages are
 * driven as the suite's admin; the lock notice needs a second person, so that
 * test creates an account for the run, locks the view as that account through
 * OpenRegister's lock route, and removes the account again.
 *
 * Covered here, beside the unit and vitest suites the change names:
 * - the Views list quick filters Drawn, Imported and Mine, and the tag and
 *   status filters as list queries (REQ-AVE-004)
 * - drawing: two new elements placed and connected, saved, and still there
 *   after a reload, with the elements and the relation stored `origin: drawn`
 *   (REQ-AVE-001, REQ-AVE-003)
 * - an imported view opens without edit controls and Copy to edit opens a
 *   drawn copy whose `basedOn` names it (REQ-AVE-002)
 * - Save version twice and compare the two (REQ-AVE-005)
 * - a view a colleague holds opens read-only, naming them (REQ-AVE-001)
 *
 * @spec openspec/changes/archive/2026-11-26-architecture-views-editor/specs/architecture-views-editor/spec.md
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { APP_PATH, BASE_URL } from '../base-url.ts'
import { createObject, deleteObject, findAll, newApiContext, RUN_ID } from './_fixtures.ts'

test.describe.configure({ mode: 'serial' })

const OR = '/index.php/apps/openregister/api/objects'

let api: APIRequestContext
let register = ''
const ids: Record<string, string> = {}

/**
 * Every object of a schema this run made, by its token in the name.
 *
 * @param schema The schema slug.
 * @return The objects.
 */
async function ofThisRun(schema: string): Promise<Array<Record<string, any>>> {
	return (await findAll(api, register, schema, RUN_ID)) as Array<Record<string, any>>
}

/**
 * Read one view as the admin.
 *
 * @param id The view's uuid.
 * @return The view.
 */
async function view(id: string): Promise<Record<string, any>> {
	const res = await api.get(`${OR}/${register}/view/${id}`)
	expect(res.ok(), `view ${id}: HTTP ${res.status()}`).toBe(true)
	return res.json()
}

/**
 * Open the editor of a view and wait for it to settle.
 *
 * @param page The page.
 * @param id   The view's uuid, or `new`.
 * @return The editor.
 */
async function openEditor(page: Page, id: string) {
	await page.goto(`${APP_PATH}/views/${id}`)
	const editor = page.getByTestId('architecture-view-editor')
	await expect(editor).toBeVisible({ timeout: 30_000 })
	return editor
}

/**
 * Pick an option in an NcSelect named by its input label.
 *
 * @param page   The page.
 * @param label  The select's input label.
 * @param option The option text.
 * @return Resolves once picked.
 */
async function pick(page: Page, label: string, option: string | RegExp): Promise<void> {
	await page.getByLabel(label).first().click()
	await page.getByRole('option', { name: option }).first().click()
}

test.beforeAll(async () => {
	api = await newApiContext()
	const res = await api.get('/index.php/apps/stackiq/api/amef/config')
	expect(res.ok(), 'the AMEF config resolves').toBe(true)
	const config = (await res.json())?.config ?? {}
	register = String(config.register ?? config.register_id ?? '')
	expect(register, 'stackiq maps an AMEF register').not.toBe('')

	ids.imported = await createObject(api, register, 'view', {
		connections: [],
		identifier: `id-${RUN_ID}-imported`,
		name: `${RUN_ID} imported view`,
		nodes: [],
		origin: 'imported',
	})
	ids.tagged = await createObject(api, register, 'view', {
		connections: [],
		identifier: `id-${RUN_ID}-tagged`,
		name: `${RUN_ID} tagged view`,
		nodes: [],
		origin: 'drawn',
		status: 'published',
		tags: [`tag-${RUN_ID}`],
	})
})

test.afterAll(async () => {
	for (const schema of ['view-version', 'view', 'relation', 'element']) {
		for (const obj of await ofThisRun(schema)) {
			await deleteObject(api, register, schema, String(obj.id ?? obj['@self']?.id))
		}
	}
	// Relations carry no name; remove the ones between this run's elements.
	for (const id of [ids.relation].filter(Boolean)) {
		await deleteObject(api, register, 'relation', id)
	}
	await api.dispose()
})

test('the views list filters on drawn, imported and mine, and on tag and status', async ({ page }) => {
	await page.goto(`${APP_PATH}/views?_search=${encodeURIComponent(RUN_ID)}`)
	const main = page.locator('main, .app-content').first()
	await expect(main.getByText(`${RUN_ID} imported view`).first()).toBeVisible({ timeout: 30_000 })
	await expect(main.getByText(`${RUN_ID} tagged view`).first()).toBeVisible()

	for (const [filter, shown, hidden] of [
		['Drawn', 'tagged', 'imported'],
		['Imported', 'imported', 'tagged'],
		['Mine', 'tagged', null],
	] as Array<[string, string, string | null]>) {
		// The quick filters are tabs.
		await main.getByRole('tab', { name: filter, exact: true }).first().click()
		await expect(main.getByText(`${RUN_ID} ${shown} view`).first()).toBeVisible({ timeout: 30_000 })
		if (hidden !== null) {
			await expect(main.getByText(`${RUN_ID} ${hidden} view`)).toHaveCount(0)
		}
	}

	const byTag = await api.get(`${OR}/${register}/view`, { params: { _limit: 50, tags: `tag-${RUN_ID}` } })
	const tagged = ((await byTag.json()).results ?? []).map((v: any) => v.id ?? v['@self']?.id)
	expect(tagged).toEqual([ids.tagged])
	const byStatus = await api.get(`${OR}/${register}/view`, { params: { _limit: 50, _search: RUN_ID, status: 'published' } })
	expect(((await byStatus.json()).results ?? []).map((v: any) => v.id ?? v['@self']?.id)).toEqual([ids.tagged])
})

test('an owner draws two elements, connects them, saves, and finds them again', async ({ page }) => {
	const editor = await openEditor(page, 'new')
	await editor.getByLabel('Name').fill(`${RUN_ID} drawn view`)
	await editor.getByRole('button', { name: 'Create view' }).click()
	await expect(page).not.toHaveURL(/\/views\/new$/, { timeout: 30_000 })
	ids.drawn = page.url().split('/views/')[1].split(/[/?#]/)[0]

	for (const name of ['portal', 'zaaksysteem']) {
		await editor.getByLabel('New element name').fill(`${RUN_ID} ${name}`)
		await editor.getByRole('button', { name: 'Add element' }).click()
		await expect(editor.locator('.vue-flow__node').filter({ hasText: `${RUN_ID} ${name}` })).toBeVisible()
	}

	const from = editor.locator('.vue-flow__node').filter({ hasText: `${RUN_ID} portal` })
	const to = editor.locator('.vue-flow__node').filter({ hasText: `${RUN_ID} zaaksysteem` })
	await from.locator('.vue-flow__handle.source, .vue-flow__handle-right, .vue-flow__handle-bottom').first()
		.dragTo(to.locator('.vue-flow__handle.target, .vue-flow__handle-left, .vue-flow__handle-top').first())
	await expect(editor.locator('.vue-flow__edge')).toHaveCount(1)

	await editor.getByRole('button', { name: 'Save', exact: true }).click()
	await expect(page.getByText('The view is saved.')).toBeVisible({ timeout: 30_000 })

	const stored = await view(ids.drawn)
	expect(stored.origin).toBe('drawn')
	expect(stored.nodes).toHaveLength(2)
	expect(stored.connections).toHaveLength(1)

	const elements = await ofThisRun('element')
	expect(elements.map((e) => e.name).sort()).toEqual([`${RUN_ID} portal`, `${RUN_ID} zaaksysteem`])
	expect(elements.every((e) => e.origin === 'drawn')).toBe(true)
	const relationId = String(stored.connections[0].modelRelationshipId ?? '')
	const relations = await api.get(`${OR}/${register}/relation`, { params: { _limit: 50, identifier: relationId } })
	const relation = ((await relations.json()).results ?? [])[0]
	expect(relation?.origin).toBe('drawn')
	ids.relation = String(relation?.id ?? relation?.['@self']?.id ?? '')

	await page.reload()
	await expect(page.getByTestId('architecture-view-editor').locator('.vue-flow__node')).toHaveCount(2, { timeout: 30_000 })
	await expect(page.getByTestId('architecture-view-editor').locator('.vue-flow__edge')).toHaveCount(1)
})

test('an imported view is read-only and Copy to edit opens a drawn copy', async ({ page }) => {
	const editor = await openEditor(page, ids.imported)
	await expect(editor.getByText(/comes from the GEMMA import and is read-only/)).toBeVisible()
	await expect(editor.getByRole('button', { name: 'Save', exact: true })).toHaveCount(0)
	await expect(editor.getByRole('button', { name: 'Add element' })).toHaveCount(0)

	await editor.getByRole('button', { name: 'Copy to edit' }).click()
	await expect(page).not.toHaveURL(new RegExp(`/views/${ids.imported}$`), { timeout: 30_000 })
	const copyId = page.url().split('/views/')[1].split(/[/?#]/)[0]
	await expect(page.getByTestId('architecture-view-editor').getByRole('button', { name: 'Add element' })).toBeVisible()

	const copy = await view(copyId)
	expect(copy.origin).toBe('drawn')
	expect(copy.basedOn).toBe(ids.imported)
	expect(copy.identifier).not.toBe(`id-${RUN_ID}-imported`)
	expect((await view(ids.imported)).origin).toBe('imported')
})

test('an owner saves two versions and compares them', async ({ page }) => {
	const editor = await openEditor(page, ids.drawn)
	await editor.getByLabel('Version label').fill(`${RUN_ID} v1`)
	await editor.getByRole('button', { name: 'Save version' }).click()
	await expect(page.getByText(/Version 1 is saved\./)).toBeVisible({ timeout: 30_000 })

	await editor.getByLabel('New element name').fill(`${RUN_ID} archive`)
	await editor.getByRole('button', { name: 'Add element' }).click()
	await editor.getByRole('button', { name: 'Save', exact: true }).click()
	await expect(page.getByText('The view is saved.')).toBeVisible({ timeout: 30_000 })
	await editor.getByLabel('Version label').fill(`${RUN_ID} v2`)
	await editor.getByRole('button', { name: 'Save version' }).click()
	await expect(page.getByText(/Version 2 is saved\./)).toBeVisible({ timeout: 30_000 })

	await page.goto(`${APP_PATH}/views/${ids.drawn}/compare`)
	const compare = page.getByTestId('view-version-compare')
	await expect(compare).toBeVisible({ timeout: 30_000 })
	// The options shorten long labels in the middle, so they are picked by version number.
	await pick(page, 'Earlier', /^Version 1:/)
	await pick(page, 'Later', /^Version 2:/)
	await expect(compare.getByText('Added').first()).toBeVisible()
	await expect(compare.getByText(`${RUN_ID} archive`).first()).toBeVisible()
})

test('a view a colleague is editing opens read-only and names them', async ({ page, playwright }) => {
	const uid = `ave-${RUN_ID}`.slice(0, 60)
	const password = `St-${randomBytes(18).toString('hex')}-Pw1!`
	const made = await api.post('/ocs/v2.php/cloud/users?format=json', {
		data: { groups: ['admin'], password, userid: uid },
	})
	expect(made.ok(), `account: HTTP ${made.status()}`).toBe(true)
	const colleague = await playwright.request.newContext({
		baseURL: BASE_URL,
		extraHTTPHeaders: { 'OCS-APIREQUEST': 'true' },
		httpCredentials: { password, send: 'always', username: uid },
	})
	try {
		const locked = await colleague.post(`${OR}/${register}/view/${ids.tagged}/lock`, { data: {} })
		expect(locked.ok(), `lock: HTTP ${locked.status()} ${await locked.text()}`).toBe(true)

		const editor = await openEditor(page, ids.tagged)
		const notice = page.getByTestId('view-editor-lock-notice')
		await expect(notice).toBeVisible()
		await expect(notice).toContainText(uid)
		await expect(editor.getByRole('button', { name: 'Save', exact: true })).toHaveCount(0)
	} finally {
		await colleague.delete(`${OR}/${register}/view/${ids.tagged}/lock`).catch(() => undefined)
		await colleague.dispose()
		await api.delete(`/ocs/v2.php/cloud/users/${encodeURIComponent(uid)}?format=json`).catch(() => undefined)
	}
})
