/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The architecture view store against a fake OpenRegister objects API: drawn
 * views, the relations behind connections, Copy to edit and saved versions.
 *
 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md
 */

import { describe, expect, it } from 'vitest'
import { createArchitectureViewStore, isImported, uuidOf } from '../../src/store/modules/architectureView.js'
import { toCanvas } from '../../src/utils/viewGraph.js'

const BASE = '/index.php/apps/openregister/api/objects/7/'

/**
 * An in-memory objects API: GET list (filtered on the plain params), GET one,
 * POST and PUT, every call recorded. `failPut` makes the next PUT fail once.
 *
 * @return {object}
 */
function fakeApi() {
	const tables = { view: [], element: [], relation: [], 'view-version': [] }
	const calls = []
	let sequence = 0
	let failPut = false
	const parse = (url) => {
		const [schema, id] = url.slice(BASE.length).split('/').map(decodeURIComponent)
		return { schema, id }
	}
	const http = {
		async get(url, config = {}) {
			calls.push(['GET', url, config.params])
			const { schema, id } = parse(url)
			if (id) {
				const found = tables[schema].find((o) => o['@self'].id === id)
				if (!found) throw Object.assign(new Error('404'), { response: { status: 404 } })
				return { data: found }
			}
			const filters = Object.entries(config.params ?? {}).filter(([key]) => !key.startsWith('_'))
			return { data: { results: tables[schema].filter((o) => filters.every(([key, value]) => o[key] === value)) } }
		},
		async post(url, body) {
			calls.push(['POST', url, body])
			const { schema } = parse(url)
			const saved = { ...JSON.parse(JSON.stringify(body)), '@self': { id: `uuid-${++sequence}` } }
			tables[schema].push(saved)
			return { data: saved }
		},
		async put(url, body) {
			calls.push(['PUT', url, body])
			if (failPut) {
				failPut = false
				throw Object.assign(new Error('500'), { response: { status: 500 } })
			}
			const { schema, id } = parse(url)
			const index = tables[schema].findIndex((o) => o['@self'].id === id)
			const saved = { ...JSON.parse(JSON.stringify(body)), '@self': { id } }
			tables[schema][index] = saved
			return { data: saved }
		},
	}
	return { http, tables, calls, failNextPut: () => { failPut = true } }
}

/**
 * A store over the fake, with a fixed clock and counted identifiers.
 *
 * @param {object} api The fake.
 * @return {object}
 */
function storeOver(api) {
	let n = 0
	return createArchitectureViewStore({
		http: api.http,
		register: 7,
		user: 'ines',
		now: () => new Date('2026-11-14T10:00:00Z'),
		newIdentifier: () => `id-new-${++n}`,
	})
}

/**
 * Two canvas nodes on existing elements, connected once.
 *
 * @param {string} [type] The relation type of the connection.
 * @return {{nodes: Array<object>, edges: Array<object>}}
 */
function twoConnectedNodes(type = 'Flow') {
	return {
		nodes: [
			{ id: 'n1', position: { x: 10, y: 20 }, data: { name: 'Zaaksysteem', type: 'ApplicationComponent', elementRef: 'id-el-a', width: 120, height: 55 } },
			{ id: 'n2', position: { x: 200, y: 20 }, data: { name: 'Documentbeheer', type: 'ApplicationComponent', elementRef: 'id-el-b', width: 120, height: 55 } },
		],
		edges: [{ id: 'c1', source: 'n1', target: 'n2', data: { type } }],
	}
}

describe('isImported', () => {
	it('reads imported and an empty origin as GEMMA content, drawn as not', () => {
		expect(isImported({ origin: 'imported' })).toBe(true)
		expect(isImported({})).toBe(true)
		expect(isImported({ origin: '' })).toBe(true)
		expect(isImported({ origin: null })).toBe(true)
		expect(isImported({ origin: 'drawn' })).toBe(false)
	})
})

