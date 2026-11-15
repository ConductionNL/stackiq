// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Mounts the license posture view over real posture utilities: the portfolio
 * KPIs, the per-vendor table, the per-organisation report with its
 * closed-source contributors, the seats table (over-licence row, contract
 * link, plain name without a contract id), the empty states and the loading
 * state.
 *
 * @spec openspec/specs/software-license-posture/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/composables/useLiveCollections.js', () => ({ useLiveCollections: vi.fn() }))
vi.mock('../../src/utils/objectTypeCollection.js', () => ({ fetchObjectTypeCollection: vi.fn() }))
const store = vi.hoisted(() => ({
	objectStore: { collections: {}, getCollection(type) { return this.collections[type] } },
}))
vi.mock('../../src/store/store.js', () => store)

const { default: LicensePostureView } = await import('../../src/views/LicensePostureView.vue')
const { fetchObjectTypeCollection } = await import('../../src/utils/objectTypeCollection.js')
const { objectStore } = store

const NcSelectStub = {
	name: 'NcSelect',
	props: ['modelValue', 'options'],
	emits: ['update:modelValue'],
	template: '<div class="select-stub" />',
}

const RouterLinkStub = {
	name: 'RouterLink',
	props: ['to'],
	template: '<a class="router-link-stub"><slot /></a>',
}

const inProduction = { startDateInProduction: '2020-01-01' }

/**
 * Mount the view and let its load settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mounted() {
	const wrapper = mount(LicensePostureView, {
		global: { stubs: { NcSelect: NcSelectStub, RouterLink: RouterLinkStub } },
	})
	await flushPromises()
	return wrapper
}

/**
 * The trimmed texts of the cells of a row.
 *
 * @param {object} row The row wrapper.
 * @return {Array<string>} The cell texts.
 */
const cells = (row) => row.findAll('td').map((td) => td.text())

beforeEach(() => {
	objectStore.collections = {
		module: {
			results: [
				{ uuid: 'm1', name: 'Zaaksysteem', licentietype: 'Closed source', provider: 'v1' },
				{ uuid: 'm2', name: 'Nextcloud', licentietype: 'Open source', provider: 'v2' },
				{ uuid: 'm3', name: 'Tool', provider: 'v1' },
			],
		},
		usage: {
			results: [
				{ id: 'u1', consumer: 'o1', module: 'm1', ...inProduction },
				{ id: 'u2', consumer: 'o1', module: 'm2', ...inProduction },
				{ id: 'u3', consumer: 'o2', module: 'm2', ...inProduction },
				{ id: 'u4', consumer: 'o2', module: 'm3', ...inProduction },
				{ id: 'u5', consumer: 'o1', module: 'm1' },
			],
		},
		organization: {
			results: [
				{ uuid: 'o1', name: 'Gemeente A' },
				{ uuid: 'o2', name: 'Gemeente B' },
				{ uuid: 'v1', name: 'Leverancier X' },
			],
		},
		catalogContract: {
			results: [
				{ id: 'c2', usage: 'u2', licenceMetric: 'Per device', licencesBought: 5, licencesInUse: 3 },
				{ id: 'c1', usage: 'u1', licenceMetric: 'Per named user', licencesBought: 10, licencesInUse: 12 },
				{ contractNumber: 'K-3', usage: 'gone', licenceMetric: 'Per device', licencesBought: 1 },
				{ id: 'c4', usage: 'u3', licenceMetric: 'Per organisation', licencesBought: 1 },
			],
		},
	}
	objectStore.settings = {}
	fetchObjectTypeCollection.mockReset()
	fetchObjectTypeCollection.mockResolvedValue(undefined)
})

