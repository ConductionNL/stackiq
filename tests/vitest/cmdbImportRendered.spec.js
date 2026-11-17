// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The CMDB import section as rendered with every slot filled: the option
 * switches and selects writing back to the section, the action buttons' icons
 * and the cancel button while running, the progress the polling reports, the
 * error's hint, the file-wide warnings and the report table's cells (row,
 * APPID, outcome badge, notes) under the outcome filter.
 *
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

const polling = vi.hoisted(() => ({ onProgress: null, stop: null }))

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
vi.mock('../../src/utils/archiMateImportProgress.js', () => ({
	startProgressPolling: ({ onProgress }) => {
		polling.onProgress = onProgress
		polling.stop = vi.fn()
		return polling.stop
	},
}))
// The component libraries are stubbed; these stubs render every slot the
// section fills and emit the v-model updates the real components emit.
vi.mock(
	'@nextcloud/vue',
	async () => {
		const { h } = await import('vue')
		return {
			NcButton: {
				props: { disabled: { default: false } },
				emits: ['click'],
				render() {
					return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, [
						h('span', { class: 'icon' }, this.$slots.icon?.()),
						this.$slots.default?.(),
					])
				},
			},
			NcCheckboxRadioSwitch: {
				props: {
					modelValue: { default: null },
					value: { default: null },
					type: { default: 'checkbox' },
				},
				emits: ['update:modelValue'],
				render() {
					const radio = this.type === 'radio'
					return h('label', [
						h('input', {
							type: radio ? 'radio' : 'checkbox',
							checked: radio ? this.modelValue === this.value : Boolean(this.modelValue),
							onChange: (event) => this.$emit('update:modelValue', radio ? this.value : event.target.checked),
						}),
						this.$slots.default?.(),
					])
				},
			},
			NcLoadingIcon: { render: () => h('i', { class: 'loading' }) },
			NcNoteCard: {
				props: { heading: { default: '' } },
				render() {
					return h('div', { class: 'note' }, [h('strong', this.heading), this.$slots.default?.()])
				},
			},
			NcProgressBar: {
				props: { value: { default: 0 } },
				render() {
					return h('progress', { value: this.value })
				},
			},
			NcSelect: {
				inheritAttrs: false,
				props: { modelValue: { default: null }, options: { default: () => [] }, inputId: { default: '' } },
				emits: ['update:modelValue'],
				render() {
					return h('select', {
						id: this.inputId,
						onChange: (event) => this.$emit('update:modelValue', this.options[Number(event.target.value)]),
					}, this.options.map((option, index) => h('option', { value: index }, option.label)))
				},
			},
		}
	},
)
vi.mock(
	'@conduction/nextcloud-vue',
	async () => {
		const { h } = await import('vue')
		const cells = ['row', 'appId', 'name', 'outcome', 'notes']
		return {
			CnDataTable: {
				props: { rows: { default: () => [] } },
				render() {
					return h('div', this.rows.map((row) =>
						h('div', { class: 'table-row' }, cells.map((cell) =>
							h('span', { class: `cell-${cell}` }, this.$slots[`column-${cell}`]?.({ row })),
						)),
					))
				},
			},
			CnStatusBadge: {
				props: { label: { default: '' } },
				render() {
					return h('em', this.label)
				},
			},
		}
	},
)
vi.mock('vue-material-design-icons/Close.vue', async () => {
	const { h } = await import('vue')
	return { default: { render: () => h('i', { class: 'close-icon' }) } }
})
vi.mock('vue-material-design-icons/DatabaseImport.vue', async () => {
	const { h } = await import('vue')
	return { default: { render: () => h('i', { class: 'import-icon' }) } }
})
vi.mock('vue-material-design-icons/TrayArrowUp.vue', () => ({ default: { render: () => null } }))
vi.mock('vue-material-design-icons/ChevronDown.vue', () => ({ default: { render: () => null } }))
vi.mock('vue-material-design-icons/ChevronUp.vue', () => ({ default: { render: () => null } }))
vi.mock('vue-material-design-icons/OpenInNew.vue', () => ({ default: { render: () => null } }))
vi.mock('../../src/views/settings/sections/CmdbImportMapping.vue', () => ({ default: { render: () => null } }))
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

const { default: CmdbImport } = await import('../../src/views/settings/sections/CmdbImport.vue')

const ORGANISATIONS = [
	{ id: 'aaaaaaaa-1111', name: 'Gemeente Bergen', type: 'Municipality', status: 'Active' },
	{ id: 'cccccccc-3333', name: 'Gemeente Zuid', type: 'Municipality', status: 'Active' },
]

