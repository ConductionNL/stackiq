// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Mounts the portfolio roadmap view: the empty state before an organisation
 * is chosen, the phase groups with their counts, the end-of-support,
 * approaching and withdrawn badges, the date line, the successor link and
 * the loading state.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/composables/useLiveCollections.js', () => ({ useLiveCollections: vi.fn() }))
vi.mock('../../src/utils/objectTypeCollection.js', () => ({ fetchObjectTypeCollection: vi.fn() }))
const store = vi.hoisted(() => ({
	objectStore: { collections: {}, getCollection(type) { return this.collections[type] } },
	navigationStore: {},
}))
vi.mock('../../src/store/store.js', () => store)

const { default: LifecycleRoadmapView } = await import('../../src/views/LifecycleRoadmapView.vue')
const { fetchObjectTypeCollection } = await import('../../src/utils/objectTypeCollection.js')
const { useLiveCollections } = await import('../../src/composables/useLiveCollections.js')
const { objectStore, navigationStore } = store

const NcSelectStub = {
	name: 'NcSelect',
	props: ['modelValue', 'options'],
	emits: ['update:modelValue'],
	template: '<div class="select-stub" />',
}

/**
 * Mount the view and let its load settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mounted() {
	const wrapper = mount(LifecycleRoadmapView, { global: { stubs: { NcSelect: NcSelectStub } } })
	await flushPromises()
	return wrapper
}

/**
 * Choose an organisation in the selector.
 *
 * @param {object} wrapper The wrapper.
 * @param {number} index The option index.
 * @return {Promise<void>}
 */
async function choose(wrapper, index) {
	const select = wrapper.findComponent(NcSelectStub)
	select.vm.$emit('update:modelValue', select.props('options')[index])
	await flushPromises()
}

beforeEach(() => {
	vi.useFakeTimers({ now: new Date('2026-05-15T12:00:00Z'), toFake: ['Date'] })
	objectStore.collections = {
		organization: { results: [{ uuid: 'o1', name: 'Gemeente A' }, { uuid: 'o2', name: 'Gemeente B' }] },
		module: { results: [{ uuid: 'm1', name: 'Zaaksysteem' }, { uuid: 'm2', name: 'DMS' }, { uuid: 'm3', name: 'New DMS' }] },
		moduleVersion: {
			results: [
				{ uuid: 'mv1', dateEndSupport: '2026-08-01' },
				{ uuid: 'mv2', dateEndSupport: '2027-12-31' },
				{ uuid: 'mv3', dateEndSupport: '2025-01-01', dateWithdrawn: '2025-02-01' },
			],
		},
		usage: {
			results: [
				{ uuid: 'g1', consumer: 'o1', module: 'm1', moduleVersion: 'mv1', startDateInProduction: '2020-01-01' },
				{
					uuid: 'g2',
					consumer: 'o1',
					module: 'm2',
					moduleVersion: 'mv2',
					startDateInProduction: '2019-01-01',
					startDateOutPhasing: '2026-01-01',
					plannedReplacement: 'm3',
					plannedReplacementDate: '2026-09-01',
				},
				{ uuid: 'g3', consumer: 'o1', module: 'm1', moduleVersion: 'mv3', startDateInProduction: '2018-01-01' },
			],
		},
	}
	objectStore.settings = {}
	navigationStore.setSelected = vi.fn()
	fetchObjectTypeCollection.mockReset()
	fetchObjectTypeCollection.mockResolvedValue(undefined)
})

afterEach(() => {
	vi.useRealTimers()
})

describe('LifecycleRoadmapView mounted', () => {
	it('subscribes to live updates and asks for an organisation first', async () => {
		const wrapper = await mounted()

		expect(useLiveCollections).toHaveBeenCalledWith(objectStore, ['usage', 'moduleVersion', 'module', 'organization'])
		expect(wrapper.findComponent(NcSelectStub).props('options')).toEqual([
			{ uuid: 'o1', label: 'Gemeente A' },
			{ uuid: 'o2', label: 'Gemeente B' },
		])
		const empty = wrapper.get('.empty-content-stub')
		expect(empty.attributes('name')).toBe('Select an organisation')
		expect(wrapper.find('.rm-groups').exists()).toBe(false)
		expect(wrapper.find('.rm-loading').exists()).toBe(false)
	})

	it('renders the phase groups with badges, dates and the successor', async () => {
		const wrapper = await mounted()
		await choose(wrapper, 0)

		const groups = wrapper.findAll('.rm-group')
		expect(groups.map((g) => g.get('.rm-groupTitle').text().replace(/\s+/g, ' '))).toEqual([
			'Unknown (0)',
			'In production (2)',
			'Phasing out (1)',
		])
		expect(groups[0].get('.empty-content-stub').attributes('name')).toBe('No applications in this phase')

		const production = groups[1].findAll('.rm-entry')
		expect(production.map((e) => e.get('.rm-appName').text())).toEqual(['Zaaksysteem', 'Zaaksysteem'])
		expect(production[0].findAll('.rm-badge').map((b) => b.text())).toEqual(['End of support passed', 'Withdrawn'])
		expect(production[0].get('.rm-entryDates').text()).toBe('End of support: 2025-01-01')
		expect(production[1].findAll('.rm-badge').map((b) => b.text())).toEqual(['End of support approaching'])
		expect(production[1].find('.rm-replacement').exists()).toBe(false)

		const phasingOut = groups[2].get('.rm-entry')
		expect(phasingOut.findAll('.rm-badge')).toHaveLength(0)
		expect(phasingOut.findAll('.rm-entryDates span').map((s) => s.text().replace(/\s+/g, ' '))).toEqual([
			'End of support: 2027-12-31',
			'Phase-out: 2026-01-01',
			'Planned replacement: 2026-09-01',
		])
		expect(phasingOut.get('.rm-replacement').text().replace(/\s+/g, ' ')).toBe('Successor: New DMS')
	})

	it('opens the module versions from the successor link', async () => {
		const wrapper = await mounted()
		await choose(wrapper, 0)

		await wrapper.get('.rm-link').trigger('click')

		expect(navigationStore.setSelected).toHaveBeenCalledWith('moduleversies')
	})

	it('shows only the empty unknown group for an organisation without usages', async () => {
		const wrapper = await mounted()
		await choose(wrapper, 1)

		const groups = wrapper.findAll('.rm-group')
		expect(groups).toHaveLength(1)
		expect(groups[0].get('.rm-groupTitle').text().replace(/\s+/g, ' ')).toBe('Unknown (0)')
	})

	it('shows the loading icon instead of the content while loading, and refreshes', async () => {
		let release
		fetchObjectTypeCollection.mockReturnValue(new Promise((resolve) => { release = resolve }))
		const wrapper = mount(LifecycleRoadmapView, { global: { stubs: { NcSelect: NcSelectStub } } })
		await flushPromises()

		expect(wrapper.find('.rm-loading').exists()).toBe(true)
		expect(wrapper.find('.empty-content-stub').exists()).toBe(false)

		release()
		await flushPromises()
		expect(wrapper.find('.rm-loading').exists()).toBe(false)

		fetchObjectTypeCollection.mockClear()
		await wrapper.get('[aria-label="Refresh data"]').trigger('click')
		await flushPromises()
		expect(fetchObjectTypeCollection.mock.calls.map((c) => c[1])).toEqual(['usage', 'moduleVersion', 'module', 'organization'])
	})
})
