// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The relations of a data entity (architecture-data-model-and-ggm, task 3):
 * found through the identifier field for an imported entity, whose uuid is
 * its GEMMA object id, and for a drawn one; drawn around the entity with the
 * name and both cardinalities on the edge; every colour a CSS variable; the
 * same relations listed as text; a node or row opens that entity's page.
 *
 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-002-a-data-entity-page-shall-draw-the-entitys-relations-to-other-entities
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const BASE = '/index.php/apps/openregister/api/objects/7/'
const db = { element: [], relation: [] }
const calls = []

const http = {
	async get(url, config = {}) {
		calls.push(['GET', url, config.params])
		const [schema, id] = url.slice(BASE.length).split('/').map(decodeURIComponent)
		if (id) {
			const found = db[schema].find((o) => o['@self'].id === id)
			if (!found) throw Object.assign(new Error('404'), { response: { status: 404 } })
			return { data: JSON.parse(JSON.stringify(found)) }
		}
		const filters = Object.entries(config.params ?? {}).filter(([key]) => !key.startsWith('_'))
		const limit = config.params?._limit ?? Infinity
		return { data: { results: db[schema].filter((o) => filters.every(([key, value]) => o[key] === value)).slice(0, limit) } }
	},
	async put() {
		throw new Error('the relations widget never writes')
	},
}

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/l10n', () => {
	const fill = (text, vars) => String(text).replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m))
	return { translate: (app, text, vars) => fill(text, vars) }
})

/**
 * A stub that renders its default slot inside a tag.
 *
 * @param {string} name The component name.
 * @param {string} tag The element.
 * @return {object}
 */
function passthrough(name, tag = 'div') {
	return defineComponent({
		name,
		inheritAttrs: false,
		props: ['name', 'description', 'type', 'variant'],
		emits: ['click'],
		setup(props, { slots, emit, attrs }) {
			return () => h(tag, { ...attrs, 'data-stub': name, onClick: () => emit('click') }, [props.name ? h('span', props.name) : null, slots.default?.()])
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: passthrough('NcButton', 'button'),
	NcEmptyContent: passthrough('NcEmptyContent'),
	NcLoadingIcon: passthrough('NcLoadingIcon'),
	NcNoteCard: passthrough('NcNoteCard'),
}))

vi.mock('@conduction/nextcloud-vue', () => ({
	CnIcon: passthrough('CnIcon', 'span'),
	CnWidgetWrapper: defineComponent({
		name: 'CnWidgetWrapper',
		props: ['title'],
		setup(props, { slots }) {
			return () => h('section', { 'data-stub': 'CnWidgetWrapper' }, [h('h2', props.title), slots.default?.()])
		},
	}),
	CnRelationshipGraph: defineComponent({
		name: 'CnRelationshipGraph',
		props: ['nodes', 'edges', 'layout', 'nodeColor', 'rootColor', 'edgeColor', 'strokeColor', 'ariaLabel'],
		emits: ['node-click'],
		setup(props, { emit }) {
			return () => h('div', { 'data-stub': 'CnRelationshipGraph' }, [
				...props.nodes.map((node) => h('button', { class: 'stub-node', 'data-id': node.id, 'data-root': String(Boolean(node.isRoot)), onClick: () => emit('node-click', node) }, node.label)),
				...props.edges.map((edge) => h('span', { class: 'stub-edge', 'data-source': edge.source, 'data-target': edge.target }, edge.label)),
			])
		},
	}),
}))

const { default: DataEntityRelations } = await import('../../src/views/architecture/DataEntityRelations.vue')
const { buildEntityGraph, createDataEntityApi, edgeLabel, entityRelations, sortedAttributes, attributesToSave } = await import('../../src/utils/dataEntityGraph.js')

const tt = (app, text, vars) => String(text).replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m))

const melding = { '@self': { id: 'uuid-melding' }, identifier: 'id-seed-el-melding', type: 'BusinessObject', name: 'Melding openbare ruimte', origin: 'drawn' }
const melder = { '@self': { id: 'uuid-melder' }, identifier: 'id-seed-el-melder', type: 'BusinessObject', name: 'Melder', origin: 'drawn' }
// An imported GGM entity: its uuid is the GEMMA object id, not its identifier.
const werknemer = { '@self': { id: 'b1c2d3e4-0000-4000-8000-00000000aaaa' }, identifier: 'id-0b8d1a27-ggm-werknemer', type: 'BusinessObject', name: 'Werknemer', origin: 'imported' }
const formatieplaats = { '@self': { id: 'b1c2d3e4-0000-4000-8000-00000000bbbb' }, identifier: 'id-0b8d1a27-ggm-formatieplaats', type: 'BusinessObject', name: 'Formatieplaats' }

const gedaanDoor = { '@self': { id: 'uuid-rel-1' }, identifier: 'id-seed-rel-melding-melder', type: 'Association', name: 'gedaan door', source: melding.identifier, target: melder.identifier, sourceCardinality: '0..*', targetCardinality: '1', origin: 'drawn' }

