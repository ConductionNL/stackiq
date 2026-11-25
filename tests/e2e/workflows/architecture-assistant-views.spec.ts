// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Assistant-drafted views: a draft written through the `stackiq.draftView`
 * MCP tool hands back its editor link and is listed as a draft on the Views
 * page, the editor names who the draft was made for (also after a person
 * moves a node and saves), and a fresh draft without positions is laid out
 * when it first opens and is found under the origin assistant in the Views
 * sidebar.
 *
 * Seeds three AMEF elements carrying this run's RUN_ID in the register the app
 * maps (`/api/amef/config`) through the objects API, and drafts views through
 * OpenRegister's MCP endpoint as the suite's admin (the call Hermiq makes in a
 * logged-in user's session). Removes exactly the seeded elements, the drafted
 * views and the relations those drafts created afterwards.
 *
 * The scenarios marked `@e2e exclude` in the change's spec are covered by
 * tests/Unit/Service/ArchitectureViewDraftServiceTest.php,
 * tests/Unit/Mcp/StackiqToolProviderDraftToolsTest.php,
 * tests/Unit/Service/ViewServiceDrawnViewTest.php and
 * tests/Unit/Service/ArchiMateExportServiceDrawnFilterTest.php.
 *
 * Run live 25 Nov 2026 on NC 34 + PostgreSQL + OpenRegister main.
 *
 * @spec openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-002-stackiq-shall-offer-a-create-tool-that-writes-a-draft-view-from-elements-and-relations
 * @spec openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-003-every-object-the-draft-tool-writes-shall-carry-the-assistant-mark-in-the-same-write
 * @spec openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-004-a-drafted-view-shall-be-laid-out-when-it-is-first-opened
 */
import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	createObject,
	deleteObject,
	findAll,
	NC_ADMIN_USER,
	newApiContext,
	RUN_ID,
} from './_fixtures.ts'
import { dismissSupportDialog, gotoAppRoute } from './_ui.ts'

interface DraftResult {
	uuid: string
	url: string
	created: { elements: number, relations: number }
}

const OR = '/index.php/apps/openregister/api/objects'
const MCP = '/index.php/apps/openregister/api/mcp'

let apiCtx: APIRequestContext
let register = ''
const seeded: Array<[string, string]> = []
const drafted: string[] = []
const ids: Record<string, string> = {}
const caseSystem = `${RUN_ID} case system`
const documentStore = `${RUN_ID} document store`
const caseRegister = `${RUN_ID} case register`

/**
 * Create a row in the AMEF register and remember it for cleanup.
 *
 * @param schema The schema slug.
 * @param data The object.
 * @return The new id.
 */
async function seed(schema: string, data: Record<string, unknown>): Promise<string> {
	const id = await createObject(apiCtx, register, schema, data)
	seeded.push([schema, id])
	return id
}

/**
 * Read a JSON-RPC answer, sent as plain JSON or as a server-sent event.
 *
 * @param raw The response body.
 * @return The JSON-RPC message.
 */
function rpcMessage(raw: string): Record<string, any> {
	const trimmed = raw.trim()
	if (trimmed.startsWith('{')) {
		return JSON.parse(trimmed)
	}
	const data = trimmed.split('\n').filter((line) => line.startsWith('data:')).pop() ?? ''
	return JSON.parse(data.slice('data:'.length).trim())
}

/**
 * Call one tool on OpenRegister's MCP endpoint as the admin.
 *
 * @param name The tool name.
 * @param args The tool arguments.
 * @return The tool's result payload.
 */
async function callTool(name: string, args: Record<string, unknown>): Promise<Record<string, any>> {
	const accept = { Accept: 'application/json, text/event-stream' }
	const init = await apiCtx.post(MCP, {
		headers: accept,
		data: {
			jsonrpc: '2.0',
			id: 1,
			method: 'initialize',
			params: { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'e2e', version: '1.0.0' } },
		},
	})
	expect(init.ok(), `MCP initialize: HTTP ${init.status()}`).toBe(true)
	const session = init.headers()['mcp-session-id']
	const headers: Record<string, string> = session ? { ...accept, 'Mcp-Session-Id': session } : accept
	const res = await apiCtx.post(MCP, {
		headers,
		data: { jsonrpc: '2.0', id: 2, method: 'tools/call', params: { name, arguments: args } },
	})
	expect(res.ok(), `MCP ${name}: HTTP ${res.status()}`).toBe(true)
	const message = rpcMessage(await res.text())
	expect(message.error, `MCP ${name}: ${JSON.stringify(message.error)}`).toBeUndefined()
	const result = message.result ?? {}
	expect(result.isError, `MCP ${name} returned an error: ${JSON.stringify(result.content)}`).not.toBe(true)
	if (result.structuredContent) {
		return result.structuredContent
	}
	const text = (result.content ?? []).find((part: { type?: string }) => part.type === 'text')?.text
	const payload = text ? JSON.parse(text) : result
	// A refused tool answers isError inside its text payload, not on the result.
	expect(payload.isError, `MCP ${name} returned an error: ${text}`).not.toBe(true)
	return payload
}

