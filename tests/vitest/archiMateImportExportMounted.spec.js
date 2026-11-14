// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * ArchiMateImportExport as rendered: the file-upload view and its selected-file
 * label, the success-results and error-results views that replace it, the
 * running-import progress block, the import button's label/gating and the
 * reset button, the organisation select revealing the include switches, and the
 * base/organisation export buttons' gating and wiring.
 *
 * @spec openspec/specs/fe-settings-ui/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({ loading: false }))
const config = vi.hoisted(() => ({
	organisationSource: vi.fn(async () => null),
	fetchOrganizationOptions: vi.fn(async () => null),
	missingConfigItems: vi.fn(() => []),
}))
const progress = vi.hoisted(() => ({
	cancelImport: vi.fn(async () => {}),
	makeOperationId: vi.fn(() => 'op-1'),
	progressView: vi.fn(() => null),
	startProgressPolling: vi.fn(() => vi.fn()),
}))

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('../../src/store/store.js', () => ({ settingsStore: store }))
vi.mock('../../src/utils/heartbeat.js', () => ({ withHeartbeat: (op) => op() }))
vi.mock('../../src/utils/archiMateImportProgress.js', () => progress)
vi.mock('../../src/views/settings/sections/archiMateConfig.js', () => config)

const { default: ArchiMateImportExport } = await import('../../src/views/settings/sections/ArchiMateImportExport.vue')

const stubs = {
	AlwaysVisibleSection: {
		props: ['loading'],
		template: '<section :data-loading="String(loading)"><slot /><slot name="info-content" /></section>',
	},
	NcNoteCard: { props: ['type'], template: '<div :data-note="type"><slot /></div>' },
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcSelect: {
		props: ['modelValue', 'options'],
		emits: ['update:modelValue'],
		template: '<select data-testid="org-select" @change="$emit(\'update:modelValue\', options[Number($event.target.value)])"><option v-for="(o, i) in options" :key="i" :value="i" /></select>',
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue', 'disabled'],
		emits: ['update:modelValue'],
		template: '<label class="switch"><input type="checkbox" :checked="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>',
	},
	NcProgressBar: { props: ['value'], template: '<progress :value="value" data-testid="bar" />' },
	NcLoadingIcon: { template: '<i data-testid="spinner" />' },
	CloudUpload: true,
	Download: true,
	Refresh: true,
	Close: true,
	AlertCircle: true,
}

const SUCCESS = {
	success: true,
	file_info: { name: 'model.xml', size: 2048 },
	performance_metrics: {
		objects_processed: 1200,
		total_time_seconds: 75,
		items_per_second: 16,
		memory_usage: { peak_memory_mb: 128 },
		timing_breakdown: { xml_parsing_seconds: 2, data_transformation_seconds: 3, database_save_seconds: 4 },
		processing_rates: { xml_parse_objects_per_second: 10, transform_objects_per_second: 20, save_objects_per_second: 30 },
	},
	statistics: {},
}

async function mountSection() {
	store.loading = false
	const wrapper = mount(ArchiMateImportExport, { global: { stubs, mocks: { t: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, k) => (k in vars ? vars[k] : m)) } } })
	await flushPromises()
	return wrapper
}

const button = (wrapper, label) => wrapper.findAll('button').find((b) => b.text() === label)

beforeEach(() => {
	vi.clearAllMocks()
	vi.spyOn(console, 'error').mockImplementation(() => {})
	vi.spyOn(console, 'warn').mockImplementation(() => {})
	global.OC = { requestToken: 'tok', Notification: { showTemporary: vi.fn() } }
	global.fetch = vi.fn(async () => ({ ok: true, blob: async () => new Blob(['x']), headers: { get: () => 'attachment; filename="a.xml"' } }))
})

describe('ArchiMateImportExport mounted', () => {
	it('shows the upload view with a disabled import button until a file is chosen', async () => {
		const wrapper = await mountSection()
		expect(wrapper.find('.file-upload-container').exists()).toBe(true)
		expect(wrapper.find('.import-results-section').exists()).toBe(false)
		expect(button(wrapper, 'Import').attributes('disabled')).toBe('')

		const input = wrapper.find('input[type="file"]')
		const file = new File(['<model/>'], 'diagram.xml')
		Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
		await input.trigger('change')
		expect(wrapper.find('.file-upload-label').text()).toContain('diagram.xml')
		expect(button(wrapper, 'Import').attributes('disabled')).toBeUndefined()
	})

	it('replaces the upload view with the success results and resets back on demand', async () => {
		const wrapper = await mountSection()
		wrapper.vm.importResult = SUCCESS
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.file-upload-container').exists()).toBe(false)
		const success = wrapper.find('.success-results')
		expect(success.exists()).toBe(true)
		expect(success.text()).toContain('model.xml')
		expect(success.text()).toContain('1,200')
		expect(button(wrapper, 'Import')).toBeUndefined()

		await button(wrapper, 'Reset Import').trigger('click')
		expect([wrapper.vm.importResult, wrapper.vm.selectedFile]).toEqual([null, null])
		expect(wrapper.find('.file-upload-container').exists()).toBe(true)
	})

	it('shows an error-results note, flagging a configuration error', async () => {
		const wrapper = await mountSection()
		wrapper.vm.importError = { message: 'AMEF element schema is not configured' }
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.error-results [data-note="error"]').exists()).toBe(true)
		expect(wrapper.vm.isConfigurationError(wrapper.vm.importError.message)).toBe(true)
		expect(wrapper.vm.isConfigurationError('some random failure')).toBeFalsy()
	})

	it('renders the running-import progress block from the progress view', async () => {
		progress.progressView.mockReturnValue({ label: 'Saving objects', percentage: 40, detail: '480 of 1200' })
		const wrapper = await mountSection()
		wrapper.vm.importing = true
		wrapper.vm.importProgress = { processed: 480 }
		await wrapper.vm.$nextTick()
		const block = wrapper.find('.import-progress')
		expect([block.text().includes('Saving objects'), block.text().includes('480 of 1200'), block.find('[data-testid="bar"]').attributes('value')]).toEqual([true, true, '40'])
		expect(button(wrapper, 'Importing…').attributes('disabled')).toBe('')
	})

	it('reveals the include switches only when an organisation is chosen and gates the org export', async () => {
		config.fetchOrganizationOptions.mockResolvedValue([{ label: 'Generic', value: null }, { label: 'Gemeente A', value: 'o1' }])
		const wrapper = await mountSection()
		expect(config.organisationSource).toHaveBeenCalledTimes(1)
		expect(wrapper.find('.checkbox-group').exists()).toBe(false)
		expect(button(wrapper, 'Organization Export').attributes('disabled')).toBe('')

		await wrapper.find('[data-testid="org-select"]').setValue('1')
		expect(wrapper.findAll('.switch')).toHaveLength(3)
		expect(button(wrapper, 'Organization Export').attributes('disabled')).toBeUndefined()
	})

	it('runs a base export through fetch and notifies on success', async () => {
		const wrapper = await mountSection()
		await button(wrapper, 'Export Base').trigger('click')
		await flushPromises()
		expect(global.fetch).toHaveBeenCalledWith('/index.php/apps/stackiq/api/archimate/export', expect.objectContaining({ method: 'POST' }))
		expect(OC.Notification.showTemporary).toHaveBeenCalled()
		expect(wrapper.vm.exporting).toBe(false)
	})
})
