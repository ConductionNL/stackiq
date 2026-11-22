// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The GEMMA plate's view picker: the views it lists with their release, the
 * view it fetches with `include_gebruik`, the view's own groups with the
 * coverage states kept, and the view the URL names.
 *
 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-draws-the-gemma-view-the-user-picks-req-gpl-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn() }))
const store = vi.hoisted(() => ({ organisations: [] }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m)),
}))
vi.mock('../../src/composables/useLiveCollections.js', () => ({ useLiveCollections: vi.fn() }))
vi.mock('../../src/store/store.js', () => ({
	objectStore: { getCollection: () => ({ results: store.organisations }) },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnKpiGrid: { template: '<div class="kpi"><slot /></div>' },
	CnStatsBlock: { props: ['title', 'count', 'countLabel'], template: '<div class="stat">{{ title }}={{ count }}</div>' },
	CnStatusBadge: { props: ['label', 'variant'], template: '<span class="badge" :data-variant="variant">{{ label }}</span>' },
	CnGraphCanvas: { template: '<div class="canvas" />' },
}))

const {
	filterGroups,
	viewGroups,
	viewId,
	viewOptions,
	viewRelease,
} = await import('../../src/utils/referenceComponentCoverage.js')
const { default: pinia } = await import('../../src/pinia.js')
const { useViewStore } = await import('../../src/store/modules/view.js')
const { default: GemmaPlate } = await import('../../src/views/organisaties/GemmaPlate.vue')

const coverageRows = [
	{ id: 'u-zrc', identifier: 'el-zrc', name: 'Zaakregistratiecomponent', group: 'Mid-office', state: 'covered', fillable: false, fillableBy: [], applications: [{ moduleName: 'Mozard' }] },
	{ id: 'u-kb', identifier: 'el-kb', name: 'Kennisbankcomponent', group: 'Front-office', state: 'gap', fillable: true, fillableBy: ['Djuma'], applications: [] },
	{ id: 'u-dms', identifier: 'el-dms', name: 'Documentbeheercomponent', group: 'Back-office', state: 'overlap', fillable: false, fillableBy: [], applications: [{ moduleName: 'A' }, { moduleName: 'B' }] },
]

const view = {
	id: 'v2',
	name: 'GEMMA Applicatiearchitectuur, gemeentebreed',
	viewNodes: [
		{ viewNodeId: 'g-front', modelNodeId: 'el-group-front', name: 'Front-office', parent: null, type: 'grouping', usage: [{ name: 'A group is never a component' }] },
		{ viewNodeId: 'g-mid', modelNodeId: null, name: 'Mid-office', parent: null, type: 'container' },
		{ viewNodeId: 'n-kb', modelNodeId: 'el-kb', name: 'Kennisbank', parent: 'g-front', type: 'applicationcomponent' },
		{ viewNodeId: 'n-zrc', modelNodeId: 'el-zrc', name: 'ZRC', parent: 'g-mid', type: 'applicationcomponent' },
		{ viewNodeId: 'n-zrc-2', modelNodeId: 'el-zrc', name: 'ZRC again', parent: 'g-front', type: 'applicationcomponent' },
		{ viewNodeId: 'n-new', modelNodeId: 'el-new', name: 'Nieuwe component', parent: null, type: 'applicationcomponent', usage: [{ _linked_module: { name: 'Eigen' } }] },
		{ viewNodeId: 'n-plain', modelNodeId: 'el-plain', name: 'Not a reference component', parent: 'g-mid', type: 'applicationcomponent' },
		{ viewNodeId: 'l1', modelNodeId: null, name: 'Legend', parent: null, type: 'label' },
	],
}

describe('the view helpers', () => {
	it('reads a view id and release from any shape the endpoint gives', () => {
		expect(viewId({ id: 'a' })).toBe('a')
		expect(viewId({ '@self': { id: 'b' } })).toBe('b')
		expect(viewId({})).toBe('')
		expect(viewRelease({ release: '2025.1' })).toBe('2025.1')
		expect(viewRelease({ name: 'x' })).toBe('')
	})

	it('lists the views by name with their release, without views lacking an id', () => {
		expect(viewOptions([
			{ id: 'v2', name: 'Zaakgericht werken', release: '2025.1' },
			{ id: 'v1', name: 'Applicatiearchitectuur' },
			{ name: 'No id' },
		])).toEqual([
			{ id: 'v1', label: 'Applicatiearchitectuur' },
			{ id: 'v2', label: 'Zaakgericht werken (2025.1)' },
		])
		expect(viewOptions(undefined)).toEqual([])
	})

	it('groups the components by the view\'s own groups, keeping their coverage rows', () => {
		const groups = viewGroups(view, coverageRows, 'Other')

		expect(groups.map((group) => group.name)).toEqual(['Front-office', 'Mid-office', 'Other'])
		expect(groups[0].components).toEqual([coverageRows[1]])
		// The second node of the same component is not drawn twice.
		expect(groups[1].components).toEqual([coverageRows[0]])
		expect(groups[2].components).toEqual([{
			id: 'el-new',
			identifier: 'el-new',
			name: 'Nieuwe component',
			documentation: '',
			state: 'covered',
			fillable: false,
			fillableBy: [],
			applications: [{ moduleName: 'Eigen' }],
		}])
	})

	it('matches a component by its id when the coverage has no identifier', () => {
		const rows = [{ id: 'el-kb', name: 'Kennisbank', state: 'gap', applications: [] }]
		expect(viewGroups(view, rows, 'Other')[0].components).toEqual(rows)
	})

	it('gives a view without nodes no groups', () => {
		expect(viewGroups({}, coverageRows, 'Other')).toEqual([])
		expect(viewGroups(null, coverageRows, 'Other')).toEqual([])
	})

	it('filters within the groups and drops a group left empty', () => {
		const groups = viewGroups(view, coverageRows, 'Other')
		expect(filterGroups(groups, 'gap')).toEqual([{ name: 'Front-office', components: [coverageRows[1]] }])
		expect(filterGroups(groups, 'all')).toEqual(groups)
	})
})

