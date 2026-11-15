// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the application's contracts panel and a contract's licences panel
 * show: the contracts an application is used or offered under, and a
 * contract's seat position (within, over, not counted, unknown).
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockFetch = vi.hoisted(() => vi.fn())
const mockEnsure = vi.hoisted(() => vi.fn())
const mockGet = vi.hoisted(() => vi.fn())

vi.mock('../../src/store/store.js', () => ({
	objectStore: { fetchCollectionForOptions: (...a) => mockFetch(...a) },
}))
vi.mock('../../src/utils/objectTypes.js', () => ({
	ensureObjectType: (...a) => mockEnsure(...a),
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => mockGet(...a) },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params) =>
		String(url).replace(/\{(\w+)\}/g, (_, key) => String(params?.[key] ?? '')),
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		String(text).replace(/\{(\w+)\}/g, (_, key) =>
			String(vars?.[key] ?? `{${key}}`),
		),
}))

function stub(name, props = []) {
	return defineComponent({
		name,
		props: [
			'name',
			'size',
			'type',
			'title',
			'items',
			'showValue',
			'to',
			...props,
		],
		render() {
			return h('div', { class: name }, [
				this.$slots.default?.() ?? [],
				this.$slots['title-icon']?.() ?? [],
			])
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnIcon: stub('CnIcon'),
	CnProgressBar: stub('CnProgressBar'),
	CnWidgetWrapper: stub('CnWidgetWrapper'),
}))

const { default: ApplicationContractsPanel } =
	await import('../../src/components/contracts/ApplicationContractsPanel.vue')
const { default: ContractSeatsPanel } =
	await import('../../src/components/contracts/ContractSeatsPanel.vue')

const RouterLink = stub('RouterLink')

beforeEach(() => {
	mockFetch.mockReset()
	mockEnsure.mockReset()
	mockGet.mockReset()
})

describe('ApplicationContractsPanel', () => {
	function mountPanel(objectId = 'app-1') {
		return mount(ApplicationContractsPanel, {
			props: { objectId },
			global: {
				components: { RouterLink },
				stubs: { 'router-link': RouterLink },
			},
		})
	}

	it('lists the contracts the application is used and offered under, once each', async () => {
		mockFetch.mockImplementation(async (type) => {
			if (type === 'usage') return [{ id: 'u-1' }]
			if (type === 'catalogService') return [{ '@self': { id: 's-1' } }]
			return [
				{
					id: 'c-1',
					contractNumber: 'K-001',
					contractType: 'SaaS',
					endDate: '2027-01-01',
					status: 'active',
				},
				{ '@self': { id: 'c-2' } },
			]
		})

		const wrapper = mountPanel()
		await flushPromises()

		expect(mockFetch).toHaveBeenCalledWith('usage', {
			module: 'app-1',
			_limit: 500,
		})
		expect(mockFetch).toHaveBeenCalledWith('catalogService', {
			modules: 'app-1',
			_limit: 500,
		})
		expect(mockEnsure).toHaveBeenCalledWith('catalogContract')
		const rows = wrapper.findAll('tbody tr')
		expect(rows).toHaveLength(2)
		expect(rows[0].findAll('td').map((c) => c.text())).toEqual([
			'K-001',
			'SaaS',
			'2027-01-01',
			'active',
		])
		expect(rows[1].find('td').text()).toBe('Contract')
		expect(
			wrapper.findAllComponents(RouterLink).map((l) => l.props('to')),
		).toEqual([
			{ name: 'ContractDetail', params: { id: 'c-1' } },
			{ name: 'ContractDetail', params: { id: 'c-2' } },
		])
	})

	it('says so when there are no contracts, and reloads for another application', async () => {
		mockFetch.mockResolvedValue([])

		const wrapper = mountPanel()
		await flushPromises()
		expect(wrapper.find('.application-contracts-panel__empty').exists()).toBe(
			true,
		)

		await wrapper.setProps({ objectId: 'app-2' })
		await flushPromises()
		expect(mockFetch).toHaveBeenCalledWith('usage', {
			module: 'app-2',
			_limit: 500,
		})
	})

	it('shows an error when the contracts cannot be loaded', async () => {
		mockFetch.mockRejectedValue(new Error('register offline'))

		const wrapper = mountPanel()
		await flushPromises()

		expect(wrapper.find('.NcNoteCard').text()).toBe(
			'The contracts could not be loaded.',
		)
		expect(wrapper.find('table').exists()).toBe(false)
		expect(wrapper.vm.contractId({ uuid: 'x' })).toBe('x')
	})
})

describe('ContractSeatsPanel', () => {
	async function mountWith(contract, props = {}) {
		mockGet.mockResolvedValue({ data: contract })
		const wrapper = mount(ContractSeatsPanel, {
			props: { objectId: 'c-1', ...props },
		})
		await flushPromises()
		return wrapper
	}

	it('shows a contract within its licence with a success bar', async () => {
		const wrapper = await mountWith({
			'@self': { id: 'c-1', updated: '2026-03-01T10:00:00Z' },
			licenceMetric: 'Per named user',
			licencesBought: 10,
			licencesInUse: 4,
		})

		expect(mockGet).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/stackiq/catalogContract/c-1',
		)
		expect(wrapper.find('[data-testid="contract-seats-state"]').text()).toBe(
			'Within licence',
		)
		const [item] = wrapper
			.findComponent({ name: 'CnProgressBar' })
			.props('items')
		expect(item).toEqual({
			key: 'seats',
			label: '4 of 10 in use',
			percentage: 40,
			variant: 'success',
		})
		expect(wrapper.findAll('dd').map((d) => d.text())).toEqual([
			'Per named user',
			'10',
			'4',
		])
		expect(wrapper.find('.contract-seats-panel__updated').exists()).toBe(true)
	})

	it('shows a contract over its licence with a full error bar', async () => {
		const wrapper = await mountWith({
			object: {
				licenceMetric: 'Per device',
				licencesBought: 5,
				licencesInUse: 8,
			},
		})

		expect(wrapper.find('[data-testid="contract-seats-state"]').text()).toBe(
			'Over licence by 3',
		)
		expect(
			wrapper.findComponent({ name: 'CnProgressBar' }).props('items')[0],
		).toMatchObject({
			percentage: 100,
			variant: 'error',
		})
		expect(wrapper.find('.contract-seats-panel__updated').exists()).toBe(false)
	})

	it('shows no bar for an unknown position, and blanks missing counts and a bad date', async () => {
		const wrapper = await mountWith({
			'@self': { updated: 'not a date' },
			licenceMetric: 'Per named user',
		})

		expect(wrapper.find('[data-testid="contract-seats-state"]').text()).toBe(
			'Unknown',
		)
		expect(wrapper.findComponent({ name: 'CnProgressBar' }).exists()).toBe(false)
		expect(wrapper.findAll('dd').map((d) => d.text())).toEqual([
			'Per named user',
			'',
			'',
		])
		expect(wrapper.vm.updated).toBe('')
	})

	it('reads from the register and schema it is given, and shows an error when the read fails', async () => {
		mockGet.mockRejectedValue(new Error('gone'))
		const wrapper = mount(ContractSeatsPanel, {
			props: { objectId: 42, register: 'other', schema: 'deal' },
		})
		await flushPromises()

		expect(mockGet).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/other/deal/42',
		)
		expect(wrapper.find('.NcNoteCard').text()).toBe(
			'The licences could not be loaded.',
		)
	})
})
