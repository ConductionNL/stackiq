/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The CMDB import section's municipality chooser: which organisations it
 * offers, and what it sends for a typed name.
 *
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-every-import-shall-have-exactly-one-consuming-municipality-chosen-by-the-admin-req-cmdb-004
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount, shallowMount } from '@vue/test-utils'
import { h } from 'vue'
import CmdbImport from './CmdbImport.vue'
import CmdbImportMapping from './CmdbImportMapping.vue'

// Virtual: the package's `exports` field declares only the `import` condition, which jest does not resolve.
jest.mock(
	'@nextcloud/axios',
	() => ({
		__esModule: true,
		default: { get: jest.fn(), post: jest.fn() },
	}),
	{ virtual: true },
)
jest.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) =>
		url.replace(/{(\w+)}/g, (match, key) => String(params[key] ?? match)),
	generateOcsUrl: (url) => url,
}))
// The component libraries are stubbed; virtual for the same `exports` reason.
// shallowMount replaces them with empty stubs. The ones below also render what
// the tests that mount the section for real (`mountFull`) need to see: the
// slots, the radio's value and the table's cell slots.
jest.mock(
	'@nextcloud/vue',
	() => {
		const { h } = require('vue')
		const withSlot = {
			render() {
				return h('div', this.$slots.default?.())
			},
		}
		return {
			NcButton: {
				render() {
					return h('button', this.$slots.default?.())
				},
			},
			NcCheckboxRadioSwitch: {
				props: {
					modelValue: { default: null },
					value: { default: null },
					name: { default: '' },
					type: { default: 'checkbox' },
					disabled: { default: false },
				},
				emits: ['update:modelValue'],
				render() {
					const radio = this.type === 'radio'
					return h('label', [
						h('input', {
							type: radio ? 'radio' : 'checkbox',
							name: this.name,
							value: this.value,
							disabled: this.disabled,
							checked: radio
								? this.modelValue === this.value
								: Boolean(this.modelValue),
							onChange: (event) =>
								this.$emit(
									'update:modelValue',
									radio ? this.value : event.target.checked,
								),
						}),
						this.$slots.default?.(),
					])
				},
			},
			NcLoadingIcon: { render: () => null },
			NcNoteCard: withSlot,
			NcProgressBar: { render: () => null },
			NcSelect: { render: () => null },
		}
	},
	{ virtual: true },
)
jest.mock(
	'@conduction/nextcloud-vue',
	() => {
		const { h } = require('vue')
		return {
			// One element per row, with the cell slots the section fills.
			CnDataTable: {
				props: { rows: { default: () => [] } },
				render() {
					return h(
						'div',
						this.rows.map((row) =>
							h('div', { 'data-testid': 'table-row' }, [
								h(
									'span',
									{ 'data-testid': 'table-row-number' },
									this.$slots['column-row']?.({ row }),
								),
								h('span', this.$slots['column-name']?.({ row })),
							]),
						),
					)
				},
			},
			CnStatusBadge: { render: () => null },
		}
	},
	{ virtual: true },
)
// The icon SFCs live in node_modules, which jest does not transform.
jest.mock('vue-material-design-icons/Close.vue', () => ({ render: () => null }))
jest.mock('vue-material-design-icons/DatabaseImport.vue', () => ({
	render: () => null,
}))
jest.mock('vue-material-design-icons/TrayArrowUp.vue', () => ({
	render: () => null,
}))
jest.mock('vue-material-design-icons/ChevronDown.vue', () => ({
	render: () => null,
}))
jest.mock('vue-material-design-icons/ChevronUp.vue', () => ({
	render: () => null,
}))
jest.mock('vue-material-design-icons/OpenInNew.vue', () => ({
	render: () => null,
}))
jest.mock('../../../components/AlwaysVisibleSection.vue', () => {
	const { h } = require('vue')
	return {
		render() {
			return h('div', this.$slots.default?.())
		},
	}
})

