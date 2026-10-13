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
	editorUrl,
	fileHelpText,
	mappingSheetNames,
	packEditUrl,
	packFieldRows,
	packSourceLabel,
	packTargetLabel,
	packWriteRules,
	packVersionText,
	PROFILE_DEFAULTS,
	sheetsReadText,
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
			fieldMappings: [
				{
					source: 'APPID',
					target: 'externalNumber',
					required: true,
					transform: { type: 'trim' },
				},
				{
					source: 'Soort',
					target: 'kind',
					required: false,
					transform: { type: 'lookup', map: { a: 'A', b: 'B' } },
				},
			],
			defaults:
				target === 'manufacturer'
					? { type: 'Supplier', status: 'Active' }
					: [],
			createOnlyDefaults: target === 'module' ? { type: 'Application' } : [],
			createOnlyFields: target === 'usage' ? ['interneAnnotation'] : [],
			neverWritten: target === 'module' ? ['publicationDate'] : [],
			updatePending: false,
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

	it('shows one row per sheet pack from the endpoint and emits the answer', async () => {
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
			).toBe(target !== 'municipality')
		}
		const profile = wrapper.find('[data-testid="cmdb-import-mapping-profile"]')
		expect(profile.element.tagName).toBe('TABLE')
		expect(
			profile
				.findAll('tr')[0]
				.findAll('li')
				.map((item) => item.text()),
		).toEqual(['Sheet A', 'Sheet B'])
		expect(
			wrapper.find('[data-testid="cmdb-import-mapping-packs"]').element
				.tagName,
		).toBe('TABLE')
		// The columns are edited in OpenRegister's editor: nothing here is a field.
		expect(wrapper.find('input, textarea, select').exists()).toBe(false)
		expect(wrapper.text()).not.toContain('lib/Settings/cmdb-import')
		expect(wrapper.text()).toContain(
			'A pack deleted in OpenRegister is restored',
		)
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

	it('lists the column-to-field mappings and the write rules of each sheet pack as text', async () => {
		const wrapper = await mountOpen(answer())

		const module = wrapper.find(
			'[data-testid="cmdb-import-mapping-details-module"]',
		)
		expect(module.element.tagName).toBe('DETAILS')
		expect(module.find('summary').text()).toBe(
			'Columns and fields: Application (module)',
		)
		const fields = module.find('[data-testid="cmdb-import-mapping-fields"]')
		expect(fields.find('caption').text()).toBe(
			'Which column of the export goes to which field',
		)
		expect(fields.findAll('thead th[scope="col"]')).toHaveLength(4)
		const rows = fields
			.findAll('tbody tr')
			.map((row) => row.findAll('th, td').map((cell) => cell.text()))
		expect(rows).toEqual([
			['APPID', 'externalNumber', 'Yes', 'trim'],
			['Soort', 'kind', 'No', 'lookup (2)'],
		])
		expect(fields.findAll('tbody th[scope="row"]')).toHaveLength(2)
		const rules = module.find('[data-testid="cmdb-import-mapping-rules"]')
		expect(rules.text()).toContain('Set on a new record only')
		expect(rules.text()).toContain('type: Application')
		expect(rules.text()).toContain('Never written on an existing record')
		expect(rules.text()).toContain('publicationDate')
		expect(rules.text()).not.toContain('Set on every record')
		const manufacturer = wrapper.find(
			'[data-testid="cmdb-import-mapping-details-manufacturer"]',
		)
		expect(manufacturer.text()).toContain('type: Supplier')
		expect(manufacturer.text()).toContain('status: Active')
		expect(
			wrapper
				.find('[data-testid="cmdb-import-mapping-details-municipality"]')
				.exists(),
		).toBe(false)
		expect(
			wrapper.find('[data-testid="cmdb-import-mapping-packs"] caption').text(),
		).toBe('Packs')
		expect(wrapper.html()).not.toContain('which column goes to which field')
	})

	it('shapes field mappings and write rules without throwing on odd input', () => {
		expect(packFieldRows(undefined)).toEqual([])
		expect(
			packFieldRows({
				fieldMappings: [{ source: 'A', target: 'b', transform: 'x' }],
			}),
		).toEqual([{ source: 'A', target: 'b', required: false, transform: 'x' }])
		expect(packWriteRules({ defaults: [], neverWritten: [] })).toEqual([])
		expect(packWriteRules({ defaults: { n: 1 } })[0].values).toEqual(['n: 1'])
	})

	it('says an update is pending for an unedited stored pack the next import replaces', async () => {
		const data = answer()
		Object.assign(data.packs[0], {
			version: '1.1.0',
			storedVersion: '1.0.0',
			shippedVersion: '1.1.0',
			updatePending: true,
		})
		const wrapper = await mountOpen(data)

		const module = wrapper.find('[data-testid="cmdb-import-mapping-module"]')
		expect(
			module.find('[data-testid="cmdb-import-mapping-update-pending"]').text(),
		).toBe(
			'Update pending: shipped 1.1.0 replaces stored 1.0.0 at the next import.',
		)
		expect(
			wrapper
				.find('[data-testid="cmdb-import-mapping-manufacturer"]')
				.find('[data-testid="cmdb-import-mapping-update-pending"]')
				.exists(),
		).toBe(false)
	})

	it('announces the loading in a live region outside the block hidden while collapsed', async () => {
		let resolve
		http.get.mockReturnValue(
			new Promise((done) => {
				resolve = done
			}),
		)
		const wrapper = mount(CmdbImportMapping)
		await flushPromises()

		const status = wrapper.find('[data-testid="cmdb-import-mapping-status"]')
		expect(status.attributes('role')).toBe('status')
		expect(status.text()).toBe('Loading the mapping…')
		expect(
			wrapper
				.find('#cmdb-import-mapping-content')
				.find('[role="status"]')
				.exists(),
		).toBe(false)
		expect(
			wrapper.find('#cmdb-import-mapping-content').attributes('style'),
		).toContain('display: none')

		resolve({ data: answer() })
		await flushPromises()
		expect(
			wrapper.find('[data-testid="cmdb-import-mapping-status"]').text(),
		).toBe('')
	})

	it('leaves out the municipality pack, which maps the import options and no column', async () => {
		const wrapper = await mountOpen(answer())

		expect(
			wrapper
				.find('[data-testid="cmdb-import-mapping-municipality"]')
				.exists(),
		).toBe(false)
		expect(wrapper.text()).not.toContain(
			'Municipality (from the import options)',
		)
		expect(
			wrapper.findAll('[data-testid="cmdb-import-mapping-edit"]'),
		).toHaveLength(4)
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
		expect(
			wrapper.find('[data-testid="cmdb-import-mapping-error-edit"]').exists(),
		).toBe(false)
	})

	it('links a broken stored pack to its editor from the error', async () => {
		const failure = (editUrl) => ({
			response: {
				status: 503,
				data: {
					success: false,
					error: 'MAPPING_UNAVAILABLE',
					message: 'The import mapping cannot be shown.',
					details: {
						pack: 'stackiq-topdesk-usage',
						packId: 13,
						editUrl,
						reason: 'OpenRegister migration pack stackiq-topdesk-usage (id 13) breaks a rule of the CMDB import',
					},
				},
			},
		})

		http.get.mockRejectedValue(
			failure(
				'https://cloud.example/index.php/apps/openregister/migration-packs/13',
			),
		)
		let wrapper = mount(CmdbImportMapping)
		await flushPromises()
		const edit = wrapper.find('[data-testid="cmdb-import-mapping-error-edit"]')
		expect(edit.exists()).toBe(true)
		expect(edit.text()).toContain('Edit in OpenRegister')
		expect(edit.attributes('href')).toBe(
			'https://cloud.example/index.php/apps/openregister/migration-packs/13',
		)
		expect(edit.attributes('target')).toBe('_blank')
		expect(edit.attributes('aria-label')).toBe(
			'Edit in OpenRegister: stackiq-topdesk-usage (opens in a new tab)',
		)

		http.get.mockRejectedValue(failure('javascript:alert(1)'))
		wrapper = mount(CmdbImportMapping)
		await flushPromises()
		expect(
			wrapper.find('[data-testid="cmdb-import-mapping-error"]').exists(),
		).toBe(true)
		expect(
			wrapper.find('[data-testid="cmdb-import-mapping-error-edit"]').exists(),
		).toBe(false)
	})
})

