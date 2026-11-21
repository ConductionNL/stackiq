// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The view editor and the version compare, mounted over a fake OpenRegister
 * objects API: imported views read-only with Copy to edit, the lock notice,
 * drawing and saving, and the compare's marks and text summary
 * (architecture-views-editor, tasks 4, 6 and 7).
 *
 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'

const BASE = '/index.php/apps/openregister/api/objects/7/'
const db = { view: [], element: [], relation: [], 'view-version': [] }
const calls = []
let sequence = 0
const lockState = { mode: 'mine', holder: null }

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
		return { data: { results: db[schema].filter((o) => filters.every(([key, value]) => o[key] === value)) } }
	},
	async post(url, body) {
		calls.push(['POST', url, body])
		const schema = url.slice(BASE.length).split('/')[0]
		const saved = { ...JSON.parse(JSON.stringify(body)), '@self': { id: `uuid-${++sequence}` } }
		db[schema].push(saved)
		return { data: saved }
	},
	async put(url, body) {
		calls.push(['PUT', url, body])
		const [schema, id] = url.slice(BASE.length).split('/')
		const saved = { ...JSON.parse(JSON.stringify(body)), '@self': { id } }
		db[schema] = db[schema].map((o) => (o['@self'].id === id ? saved : o))
		return { data: saved }
	},
}

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'ines' }) }))
vi.mock('@nextcloud/l10n', () => {
	const fill = (text, vars) => String(text).replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m))
	return {
		translate: (app, text, vars) => fill(text, vars),
		n: (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count)),
	}
})

/**
 * A stub that renders its default slot inside a tag.
 *
 * @param {string} name The component name.
 * @param {string} tag The element.
 * @return {object}
 */
function passthrough (name, tag = 'div') {
  return defineComponent({
	name,
	inheritAttrs: false,
	props: ['name', 'description', 'type', 'disabled', 'to', 'label', 'inputLabel', 'modelValue', 'options'],
	emits: ['click', 'update:modelValue', 'search'],
	setup(props, { slots, emit, attrs }) {
		return () => h(tag, {
			...attrs,
			'data-stub': name,
			'data-label': props.inputLabel ?? props.label ?? props.name ?? '',
			disabled: props.disabled,
			onClick: () => emit('click'),
		}, [props.name ? h('span', props.name) : null, slots.default?.()])
	},
})
}

// The Export menu has its own spec (viewExportMenu.spec.js).
vi.mock('../../src/components/architecture/ViewExportMenu.vue', () => ({
	default: { name: 'ViewExportMenu', props: ['viewId', 'viewName'], template: '<div class="view-export-stub" :data-view="viewId" />' },
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: passthrough('NcButton', 'button'),
	NcCheckboxRadioSwitch: passthrough('NcCheckboxRadioSwitch'),
	NcEmptyContent: passthrough('NcEmptyContent'),
	NcLoadingIcon: passthrough('NcLoadingIcon'),
	NcNoteCard: passthrough('NcNoteCard'),
	NcSelect: passthrough('NcSelect'),
	NcTextField: passthrough('NcTextField'),
}))

vi.mock('@conduction/nextcloud-vue', () => {
	class LockConflictError extends Error {
		constructor(message, info) {
			super(message)
			this.name = 'LockConflictError'
			this.lockedBy = info?.user ?? null
		}
	}
	const CnGraphCanvas = defineComponent({
		name: 'CnGraphCanvas',
		props: ['nodes', 'edges', 'readOnly', 'showMiniMap'],
		emits: ['connect', 'nodes-change', 'edges-change', 'node-remove'],
		setup(props, { slots }) {
			return () => h('div', { 'data-stub': 'CnGraphCanvas', 'data-readonly': String(props.readOnly) }, [
				...props.nodes.map((node) => h('div', { class: 'stub-node', 'data-id': node.id }, slots.node?.({ node }))),
				...props.edges.map((edge) => h('div', { class: ['stub-edge', edge.class], 'data-id': edge.id }, edge.label ?? '')),
			])
		},
	})
	return {
		CnGraphCanvas,
		LockConflictError,
		useObjectLock() {
			const mine = ref(false)
			const locked = ref(false)
			const by = ref(null)
			return {
				locked,
				lockedByMe: mine,
				lockedBy: by,
				async acquire() {
					if (lockState.mode === 'other') throw new LockConflictError('locked', { user: lockState.holder })
					mine.value = true
					locked.value = true
					by.value = 'ines'
				},
				async release() {
					mine.value = false
				},
			}
		},
	}
})

