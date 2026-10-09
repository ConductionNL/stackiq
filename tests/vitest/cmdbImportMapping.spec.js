// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The read-only "Mapping (read-only)" block of the CMDB import section, and
 * the helpers that shape the mapping endpoint's answer into its tables.
 *
 * The block is mounted against a mocked endpoint answer: one table per pack,
 * the lookup pairs of a row, the `loaded` event the section takes its sheet
 * names from, and the error state of a 503.
 *
 * @spec openspec/changes/cmdb-import-mapping-view/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const http = vi.hoisted(() => ({ get: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))

// `@conduction/nextcloud-vue` is not resolvable in this offline suite. The
// stub renders the rows through the same `column-<key>` slots the real table
// offers, so the block's own cell markup is what is asserted.
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: defineComponent({
		name: 'CnDataTable',
		props: {
			rows: { type: Array, default: () => [] },
			columns: { type: Array, default: () => [] },
			rowKey: { type: String, default: 'id' },
			emptyText: { type: String, default: '' },
		},
		setup(props, { slots }) {
			return () =>
				h('table', [
					h(
						'thead',
						h(
							'tr',
							props.columns.map((column) => h('th', column.label)),
						),
					),
					h(
						'tbody',
						props.rows.map((row) =>
							h(
								'tr',
								{ key: row[props.rowKey] },
								props.columns.map((column) => {
									const slot = slots['column-' + column.key]
									return h(
										'td',
										slot
											? slot({ row })
											: String(row[column.key]),
									)
								}),
							),
						),
					),
				])
		},
	}),
}))

const {
	mappingRows,
	mappingSheetNames,
	packTargetLabel,
	PROFILE_DEFAULTS,
	transformDetails,
	transformLabel,
} = await import('../../src/utils/cmdbImport.js')
const CmdbImportMapping = (
	await import('../../src/views/settings/sections/CmdbImportMapping.vue')
).default

/**
 * A small answer of the mapping endpoint, shaped like the shipped files.
 *
 * @return {{profile: object, packs: Array<object>}} The answer
 */
function answer() {
	const pack = (target, fieldMappings = []) => ({
		target,
		file: `topdesk-${target}.json`,
		id: `stackiq-topdesk-${target}`,
		name: `TOPdesk ${target}`,
		version: '1.0.0',
		description: '',
		fieldMappings,
	})
	return {
		profile: {
			id: 'topdesk-cmdb',
			sheets: [
				{ name: 'Sheet A', constants: {}, absentColumns: [] },
				{ name: 'Sheet B', constants: {}, absentColumns: [] },
			],
			keyColumn: 'APPID',
			nameColumn: 'Applicatie Naam',
			requiredColumns: ['APPID', 'Applicatie Naam'],
			dateColumns: ['Datum'],
			missingRecords: ['keep'],
		},
		packs: [
			pack('module', [
				{
					source: 'Applicatie Naam',
					target: 'name',
					required: true,
					transform: { type: 'trim' },
				},
			]),
			pack('manufacturer'),
			pack('municipality'),
			pack('usage', [
				{
					source: 'Applicatie Status',
					target: 'status',
					required: false,
					transform: {
						type: 'lookup',
						map: { 'In productie': 'In production' },
						default: null,
					},
				},
			]),
			pack('businessOwner'),
		],
	}
}