const ORGANISATIONS = [
	{
		id: 'aaaaaaaa-1111',
		name: 'Gemeente Bergen',
		type: 'Municipality',
		status: 'Active',
	},
	{
		id: 'bbbbbbbb-2222',
		name: 'Gemeente Bergen',
		type: 'Municipality',
		status: 'Active',
	},
	{
		id: 'cccccccc-3333',
		name: 'Gemeente Oud',
		type: 'Municipality',
		status: 'merged',
	},
	{
		id: 'dddddddd-4444',
		name: 'Gemeente Slaap',
		type: 'Municipality',
		status: 'Inactive',
	},
]

/**
 * Mount the section with OpenRegister answering the given organisations.
 *
 * @return {Promise<object>} The wrapper, after the municipalities loaded
 */
async function mountSection(options = {}) {
	axios.get.mockImplementation((url) =>
		Promise.resolve(
			url.includes('/voorzieningen/config')
				? { data: { config: { register: 7, organisatie_schema: 33 } } }
				: { data: { results: ORGANISATIONS } },
		),
	)
	const wrapper = shallowMount(CmdbImport, options)
	await flushPromises()
	return wrapper
}

/**
 * Mount the section with its child components rendering what the tests read.
 *
 * @return {Promise<object>} The wrapper, after the municipalities loaded
 */
async function mountFull() {
	axios.get.mockImplementation((url) =>
		Promise.resolve(
			url.includes('/voorzieningen/config')
				? { data: { config: { register: 7, organisatie_schema: 33 } } }
				: { data: { results: ORGANISATIONS } },
		),
	)
	const wrapper = mount(CmdbImport)
	await flushPromises()
	return wrapper
}

describe('CmdbImport municipality chooser', () => {
	afterEach(() => {
		jest.clearAllMocks()
	})

	it('does not offer a merged or an inactive municipality', async () => {
		const wrapper = await mountSection()

		const ids = wrapper.vm.municipalityOptions.map((option) => option.id)
		expect(ids).toEqual(['aaaaaaaa-1111', 'bbbbbbbb-2222'])
		expect(
			new Set(wrapper.vm.municipalityOptions.map((o) => o.label)).size,
		).toBe(2)
	})

	it('posts a typed name that two municipalities share as municipalityName and shows MUNICIPALITY_AMBIGUOUS', async () => {
		const wrapper = await mountSection()
		axios.post.mockRejectedValue({
			response: {
				status: 422,
				data: {
					success: false,
					error: 'MUNICIPALITY_AMBIGUOUS',
					message:
						'Several municipalities have this name; choose one from the list.',
					details: { matches: ['aaaaaaaa-1111', 'bbbbbbbb-2222'] },
				},
			},
		})

		wrapper.vm.municipality =
			wrapper.vm.createMunicipalityOption('gemeente bergen')
		wrapper.vm.selectedFile = new File(['x'], 'export.xlsx')
		await wrapper.vm.startImport()

		expect(axios.post).toHaveBeenCalledTimes(1)
		const form = axios.post.mock.calls[0][1]
		expect(form.get('municipalityName')).toBe('gemeente bergen')
		expect(form.has('municipalityUuid')).toBe(false)
		expect(wrapper.vm.error.error).toBe('MUNICIPALITY_AMBIGUOUS')
		expect(wrapper.vm.report).toBe(null)
	})
})

// shallowMount stubs the section's wrapper without its slots; this one renders them, so the help text and the info panel are in the DOM.
const SLOT_RENDERING_SECTION = {
	render() {
		return h('div', [this.$slots.default?.(), this.$slots['info-content']?.()])
	},
}

/**
 * Mount the section with the wrapper rendering its slots.
 *
 * @return {Promise<object>} The wrapper
 */
function mountWithSlots() {
	return mountSection({
		global: { stubs: { AlwaysVisibleSection: SLOT_RENDERING_SECTION } },
	})
}

