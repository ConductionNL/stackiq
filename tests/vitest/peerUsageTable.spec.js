// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The layer "What comparable organisations use": the table with the own and
 * the peer applications per component and their counts, a held-back count as
 * "fewer than 3", the heading naming the band, and the side panel line.
 *
 * @spec openspec/changes/archive/2026-11-24-gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
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
vi.mock('../../src/store/modules/view.js', () => ({
	useViewStore: () => ({ views: [], activeView: null, loading: false, setIncludeGebruik: vi.fn(), fetchViews: vi.fn(async () => {}), fetchView: vi.fn(async () => {}) }),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnKpiGrid: { template: '<div class="kpi"><slot /></div>' },
	CnStatsBlock: { props: ['title', 'count'], template: '<div class="stat">{{ title }}={{ count }}</div>' },
	CnStatusBadge: { props: ['label', 'variant'], template: '<span class="badge">{{ label }}</span>' },
	CnGraphCanvas: { template: '<div class="canvas" />' },
}))

const { peerApplicationsOf, peerIndex, isHeldBack } = await import('../../src/utils/peerUsage.js')
const { peerLabel } = await import('../../src/components/gemma/peerLabel.js')
const { default: PeerUsageTable } = await import('../../src/components/gemma/PeerUsageTable.vue')
const { default: GemmaPlate } = await import('../../src/views/organisaties/GemmaPlate.vue')

const components = [
	{ id: 'zac', name: 'Zaakafhandelcomponent', group: 'Mid-office', state: 'covered', applications: [{ moduleName: 'Mozard' }] },
	{ id: 'kb', name: 'Kennisbankcomponent', group: 'Front-office', state: 'gap', fillable: false, fillableBy: [], applications: [] },
	{ id: 'dms', identifier: 'el-dms', name: 'Documentbeheercomponent', group: 'Back-office', state: 'gap', applications: [] },
]

const peers = {
	organisation: 'zuiddrecht',
	comparable: { type: 'gemeente', band: { min: 30000, max: 60000 }, organisations: 21 },
	components: [
		{ referenceComponent: 'zac', applications: [{ moduleId: 'm1', moduleName: 'OpenZaak-UI', count: 21 }, { moduleId: 'm2', moduleName: 'Rare', count: null, fewerThan: 3 }] },
		{ referenceComponent: 'kb', applications: [{ moduleId: 'm3', moduleName: 'Djuma Kennisbank', count: 12 }] },
		{ referenceComponent: 'el-dms', applications: [{ moduleId: 'm4', moduleName: '', count: 5 }] },
		{ applications: [{ moduleId: 'x', moduleName: 'No component', count: 9 }] },
	],
	truncated: false,
}

describe('the peer helpers', () => {
	it('indexes the peer applications by component, skipping rows without one', () => {
		const index = peerIndex(peers)
		expect([...index.keys()]).toEqual(['zac', 'kb', 'el-dms'])
		expect(peerIndex(null).size).toBe(0)
	})

	it('finds a component\'s peers by its id, else its identifier', () => {
		const index = peerIndex(peers)
		expect(peerApplicationsOf(index, components[1])).toEqual(peers.components[1].applications)
		expect(peerApplicationsOf(index, components[2])).toEqual(peers.components[2].applications)
		expect(peerApplicationsOf(index, { id: 'none' })).toEqual([])
	})

	it('labels a count, and a held-back count as fewer than its minimum', () => {
		expect(peerLabel({ moduleName: 'OpenZaak-UI', count: 21 })).toBe('OpenZaak-UI (21)')
		expect(peerLabel({ moduleName: 'Rare', count: null, fewerThan: 3 })).toBe('Rare (fewer than 3)')
		expect(peerLabel({ moduleName: '', count: 5 })).toBe('Unnamed application (5)')
		expect(isHeldBack({ count: 0 })).toBe(false)
		expect(isHeldBack({})).toBe(true)
	})
})