describe('CmdbImportMapping', () => {
	beforeEach(() => {
		http.get.mockReset()
	})

	it('renders one table per pack from the endpoint and emits the answer', async () => {
		http.get.mockResolvedValue({ data: answer() })

		const wrapper = mount(CmdbImportMapping)
		await flushPromises()

		expect(http.get).toHaveBeenCalledWith(
			expect.stringContaining(
				'/apps/stackiq/api/settings/cmdb-import/mapping',
			),
		)
		expect(wrapper.emitted('loaded')[0][0].profile.keyColumn).toBe('APPID')

		const toggle = wrapper.find('[data-testid="cmdb-import-mapping-toggle"]')
		expect(toggle.attributes('aria-expanded')).toBe('false')
		await toggle.trigger('click')
		expect(toggle.attributes('aria-expanded')).toBe('true')

		for (const target of [
			'module',
			'manufacturer',
			'municipality',
			'usage',
			'businessOwner',
		]) {
			expect(
				wrapper
					.find(`[data-testid="cmdb-import-mapping-${target}"]`)
					.exists(),
				target,
			).toBe(true)
		}

		const usage = wrapper.find('[data-testid="cmdb-import-mapping-usage"]')
		expect(usage.text()).toContain('Applicatie Status')
		expect(usage.text()).toContain('status')
		expect(usage.text()).toContain('Lookup')
		expect(usage.text()).toContain('In productie → In production')
		expect(
			wrapper.find('[data-testid="cmdb-import-mapping-profile"]').text(),
		).toContain('Sheet A, Sheet B')
		expect(
			wrapper.find('[data-testid="cmdb-import-mapping-error"]').exists(),
		).toBe(false)
		// Read-only: the only button is the toggle.
		expect(wrapper.findAll('button')).toHaveLength(1)
	})

	it('shows the error, its reason and its code on a 503, and no table', async () => {
		http.get.mockRejectedValue({
			response: {
				status: 503,
				data: {
					success: false,
					error: 'MAPPING_UNAVAILABLE',
					message: 'The import mapping cannot be shown.',
					details: { reason: 'topdesk-usage.json: unknown transform' },
				},
			},
		})

		const wrapper = mount(CmdbImportMapping)
		await flushPromises()
		await wrapper
			.find('[data-testid="cmdb-import-mapping-toggle"]')
			.trigger('click')

		const error = wrapper.find('[data-testid="cmdb-import-mapping-error"]')
		expect(error.exists()).toBe(true)
		expect(error.text()).toContain('topdesk-usage.json: unknown transform')
		expect(error.text()).toContain('MAPPING_UNAVAILABLE')
		expect(
			wrapper.find('[data-testid="cmdb-import-mapping-usage"]').exists(),
		).toBe(false)
		expect(wrapper.emitted('loaded')).toBeUndefined()
	})
})

describe('cmdbImport mapping helpers', () => {
	it('takes the sheet names from the answer, and the shipped ones without it', () => {
		expect(mappingSheetNames(answer())).toEqual(['Sheet A', 'Sheet B'])
		expect(mappingSheetNames(null)).toEqual(PROFILE_DEFAULTS.sheets)
		expect(mappingSheetNames({ profile: { sheets: [] } })).toEqual(
			PROFILE_DEFAULTS.sheets,
		)
	})

	it('keeps the shipped sheet names when the answer names only one sheet', () => {
		expect(
			mappingSheetNames({ profile: { sheets: [{ name: 'Sheet A' }] } }),
		).toEqual(PROFILE_DEFAULTS.sheets)
	})

	it('describes every transformation the packs use', () => {
		expect(transformLabel(undefined)).toBe('As is')
		expect(transformLabel('bool-map')).toBe('Yes/no lookup')
		expect(transformLabel('something-new')).toBe('something-new')
		expect(transformDetails({ type: 'trim' })).toEqual([])
		expect(
			transformDetails({
				type: 'concat',
				fields: ['Cluster', 'Afdeling'],
				separator: ' / ',
			}),
		).toEqual(['Joined with the columns Cluster, Afdeling, separated by " / "'])
		expect(transformDetails({ type: 'const', value: 'Gemeente' })).toEqual([
			'Value: Gemeente',
		])
		expect(
			transformDetails({
				type: 'date',
				sourceFormat: 'd-m-Y',
				targetFormat: 'Y-m-d',
			}),
		).toEqual(['Read as d-m-Y, stored as Y-m-d'])
		expect(
			transformDetails({ type: 'lookup', map: { Ja: true }, extra: 1 }),
		).toEqual(['Ja → true', 'extra: 1'])
	})

	it('builds one row per field mapping and names each target', () => {
		const rows = mappingRows(answer().packs[3])
		expect(rows).toHaveLength(1)
		expect(rows[0]).toMatchObject({
			source: 'Applicatie Status',
			target: 'status',
			required: false,
			transform: 'Lookup',
		})
		expect(rows[0].details).toEqual([
			'In productie → In production',
			'Any other value: —',
		])
		expect(mappingRows({})).toEqual([])
		expect(packTargetLabel('businessOwner')).toBe(
			'Business owner (contact person)',
		)
	})
})