const stubs = {
	NcButton: { props: ['href', 'variant'], emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
	NcEmptyContent: { props: ['name'], template: '<div class="empty">{{ name }}</div>' },
	NcLoadingIcon: { template: '<i class="loading" />' },
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	NcSelect: {
		props: ['options', 'modelValue', 'inputLabel'],
		emits: ['update:modelValue'],
		template: '<div class="select" :data-label="inputLabel"><span v-for="o in options" :key="o.uuid || o.id" class="option">{{ o.label }}</span></div>',
	},
	Download: true,
}

/**
 * Answer the views, view and coverage requests.
 *
 * @param {Array<object>} views The views list.
 * @return {void}
 */
function answer(views) {
	http.get.mockImplementation(async (url) => {
		if (url.endsWith('/api/views')) {
			return { data: { views } }
		}
		if (url.includes('/api/views/')) {
			return { data: { view } }
		}
		return { data: { truncated: false, summary: { components: 3 }, components: coverageRows } }
	})
}

/**
 * Mount the plate with one organisation.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountPlate() {
	store.organisations = [{ uuid: 'org-1', name: 'Zuiddrecht' }]
	const wrapper = mount(GemmaPlate, { global: { stubs } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	http.get.mockReset()
	const viewStore = useViewStore(pinia)
	viewStore.$reset()
	window.history.replaceState(null, '', '/')
})

describe('GemmaPlate view picker', () => {
	it('lists the imported views with their release and fetches them with include_gebruik', async () => {
		answer([{ id: 'v2', name: 'Gemeentebreed', release: '2025.1' }, { id: 'v1', name: 'Applicaties' }])
		const wrapper = await mountPlate()

		const picker = wrapper.findAll('.select')[1]
		expect(picker.attributes('data-label')).toBe('GEMMA view')
		expect(picker.findAll('.option').map((o) => o.text())).toEqual(['Applicaties', 'Gemeentebreed (2025.1)'])
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/stackiq/api/views', { params: { include_gebruik: true } })
		expect(wrapper.find('[data-testid="gemma-plate-no-views"]').exists()).toBe(false)
	})

	it('says the GEMMA model is needed when no view is imported', async () => {
		answer([])
		const wrapper = await mountPlate()

		expect(wrapper.findAll('.select')[1].findAll('.option')).toHaveLength(0)
		expect(wrapper.find('[data-testid="gemma-plate-no-views"]').text()).toBe('Import the GEMMA model to pick a GEMMA view.')
	})

	it('opens the view the URL names and draws its groups with the coverage states', async () => {
		window.history.replaceState(null, '', '/?view=v2')
		answer([{ id: 'v2', name: 'Gemeentebreed' }])
		const wrapper = await mountPlate()

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/stackiq/api/views/v2', { params: { include_gebruik: true } })
		const groups = wrapper.findAll('.gemma-plate__group')
		expect(groups.map((group) => group.find('h3').text())).toEqual(['Front-office', 'Mid-office', 'Other'])
		expect(groups[0].find('.gemma-plate__component').attributes('data-state')).toBe('gap')
		expect(groups[1].find('.gemma-plate__component').attributes('data-state')).toBe('covered')
		expect(groups[1].text()).toContain('Mozard')
	})

	it('keeps a picked view in the URL and draws the whole model when the pick is cleared', async () => {
		answer([{ id: 'v2', name: 'Gemeentebreed' }])
		const wrapper = await mountPlate()
		const groupNames = () => wrapper.findAll('.gemma-plate__group h3').map((h) => h.text())
		expect(groupNames()).toEqual(['Mid-office', 'Front-office', 'Back-office'])

		wrapper.vm.selectedView = { id: 'v2', label: 'Gemeentebreed' }
		await wrapper.vm.pickView({ id: 'v2' })
		await flushPromises()
		expect(new URLSearchParams(window.location.search).get('view')).toBe('v2')
		expect(groupNames()).toEqual(['Front-office', 'Mid-office', 'Other'])

		wrapper.vm.selectedView = null
		await wrapper.vm.pickView(null)
		await flushPromises()
		expect(window.location.search).toBe('')
		expect(groupNames()).toEqual(['Mid-office', 'Front-office', 'Back-office'])
	})

	it('ignores a view in the URL that is not imported', async () => {
		window.history.replaceState(null, '', '/?view=gone')
		answer([{ id: 'v2', name: 'Gemeentebreed' }])
		await mountPlate()

		expect(http.get).not.toHaveBeenCalledWith('/index.php/apps/stackiq/api/views/gone', expect.anything())
	})
})