/**
 * Draft a view through `stackiq.draftView` and remember it for cleanup.
 *
 * @param name The view name.
 * @param elements The element uuids, each with its key.
 * @param relations The Flow relations, as source and target keys.
 * @return The draft result.
 */
async function draft(name: string, elements: Array<[string, string]>, relations: Array<[string, string]>): Promise<DraftResult> {
	const result = (await callTool('stackiq.draftView', {
		name,
		description: `${RUN_ID} drafted in the e2e run`,
		elements: elements.map(([key, uuid]) => ({ key, uuid })),
		relations: relations.map(([source, target]) => ({ source, target, type: 'Flow' })),
	})) as DraftResult
	expect(result.uuid, 'the draft names its view').toBeTruthy()
	drafted.push(result.uuid)
	return result
}

/**
 * Read one view as the admin.
 *
 * @param id The view's uuid.
 * @return The view.
 */
async function readView(id: string): Promise<Record<string, any>> {
	const res = await apiCtx.get(`${OR}/${register}/view/${id}`)
	expect(res.ok(), `view ${id}: HTTP ${res.status()}`).toBe(true)
	return res.json()
}

/**
 * Open a view in the editor and wait for it to settle.
 *
 * @param page The page.
 * @param id The view's uuid.
 * @return The editor.
 */
async function openEditor(page: Page, id: string): Promise<Locator> {
	await gotoAppRoute(page, `/views/${id}`)
	await dismissSupportDialog(page)
	const editor = page.getByTestId('architecture-view-editor')
	await expect(editor).toBeVisible({ timeout: 30000 })
	return editor
}

