// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The "Mapping" block of the CMDB import section, and the helpers that shape
 * the mapping endpoint's answer into one line per pack.
 *
 * The block is mounted against a mocked endpoint answer: one line per pack
 * with its source, version, edited marker, newer-version hint and the link to
 * OpenRegister's editor, the note when OpenRegister's pack store is missing,
 * the `loaded` event the section takes its sheet names from, and the error
 * state of a 503.
 *
 * @spec openspec/changes/cmdb-import-mapping-view/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const http = vi.hoisted(() => ({ get: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))

// `@conduction/nextcloud-vue` is not resolvable in this offline suite. The
// stub renders the badge's label and variant, so the block's choice of both
// is what is asserted.
vi.mock('@conduction/nextcloud-vue', () => ({
	CnStatusBadge: defineComponent({
		name: 'CnStatusBadge',
		props: {
			label: { type: String, default: '' },
			variant: { type: String, default: 'default' },
			size: { type: String, default: 'medium' },
		},
		setup(props) {
			return () => h('span', { 'data-variant': props.variant }, props.label)
		},
	}),
}))

const {
	mappingSheetNames,
	packEditUrl,
	packSourceLabel,
	packTargetLabel,
	packVersionText,
	PROFILE_DEFAULTS,
} = await import('../../src/utils/cmdbImport.js')
const CmdbImportMapping = (
	await import('../../src/views/settings/sections/CmdbImportMapping.vue')
).default

const TARGETS = ['module', 'manufacturer', 'municipality', 'usage', 'businessOwner']

/**
 * A small answer of the mapping endpoint: four packs from OpenRegister's
 * store (module unedited, usage edited with a newer shipped version) and the
 * municipality pack from the shipped file.
 *
 * @param {boolean} store Whether OpenRegister's pack store is available
 * @return {{profile: object, packs: Array<object>, openRegisterPacks: boolean}} The answer
 */
function answer(store = true) {
	const pack = (target, overrides = {}) => {
		const fromStore = store && target !== 'municipality'
		const packId = fromStore ? TARGETS.indexOf(target) + 10 : null
		return {
			target,
			file: `topdesk-${target}.json`,
			id: `stackiq-topdesk-${target}`,
			name: `TOPdesk ${target}`,
			version: '1.0.0',
			description: '',
			fieldMappings: [],
			source: fromStore ? 'openregister' : 'file',
			packId,
			storedVersion: fromStore ? '1.0.0' : null,
			shippedVersion: '1.0.0',
			edited: fromStore ? false : null,
			newerShipped: false,
			editable: target !== 'municipality',
			editUrl: fromStore
				? `https://cloud.example/index.php/apps/openregister/migration-packs/${packId}`
				: null,
			...overrides,
		}
	}
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
			pack('module'),
			pack('manufacturer'),
			pack('municipality'),
			pack(
				'usage',
				store
					? {
							storedVersion: '1.0.1',
							shippedVersion: '1.1.0',
							edited: true,
							newerShipped: true,
						}
					: {},
			),
			pack('businessOwner'),
		],
		openRegisterPacks: store,
	}
}

/**
 * Mount the block against the given answer and open it.
 *
 * @param {object} data The endpoint's answer
 * @return {Promise<object>} The wrapper
 */
async function mountOpen(data) {
	http.get.mockResolvedValue({ data })
	const wrapper = mount(CmdbImportMapping)
	await flushPromises()
	await wrapper.find('[data-testid="cmdb-import-mapping-toggle"]').trigger('click')
	return wrapper
}