const { default: ArchitectureViewEditor } = await import('../../src/views/architecture/ArchitectureViewEditor.vue')
const { default: ViewVersionCompare } = await import('../../src/views/architecture/ViewVersionCompare.vue')

const nodeA = { viewNodeId: 'n1', parent: null, elementRef: 'id-el-a', x: 10, y: 20, width: 120, height: 55, name: 'Zaaksysteem', type: 'ApplicationComponent' }
const nodeB = { viewNodeId: 'n2', parent: null, elementRef: 'id-el-b', x: 200, y: 20, width: 120, height: 55, name: 'Documentbeheer', type: 'ApplicationComponent' }

/**
 * Mount a page at `/views/:id`.
 *
 * @param {object} component The page.
 * @param {string} id The route id.
 * @return {Promise<{wrapper: object, router: object}>}
 */
async function open(component, id) {
	const router = { push: vi.fn() }
	const wrapper = mount(component, {
		props: { register: 7 },
		global: { mocks: { $route: { params: { id } }, $router: router } },
	})
	await flushPromises()
	return { wrapper, router }
}

const button = (wrapper, text) => wrapper.findAll('button').find((b) => b.text() === text)

beforeEach(() => {
	for (const key of Object.keys(db)) db[key] = []
	calls.length = 0
	sequence = 0
	lockState.mode = 'mine'
	lockState.holder = null
})

describe('the view editor', () => {
	it('opens an imported view read-only and Copy to edit opens a drawn copy', async () => {
		db.view.push({ identifier: 'id-g', name: 'GEMMA view', origin: 'imported', status: 'published', xml: { viewNodes: [nodeA], viewRelationships: [] }, '@self': { id: 'g1' } })

		const { wrapper, router } = await open(ArchitectureViewEditor, 'g1')

		expect(wrapper.find('[data-stub="CnGraphCanvas"]').attributes('data-readonly')).toBe('true')
		expect(wrapper.find('.view-editor__palette').exists()).toBe(false)
		expect(button(wrapper, 'Save')).toBeUndefined()
		expect(calls.some(([method, url]) => method === 'POST' && url.endsWith('/lock'))).toBe(false)

		await button(wrapper, 'Copy to edit').trigger('click')
		await flushPromises()

		const copy = db.view.find((v) => v.origin === 'drawn')
		expect(copy).toMatchObject({ name: 'GEMMA view (copy)', status: 'draft', basedOn: 'g1', nodes: [nodeA] })
		expect(copy.identifier).not.toBe('id-g')
		expect(router.push).toHaveBeenCalledWith({ name: 'ViewEditor', params: { id: copy['@self'].id } })
		expect(db.view.find((v) => v['@self'].id === 'g1').origin).toBe('imported')
	})

	it('shows a drawn view read-only with a notice naming the colleague who holds the lock', async () => {
		db.view.push({ name: 'Mine', origin: 'drawn', status: 'draft', xml: { viewNodes: [nodeA], viewRelationships: [] }, '@self': { id: 'd1' } })
		lockState.mode = 'other'
		lockState.holder = 'bert'

		const { wrapper } = await open(ArchitectureViewEditor, 'd1')

		expect(wrapper.find('[data-testid="view-editor-lock-notice"]').text()).toContain('bert is editing this view')
		expect(wrapper.find('[data-stub="CnGraphCanvas"]').attributes('data-readonly')).toBe('true')
		expect(wrapper.find('.view-editor__palette').exists()).toBe(false)
	})

	it('connects two placed elements and saves them with a drawn relation', async () => {
		db.view.push({ name: 'Mine', origin: 'drawn', status: 'draft', xml: { viewNodes: [nodeA, nodeB], viewRelationships: [] }, '@self': { id: 'd1' } })

		const { wrapper } = await open(ArchitectureViewEditor, 'd1')
		const canvas = wrapper.findComponent({ name: 'CnGraphCanvas' })
		expect(canvas.props('readOnly')).toBe(false)
		expect(button(wrapper, 'Save').attributes('disabled')).toBeDefined()

		canvas.vm.$emit('connect', { source: 'n1', target: 'n2' })
		await flushPromises()
		expect(wrapper.findAll('.stub-edge')).toHaveLength(1)

		await button(wrapper, 'Save').trigger('click')
		await flushPromises()

		expect(db.relation).toEqual([expect.objectContaining({ type: 'Association', source: 'id-el-a', target: 'id-el-b', origin: 'drawn' })])
		const saved = db.view[0]
		expect(saved.origin).toBe('drawn')
		expect(saved.xml.viewNodes.map((n) => [n.viewNodeId, n.x, n.y])).toEqual([['n1', 10, 20], ['n2', 200, 20]])
		expect(saved.xml.viewRelationships).toEqual([expect.objectContaining({ sourceId: 'n1', targetId: 'n2', modelRelationshipId: db.relation[0].identifier })])
		expect(wrapper.text()).toContain('The view is saved.')
	})

	it('lays out a drafted view on first open, keeps its mark on save and names who it was drafted for', async () => {
		const unplaced = ({ x, y, ...node }) => node
		db.view.push({
			name: 'Concept',
			origin: 'assistant',
			status: 'draft',
			draftedFor: 'anna',
			draftedAt: '2026-09-27T09:00:00+00:00',
			xml: { viewNodes: [unplaced(nodeA), unplaced(nodeB)], viewRelationships: [{ viewRelationshipId: 'c1', modelRelationshipId: 'id-rel', sourceId: 'n1', targetId: 'n2', type: 'Flow', bendpoints: [] }] },
			'@self': { id: 'a1' },
		})

		const { wrapper } = await open(ArchitectureViewEditor, 'a1')
		const notice = wrapper.find('[data-testid="view-editor-draft-notice"]')
		expect(notice.exists()).toBe(true)
		expect(notice.text()).toContain('Drafted by an assistant for anna on')
		expect(wrapper.text()).toContain('Drafted by an assistant ·')

		// The layout is unsaved work: Save is offered straight away.
		expect(button(wrapper, 'Save').attributes('disabled')).toBeUndefined()
		await button(wrapper, 'Save').trigger('click')
		await flushPromises()

		const saved = db.view[0]
		expect(saved.origin).toBe('assistant')
		const points = saved.xml.viewNodes.map((n) => `${n.x},${n.y}`)
		expect(points.every((point) => !point.includes('undefined'))).toBe(true)
		expect(new Set(points).size).toBe(2)
		expect(wrapper.find('[data-testid="view-editor-draft-notice"]').exists()).toBe(true)
	})

	it('offers the Export menu on a saved view, and not on /views/new', async () => {
		db.view.push({ name: 'Mine', origin: 'drawn', status: 'draft', xml: { viewNodes: [nodeA], viewRelationships: [] }, '@self': { id: 'd1' } })

		const saved = await open(ArchitectureViewEditor, 'd1')
		const fresh = await open(ArchitectureViewEditor, 'new')

		expect(saved.wrapper.get('.view-export-stub').attributes('data-view')).toBe('d1')
		expect(fresh.wrapper.find('.view-export-stub').exists()).toBe(false)
	})

	it('asks for a name and creates a drawn draft on /views/new', async () => {
		const { wrapper, router } = await open(ArchitectureViewEditor, 'new')
		wrapper.findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', 'Zaakgericht werken')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(db.view).toEqual([expect.objectContaining({ name: 'Zaakgericht werken', origin: 'drawn', status: 'draft' })])
		expect(router.push).toHaveBeenCalledWith({ name: 'ViewEditor', params: { id: 'uuid-1' } })
	})
})