test.describe.configure({ mode: 'serial' })

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	const res = await apiCtx.get('/index.php/apps/stackiq/api/amef/config')
	expect(res.ok(), `amef/config returned ${res.status()}`).toBe(true)
	const config = (await res.json())?.config ?? {}
	register = String(config.register ?? config.register_id ?? '')
	expect(register, 'stackiq maps an AMEF register').not.toBe('')

	ids.caseSystem = await seed('element', {
		identifier: `id-${RUN_ID}-case-system`,
		name: caseSystem,
		type: 'ApplicationComponent',
		// The element schema requires `properties` (the ArchiMate property list).
		properties: [],
		origin: 'drawn',
	})
	ids.documentStore = await seed('element', {
		identifier: `id-${RUN_ID}-document-store`,
		name: documentStore,
		type: 'ApplicationComponent',
		// The element schema requires `properties` (the ArchiMate property list).
		properties: [],
		origin: 'drawn',
	})
	ids.caseRegister = await seed('element', {
		identifier: `id-${RUN_ID}-case-register`,
		name: caseRegister,
		type: 'ApplicationComponent',
		// The element schema requires `properties` (the ArchiMate property list).
		properties: [],
		origin: 'drawn',
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const id of drafted) {
		const res = await apiCtx.get(`${OR}/${register}/view/${id}`)
		const view = res.ok() ? await res.json() : {}
		for (const connection of view.connections ?? []) {
			const identifier = String(connection.modelRelationshipId ?? '')
			if (!identifier) continue
			const found = await apiCtx.get(`${OR}/${register}/relation`, { params: { _limit: 5, identifier } })
			const relation = found.ok() ? ((await found.json()).results ?? [])[0] : null
			const relationId = String(relation?.id ?? relation?.['@self']?.id ?? '')
			if (relationId && relation?.origin === 'assistant') {
				await deleteObject(apiCtx, register, 'relation', relationId)
			}
		}
		await deleteObject(apiCtx, register, 'view', id)
	}
	for (const row of await findAll(apiCtx, register, 'view', RUN_ID)) {
		const id = String(row.id ?? (row as { '@self'?: { id?: string } })['@self']?.id ?? '')
		if (id && !drafted.includes(id)) {
			await deleteObject(apiCtx, register, 'view', id)
		}
	}
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e architecture-assistant-views::an-assistant-drafts-a-view-and-hands-back-a-link
test('a draftView call hands back the editor link and the Views page lists the draft', async ({ page }) => {
	const name = `${RUN_ID} assistant link draft`
	const result = await draft(
		name,
		[['a', ids.caseSystem], ['b', ids.documentStore]],
		[['a', 'b']],
	)
	expect(result.url).toBe(`/apps/stackiq/views/${result.uuid}`)

	await gotoAppRoute(page, `/views?_search=${encodeURIComponent(RUN_ID)}`)
	await dismissSupportDialog(page)
	const row = page.getByRole('row').filter({ hasText: name })
	await expect(row).toBeVisible({ timeout: 30000 })
	await expect(row).toContainText(/draft/i)
})

// @e2e architecture-assistant-views::a-reviewer-sees-who-the-draft-was-made-for
test('the editor names who the draft was made for, also after a node is moved and saved', async ({ page }) => {
	const result = await draft(
		`${RUN_ID} assistant reviewed draft`,
		[['a', ids.caseSystem], ['b', ids.documentStore]],
		[['a', 'b']],
	)
	const editor = await openEditor(page, result.uuid)
	const notice = page.getByTestId('view-editor-draft-notice')
	await expect(notice).toContainText('Drafted by an assistant for', { timeout: 30000 })
	await expect(notice).toContainText(NC_ADMIN_USER)
	const draftedAt = new Date(String((await readView(result.uuid)).draftedAt))
	expect(Number.isNaN(draftedAt.getTime()), 'the view carries draftedAt').toBe(false)
	await expect(notice).toContainText(/\d/)

	const node = editor.locator('.vue-flow__node').filter({ hasText: caseSystem })
	await expect(node).toBeVisible({ timeout: 30000 })
	const box = await node.boundingBox()
	expect(box, 'the node has a box on the canvas').not.toBeNull()
	const x = (box?.x ?? 0) + (box?.width ?? 0) / 2
	const y = (box?.y ?? 0) + (box?.height ?? 0) / 2
	await page.mouse.move(x, y)
	await page.mouse.down()
	await page.mouse.move(x + 120, y + 60, { steps: 8 })
	await page.mouse.up()

	await editor.getByRole('button', { name: 'Save', exact: true }).click()
	await expect(page.getByText('The view is saved.')).toBeVisible({ timeout: 30000 })
	await expect(notice).toContainText('Drafted by an assistant for')
	await expect(notice).toContainText(NC_ADMIN_USER)

	const stored = await readView(result.uuid)
	expect(stored.origin).toBe('assistant')
	expect(stored.draftedFor).toBe(NC_ADMIN_USER)

	await page.reload()
	await expect(page.getByTestId('view-editor-draft-notice')).toContainText('Drafted by an assistant for', { timeout: 30000 })
})

// @e2e architecture-assistant-views::an-application-owner-opens-a-fresh-draft
test('a fresh draft is laid out on open and is listed under the origin assistant', async ({ page }) => {
	const name = `${RUN_ID} assistant fresh draft`
	const result = await draft(
		name,
		[['a', ids.caseSystem], ['b', ids.documentStore], ['c', ids.caseRegister]],
		[['a', 'b'], ['b', 'c']],
	)
	const editor = await openEditor(page, result.uuid)
	const nodes = editor.locator('.vue-flow__node')
	await expect(nodes).toHaveCount(3, { timeout: 30000 })
	await expect(editor.locator('.vue-flow__edge')).toHaveCount(2)
	const points = new Set<string>()
	for (const node of await nodes.all()) {
		const box = await node.boundingBox()
		expect(box, 'each node has a box on the canvas').not.toBeNull()
		points.add(`${Math.round(box?.x ?? 0)},${Math.round(box?.y ?? 0)}`)
	}
	expect(points.size, 'the three nodes sit at distinct positions').toBe(3)

	await gotoAppRoute(page, `/views?_search=${encodeURIComponent(RUN_ID)}`)
	await dismissSupportDialog(page)
	// The facets sit in the index sidebar, which opens collapsed.
	const openSidebar = page.getByRole('button', { name: 'Open sidebar' })
	if (await openSidebar.isVisible()) {
		await openSidebar.click()
	}
	await page.getByRole('combobox', { name: 'Origin', exact: true }).first().click()
	await page.locator('.vs__dropdown-option', { hasText: /assistant/i }).first().click()
	await expect(page.getByRole('row').filter({ hasText: name })).toBeVisible({ timeout: 30000 })
})