describe('LicensePostureView mounted', () => {
	it('shows the portfolio KPIs of the in-production deployments', async () => {
		const wrapper = await mounted()

		expect(fetchObjectTypeCollection.mock.calls.map((c) => c[1])).toEqual(['module', 'usage', 'organization', 'catalogContract'])
		const kpis = wrapper.get('[data-testid="posture-portfolio"]').findAll('.pv-kpiValue').map((k) => k.text())
		expect(kpis).toEqual(['67%', '2', '1', '1'])
	})

	it('lists one row per vendor with its licence mix', async () => {
		const wrapper = await mounted()

		const rows = wrapper.findAll('[data-testid="posture-vendor-row"]')
		expect(rows.map((row) => cells(row).slice(0, 5))).toEqual([
			['Leverancier X', '2', '0', '1', '1'],
			['v2', '2', '2', '0', '0'],
		])
	})

	it('reports the selected organisation with its closed-source applications', async () => {
		const wrapper = await mounted()
		expect(wrapper.find('[data-testid="posture-org-report"]').exists()).toBe(false)

		const select = wrapper.findComponent(NcSelectStub)
		expect(select.props('options').map((o) => o.label)).toEqual(['Gemeente A', 'Gemeente B', 'Leverancier X'])
		select.vm.$emit('update:modelValue', select.props('options')[0])
		await flushPromises()

		const report = wrapper.get('[data-testid="posture-org-report"]')
		expect(report.findAll('.pv-kpiValue').map((k) => k.text())).toEqual(['50%', '1', '1'])
		expect(report.findAll('.pv-contributors li').map((li) => li.text())).toEqual(['Zaaksysteem'])

		select.vm.$emit('update:modelValue', select.props('options')[2])
		await flushPromises()
		const empty = wrapper.get('[data-testid="posture-org-report"]')
		expect(empty.findAll('.pv-kpiValue').map((k) => k.text())).toEqual(['—', '0', '0'])
		expect(empty.find('.pv-contributors').exists()).toBe(false)
	})

	it('lists counted seats, over-licence first, linking contracts that have an id', async () => {
		const wrapper = await mounted()

		const rows = wrapper.findAll('[data-testid="posture-seat-row"]')
		expect(rows.map(cells)).toEqual([
			['Zaaksysteem', 'Gemeente A', 'Per named user', '10', '12', 'Over licence by 2'],
			['Nextcloud', 'Gemeente A', 'Per device', '5', '3', 'Within licence'],
			['K-3', '', 'Per device', '1', '', 'Unknown'],
		])
		expect(rows.map((row) => row.classes().includes('pv-row--over'))).toEqual([true, false, false])
		const link = rows[0].getComponent(RouterLinkStub)
		expect(link.props('to')).toEqual({ name: 'ContractDetail', params: { id: 'c1' } })
		expect(rows[2].find('.router-link-stub').exists()).toBe(false)
	})

	it('shows the empty states without data', async () => {
		objectStore.collections = {}
		const wrapper = await mounted()

		expect(wrapper.get('[data-testid="posture-vendor"] .empty-content-stub').attributes('name')).toBe('No in-production deployments')
		expect(wrapper.get('[data-testid="posture-seats"] .empty-content-stub').attributes('name')).toBe('No licence contracts with counts')
		expect(wrapper.get('[data-testid="posture-portfolio"]').findAll('.pv-kpiValue').map((k) => k.text())).toEqual(['—', '0', '0', '0'])
	})

	it('shows only the loading icon while loading', async () => {
		let release
		fetchObjectTypeCollection.mockReturnValue(new Promise((resolve) => { release = resolve }))
		const wrapper = mount(LicensePostureView, {
			global: { stubs: { NcSelect: NcSelectStub, RouterLink: RouterLinkStub } },
		})
		await flushPromises()

		expect(wrapper.find('.pv-loading').exists()).toBe(true)
		expect(wrapper.find('[data-testid="posture-portfolio"]').exists()).toBe(false)

		release()
		await flushPromises()
		expect(wrapper.find('.pv-loading').exists()).toBe(false)
		expect(wrapper.find('[data-testid="posture-portfolio"]').exists()).toBe(true)
	})
})