describe('the version compare', () => {
	it('marks the added node on the current side and lists one added node', async () => {
		db.view.push({ name: 'Mine', origin: 'drawn', xml: { viewNodes: [nodeA, nodeB, { ...nodeB, viewNodeId: 'n3', name: 'Zaakregistratie' }], viewRelationships: [] }, '@self': { id: 'd1' } })
		db['view-version'].push({ view: 'd1', versionNumber: 1, label: 'Eerste opzet', nodes: [nodeA, nodeB], connections: [], '@self': { id: 'v1' } })

		const { wrapper } = await open(ViewVersionCompare, 'd1')

		const sides = wrapper.findAll('.view-compare__side')
		expect(sides.map((s) => s.find('h3').text())).toEqual(['Version 1: Eerste opzet', 'Current view'])
		expect(sides[1].find('.view-compare__node--added').text()).toContain('Zaakregistratie')
		expect(sides[0].find('[class*="view-compare__node--"]').exists()).toBe(false)
		const summary = wrapper.find('.view-compare__summary')
		expect(summary.find('p').text()).toBe('1 added node, 0 removed nodes, 0 changed nodes')
		expect(summary.findAll('li').map((li) => li.text())).toEqual(['Node Zaakregistratie added'])
	})

	it('says there are no saved versions yet', async () => {
		db.view.push({ name: 'Mine', origin: 'drawn', xml: { viewNodes: [], viewRelationships: [] }, '@self': { id: 'd1' } })

		const { wrapper } = await open(ViewVersionCompare, 'd1')

		expect(wrapper.find('[data-label="No saved versions yet"]').exists()).toBe(true)
		expect(wrapper.find('.view-compare__summary').exists()).toBe(false)
	})
})