describe('CmdbImport sheet names in the help texts', () => {
	afterEach(() => {
		jest.clearAllMocks()
	})

	const SHIPPED = ['Onbeh Applicaties CMDB', 'Beheerde Applicaties CMDB']

	/**
	 * The mapping endpoint's answer for a profile with the given sheets.
	 *
	 * @param {Array<string>} names The sheet names
	 * @return {object} The answer, reduced to what the section reads
	 */
	function mappingOf(names) {
		return {
			profile: { sheets: names.map((name) => ({ name })) },
			packs: [],
		}
	}

	/**
	 * The help text under the file chooser and the info panel's sheet sentence.
	 *
	 * @param {object} wrapper The mounted section
	 * @return {{help: string, info: string}} Both texts, as rendered
	 */
	function renderedTexts(wrapper) {
		return {
			help: wrapper.find('#cmdb-import-file-help').text(),
			info: wrapper
				.findAll('.cmdb-import-info li')
				.map((li) => li.text())
				.find((text) => /sheets? .* (is|are) read/.test(text)),
		}
	}

	it('names the shipped sheets until the mapping block has loaded', async () => {
		const wrapper = await mountWithSlots()

		const { help, info } = renderedTexts(wrapper)
		expect(help).toContain('"' + SHIPPED[0] + '", "' + SHIPPED[1] + '"')
		expect(info).toContain('"' + SHIPPED[0] + '", "' + SHIPPED[1] + '"')
	})

	it('names the sheets the mapping block emits, in the help text and the info panel', async () => {
		const wrapper = await mountWithSlots()

		wrapper
			.findComponent(CmdbImportMapping)
			.vm.$emit('loaded', mappingOf(['Alpha', 'Beta', 'Gamma']))
		await flushPromises()

		const { help, info } = renderedTexts(wrapper)
		expect(help).toContain('"Alpha", "Beta", "Gamma"')
		expect(info).toContain('"Alpha", "Beta", "Gamma"')
		for (const shipped of SHIPPED) {
			expect(help).not.toContain(shipped)
			expect(info).not.toContain(shipped)
		}
	})

	it('names only the one sheet of a profile with one sheet', async () => {
		const wrapper = await mountWithSlots()

		wrapper
			.findComponent(CmdbImportMapping)
			.vm.$emit('loaded', mappingOf(['Alpha']))
		await flushPromises()

		const { help, info } = renderedTexts(wrapper)
		expect(help).toContain('with the sheet "Alpha".')
		expect(info).toContain('The sheet "Alpha" is read')
		for (const shipped of SHIPPED) {
			expect(help).not.toContain(shipped)
		}
	})

	it("names the profile's archive sheet in the info panel, and no archive sheet when the profile names none", async () => {
		const wrapper = await mountWithSlots()
		const mapping = (archiveSheet) => ({
			...mappingOf(['Alpha', 'Beta']),
			profile: { ...mappingOf(['Alpha', 'Beta']).profile, archiveSheet },
		})

		expect(renderedTexts(wrapper).info).toContain(
			'of the sheet "Gearchiveerde Applicaties" only the APPID column',
		)

		wrapper
			.findComponent(CmdbImportMapping)
			.vm.$emit('loaded', mapping('Archief'))
		await flushPromises()
		expect(renderedTexts(wrapper).info).toContain(
			'The sheets "Alpha", "Beta" are read, and of the sheet "Archief" only the APPID column',
		)
		expect(wrapper.find('.cmdb-import-info').text()).toContain(
			'archived when the sheet "Archief" lists it',
		)
		const tooLarge = {
			error: 'WORKBOOK_TOO_LARGE',
			details: { maxUncompressedBytes: 50 * 1024 * 1024 },
		}
		await wrapper.setData({ error: tooLarge })
		expect(wrapper.vm.errorView.hint).toContain(
			'(it reads "Alpha", "Beta", and the APPID column of "Archief")',
		)
		await wrapper.setData({ error: null })

		wrapper.findComponent(CmdbImportMapping).vm.$emit('loaded', mapping(null))
		await flushPromises()
		const info = renderedTexts(wrapper).info
		expect(info).toContain('The sheets "Alpha", "Beta" are read;')
		expect(info).not.toContain('APPID')
		const panel = wrapper.find('.cmdb-import-info').text()
		expect(panel).not.toContain('Gearchiveerde Applicaties')
		expect(panel).not.toContain('moved to the trash')
		expect(panel).not.toContain('archived or deleted')
		const choice = wrapper.find('[data-testid="cmdb-import-missing-records"]').text()
		expect(wrapper.vm.missingRecordsWords.archiveOption).toBe('Archive them')
		expect(choice).not.toContain('delete')
		expect(choice).not.toContain('trash')
		await wrapper.setData({ error: tooLarge })
		expect(wrapper.vm.errorView.hint).toContain(
			'(it reads only "Alpha", "Beta")',
		)
		expect(wrapper.vm.errorView.hint).not.toContain('APPID')
	})

	it("names the profile's sheets in the section's missing-sheet and too-large errors", async () => {
		const wrapper = await mountWithSlots()
		wrapper
			.findComponent(CmdbImportMapping)
			.vm.$emit('loaded', mappingOf(['Alpha', 'Beta', 'Gamma']))
		await flushPromises()

		await wrapper.setData({ error: { error: 'NO_SOURCE_SHEET', details: {} } })
		expect(wrapper.vm.errorView.hint).toContain('"Alpha", "Beta", "Gamma"')

		await wrapper.setData({
			error: {
				error: 'WORKBOOK_TOO_LARGE',
				details: { maxUncompressedBytes: 50 * 1024 * 1024 },
			},
		})
		expect(wrapper.vm.errorView.hint).toContain(
			'(it reads "Alpha", "Beta", "Gamma", and the APPID column of "Gearchiveerde Applicaties")',
		)
	})
})