/**
 * Mount the widget for one entity.
 *
 * @param {string} objectId The entity uuid.
 * @return {Promise<{wrapper: object, router: object}>}
 */
async function open(objectId) {
	const router = { push: vi.fn() }
	const wrapper = mount(DataEntityRelations, {
		props: { objectId, register: 7 },
		global: { mocks: { $router: router } },
	})
	await flushPromises()
	return { wrapper, router }
}

beforeEach(() => {
	db.element = [melding, melder, werknemer, formatieplaats].map((o) => JSON.parse(JSON.stringify(o)))
	db.relation = [JSON.parse(JSON.stringify(gedaanDoor))]
	calls.length = 0
})

describe('edgeLabel', () => {
	it('holds the name and both cardinalities', () => {
		expect(edgeLabel(gedaanDoor, tt)).toBe('gedaan door, 0..* to 1')
	})

	it('falls back on the type and leaves cardinalities out when none is set', () => {
		expect(edgeLabel({ type: 'Specialization', name: ' ' }, tt)).toBe('Specialization')
	})

	it('marks a missing end with a question mark', () => {
		expect(edgeLabel({ type: 'Association', name: 'heeft', targetCardinality: '1..*' }, tt)).toBe('heeft, ? to 1..*')
	})
})

describe('entityRelations', () => {
	it('keeps data model types touching the entity, each relation once', () => {
		const flow = { '@self': { id: 'f' }, type: 'Flow', source: 'id-a', target: 'id-b' }
		const elsewhere = { '@self': { id: 'e' }, type: 'Association', source: 'id-x', target: 'id-y' }
		const self = { '@self': { id: 's' }, type: 'Aggregation', source: 'id-a', target: 'id-a' }
		const kept = entityRelations('id-a', [gedaanDoor, flow, self, { ...self }], [elsewhere, self])
		expect(kept.map((r) => r['@self'].id)).toEqual(['s'])
	})
})

describe('buildEntityGraph', () => {
	it('puts the entity at the root and keeps an unreadable neighbour by identifier', () => {
		const relation = { '@self': { id: 'r' }, type: 'Association', name: 'hoort bij', source: 'id-missing', target: melding.identifier }
		const graph = buildEntityGraph(melding, [relation], new Map(), tt)
		expect(graph.nodes).toEqual([
			{ id: melding.identifier, label: 'Melding openbare ruimte', isRoot: true, uuid: 'uuid-melding' },
			{ id: 'id-missing', label: 'id-missing', uuid: null },
		])
		expect(graph.rows[0]).toMatchObject({ direction: 'incoming', other: 'id-missing', otherUuid: null })
	})

	it('draws a specialization dashed', () => {
		const relation = { '@self': { id: 'r' }, type: 'Specialization', source: werknemer.identifier, target: formatieplaats.identifier }
		const graph = buildEntityGraph(werknemer, [relation], new Map([[formatieplaats.identifier, formatieplaats]]), tt)
		expect(graph.edges).toEqual([{ id: 'r', source: werknemer.identifier, target: formatieplaats.identifier, label: 'Specialization', dashed: true }])
	})
})

describe('sortedAttributes and attributesToSave', () => {
	it('lists key attributes first and keeps the stored order otherwise', () => {
		const entity = { attributes: [{ name: 'datum' }, { name: 'nummer', isKey: true }, { name: 'locatie', dataType: 'text' }, 'junk'] }
		expect(sortedAttributes(entity).map((a) => [a.name, a.isKey, a.dataType])).toEqual([
			['nummer', true, 'text'],
			['datum', false, 'text'],
			['locatie', false, 'text'],
		])
		expect(sortedAttributes(null)).toEqual([])
	})

	it('drops nameless rows, trims, and keeps a description only when there is one', () => {
		expect(attributesToSave([
			{ name: '  naam ', dataType: 'text', isKey: false, description: ' ' },
			{ name: '', dataType: 'date', isKey: true },
			{ name: 'bsn', dataType: 'nonsense', isKey: true, description: ' het nummer ' },
		])).toEqual([
			{ name: 'bsn', dataType: 'text', isKey: true, description: 'het nummer' },
			{ name: 'naam', dataType: 'text', isKey: false },
		])
	})
})