describe('CmdbImportMapping errors without a reason', () => {
	beforeEach(() => {
		http.get.mockReset()
	})

	/**
	 * Load the block against a failing endpoint and return its error card.
	 *
	 * @param {object} failure The rejection the endpoint produces
	 * @return {Promise<object>} The error card
	 */
	async function errorCard(failure) {
		http.get.mockRejectedValue(failure)
		const wrapper = mount(CmdbImportMapping)
		await flushPromises()
		await wrapper
			.find('[data-testid="cmdb-import-mapping-toggle"]')
			.trigger('click')
		expect(wrapper.emitted('loaded')).toBeUndefined()
		return wrapper.find('[data-testid="cmdb-import-mapping-error"]')
	}

	it('shows the server message and the code on a 403 without a reason', async () => {
		const error = await errorCard({
			response: {
				status: 403,
				data: {
					success: false,
					error: 'NOT_ADMIN',
					message: 'Admins only.',
				},
			},
		})

		expect(error.exists()).toBe(true)
		expect(error.text()).toContain('Admins only.')
		expect(error.text()).toContain('NOT_ADMIN')
	})

	it('shows only the code when the request never got an answer', async () => {
		const error = await errorCard(new Error('Network Error'))

		expect(error.exists()).toBe(true)
		expect(error.text()).toContain('NETWORK_ERROR')
		expect(error.findAll('p')).toHaveLength(1)
	})

	it('shows the code of a 500 whose body carries no reason', async () => {
		const error = await errorCard({
			response: {
				status: 500,
				data: { success: false, error: 'IMPORT_FAILED', details: {} },
			},
		})

		expect(error.text()).toContain('IMPORT_FAILED')
		expect(error.findAll('p')).toHaveLength(1)
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

	it.each([
		[0, PROFILE_DEFAULTS.sheets],
		[1, ['Sheet A']],
		[2, ['Sheet A', 'Sheet B']],
		[3, ['Sheet A', 'Sheet B', 'Sheet C']],
	])(
		'names every sheet of a profile with %i sheets in both help texts, in its order',
		(count, expected) => {
			const names = ['Sheet A', 'Sheet B', 'Sheet C'].slice(0, count)
			const mapping = {
				profile: { sheets: names.map((name) => ({ name })) },
			}
			const sheets = mappingSheetNames(mapping)
			expect(sheets).toEqual(expected)

			const quoted = expected.map((name) => `"${name}"`).join(', ')
			const help = fileHelpText(sheets, 10 * 1024 * 1024)
			expect(help).toContain(quoted)
			expect(help).toContain('at most 10 MB')
			expect(help).toContain(
				expected.length === 1
					? 'with the sheet '
					: 'with one of the sheets ',
			)
			const info = sheetsReadText(sheets)
			expect(info).toContain(quoted)
			expect(info).toContain(expected.length === 1 ? 'is read' : 'are read')
			// No sheet the profile does not name, and no position-bound description.
			for (const shipped of PROFILE_DEFAULTS.sheets) {
				if (!expected.includes(shipped)) {
					expect(help).not.toContain(shipped)
					expect(info).not.toContain(shipped)
				}
			}
			expect(info).not.toContain('maintenance')
		},
	)

	it('names each target and each source', () => {
		expect(packTargetLabel('businessOwner')).toBe(
			'Business owner (contact person)',
		)
		expect(packTargetLabel('something-new')).toBe('something-new')
		// The municipality pack is never listed, so it has no words of its own.
		expect(packTargetLabel('municipality')).toBe('municipality')
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

	it('accepts only an http(s) or server-relative editor address', () => {
		expect(editorUrl('https://cloud.example/x')).toBe('https://cloud.example/x')
		expect(editorUrl('/apps/openregister/migration-packs/7')).toBe(
			'/apps/openregister/migration-packs/7',
		)
		expect(editorUrl('javascript:alert(1)')).toBeNull()
		expect(editorUrl('//evil.example/x')).toBeNull()
		expect(editorUrl(null)).toBeNull()
		expect(editorUrl(7)).toBeNull()
	})
})