/**
 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014
 */
describe('CmdbImport applications missing from the export', () => {
	afterEach(() => {
		jest.clearAllMocks()
	})

	it('archives by default and posts the choice the admin made', async () => {
		const wrapper = await mountSection()
		axios.post.mockResolvedValue({
			data: { success: true, summary: {}, rows: [] },
		})
		wrapper.vm.municipality = wrapper.vm.municipalityOptions[0]
		wrapper.vm.selectedFile = new File(['x'], 'export.xlsx')

		expect(wrapper.vm.missingRecords).toBe('archive')
		await wrapper.vm.startImport()
		wrapper.vm.missingRecords = 'keep'
		await wrapper.vm.startImport()

		expect(
			axios.post.mock.calls.map(([, form]) => form.get('missingRecords')),
		).toEqual(['archive', 'keep'])
	})

	it('shows the archived, unarchived, deleted and restored counts only when there are any', async () => {
		const wrapper = await mountSection()
		const tileKeys = () => wrapper.vm.summaryTiles.map((tile) => tile.key)

		wrapper.vm.report = { summary: { rowsRead: 2, created: 2 }, rows: [] }
		expect(tileKeys()).not.toContain('archived')

		wrapper.vm.report = {
			summary: {
				rowsRead: 1,
				archived: 1,
				deleted: 1,
				unarchived: 0,
				restored: 2,
			},
			rows: [],
		}
		expect(tileKeys()).toEqual(
			expect.arrayContaining(['archived', 'deleted', 'restored']),
		)
		expect(tileKeys()).not.toContain('unarchived')
		expect(wrapper.vm.outcomeFilterOptions.map((option) => option.id)).toEqual(
			expect.arrayContaining([
				'archived',
				'unarchived',
				'deleted',
				'restored',
			]),
		)
	})
})

/**
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-a-running-import-shall-report-its-progress-and-shall-stop-when-cancelled-req-cmdb-013
 */
