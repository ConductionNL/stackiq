// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The CMDB import section's handlers: choosing a file, showing a report (and
 * selecting the municipality it created), recovering an interrupted import,
 * a refused cancel, sorting and paging the report.
 *
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-every-import-shall-have-exactly-one-consuming-municipality-chosen-by-the-admin-req-cmdb-004
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import CmdbImport from '../../src/views/settings/sections/CmdbImport.vue'

vi.mock(
	'@nextcloud/axios',
	() => ({
		__esModule: true,
		default: { get: vi.fn(), post: vi.fn() },
	}),
)
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) =>
		url.replace(/{(\w+)}/g, (match, key) => String(params[key] ?? match)),
	generateOcsUrl: (url) => url,
}))
// The component libraries are stubbed.
// shallowMount replaces them with empty stubs. The ones below also render what
// the tests that mount the section for real (`mountFull`) need to see: the
// slots, the radio's value and the table's cell slots.
vi.mock(
	'@nextcloud/vue',
	async () => {
		const { h } = await import('vue')
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
)
vi.mock(
	'@conduction/nextcloud-vue',
	async () => {
		const { h } = await import('vue')
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
)
// The icon SFCs are stubbed.
vi.mock('vue-material-design-icons/Close.vue', () => ({ default: { render: () => null } }))
vi.mock('vue-material-design-icons/DatabaseImport.vue', () => ({ default: { render: () => null } }))
vi.mock('vue-material-design-icons/TrayArrowUp.vue', () => ({ default: { render: () => null } }))
vi.mock('vue-material-design-icons/ChevronDown.vue', () => ({ default: { render: () => null } }))
vi.mock('vue-material-design-icons/ChevronUp.vue', () => ({ default: { render: () => null } }))
vi.mock('vue-material-design-icons/OpenInNew.vue', () => ({ default: { render: () => null } }))
vi.mock('../../src/components/AlwaysVisibleSection.vue', async () => {
	const { h } = await import('vue')
	return {
		default: {
			render() {
				return h('div', this.$slots.default?.())
			},
		},
	}
})

const ORGANISATIONS = [
	{ id: 'aaaaaaaa-1111', name: 'Gemeente Bergen', type: 'Municipality', status: 'Active' },
	{ id: 'cccccccc-3333', name: 'Gemeente Zuid', type: 'Municipality', status: 'Active' },
]

/**
 * Mount the section with OpenRegister answering the organisations.
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
 * A file input change event carrying this file, or none.
 *
 * @param {object|null} file The file
 * @return {object} The event
 */
function fileEvent(file) {
	return { target: { files: file ? [file] : [] } }
}

afterEach(() => {
	vi.clearAllMocks()
})

describe('CmdbImport choosing a file', () => {
	it('keeps an xlsx and clears the previous run', async () => {
		const wrapper = await mountSection()
		wrapper.vm.error = { error: 'UPLOAD_FAILED' }
		wrapper.vm.report = { rows: [] }
		const file = { name: 'Export.XLSX', size: 10 }

		wrapper.vm.handleFileSelect(fileEvent(file))

		expect(wrapper.vm.selectedFile).toStrictEqual(file)
		expect(wrapper.vm.error).toBeNull()
		expect(wrapper.vm.report).toBeNull()
	})

	it('refuses another kind of file in the server\'s error shape', async () => {
		const wrapper = await mountSection()

		wrapper.vm.handleFileSelect(fileEvent({ name: 'export.csv' }))

		expect(wrapper.vm.error).toEqual({ error: 'NOT_XLSX', details: {}, message: '', status: 0 })
	})

	it('clears the choice when the input is emptied', async () => {
		const wrapper = await mountSection()
		wrapper.vm.selectedFile = { name: 'old.xlsx' }

		wrapper.vm.handleFileSelect(fileEvent(null))

		expect(wrapper.vm.selectedFile).toBeNull()
		expect(wrapper.vm.error).toBeNull()
	})
})

describe('CmdbImport showing a report', () => {
	it('resets the outcome filter and the page, and keeps an existing municipality', async () => {
		const wrapper = await mountSection()
		const chosen = wrapper.vm.municipalityOptions[0]
		wrapper.vm.municipality = chosen
		wrapper.vm.visibleRowCount = 300
		const report = { rows: [], municipality: { uuid: 'aaaaaaaa-1111', name: 'Gemeente Bergen' } }

		wrapper.vm.showReport(report)

		expect(wrapper.vm.report).toStrictEqual(report)
		expect(wrapper.vm.outcomeFilter.id).toBe('all')
		expect(wrapper.vm.visibleRowCount).toBe(100)
		expect(wrapper.vm.municipality).toStrictEqual(chosen)
	})

	it('selects the municipality the import created, sorted in among the others', async () => {
		const wrapper = await mountSection()
		// The server's spelling of the created name wins over what was typed.
		wrapper.vm.municipality = { id: null, label: 'gemeente noord', name: 'gemeente noord', isNew: true }

		wrapper.vm.showReport({ rows: [], municipality: { uuid: 'nnnnnnnn-9999', name: 'Gemeente Noord' } })

		const created = { id: 'nnnnnnnn-9999', label: 'Gemeente Noord', name: 'Gemeente Noord', isNew: false }
		expect(wrapper.vm.municipality).toEqual(created)
		expect(wrapper.vm.municipalityOptions.map((option) => option.label)).toEqual([
			'Gemeente Bergen',
			'Gemeente Noord',
			'Gemeente Zuid',
		])
	})

	it('takes the typed name when the report names none', async () => {
		const wrapper = await mountSection()
		wrapper.vm.municipality = { id: null, label: 'Gemeente Oost', name: 'Gemeente Oost', isNew: true }

		wrapper.vm.showReport({ rows: [], municipality: { uuid: 'oooooooo-5555' } })

		expect(wrapper.vm.municipality.id).toBe('oooooooo-5555')
		expect(wrapper.vm.municipality.name).toBe('Gemeente Oost')
	})

	it('selects the listed option when the created municipality is already offered', async () => {
		const wrapper = await mountSection()
		const before = wrapper.vm.municipalityOptions.length
		wrapper.vm.municipality = { id: null, label: 'Gemeente Zuid', name: 'Gemeente Zuid', isNew: true }

		wrapper.vm.showReport({ rows: [], municipality: { uuid: 'cccccccc-3333', name: 'Gemeente Zuid' } })

		expect(wrapper.vm.municipality).toStrictEqual(wrapper.vm.municipalityOptions[1])
		expect(wrapper.vm.municipalityOptions).toHaveLength(before)
	})
})

describe('CmdbImport recovering an interrupted import', () => {
	it('shows the report the server finished meanwhile', async () => {
		const wrapper = await mountSection()
		wrapper.vm.operationId = 'cmdb_import_abc'
		const report = { rows: [{ row: 2 }] }
		axios.get.mockResolvedValue({ data: { progress: { status: 'completed', statistics: { report } } } })

		await wrapper.vm.recoverInterruptedImport({ error: 'NETWORK_ERROR', status: 504 })

		expect(axios.get).toHaveBeenLastCalledWith('/apps/stackiq/api/progress/cmdb_import_abc')
		expect(wrapper.vm.report).toEqual(report)
		expect(wrapper.vm.progress.status).toBe('completed')
	})

	it('reports a failed import as failed', async () => {
		const wrapper = await mountSection()
		wrapper.vm.operationId = 'cmdb_import_abc'
		axios.get.mockResolvedValue({ data: { progress: { status: 'failed' } } })

		await wrapper.vm.recoverInterruptedImport({ error: 'IMPORT_INTERRUPTED', status: 502 })

		expect(wrapper.vm.error).toEqual({
			message: '',
			details: {},
			status: 502,
			interrupted: true,
			error: 'IMPORT_FAILED',
		})
		expect(wrapper.vm.report).toBeNull()
	})
})

describe('CmdbImport cancelling', () => {
	it('does nothing without a running operation', async () => {
		const wrapper = await mountSection()
		wrapper.vm.operationId = null

		await wrapper.vm.cancelImport()

		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.vm.cancelling).toBe(false)
	})

	it('lets the admin press Cancel again when the server refuses', async () => {
		const wrapper = await mountSection()
		wrapper.vm.operationId = 'cmdb_import_abc'
		axios.post.mockRejectedValue({ response: { status: 404, data: { error: 'OPERATION_NOT_FOUND' } } })

		await wrapper.vm.cancelImport()

		expect(axios.post).toHaveBeenCalledWith('/apps/stackiq/api/cmdb-import/cmdb_import_abc/cancel')
		expect(wrapper.vm.cancelling).toBe(false)
		expect(wrapper.vm.cancelStatus).toContain('cannot be cancelled now')
	})
})

describe('CmdbImport leaving the page', () => {
	it('stops following the import when the section is unmounted', async () => {
		const wrapper = await mountSection()
		const stop = vi.fn()
		wrapper.vm.stopProgressPolling = stop

		wrapper.unmount()

		expect(stop).toHaveBeenCalledTimes(1)
	})
})

describe('CmdbImport file size', () => {
	it('shows kilobytes, at least one, below a megabyte and megabytes with one decimal above', async () => {
		const wrapper = await mountSection()

		expect(wrapper.vm.formatFileSize(0)).toBe('1 KB')
		expect(wrapper.vm.formatFileSize(300 * 1024)).toBe('300 KB')
		expect(wrapper.vm.formatFileSize(3.5 * 1024 * 1024)).toBe('3.5 MB')
	})
})

describe('CmdbImport report table', () => {
	it('lists the import warnings, prefixed with their sheet when they name one', async () => {
		const wrapper = await mountSection()
		wrapper.vm.report = {
			rows: [],
			importWarnings: [
				{ sheet: 'Applicaties', message: 'Column X is ignored' },
				{ sheet: 'Archief' },
				{ message: 'No archive sheet' },
				'Plain text',
				null,
			],
		}

		expect(wrapper.vm.importWarnings).toEqual([
			'Applicaties: Column X is ignored',
			'Archief: ',
			'No archive sheet',
			'Plain text',
			'',
		])
	})

	it('shows only the rows with the chosen outcome', async () => {
		const wrapper = await mountSection()
		wrapper.vm.report = {
			rows: [
				{ sheet: 'A', row: 2, name: 'One', outcome: 'created' },
				{ sheet: 'A', row: 3, name: 'Two', outcome: 'failed' },
				{ sheet: 'A', row: 4, name: 'Three', outcome: 'created' },
			],
		}

		wrapper.vm.outcomeFilter = { id: 'created' }
		expect(wrapper.vm.filteredRows.map((row) => row.name)).toEqual(['One', 'Three'])

		wrapper.vm.outcomeFilter = { id: 'all' }
		expect(wrapper.vm.filteredRows).toHaveLength(3)
	})

	it('keeps the sort the admin chose and shows the next page of rows', async () => {
		const wrapper = await mountSection()
		wrapper.vm.visibleRowCount = 100

		wrapper.vm.onSort({ key: 'name', order: 'desc' })
		wrapper.vm.showMoreRows()

		expect(wrapper.vm.sortKey).toBe('name')
		expect(wrapper.vm.sortOrder).toBe('desc')
		expect(wrapper.vm.visibleRowCount).toBe(200)
	})
})
