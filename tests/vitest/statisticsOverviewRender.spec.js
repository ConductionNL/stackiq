// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The object statistics section as it renders: one row per register and
 * object type with its count and configured state, the sync button only on
 * a configured Compliancy row, the empty, loading and error states, and the
 * refresh and bulk sync dialog wiring.
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h, reactive } from 'vue'

const settings = vi.hoisted(() => ({ store: null }))
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => settings.store }))
vi.mock('../../src/modals/BulkSyncDialog.vue', () => ({
	default: {
		name: 'BulkSyncDialog',
		props: ['open', 'complianceCount'],
		emits: ['update:open', 'loadingChange', 'synced'],
		render() {
			return h('div', { class: 'bulk-sync', 'data-open': String(this.open), 'data-count': String(this.complianceCount) })
		},
	},
}))

const { default: StatisticsOverview } = await import('../../src/views/settings/sections/StatisticsOverview.vue')

const ROWS = [
	{ register: 'Stackiq', type: 'Module', count: 12500, configured: true },
	{ register: 'Stackiq', type: 'Compliancy', count: 40, configured: true },
	{ register: 'Voorzieningen', type: 'Gebruik', count: 0, configured: false },
]

/**
 * Mount the section over a settings store state.
 *
 * @param {object} state The store state.
 * @return {Promise<object>} The wrapper, after its initial refresh.
 */
async function mountSection(state) {
	settings.store = reactive({
		statistics: {},
		formattedStatistics: [],
		loadingStatistics: false,
		error: null,
		loadStatistics: vi.fn().mockResolvedValue(undefined),
		...state,
	})
	const wrapper = mount(StatisticsOverview, {
		global: {
			stubs: {
				NcSettingsSection: {
					render() {
						return h('section', [this.$slots.title?.(), this.$slots.default?.()])
					},
				},
			},
		},
	})
	await flushPromises()
	return wrapper
}

const cells = (wrapper) => wrapper.findAll('tbody tr').map((tr) => tr.findAll('td').map((td) => td.text()))

describe('StatisticsOverview rendering', () => {
	it('loads on mount and renders one row per statistic', async () => {
		const wrapper = await mountSection({ formattedStatistics: ROWS })

		expect(settings.store.loadStatistics).toHaveBeenCalledTimes(1)
		expect(cells(wrapper)).toEqual([
			['Stackiq', 'Module', (12500).toLocaleString(), 'Configured', ''],
			['Stackiq', 'Compliancy', '40', 'Configured', 'Sync Standards'],
			['Voorzieningen', 'Gebruik', '0', 'Not Configured', ''],
		])
		expect(wrapper.findAll('.count-cell').map((c) => c.classes().includes('high-count'))).toEqual([true, false, false])
		expect(wrapper.find('.status-not-configured').text()).toBe('Not Configured')
	})

	it('offers no sync on an unconfigured Compliancy row', async () => {
		const wrapper = await mountSection({ formattedStatistics: [{ register: 'R', type: 'Compliancy', count: 3, configured: false }] })

		expect(wrapper.find('.sync-button').exists()).toBe(false)
		expect(wrapper.find('.bulk-sync').attributes('data-count')).toBe('3')
	})

	it('shows the last update moment when the statistics carry one', async () => {
		const wrapper = await mountSection({ statistics: { timestamp: 1700000000 }, formattedStatistics: ROWS })

		expect(wrapper.find('.last-updated').text()).toBe('Last updated: ' + new Date(1700000000 * 1000).toLocaleString())
	})

	it('shows the empty state when nothing is configured', async () => {
		const wrapper = await mountSection({})

		expect(wrapper.find('table').exists()).toBe(false)
		expect(wrapper.find('.empty-content-stub').exists()).toBe(true)
		expect(wrapper.find('.loading-container').exists()).toBe(false)
		expect(wrapper.find('.last-updated').exists()).toBe(false)
	})

	it('shows the loading state, hides the empty state and the error, and labels the refresh button', async () => {
		const wrapper = await mountSection({ loadingStatistics: true, error: 'Boom' })

		expect(wrapper.find('.loading-container').text()).toBe('Loading statistics...')
		expect(wrapper.find('.empty-content-stub').exists()).toBe(false)
		expect(wrapper.find('.note-card-stub').exists()).toBe(false)
		expect(wrapper.find('.title-refresh-button').text()).toBe('Loading...')
		expect(wrapper.find('.title-refresh-button').attributes('disabled')).toBeDefined()
	})

	it('shows the error once loading is done', async () => {
		const wrapper = await mountSection({ error: 'Register unreachable' })

		expect(wrapper.find('.note-card-stub').text()).toBe('Register unreachable')
	})

	it('refreshes on the refresh button', async () => {
		const wrapper = await mountSection({ formattedStatistics: ROWS })

		expect(wrapper.find('.title-refresh-button').text()).toBe('Refresh')
		await wrapper.find('.title-refresh-button').trigger('click')
		await flushPromises()

		expect(settings.store.loadStatistics).toHaveBeenCalledTimes(2)
	})

	it('opens the bulk sync dialog with the compliance count and follows its events', async () => {
		const wrapper = await mountSection({ formattedStatistics: ROWS })
		const dialog = () => wrapper.findComponent({ name: 'BulkSyncDialog' })

		expect(wrapper.find('.bulk-sync').attributes('data-open')).toBe('false')
		await wrapper.find('.sync-button').trigger('click')
		expect(wrapper.find('.bulk-sync').attributes('data-open')).toBe('true')
		expect(wrapper.find('.bulk-sync').attributes('data-count')).toBe('40')

		await dialog().vm.$emit('loadingChange', true)
		expect(wrapper.find('.sync-button').text()).toBe('Syncing...')
		expect(wrapper.find('.sync-button').attributes('disabled')).toBeDefined()

		await dialog().vm.$emit('synced')
		await flushPromises()
		expect(settings.store.loadStatistics).toHaveBeenCalledTimes(2)

		await dialog().vm.$emit('update:open', false)
		expect(wrapper.find('.bulk-sync').attributes('data-open')).toBe('false')
	})
})