describe('CmdbImport cancelling', () => {
	afterEach(() => {
		jest.clearAllMocks()
	})

	it('says after Cancel that the comparison of the applications stops before the next application', async () => {
		const wrapper = await mountFull()
		axios.post.mockResolvedValue({ data: { success: true } })
		await wrapper.setData({
			importing: true,
			operationId: 'cmdb-12345678',
			progress: {
				phase: 'reconciling',
				processed_items: 400,
				total_items: 810,
			},
		})

		await wrapper.vm.cancelImport()
		await flushPromises()

		const status = wrapper
			.find('[data-testid="cmdb-import-cancel-status"]')
			.text()
		expect(status).toContain('before the next row')
		expect(status).toContain('before the next application')
	})

	it('says that the applications processed before the stop are kept, not only rows', async () => {
		const wrapper = await mountFull()

		await wrapper.setData({
			report: {
				cancelled: true,
				summary: { rowsRead: 400, archived: 3 },
				rows: [],
			},
		})

		expect(wrapper.find('[data-testid="cmdb-import-cancelled"]').text()).toMatch(
			/applications/,
		)
	})
})

/**
 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014
 */
describe('CmdbImport finished announcement', () => {
	afterEach(() => {
		jest.clearAllMocks()
	})

	it('names the archived and deleted applications in the sentence the live region announces', async () => {
		const wrapper = await mountFull()

		await wrapper.setData({
			report: {
				municipality: { name: 'Gemeente Bergen' },
				summary: { rowsRead: 0, archived: 1, deleted: 1 },
				rows: [],
			},
		})

		const announced = wrapper.find('[data-testid="cmdb-import-finished"]').text()
		expect(announced).toContain('0 rows read')
		expect(announced).toContain('1 archived')
		expect(announced).toContain('1 deleted')
		expect(announced).not.toContain('unarchived')
		expect(announced).not.toContain('restored')
	})

	it('names every kind of change after a cancel too, and adds nothing when no application was touched', async () => {
		const wrapper = await mountFull()

		await wrapper.setData({
			report: {
				cancelled: true,
				municipality: { name: 'Gemeente Bergen' },
				summary: {
					rowsRead: 5,
					archived: 2,
					unarchived: 3,
					deleted: 4,
					restored: 5,
				},
				rows: [],
			},
		})
		expect(wrapper.vm.finishedText).toBe(
			'Import for Gemeente Bergen cancelled after 5 rows. Applications: 2 archived, 3 unarchived, 4 deleted, 5 restored.',
		)

		// setData merges into the report; a new report replaces it.
		wrapper.vm.report = {
			municipality: { name: 'Gemeente Bergen' },
			summary: { rowsRead: 2, created: 2 },
			rows: [],
		}
		expect(wrapper.vm.finishedText).toBe(
			'Import for Gemeente Bergen finished. 2 rows read: 2 created, 0 updated, 0 unchanged.',
		)
	})
})

/**
 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014
 */
