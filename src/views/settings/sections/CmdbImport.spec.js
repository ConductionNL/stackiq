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
import CmdbImport from './CmdbImport.vue'

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
async function mountSection() {
	axios.get.mockImplementation((url) =>
		Promise.resolve(
			url.includes('/voorzieningen/config')
				? { data: { config: { register: 7, organisatie_schema: 33 } } }
				: { data: { results: ORGANISATIONS } },
		),
	)
	const wrapper = shallowMount(CmdbImport)
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
			progress: { phase: 'reconciling', processed_items: 400, total_items: 810 },
		})

		await wrapper.vm.cancelImport()
		await flushPromises()

		const status = wrapper.find('[data-testid="cmdb-import-cancel-status"]').text()
		expect(status).toContain('before the next row')
		expect(status).toContain('before the next application')
	})

	it('says that the applications processed before the stop are kept, not only rows', async () => {
		const wrapper = await mountFull()

		await wrapper.setData({
			report: { cancelled: true, summary: { rowsRead: 400, archived: 3 }, rows: [] },
		})

		expect(wrapper.find('[data-testid="cmdb-import-cancelled"]').text()).toMatch(
			/applications/,
		)
	})
})