describe('drawing and saving a view (REQ-AVE-001)', () => {
	it('creates an empty drawn draft', async () => {
		const api = fakeApi()
		const view = await storeOver(api).createView({ name: 'Zaakgericht werken' })
		expect(api.calls).toEqual([['POST', BASE + 'view', expect.any(Object)]])
		expect(view).toMatchObject({
			identifier: 'id-new-1',
			name: 'Zaakgericht werken',
			origin: 'drawn',
			status: 'draft',
			nodes: [],
			connections: [],
			xml: { viewNodes: [], viewRelationships: [] },
		})
	})

	it('saves both nodes and the connection in the import shape', async () => {
		const api = fakeApi()
		const store = storeOver(api)
		const view = await store.createView({ name: 'V' })
		const canvas = twoConnectedNodes()

		const saved = await store.saveView(view, canvas)

		expect(saved.origin).toBe('drawn')
		expect(saved.xml.viewNodes.map((n) => [n.viewNodeId, n.elementRef, n.x, n.y])).toEqual([
			['n1', 'id-el-a', 10, 20],
			['n2', 'id-el-b', 200, 20],
		])
		expect(saved.nodes).toEqual(saved.xml.viewNodes)
		expect(saved.xml.viewRelationships).toEqual([
			{ viewRelationshipId: 'c1', modelRelationshipId: 'id-new-2', sourceId: 'n1', targetId: 'n2', type: 'Flow', bendpoints: [] },
		])
		expect(saved.connections).toEqual(saved.xml.viewRelationships)
		expect(saved['@self']).toEqual({ id: uuidOf(view) })
	})

	it('reopens at the saved positions with the connection', async () => {
		const api = fakeApi()
		const store = storeOver(api)
		const view = await store.saveView(await store.createView({ name: 'V' }), twoConnectedNodes())

		const reopened = await store.loadView(uuidOf(view))
		const { nodes, edges } = toCanvas(reopened.xml.viewNodes, reopened.xml.viewRelationships)

		expect(nodes.map((n) => [n.id, n.position])).toEqual([['n1', { x: 10, y: 20 }], ['n2', { x: 200, y: 20 }]])
		expect(edges.map((e) => [e.source, e.target, e.data.modelRelationshipId])).toEqual([['n1', 'n2', 'id-new-2']])
	})

	it('creates a palette element as a drawn element of its ArchiMate type before the view', async () => {
		const api = fakeApi()
		const store = storeOver(api)
		const view = await store.createView({ name: 'V' })
		const canvas = { nodes: [{ id: 'n9', position: { x: 0, y: 0 }, data: { name: 'Nieuw component', type: 'ApplicationComponent', elementRef: null, width: 120, height: 55 } }], edges: [] }

		const saved = await store.saveView(view, canvas)

		expect(api.tables.element).toEqual([
			{ identifier: 'id-new-2', type: 'ApplicationComponent', name: 'Nieuw component', origin: 'drawn', properties: [], '@self': { id: 'uuid-2' } },
		])
		expect(saved.xml.viewNodes[0].elementRef).toBe('id-new-2')
		expect(api.calls.map(([method, url]) => `${method} ${url.slice(BASE.length)}`)).toEqual([
			'POST view', 'POST element', `PUT view/${uuidOf(view)}`,
		])
	})

	it('refuses to save an imported view', async () => {
		const api = fakeApi()
		await expect(storeOver(api).saveView({ origin: 'imported', '@self': { id: 'g1' } }, twoConnectedNodes()))
			.rejects.toThrow('read-only')
		expect(api.calls).toEqual([])
	})
})