describe('CmdbImportMapping', () => {
	beforeEach(() => {
		http.get.mockReset()
	})

	it('shows one line per pack from the endpoint and emits the answer', async () => {
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
		expect(toggle.text()).toBe('Mapping')
		expect(toggle.attributes('aria-expanded')).toBe('false')
		await toggle.trigger('click')
		expect(toggle.attributes('aria-expanded')).toBe('true')

		for (const target of TARGETS) {
			expect(
				wrapper
					.find(`[data-testid="cmdb-import-mapping-${target}"]`)
					.exists(),
				target,
			).toBe(true)
		}
		expect(
			wrapper.find('[data-testid="cmdb-import-mapping-profile"]').text(),
		).toContain('Sheet A, Sheet B')
		// The column-by-column tables moved to OpenRegister's editor.
		expect(wrapper.find('table').exists()).toBe(false)
		expect(wrapper.find('input, textarea, select').exists()).toBe(false)
		expect(wrapper.text()).not.toContain('lib/Settings/cmdb-import')
		expect(wrapper.text()).toContain('The packs are kept in OpenRegister')
		expect(
			wrapper.find('[data-testid="cmdb-import-mapping-no-store"]').exists(),
		).toBe(false)
		expect(
			wrapper.find('[data-testid="cmdb-import-mapping-error"]').exists(),
		).toBe(false)
	})

	it('names the source and version of a pack, and links to its editor', async () => {
		const wrapper = await mountOpen(answer())

		const module = wrapper.find('[data-testid="cmdb-import-mapping-module"]')
		expect(module.text()).toContain('Application (module)')
		const source = module.find('[data-testid="cmdb-import-mapping-source"]')
		expect(source.text()).toBe('OpenRegister')
		expect(source.attributes('data-variant')).toBe('primary')
		expect(
			module.find('[data-testid="cmdb-import-mapping-version"]').text(),
		).toBe('Version 1.0.0')
		expect(
			module.find('[data-testid="cmdb-import-mapping-edited"]').exists(),
		).toBe(false)
		expect(
			module.find('[data-testid="cmdb-import-mapping-newer"]').exists(),
		).toBe(false)

		const edit = module.find('[data-testid="cmdb-import-mapping-edit"]')
		expect(edit.text()).toContain('Edit in OpenRegister')
		expect(edit.attributes('href')).toBe(
			'https://cloud.example/index.php/apps/openregister/migration-packs/10',
		)
		expect(edit.attributes('target')).toBe('_blank')
		expect(edit.attributes('aria-label')).toBe(
			'Edit in OpenRegister: Application (module) (opens in a new tab)',
		)
	})

	it('marks an edited pack and says a newer shipped version was not applied', async () => {
		const wrapper = await mountOpen(answer())

		const usage = wrapper.find('[data-testid="cmdb-import-mapping-usage"]')
		expect(
			usage.find('[data-testid="cmdb-import-mapping-version"]').text(),
		).toBe('Version 1.0.1 (shipped: 1.1.0)')
		expect(usage.find('[data-testid="cmdb-import-mapping-edited"]').text()).toBe(
			'Edited',
		)
		const newer = usage.find('[data-testid="cmdb-import-mapping-newer"]')
		expect(newer.text()).toContain('a newer version of this pack (1.1.0)')
		expect(newer.text()).toContain('It was not applied')
	})

	it('offers no editor for the municipality pack and says why', async () => {
		const wrapper = await mountOpen(answer())

		const municipality = wrapper.find(
			'[data-testid="cmdb-import-mapping-municipality"]',
		)
		expect(
			municipality.find('[data-testid="cmdb-import-mapping-source"]').text(),
		).toBe('Shipped file')
		expect(
			municipality.find('[data-testid="cmdb-import-mapping-edit"]').exists(),
		).toBe(false)
		expect(
			municipality
				.find('[data-testid="cmdb-import-mapping-not-editable"]')
				.text(),
		).toContain('maps the import options')
		expect(
			wrapper.find('[data-testid="cmdb-import-mapping-module"]').text(),
		).not.toContain('maps the import options')
	})

	it('says the shipped mapping is used when the pack store is not available', async () => {
		const wrapper = await mountOpen(answer(false))

		expect(
			wrapper.find('[data-testid="cmdb-import-mapping-no-store"]').text(),
		).toContain("OpenRegister's pack store is not available")
		expect(wrapper.text()).not.toContain('The packs are kept in OpenRegister')
		expect(
			wrapper.findAll('[data-testid="cmdb-import-mapping-edit"]'),
		).toHaveLength(0)
		for (const source of wrapper.findAll(
			'[data-testid="cmdb-import-mapping-source"]',
		)) {
			expect(source.text()).toBe('Shipped file')
		}
		expect(
			wrapper.find('[data-testid="cmdb-import-mapping-edited"]').exists(),
		).toBe(false)
	})

	it('shows the error, its reason and its code on a 503, and no pack', async () => {
		http.get.mockRejectedValue({
			response: {
				status: 503,
				data: {
					success: false,
					error: 'MAPPING_UNAVAILABLE',
					message: 'The import mapping cannot be shown.',
					details: {
						reason: 'OpenRegister migration pack stackiq-topdesk-usage (id 13) is invalid',
					},
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
		expect(error.text()).toContain(
			'OpenRegister migration pack stackiq-topdesk-usage (id 13) is invalid',
		)
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

	it('names each target and each source', () => {
		expect(packTargetLabel('businessOwner')).toBe(
			'Business owner (contact person)',
		)
		expect(packTargetLabel('something-new')).toBe('something-new')
		expect(packSourceLabel('openregister')).toBe('OpenRegister')
		expect(packSourceLabel('file')).toBe('Shipped file')
		expect(packSourceLabel('elsewhere')).toBe('elsewhere')
		expect(packSourceLabel(undefined)).toBe('')
	})

	it('gives the stored version, and the shipped one when it differs', () => {
		const stored = {
			source: 'openregister',
			storedVersion: '2.0.1',
			shippedVersion: '2.0.0',
		}
		expect(packVersionText(stored)).toBe('Version 2.0.1 (shipped: 2.0.0)')
		expect(packVersionText({ ...stored, shippedVersion: '2.0.1' })).toBe(
			'Version 2.0.1',
		)
		expect(
			packVersionText({
				source: 'file',
				storedVersion: null,
				shippedVersion: '2.0.0',
			}),
		).toBe('Version 2.0.0')
		// An answer without the new fields falls back to the pack's own version.
		expect(packVersionText({ version: '1.0.0' })).toBe('Version 1.0.0')
		expect(packVersionText({})).toBe('')
	})

	it('links only an editable pack to an http(s) or server-relative address', () => {
		const pack = {
			editable: true,
			editUrl: 'https://cloud.example/apps/openregister/migration-packs/7',
		}
		expect(packEditUrl(pack)).toBe(pack.editUrl)
		expect(
			packEditUrl({
				editable: true,
				editUrl: '/index.php/apps/openregister/migration-packs/7',
			}),
		).toBe('/index.php/apps/openregister/migration-packs/7')
		expect(packEditUrl({ ...pack, editable: false })).toBeNull()
		expect(packEditUrl({ ...pack, editUrl: null })).toBeNull()
		expect(
			packEditUrl({ editable: true, editUrl: 'javascript:alert(1)' }),
		).toBeNull()
		expect(
			packEditUrl({ editable: true, editUrl: '//evil.example/x' }),
		).toBeNull()
		expect(packEditUrl(null)).toBeNull()
	})
})
