// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What DataQualityReport renders for each state: loading, an error, no
 * report yet, a truncated report, and the per-application table cells
 * (name or fallback, rounded completeness, missing fields or "Nothing",
 * confirmation date or "Never").
 *
 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const live = vi.hoisted(() => ({ calls: [] }))

vi.mock('../../src/store/store.js', () => ({
	objectStore: { getCollection: () => ({ results: [{ uuid: 'o1', name: 'Gemeente A' }, { uuid: 'o2', name: 'Gemeente B' }] }) },
}))
vi.mock('../../src/composables/useLiveCollections.js', () => ({
	useLiveCollections: (target, types) => live.calls.push(types),
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (match, key) => (key in vars ? vars[key] : match)),
}))

/**
 * A stub that renders its default slot, and its props as text.
 *
 * @param {string} name The component name.
 * @param {Array<string>} props The props to print.
 * @return {object} The stub.
 */
function stub (name, props = []) {
  return defineComponent({
	name,
	props: Object.fromEntries(props.map((prop) => [prop, null])),
	render() {
		return h('div', { class: name }, [props.map((prop) => `${prop}=${this[prop]}`).join(' '), this.$slots.default?.()])
	},
})
}

vi.mock('@conduction/nextcloud-vue', () => ({
	CnKpiGrid: stub('CnKpiGrid'),
	CnStatsBlock: stub('CnStatsBlock', ['title', 'count', 'countLabel']),
	CnProgressBar: stub('CnProgressBar'),
	CnDataTable: defineComponent({
		name: 'CnDataTable',
		props: { rows: Array, columns: Array },
		render() {
			return h('table', this.rows.map((row) => h('tr', this.columns.map((column) => h('td', { class: column.key }, this.$slots['column-' + column.key]({ row }))))))
		},
	}),
}))
vi.mock('@nextcloud/vue', () => ({
	NcSelect: stub('NcSelect'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard', ['type']),
	NcEmptyContent: stub('NcEmptyContent', ['name']),
}))

const { default: DataQualityReport } = await import('../../src/views/organisaties/DataQualityReport.vue')

/**
 * Mount the report in the given state.
 *
 * @param {object} state Data to set.
 * @return {object} The wrapper.
 */
function render(state) {
	return mount(DataQualityReport, {
		data: () => state,
		global: { stubs: { 'router-link': defineComponent({ props: ['to'], render() { return h('a', { 'data-id': this.to.params.id }, this.$slots.default()) } }) } },
	})
}

const report = {
	truncated: true,
	total: 120,
	summary: { entries: 100, score: 72, completeness: 81, notConfirmed: 9, ruleCount: 4 },
	rules: [],
	lowest: [
		{ id: 'u1', name: 'Zaaksysteem', completeness: 0.456, missing: ['owner', 'licence'], confirmedAt: '2026-03-01T10:00:00Z' },
		{ id: 'u2', name: '', completeness: 1, missing: [], confirmedAt: null },
	],
}

describe('DataQualityReport', () => {
	it('subscribes to the organisations', () => {
		render({ selectedOrg: { uuid: 'x' }, report: null })

		expect(live.calls).toContainEqual(['organization'])
	})

	it('shows only the loader while loading, then an error, then the empty state', () => {
		expect(render({ selectedOrg: { uuid: 'x' }, loading: true }).find('.NcLoadingIcon').exists()).toBe(true)

		const error = render({ selectedOrg: { uuid: 'x' }, error: 'Server down' })
		expect(error.find('.NcNoteCard').text()).toContain('Server down')
		expect(error.find('.NcEmptyContent').exists()).toBe(false)

		const empty = render({ selectedOrg: { uuid: 'x' }, report: null })
		expect(empty.find('.NcEmptyContent').text()).toContain('name=Select an organisation')
		expect(empty.find('table').exists()).toBe(false)
	})

	it('renders the truncation warning, the figures and the table cells', () => {
		const wrapper = render({ selectedOrg: { uuid: 'x' }, report })

		expect(wrapper.find('.NcNoteCard').text()).toContain('The figures cover the first 100 of 120 applications in use.')
		const blocks = wrapper.findAll('.CnStatsBlock').map((block) => block.text())
		expect(blocks[0]).toContain('count=72')
		expect(blocks[0]).toContain('of 100, against 4 rules')
		expect(blocks[2]).toContain('count=9')

		const rows = wrapper.findAll('tr')
		expect(rows[0].find('.name a').attributes('data-id')).toBe('u1')
		expect(rows[0].find('.name').text()).toBe('Zaaksysteem')
		expect(rows[0].find('.completeness').text()).toBe('46%')
		expect(rows[0].find('.missing').text()).toBe('owner, licence')
		expect(rows[0].find('.confirmedAt').text()).toBe(new Date('2026-03-01T10:00:00Z').toLocaleDateString())
		expect(rows[1].find('.name').text()).toBe('Unnamed application')
		expect(rows[1].find('.completeness').text()).toBe('100%')
		expect(rows[1].find('.missing').text()).toBe('Nothing')
		expect(rows[1].find('.confirmedAt').text()).toBe('Never')
	})

	it('shows no warning and zero figures for a complete report without scores', () => {
		const wrapper = render({
			selectedOrg: { uuid: 'x' },
			report: { ...report, truncated: false, summary: { entries: 0, notConfirmed: 0, ruleCount: 0 }, lowest: [] },
		})

		expect(wrapper.find('.NcNoteCard').exists()).toBe(false)
		expect(wrapper.findAll('.CnStatsBlock').map((block) => block.text().match(/count=(\S+)/)[1])).toEqual(['0', '0', '0'])
	})
})
