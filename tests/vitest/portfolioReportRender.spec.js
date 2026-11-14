// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for what the portfolio report renders: the empty and error
 * states, the truncation banner, the quadrant summary, the rows grouped by
 * quadrant with their EOL and suggestion cells, and the mismatch switch.
 *
 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m)),
}))
vi.mock('../../src/composables/useLiveCollections.js', () => ({ useLiveCollections: vi.fn() }))
vi.mock('../../src/store/store.js', () => ({
	objectStore: { settings: {}, objectTypeRegistry: {}, getCollection: () => ({ results: [] }) },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnChartWidget: { props: ['series', 'categories'], template: '<div class="chart">{{ categories.join(",") }}|{{ series[0].data.join(",") }}</div>' },
}))
vi.mock('../../src/components/portfolio/ValueFitPlot.vue', () => ({
	default: { props: ['rows'], template: '<div class="plot">{{ rows.length }}</div>' },
}))

const { default: PortfolioReport } = await import('../../src/views/organisaties/PortfolioReport.vue')

const stubs = {
	NcButton: { props: ['disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>' },
	NcCheckboxRadioSwitch: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<label class="switch" @click="$emit(\'update:modelValue\', !modelValue)"><slot /></label>',
	},
	NcEmptyContent: { props: ['name'], template: '<div class="empty">{{ name }}</div>' },
	NcLoadingIcon: { template: '<i class="loading" />' },
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	NcSelect: true,
	ChartBoxOutline: true,
	Download: true,
	Refresh: true,
}

function row (overrides) {
  return {
	uuid: overrides.moduleName,
	quadrant: 'Invest',
	eol: { passed: false },
	eolApproaching: false,
	hostingModel: [],
	lifecyclePhase: 'active',
	annualisedCost: 0,
	...overrides,
}
}

const report = {
	truncated: true,
	includedGebruiken: 2,
	totalGebruiken: 9,
	quadrants: { Invest: { count: 2, eolExposedCount: 1, cloudTransition: {}, annualisedCost: 0, oneOffCost: 0 } },
	rows: [
		row({ moduleName: 'Zaaksysteem', eol: { passed: true }, suggestedTimeClassification: 'Migrate', timeMismatch: true, hostingModel: ['SaaS', 'Cloud'], timeRationale: 'Old', businessValue: 4 }),
		row({ moduleName: 'Portaal', eolApproaching: true, timeReviewDate: '2027-01-01' }),
		row({ moduleName: 'Archief', quadrant: 'Unclassified' }),
	],
}

/**
 * Mount the report and load it for one organisation.
 *
 * @param {Function} answer Resolves or rejects the request.
 * @return {Promise<object>} The wrapper.
 */
async function loaded(answer) {
	http.get.mockImplementation(answer)
	const wrapper = mount(PortfolioReport, { global: { stubs } })
	await flushPromises()
	await wrapper.setData({ selectedOrg: { uuid: 'org-1', label: 'Gemeente A' } })
	await wrapper.vm.loadReport()
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	http.get.mockReset()
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

describe('PortfolioReport rendering', () => {
	it('asks for an organisation and disables its actions without one', async () => {
		const wrapper = mount(PortfolioReport, { global: { stubs } })
		await flushPromises()

		expect(wrapper.find('.empty').text()).toBe('Select an organisation')
		expect(wrapper.findAll('.pr-actions button').every((b) => b.attributes('disabled') !== undefined)).toBe(true)
		expect(wrapper.find('[data-testid="pr-summary"]').exists()).toBe(false)
	})

	it('shows the reason a report failed', async () => {
		const wrapper = await loaded(async () => { throw { response: { data: { message: 'No access' } } } })

		expect(wrapper.find('.note[data-type="error"]').text()).toBe('No access')
		expect(wrapper.find('[data-testid="pr-summary"]').exists()).toBe(false)
	})

	it('renders the banner, chart, summary and grouped rows', async () => {
		const wrapper = await loaded(async () => ({ data: report }))

		expect(wrapper.find('[data-testid="pr-truncated"]').text()).toBe(
			'Showing the first 2 of 9 applications in use for this organisation — the report is bounded to protect performance.',
		)
		expect(wrapper.find('.chart').text()).toBe('Tolerate,Invest,Migrate,Eliminate,Unclassified|0,2,0,0,0')
		expect(wrapper.find('.plot').text()).toBe('3')
		expect(wrapper.findAll('[data-testid="pr-summary-row"]').map((r) => r.findAll('td').slice(0, 3).map((c) => c.text()))).toEqual([
			['Tolerate', '0', '0'], ['Invest', '2', '1'], ['Migrate', '0', '0'], ['Eliminate', '0', '0'], ['Unclassified', '0', '0'],
		])
		expect(wrapper.findAll('.pr-groupTitle').map((h) => [h.find('.pr-badge').text(), h.find('.pr-count').text()])).toEqual([
			['Tolerate', '(0)'], ['Invest', '(2)'], ['Migrate', '(0)'], ['Eliminate', '(0)'], ['Unclassified', '(1)'],
		])
		expect(wrapper.findAll('.empty').map((e) => e.text())).toEqual(Array(3).fill('No applications in this quadrant'))

		const cells = wrapper.findAll('[data-testid="pr-row"]').map((r) => r.findAll('td').slice(0, 8).map((c) => c.text()))
		expect(cells).toEqual([
			['Zaaksysteem', 'Migrate', '4 / - / -', 'Old', '—', 'active', 'Passed', 'SaaS, Cloud'],
			['Portaal', '—', '—', '—', '2027-01-01', 'active', 'Approaching', '—'],
			['Archief', '—', '—', '—', '—', 'active', 'OK', '—'],
		])
		expect(wrapper.find('[data-testid="pr-row-suggested"] .pr-mismatch').exists()).toBe(true)
	})

	it('shows only rows whose recorded class differs from the scores when switched', async () => {
		const wrapper = await loaded(async () => ({ data: report }))

		await wrapper.find('[data-testid="pr-mismatch-filter"]').trigger('click')

		expect(wrapper.findAll('[data-testid="pr-row"]').map((r) => r.find('td').text())).toEqual(['Zaaksysteem'])
	})

	it('reloads from the refresh button', async () => {
		const wrapper = await loaded(async () => ({ data: report }))
		http.get.mockClear()

		await wrapper.findAll('.pr-actions button')[0].trigger('click')
		await flushPromises()

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/stackiq/api/portfolio-report', { params: { organisation: 'org-1' } })
	})
})