describe('CmdbImport the choice for missing applications, as the admin makes it', () => {
	afterEach(() => {
		jest.clearAllMocks()
	})

	const radio = (wrapper, which) =>
		wrapper.find(`[data-testid="cmdb-import-missing-records-${which}"] input`)

	it('starts on "Archive or delete them" and the radios share one name', async () => {
		const wrapper = await mountFull()

		expect(radio(wrapper, 'archive').element.checked).toBe(true)
		expect(radio(wrapper, 'keep').element.checked).toBe(false)
		expect(radio(wrapper, 'archive').attributes('name')).toBe(
			radio(wrapper, 'keep').attributes('name'),
		)
		expect(radio(wrapper, 'archive').attributes('value')).toBe('archive')
		expect(radio(wrapper, 'keep').attributes('value')).toBe('keep')
	})

	it('posts missingRecords=keep after the admin clicks "Keep them as they are", and only that field changes', async () => {
		const wrapper = await mountFull()
		axios.post.mockResolvedValue({
			data: { success: true, summary: {}, rows: [] },
		})
		wrapper.vm.municipality = wrapper.vm.municipalityOptions[0]
		wrapper.vm.selectedFile = new File(['x'], 'export.xlsx')

		await radio(wrapper, 'keep').setValue(true)
		await flushPromises()

		expect(wrapper.vm.missingRecords).toBe('keep')
		expect(radio(wrapper, 'keep').element.checked).toBe(true)
		expect(radio(wrapper, 'archive').element.checked).toBe(false)
		// The choice is not wired to another option: they keep their defaults.
		expect(wrapper.vm.publish).toBe(true)
		expect(wrapper.vm.updateExisting).toBe(true)

		await wrapper.vm.startImport()
		const form = axios.post.mock.calls[0][1]
		expect(form.get('missingRecords')).toBe('keep')

		await radio(wrapper, 'archive').setValue(true)
		expect(wrapper.vm.missingRecords).toBe('archive')
	})

	it('says in short what happens, and in the info panel when applications are only archived', async () => {
		const wrapper = await mountFull()

		const help = wrapper.get('#cmdb-import-missing-records-help').text()
		expect(help).toContain('archived or moved to the trash')

		// The info panel is the section's `info-content` slot; render it.
		const panel = mount(CmdbImport, {
			global: {
				stubs: {
					AlwaysVisibleSection: {
						render() {
							return h('div', [this.$slots['info-content']?.()])
						},
					},
				},
			},
		})
		await flushPromises()
		const text = panel.text()
		expect(text).toContain('is missing')
		expect(text).toContain('has no APPID column')
		expect(text).toContain('an APPID without a cached value')
		expect(text).toContain('only archived')
	})

	it('disables both radios while an import runs', async () => {
		const wrapper = await mountFull()

		await wrapper.setData({ importing: true })

		expect(radio(wrapper, 'archive').attributes('disabled')).toBeDefined()
		expect(radio(wrapper, 'keep').attributes('disabled')).toBeDefined()
	})
})

/**
 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014
 */
describe('CmdbImport report rows of archived and deleted applications', () => {
	afterEach(() => {
		jest.clearAllMocks()
	})

	const reconciliationRow = (outcome, extra = {}) => ({
		sheet: 'Gearchiveerde Applicaties',
		row: 0,
		appId: outcome === 'deleted' ? '2' : '1234',
		name: outcome === 'deleted' ? 'Naamtest' : 'Mailen',
		outcome,
		moduleUuid: 'module-uuid-' + outcome,
		...extra,
	})

	it('links an archived application to its module, and a deleted one to nothing', async () => {
		const wrapper = await mountFull()

		await wrapper.setData({
			report: {
				summary: { rowsRead: 0, archived: 1, deleted: 1 },
				rows: [reconciliationRow('archived'), reconciliationRow('deleted')],
			},
		})

		const rows = wrapper.findAll('[data-testid="table-row"]')
		expect(rows).toHaveLength(2)
		const [archived, deleted] = rows
		expect(
			archived
				.find('[data-testid="cmdb-import-module-link"]')
				.attributes('href'),
		).toBe('/apps/stackiq/modules/module-uuid-archived')
		expect(
			deleted.find('[data-testid="cmdb-import-module-link"]').exists(),
		).toBe(false)
		// The name of the deleted application is still shown, as text.
		expect(deleted.text()).toContain('Naamtest')
	})

	it('shows no row number for a reconciliation row, and links one that failed', async () => {
		const wrapper = await mountFull()

		await wrapper.setData({
			report: {
				summary: { rowsRead: 1, failed: 1, archived: 1 },
				rows: [
					reconciliationRow('failed', {
						reasons: ['archive: RuntimeException'],
					}),
					reconciliationRow('archived'),
					{
						sheet: 'Beheerde Applicaties CMDB',
						row: 2,
						appId: '5',
						name: 'Gewoon',
						outcome: 'created',
						moduleUuid: 'module-uuid-created',
					},
				],
			},
		})

		const rows = wrapper.findAll('[data-testid="table-row"]')
		expect(
			rows.map((row) => row.get('[data-testid="table-row-number"]').text()),
		).toEqual(['', '', '2'])
		expect(
			rows[0].find('[data-testid="cmdb-import-module-link"]').exists(),
		).toBe(true)
		expect(
			rows[2].find('[data-testid="cmdb-import-module-link"]').exists(),
		).toBe(true)
	})
})
