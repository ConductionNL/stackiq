// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The "Show applications" control on the view page: shown on an imported
 * view only, both switches off at first, each switch reloading the view with
 * exactly its enrichment flag, the overlay nodes nested in their component,
 * the legend, and a click through to the usage
 * (architecture-shared-usage-on-views, task 3).
 *
 * @spec openspec/changes/architecture-shared-usage-on-views/specs/shared-usage-on-views/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, ref } from 'vue'

const BASE = '/index.php/apps/openregister/api/objects/7/'
const VIEWS = '/index.php/apps/stackiq/api/views/'
const db = { view: [] }
const enriched = {}
const calls = []

const http = {
	async get(url, config = {}) {
		calls.push(['GET', url, config.params])
		if (url.startsWith(VIEWS)) {
			const id = decodeURIComponent(url.slice(VIEWS.length))
			if (enriched[id] === 'fail') throw new Error('500')
			return { data: { success: true, view: enriched[id] ?? null } }
		}
		const [schema, id] = url.slice(BASE.length).split('/').map(decodeURIComponent)
		const found = db[schema].find((o) => o['@self'].id === id)
		if (!found) throw Object.assign(new Error('404'), { response: { status: 404 } })
		return { data: JSON.parse(JSON.stringify(found)) }
	},
	async post(url, body) {
		calls.push(['POST', url, body])
		return { data: body }
	},
	async put(url, body) {
		calls.push(['PUT', url, body])
		return { data: body }
	},
}

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'ines' }) }))
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
		props: ['name', 'description', 'type', 'disabled', 'to', 'label', 'inputLabel', 'modelValue', 'options'],
		emits: ['click', 'update:modelValue', 'search'],
		setup(props, { slots, emit, attrs }) {
			return () => h(tag, {
				...attrs,
				'data-stub': name,
				'data-checked': String(props.modelValue),
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
	class LockConflictError extends Error {}
	const CnGraphCanvas = defineComponent({
		name: 'CnGraphCanvas',
		props: ['nodes', 'edges', 'readOnly', 'showMiniMap'],
		emits: ['connect', 'nodes-change', 'edges-change', 'node-remove', 'node-select'],
		setup(props, { slots }) {
			return () => h('div', { 'data-stub': 'CnGraphCanvas' }, props.nodes.map((node) => h('div', {
				class: ['stub-node', node.class],
				'data-id': node.id,
				'data-parent': node.parentNode ?? '',
			}, slots.node?.({ node }))))
		},
	})
	return {
		CnGraphCanvas,
		LockConflictError,
		useObjectLock() {
			const mine = ref(false)
			return {
				locked: ref(false),
				lockedByMe: mine,
				lockedBy: ref(null),
				async acquire() {
					mine.value = true
				},
				async release() {
					mine.value = false
				},
			}
		},
	}
})

const { default: ArchitectureViewEditor } = await import('../../src/views/architecture/ArchitectureViewEditor.vue')

const component = { viewNodeId: 'n1', parent: null, elementRef: 'id-ref-zaak', x: 10, y: 20, width: 220, height: 160, name: 'Zaakregistratiecomponent', type: 'ApplicationComponent' }
const own = { '@self': { id: 'u-own' }, moduleName: 'Zaaksysteem', module: 'm1' }
const shared = { '@self': { id: 'u-shared' }, moduleName: 'Documentbeheer', module: 'm2', _sourceOrganization: 'Gemeente Oost' }

/**
 * Mount the page at `/views/:id`.
 *
 * @param {string} id The route id.
 * @return {Promise<{wrapper: object, router: object}>}
 */
async function open(id) {
	const router = { push: vi.fn() }
	const wrapper = mount(ArchitectureViewEditor, {
		props: { register: 7 },
		global: { mocks: { $route: { params: { id } }, $router: router } },
	})
	await flushPromises()
	return { wrapper, router }
}

const switches = (wrapper) => wrapper.findAll('[data-stub="NcCheckboxRadioSwitch"]')
const enrichCalls = () => calls.filter(([method, url]) => method === 'GET' && url.startsWith(VIEWS))

/**
 * Flip a switch the way NcCheckboxRadioSwitch does.
 *
 * @param {object} wrapper The page.
 * @param {number} index Which switch.
 * @param {boolean} value The new state.
 * @return {Promise<void>}
 */
async function flip(wrapper, index, value) {
	await wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })[index].vm.$emit('update:modelValue', value)
	await flushPromises()
}

beforeEach(() => {
	setActivePinia(createPinia())
	db.view = [
		{ name: 'GEMMA view', origin: 'imported', status: 'published', xml: { viewNodes: [component], viewRelationships: [] }, '@self': { id: 'g1' } },
		{ name: 'Mine', origin: 'drawn', status: 'draft', xml: { viewNodes: [component], viewRelationships: [] }, '@self': { id: 'd1' } },
	]
	for (const key of Object.keys(enriched)) delete enriched[key]
	enriched.g1 = { viewNodes: [{ ...component, usage: [own], deelnamesGebruik: [shared] }] }
	calls.length = 0
})