const REPORT = {
	rows: [
		{ sheet: 'Applicaties', row: 2, appId: 'APP-1', name: 'Zaaksysteem', outcome: 'created', moduleUuid: 'm-1', reasons: [], warnings: ['No supplier'] },
		{ sheet: 'Applicaties', row: 3, appId: 'APP-2', name: '', outcome: 'failed', reasons: ['Missing name'], warnings: [] },
		{ sheet: 'Applicaties', row: 4, appId: 'APP-3', name: 'Archief', outcome: 'unchanged' },
	],
	importWarnings: [{ sheet: 'Koppelingen', message: 'Sheet is empty' }, 'A plain warning'],
}

async function mountSection() {
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

const testid = (wrapper, id) => wrapper.find(`[data-testid="${id}"]`)

describe('CmdbImport rendered', () => {
	afterEach(() => {
		vi.clearAllMocks()
	})

	it('writes the municipality select and the two option switches back to the section', async () => {
		const wrapper = await mountSection()
		const municipality = wrapper.find('#cmdb-import-municipality')
		const options = wrapper.vm.municipalityOptions
		await municipality.setValue('1')
		expect(wrapper.vm.municipality).toEqual(options[1])

		const update = testid(wrapper, 'cmdb-import-update-existing').find('input')
		const publish = testid(wrapper, 'cmdb-import-publish').find('input')
		const before = [wrapper.vm.updateExisting, wrapper.vm.publish]
		await update.setValue(!before[0])
		await publish.setValue(!before[1])
		expect([wrapper.vm.updateExisting, wrapper.vm.publish]).toEqual([!before[0], !before[1]])
	})

	it('shows the import icon at rest and a spinner plus a cancel button while importing', async () => {
		const wrapper = await mountSection()
		expect(testid(wrapper, 'cmdb-import-start').find('.import-icon').exists()).toBe(true)
		expect(testid(wrapper, 'cmdb-import-cancel').exists()).toBe(false)

		wrapper.vm.importing = true
		await wrapper.vm.$nextTick()
		expect(testid(wrapper, 'cmdb-import-start').find('.loading').exists()).toBe(true)
		const cancel = testid(wrapper, 'cmdb-import-cancel')
		expect([cancel.exists(), cancel.find('.close-icon').exists(), cancel.text()]).toEqual([true, true, 'Cancel import'])
	})

	it('keeps the progress the polling reports for a new run, after clearing the previous one', async () => {
		const wrapper = await mountSection()
		wrapper.vm.report = REPORT
		wrapper.vm.error = { error: 'NO_FILE_UPLOADED' }
		wrapper.vm.beginImport()
		expect([wrapper.vm.importing, wrapper.vm.report, wrapper.vm.error, wrapper.vm.progress]).toEqual([true, null, null, null])
		expect(wrapper.vm.operationId).toMatch(/^cmdb-/)

		const progress = { phase: 'saving', processed: 3, total: 10 }
		polling.onProgress(progress)
		expect(wrapper.vm.progress).toEqual(progress)
	})

	it('shows the hint of an error code that has one', async () => {
		const wrapper = await mountSection()
		wrapper.vm.error = { error: 'NO_FILE_UPLOADED', message: '', status: 400 }
		await wrapper.vm.$nextTick()
		const note = testid(wrapper, 'cmdb-import-error')
		expect(note.text()).toContain('No file was uploaded.')
		expect(note.text()).toContain('Choose the TOPdesk export and try again.')
	})

	it('lists the file-wide warnings and fills every cell of the report table', async () => {
		const wrapper = await mountSection()
		wrapper.vm.showReport(REPORT)
		await wrapper.vm.$nextTick()

		expect(testid(wrapper, 'cmdb-import-warnings').findAll('li').map((li) => li.text())).toEqual(['Koppelingen: Sheet is empty', 'A plain warning'])

		const rows = wrapper.findAll('.table-row')
		expect(rows.map((row) => row.find('[data-testid="cmdb-import-app-id"]').text())).toEqual(['APP-1', 'APP-2', 'APP-3'])
		expect(rows.map((row) => row.find('.cell-outcome em').text())).toEqual(['Created', 'Failed', 'Unchanged'])
		expect(rows.map((row) => row.find('.cell-notes').text())).toEqual(['No supplier', 'Missing name', '—'])
		expect(rows[0].find('[data-testid="cmdb-import-module-link"]').text()).toBe('Zaaksysteem')
		expect(rows[1].find('.cell-name').text()).toBe('—')
	})

	it('narrows the table to the outcome chosen in the filter', async () => {
		const wrapper = await mountSection()
		wrapper.vm.showReport(REPORT)
		await wrapper.vm.$nextTick()
		const filter = wrapper.find('#cmdb-import-outcome-filter')
		const failedIndex = wrapper.vm.outcomeFilterOptions.findIndex((option) => option.id === 'failed')
		await filter.setValue(String(failedIndex))
		expect(wrapper.vm.outcomeFilter.id).toBe('failed')
		expect(wrapper.findAll('.table-row').map((row) => row.find('[data-testid="cmdb-import-app-id"]').text())).toEqual(['APP-2'])
	})
})