describe('PeerUsageTable', () => {
	it('shows per component the own and the peer applications, under a heading naming the band', () => {
		const wrapper = mount(PeerUsageTable, { props: { components, peers } })

		expect(wrapper.find('h3').text()).toBe(`What comparable organisations use: gemeente, ${(30000).toLocaleString()} to ${(60000).toLocaleString()} inhabitants`)
		expect(wrapper.find('.peer-usage__hint').text()).toBe('Comparable organisations: 21')
		const rows = wrapper.findAll('tbody tr')
		expect(rows.map((row) => row.find('th').text())).toEqual(['Zaakafhandelcomponent', 'Kennisbankcomponent', 'Documentbeheercomponent'])
		expect(rows[0].find('.peer-usage__own').text()).toBe('Mozard')
		expect(rows[0].findAll('.peer-usage__peer').map((p) => p.text())).toEqual(['OpenZaak-UI (21)', 'Rare (fewer than 3)'])
		expect(rows[1].find('.peer-usage__own').text()).toBe('No application')
		expect(rows[1].find('.peer-usage__peers').text()).toBe('Djuma Kennisbank (12)')
		expect(rows[2].find('.peer-usage__peers').text()).toBe('Unnamed application (5)')
	})

	it('says None for a component no comparable organisation fills', () => {
		const wrapper = mount(PeerUsageTable, { props: { components: [{ id: 'other', name: 'X', applications: [] }], peers } })
		expect(wrapper.find('.peer-usage__peers').text()).toBe('None')
	})

	it('names an open-ended band, a lowest band, and only the type without a band', () => {
		const heading = (comparable) => mount(PeerUsageTable, { props: { components: [], peers: { comparable } } }).find('h3').text()

		expect(heading({ type: 'gemeente', band: { min: 250000, max: null } })).toBe(`What comparable organisations use: gemeente, above ${(250000).toLocaleString()} inhabitants`)
		expect(heading({ type: 'gemeente', band: { min: 0, max: 30000 } })).toBe(`What comparable organisations use: gemeente, under ${(30000).toLocaleString()} inhabitants`)
		expect(heading({ type: 'gemeente', band: null })).toBe('What comparable organisations use: gemeente')
		expect(heading({})).toBe('What comparable organisations use')
	})
})

const stubs = {
	NcButton: { props: ['href'], emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
	NcCheckboxRadioSwitch: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<label class="switch"><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>',
	},
	NcEmptyContent: { props: ['name'], template: '<div class="empty">{{ name }}</div>' },
	NcLoadingIcon: { template: '<i class="loading" />' },
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	NcSelect: true,
	Download: true,
}

/**
 * Mount the plate for one organisation, answering the coverage and peers requests.
 *
 * @param {Function} answerPeers Answers the peers request.
 * @return {Promise<object>} The wrapper.
 */
async function mountPlate(answerPeers) {
	store.organisations = [{ uuid: 'zuiddrecht', name: 'Zuiddrecht' }]
	http.get.mockImplementation(async (url) => {
		if (url.endsWith('/peers')) {
			return answerPeers()
		}
		return { data: { truncated: false, summary: { components: 3 }, components } }
	})
	const wrapper = mount(GemmaPlate, { global: { stubs } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	http.get.mockReset()
})

describe('GemmaPlate peers layer', () => {
	it('asks for nothing until the layer is switched on, then shows the table', async () => {
		const wrapper = await mountPlate(async () => ({ data: peers }))
		expect(http.get).not.toHaveBeenCalledWith('/index.php/apps/stackiq/api/reference-component-coverage/peers', expect.anything())
		expect(wrapper.find('[data-testid="gemma-plate-peers"]').exists()).toBe(false)

		await wrapper.find('[data-testid="gemma-plate-layer-peers"] input').setValue(true)
		await flushPromises()

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/stackiq/api/reference-component-coverage/peers', { params: { organisation: 'zuiddrecht' } })
		expect(wrapper.find('[data-testid="gemma-plate-peers"]').text()).toContain('OpenZaak-UI (21)')
		expect(wrapper.text()).not.toContain('zuiddrecht')
	})

	it('lists the peer applications of the selected gap in the side panel', async () => {
		const wrapper = await mountPlate(async () => ({ data: peers }))
		await wrapper.find('[data-testid="gemma-plate-layer-peers"] input').setValue(true)
		await flushPromises()

		await wrapper.findAll('.gemma-plate__component').find((b) => b.text().includes('Kennisbankcomponent')).trigger('click')

		const detail = wrapper.find('[data-testid="gemma-plate-detail"]')
		expect(detail.text()).toContain('Gap')
		expect(detail.find('[data-testid="gemma-plate-detail-peers"]').text()).toBe('Djuma Kennisbank (12)')
	})

	it('shows why the peers could not be loaded', async () => {
		const wrapper = await mountPlate(async () => {
			throw Object.assign(new Error('x'), { response: { data: { error: 'Not a member' } } })
		})
		await wrapper.find('[data-testid="gemma-plate-layer-peers"] input').setValue(true)
		await flushPromises()

		expect(wrapper.find('.note[data-type="error"]').text()).toBe('Not a member')
		expect(wrapper.find('[data-testid="gemma-plate-peers"]').exists()).toBe(false)
	})
})