describe('the Show applications control', () => {
	it('is shown on an imported view with both switches off and nothing extra loaded', async () => {
		const { wrapper } = await open('g1')

		expect(wrapper.find('[data-testid="view-editor-usage-control"]').text()).toContain('Show applications')
		expect(switches(wrapper).map((s) => [s.text(), s.attributes('data-checked')])).toEqual([
			['Our applications', 'false'],
			['Shared with partners', 'false'],
		])
		expect(enrichCalls()).toEqual([])
		expect(wrapper.findAll('.stub-node').map((n) => n.attributes('data-id'))).toEqual(['n1'])
		expect(wrapper.find('[data-testid="usage-overlay-legend"]').exists()).toBe(false)
	})

	it('is not shown on a drawn view', async () => {
		const { wrapper } = await open('d1')

		expect(wrapper.find('[data-testid="view-editor-usage-control"]').exists()).toBe(false)
		expect(switches(wrapper)).toHaveLength(0)
	})

	it('loads shared usage only and nests it, dashed and badged, inside its component', async () => {
		const { wrapper } = await open('g1')

		await flip(wrapper, 1, true)

		expect(enrichCalls()).toEqual([['GET', VIEWS + 'g1', { include_deelnames_gebruik: true }]])
		const overlay = wrapper.findAll('.stub-node').filter((n) => n.attributes('data-id') !== 'n1')
		expect(overlay.map((n) => [n.attributes('data-id'), n.attributes('data-parent')])).toEqual([['n1::usage::u-shared', 'n1']])
		const body = overlay[0].find('.view-editor__usage-node')
		expect(body.classes()).toContain('view-editor__usage-node--shared')
		expect(body.text()).toContain('Documentbeheer')
		expect(body.find('.view-editor__usage-badge').text()).toBe('Shared')
		expect(body.attributes('aria-label')).toBe('Documentbeheer, shared by Gemeente Oost')
		expect(wrapper.find('[data-testid="usage-overlay-legend"]').text()).toContain('Shared with a partner')
		expect(wrapper.find('[data-testid="usage-overlay-legend"]').text()).not.toContain('Our application')
	})

	it('shows both kinds with both switches on, and the legend lists both', async () => {
		const { wrapper } = await open('g1')

		await flip(wrapper, 0, true)
		await flip(wrapper, 1, true)

		expect(enrichCalls().at(-1)).toEqual(['GET', VIEWS + 'g1', { include_gebruik: true, include_deelnames_gebruik: true }])
		const ids = wrapper.findAll('.stub-node').map((n) => n.attributes('data-id'))
		expect(ids).toEqual(['n1', 'n1::usage::u-own', 'n1::usage::u-shared'])
		const ownBody = wrapper.find('[data-id="n1::usage::u-own"] .view-editor__usage-node')
		expect(ownBody.classes()).not.toContain('view-editor__usage-node--shared')
		expect(ownBody.find('.view-editor__usage-badge').exists()).toBe(false)
		const legend = wrapper.find('[data-testid="usage-overlay-legend"]').text()
		expect(legend).toContain('Our application')
		expect(legend).toContain('Shared with a partner')
	})

	it('drops the overlay again when both switches are off', async () => {
		const { wrapper } = await open('g1')

		await flip(wrapper, 0, true)
		await flip(wrapper, 0, false)

		expect(wrapper.findAll('.stub-node').map((n) => n.attributes('data-id'))).toEqual(['n1'])
		expect(wrapper.find('[data-testid="usage-overlay-legend"]').exists()).toBe(false)
		expect(enrichCalls()).toHaveLength(1)
	})

	it('opens the usage detail page for a clicked application, and nothing for the component', async () => {
		const { wrapper, router } = await open('g1')
		await flip(wrapper, 0, true)
		const canvas = wrapper.findComponent({ name: 'CnGraphCanvas' })

		await canvas.vm.$emit('node-select', { node: { id: 'n1', data: { name: 'Zaakregistratiecomponent' } } })
		expect(router.push).not.toHaveBeenCalled()

		const overlayNode = canvas.props('nodes').find((node) => node.id === 'n1::usage::u-own')
		await canvas.vm.$emit('node-select', { node: overlayNode })
		expect(router.push).toHaveBeenCalledWith({ name: 'GebruikDetail', params: { id: 'u-own' } })
	})

	it('says so when the applications cannot be loaded, and draws no overlay', async () => {
		enriched.g1 = 'fail'
		vi.spyOn(console, 'error').mockImplementation(() => {})
		const { wrapper } = await open('g1')

		await flip(wrapper, 0, true)

		expect(wrapper.text()).toContain('The applications on this view could not be loaded.')
		expect(wrapper.findAll('.stub-node')).toHaveLength(1)
	})
})