describe('relations behind connections (REQ-AVE-003)', () => {
	it('creates one drawn relation when none of that type joins the two elements', async () => {
		const api = fakeApi()
		api.tables.relation.push({ identifier: 'id-other', type: 'Serving', source: 'id-el-a', target: 'id-el-b', '@self': { id: 'r0' } })

		const id = await storeOver(api).ensureRelation({ type: 'Flow', source: 'id-el-a', target: 'id-el-b' })

		expect(id).toBe('id-new-1')
		expect(api.tables.relation.filter((r) => r.type === 'Flow')).toEqual([
			{ identifier: 'id-new-1', type: 'Flow', source: 'id-el-a', target: 'id-el-b', origin: 'drawn', properties: [], '@self': { id: 'uuid-1' } },
		])
		expect(api.calls[0]).toEqual(['GET', BASE + 'relation', { type: 'Flow', source: 'id-el-a', target: 'id-el-b', _limit: 1 }])
	})

	it('reuses the relation when the same elements are connected again', async () => {
		const api = fakeApi()
		const store = storeOver(api)
		const view = await store.createView({ name: 'V' })
		const canvas = twoConnectedNodes()
		canvas.edges.push({ id: 'c2', source: 'n1', target: 'n2', data: { type: 'Flow' } })

		const saved = await store.saveView(view, canvas)

		expect(api.tables.relation).toHaveLength(1)
		expect(saved.xml.viewRelationships.map((c) => c.modelRelationshipId)).toEqual(['id-new-2', 'id-new-2'])
	})

	it('reuses an imported GEMMA relation and does not change it', async () => {
		const api = fakeApi()
		const gemma = { identifier: 'id-gemma-rel', type: 'Flow', source: 'id-el-a', target: 'id-el-b', origin: 'imported', '@self': { id: 'r1' } }
		api.tables.relation.push(gemma)
		const store = storeOver(api)

		const saved = await store.saveView(await store.createView({ name: 'V' }), twoConnectedNodes())

		expect(saved.xml.viewRelationships[0].modelRelationshipId).toBe('id-gemma-rel')
		expect(api.tables.relation).toEqual([gemma])
		expect(api.calls.some(([method, url]) => method !== 'GET' && url.includes('/relation'))).toBe(false)
	})

	it('writes no second relation when the view write failed and the user saves again', async () => {
		const api = fakeApi()
		const store = storeOver(api)
		const view = await store.createView({ name: 'V' })
		const canvas = twoConnectedNodes()
		api.failNextPut()

		await expect(store.saveView(view, canvas)).rejects.toThrow('500')
		const saved = await store.saveView(view, canvas)

		expect(api.tables.relation).toHaveLength(1)
		expect(saved.xml.viewRelationships[0].modelRelationshipId).toBe('id-new-2')
		expect(api.calls.filter(([method, url]) => method === 'POST' && url.endsWith('/relation'))).toHaveLength(1)
	})
})

describe('Copy to edit (REQ-AVE-002)', () => {
	it('makes a drawn draft with a fresh identifier and basedOn, and leaves the source alone', async () => {
		const api = fakeApi()
		const source = {
			identifier: 'id-gemma-view',
			name: 'GEMMA Zaakgericht werken',
			viewpoint: 'Application Cooperation',
			origin: 'imported',
			status: 'published',
			xml: { viewNodes: [{ viewNodeId: 'g1', elementRef: 'id-el-a', x: 1, y: 2, width: 3, height: 4 }], viewRelationships: [] },
			'@self': { id: 'gemma-uuid' },
		}
		api.tables.view.push(source)
		const before = JSON.parse(JSON.stringify(source))

		const copy = await storeOver(api).copyToEdit(source, 'GEMMA Zaakgericht werken (copy)')

		expect(copy).toMatchObject({
			identifier: 'id-new-1',
			name: 'GEMMA Zaakgericht werken (copy)',
			viewpoint: 'Application Cooperation',
			origin: 'drawn',
			status: 'draft',
			basedOn: 'gemma-uuid',
			nodes: source.xml.viewNodes,
			xml: { viewNodes: source.xml.viewNodes, viewRelationships: [] },
		})
		expect(uuidOf(copy)).not.toBe('gemma-uuid')
		expect(api.tables.view[0]).toEqual(before)
		expect(api.calls.map(([method]) => method)).toEqual(['POST'])
	})
})

describe('saved versions (REQ-AVE-005)', () => {
	it('numbers versions one up and copies the saved nodes and connections', async () => {
		const api = fakeApi()
		const store = storeOver(api)
		const view = await store.saveView(await store.createView({ name: 'V' }), twoConnectedNodes())

		const first = await store.saveVersion(view, 'Eerste opzet')
		const second = await store.saveVersion(view, 'Tweede opzet')

		expect(first).toMatchObject({
			view: uuidOf(view),
			versionNumber: 1,
			label: 'Eerste opzet',
			savedBy: 'ines',
			savedAt: '2026-11-14T10:00:00.000Z',
			nodes: view.xml.viewNodes,
			connections: view.xml.viewRelationships,
		})
		expect(second.versionNumber).toBe(2)
		expect((await store.listVersions(uuidOf(view))).map((v) => v.label)).toEqual(['Eerste opzet', 'Tweede opzet'])
	})

	it('keeps a snapshot apart from later edits of the view', async () => {
		const api = fakeApi()
		const store = storeOver(api)
		const view = await store.saveView(await store.createView({ name: 'V' }), twoConnectedNodes())
		const version = await store.saveVersion(view, 'v1')

		view.xml.viewNodes[0].x = 999

		expect(api.tables['view-version'][0].nodes[0].x).toBe(10)
		expect(store.snapshotOf(version).nodes[0].x).toBe(10)
		expect(store.snapshotOf(view).nodes[0].x).toBe(999)
	})
})