describe('saving the attributes of an own entity', () => {
	it('sends the properties list the element schema requires, also when the read left it out', async () => {
		const put = vi.fn(async (url, body) => ({ data: body }))
		const api = createDataEntityApi({ http: { get: vi.fn(), put }, register: 7 })
		const entity = { '@self': { id: 'e-1' }, id: 'e-1', identifier: 'id-1', type: 'BusinessObject', name: 'Vergunning', origin: 'drawn' }

		await api.saveAttributes(entity, [{ name: 'zaaknummer', dataType: 'text', isKey: true }])

		const [url, body] = put.mock.calls[0]
		expect(url).toBe('/index.php/apps/openregister/api/objects/7/element/e-1')
		expect(body.properties).toEqual([])
		expect(body.attributes).toEqual([{ name: 'zaaknummer', dataType: 'text', isKey: true }])
		expect(body).not.toHaveProperty('@self')
	})

	it('keeps properties the entity already has', async () => {
		const put = vi.fn(async (url, body) => ({ data: body }))
		const api = createDataEntityApi({ http: { get: vi.fn(), put }, register: 7 })
		await api.saveAttributes({ '@self': { id: 'e-2' }, origin: 'drawn', properties: [{ key: 'k', value: 'v' }] }, [])
		expect(put.mock.calls[0][1].properties).toEqual([{ key: 'k', value: 'v' }])
	})
})

describe('DataEntityRelations', () => {
	it('draws Melder around Melding with the label gedaan door, 0..* to 1, and lists it as text', async () => {
		const { wrapper } = await open('uuid-melding')

		const nodes = wrapper.findAll('.stub-node')
		expect(nodes.map((n) => [n.text(), n.attributes('data-root')])).toEqual([
			['Melding openbare ruimte', 'true'],
			['Melder', 'false'],
		])
		expect(wrapper.find('.stub-edge').text()).toBe('gedaan door, 0..* to 1')

		const rows = wrapper.findAll('[data-testid="data-entity-relations-table"] tbody tr')
		expect(rows).toHaveLength(1)
		expect(rows[0].text()).toContain('gedaan door')
		expect(rows[0].text()).toContain('Association')
		expect(rows[0].text()).toContain('Melder')
		expect(rows[0].text()).toContain('(outgoing)')
		expect(rows[0].text()).toContain('0..* to 1')
	})

	it('finds relations through the identifier, for a drawn entity at either end', async () => {
		await open('uuid-melder')
		const relationQueries = calls.filter(([, url]) => url === BASE + 'relation').map(([, , params]) => params)
		expect(relationQueries).toEqual([
			{ source: 'id-seed-el-melder', _limit: 200 },
			{ target: 'id-seed-el-melder', _limit: 200 },
		])
	})

	it('finds relations of an imported entity whose uuid differs from its identifier', async () => {
		db.relation.push({ '@self': { id: 'uuid-rel-2' }, identifier: 'id-ggm-rel', type: 'Association', name: 'bezet', source: werknemer.identifier, target: formatieplaats.identifier })
		db.relation.push({ '@self': { id: 'uuid-rel-3' }, identifier: 'id-ggm-rel-uuid', type: 'Association', name: 'by uuid', source: werknemer['@self'].id, target: formatieplaats.identifier })
		const { wrapper } = await open(werknemer['@self'].id)

		expect(wrapper.findAll('.stub-node').map((n) => n.text())).toEqual(['Werknemer', 'Formatieplaats'])
		expect(wrapper.findAll('.stub-edge').map((e) => e.text())).toEqual(['bezet'])
	})

	it('passes a CSS variable for every colour the graph draws', async () => {
		const { wrapper } = await open('uuid-melding')
		const graph = wrapper.findComponent({ name: 'CnRelationshipGraph' })
		for (const prop of ['nodeColor', 'rootColor', 'edgeColor', 'strokeColor']) {
			expect(graph.props(prop), prop).toMatch(/^var\(--color-[a-z-]+\)$/)
		}
		expect(graph.props('layout')).toBe('radial')
	})

	it('opens the neighbour from its node and from its row, never from the root', async () => {
		const { wrapper, router } = await open('uuid-melding')

		await wrapper.findAll('.stub-node')[0].trigger('click')
		expect(router.push).not.toHaveBeenCalled()

		await wrapper.findAll('.stub-node')[1].trigger('click')
		expect(router.push).toHaveBeenLastCalledWith({ name: 'GegevensobjectDetail', params: { id: 'uuid-melder' } })

		router.push.mockClear()
		await wrapper.find('[data-testid="data-entity-relations-table"] tbody button').trigger('click')
		expect(router.push).toHaveBeenCalledWith({ name: 'GegevensobjectDetail', params: { id: 'uuid-melder' } })
	})

	it('says so when the entity has no relation', async () => {
		db.relation = []
		const { wrapper } = await open('uuid-melding')
		expect(wrapper.find('[data-stub="CnRelationshipGraph"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('No relations yet')
	})

	it('says when the relation limit is reached', async () => {
		db.relation = Array.from({ length: 200 }, (_, i) => ({ '@self': { id: `r${i}` }, type: 'Association', name: `r${i}`, source: melding.identifier, target: melder.identifier }))
		const { wrapper } = await open('uuid-melding')
		expect(wrapper.find('[data-testid="data-entity-relations-limit"]').text()).toBe('Only the first 200 relations at each end are shown.')
	})

	it('shows an error when the entity cannot be read', async () => {
		const { wrapper } = await open('uuid-gone')
		expect(wrapper.text()).toContain('The relations could not be loaded.')
	})
})
