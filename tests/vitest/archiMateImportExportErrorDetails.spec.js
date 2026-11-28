// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * ArchiMateImportExport as rendered, the parts beyond the basic views: the
 * error-summary button opening the detailed-errors panel (most common issues,
 * errors by section with examples) and closing it again, the configuration
 * error's missing-items list, the cancelled-import note, the cancel button of a
 * running import, the organisation export's include switches and the time
 * formatting of the performance metrics.
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
	// The round-trip check has its own spec (archiMateRoundTripCheck.spec.js).
	ArchiMateRoundTripCheck: true,
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

const DETAILED = {
	total_count: 7,
	omschrijving: [
		{ type: 'validation', message: 'Name is required', total_count: 4, affected_sections: ['elements', 'relations'] },
		{ type: 'custom_check', message: 'Odd value', total_count: 1, affected_sections: [] },
	],
	by_section: {
		elements: {
			section_name: 'Elements',
			total_errors: 5,
			error_groups: [
				{ type: 'data_type', count: 3, message: 'Wrong type', examples: ['e1', 'e2'] },
				{ type: 'reference', count: 2, message: 'Dangling reference', examples: [] },
			],
		},
		relations: { section_name: 'Relations', total_errors: 2, error_groups: [] },
	},
}

function withErrors(totalErrors, detailed = DETAILED) {
	return {
		...SUCCESS,
		statistics: {
			omschrijving: { total_objects_created: 1, total_objects_updated: 2, total_objects_unchanged: 3, total_errors: totalErrors },
		},
		detailed_errors: detailed,
	}
}

describe('ArchiMateImportExport error details and controls', () => {
	it('opens the detailed-errors panel from the error summary and closes it again', async () => {
		const wrapper = await mountSection()
		wrapper.vm.importResult = withErrors(7)
		await wrapper.vm.$nextTick()

		const summary = wrapper.find('button.summary-item.errors')
		expect([summary.attributes('disabled'), summary.classes('clickable'), summary.find('.click-hint').exists()]).toEqual([undefined, true, true])
		expect(wrapper.find('.error-details-modal').exists()).toBe(false)

		await summary.trigger('click')
		const panel = wrapper.find('.error-details-modal')
		expect(panel.exists()).toBe(true)
		expect(panel.findAll('.stat-number').map((n) => n.text())).toEqual(['7', '2'])

		const common = panel.findAll('.common-error-item')
		expect(common.map((c) => c.find('.error-type-badge').text())).toEqual(['Validation', 'Custom_check'])
		expect(common[0].find('.affected-sections').text()).toBe('in elements, relations')
		expect(common[1].find('.affected-sections').exists()).toBe(false)
		expect(common[0].find('.error-count').text()).toBe('4 occurrences')

		const sections = panel.findAll('.section-error-group')
		expect(sections.map((s) => s.find('h6').text())).toEqual(['Elements', 'Relations'])
		expect(sections[0].find('.section-error-count').text()).toBe('5 errors')
		const groups = sections[0].findAll('.error-group')
		expect(groups.map((g) => g.find('.error-type-badge').text())).toEqual(['Data Type', 'Reference'])
		expect(groups[0].find('.examples-list').text()).toBe('e1, e2')
		expect(groups[1].find('.error-examples').exists()).toBe(false)

		await panel.find('.error-details-header button').trigger('click')
		expect([wrapper.vm.showErrors, wrapper.find('.error-details-modal').exists()]).toEqual([false, false])
	})

	it('keeps the error summary disabled and the panel closed without errors', async () => {
		const wrapper = await mountSection()
		wrapper.vm.importResult = withErrors(0)
		await wrapper.vm.$nextTick()
		const summary = wrapper.find('button.summary-item.errors')
		expect([summary.attributes('disabled'), summary.find('.click-hint').exists()]).toEqual(['', false])
		wrapper.vm.showErrorDetails()
		await wrapper.vm.$nextTick()
		expect([wrapper.vm.showErrors, wrapper.find('.error-details-modal').exists()]).toEqual([false, false])
	})

	it('leaves out the most-common-issues block when the list is empty', async () => {
		const wrapper = await mountSection()
		wrapper.vm.importResult = withErrors(2, { ...DETAILED, omschrijving: [] })
		await wrapper.vm.$nextTick()
		await wrapper.find('button.summary-item.errors').trigger('click')
		expect(wrapper.find('.error-details-modal').exists()).toBe(true)
		expect(wrapper.find('.common-errors-section').exists()).toBe(false)
		expect(wrapper.findAll('.section-error-group')).toHaveLength(2)
	})

	it('lists the missing configuration items of a configuration error', async () => {
		config.missingConfigItems.mockReturnValue(['AMEF element schema', 'AMEF relation schema'])
		const wrapper = await mountSection()
		wrapper.vm.importError = { message: 'AMEF element schema is not configured' }
		await wrapper.vm.$nextTick()
		expect(wrapper.findAll('.missing-items li').map((li) => li.text())).toEqual(['AMEF element schema', 'AMEF relation schema'])
		expect(config.missingConfigItems).toHaveBeenCalledWith('AMEF element schema is not configured')
	})

	it('shows how many objects were saved before a cancelled import stopped', async () => {
		const wrapper = await mountSection()
		expect(wrapper.find('[data-note="warning"]').exists()).toBe(false)
		wrapper.vm.importCancelled = { objects_saved: 42 }
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-note="warning"]').text()).toBe('Import cancelled. 42 objects were saved before it stopped.')
		wrapper.vm.importCancelled = {}
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-note="warning"]').text()).toBe('Import cancelled. 0 objects were saved before it stopped.')
	})

	it('cancels a running import through its operation id, and re-enables cancel when that fails', async () => {
		const wrapper = await mountSection()
		expect(button(wrapper, 'Cancel import')).toBeUndefined()
		wrapper.vm.importing = true
		wrapper.vm.operationId = 'op-9'
		await wrapper.vm.$nextTick()

		await button(wrapper, 'Cancel import').trigger('click')
		await flushPromises()
		expect(progress.cancelImport).toHaveBeenCalledWith(expect.objectContaining({ operationId: 'op-9' }))
		expect(wrapper.vm.cancelling).toBe(true)
		expect(button(wrapper, 'Cancel import').attributes('disabled')).toBe('')

		progress.cancelImport.mockRejectedValueOnce(new Error('offline'))
		wrapper.vm.cancelling = false
		await wrapper.vm.$nextTick()
		await button(wrapper, 'Cancel import').trigger('click')
		await flushPromises()
		expect(wrapper.vm.cancelling).toBe(false)
	})

	it('binds the include switches of the organisation export', async () => {
		config.fetchOrganizationOptions.mockResolvedValue([{ label: 'Generic', value: null }, { label: 'Gemeente A', value: 'o1' }])
		const wrapper = await mountSection()
		await wrapper.find('[data-testid="org-select"]').setValue('1')
		const switches = wrapper.findAll('.switch input')
		expect(switches.map((s) => s.element.checked)).toEqual([wrapper.vm.includeModules, wrapper.vm.includeDeelnames, wrapper.vm.includeGebruik, wrapper.vm.includeConnections])

		for (const s of switches) {
			await s.setValue(false)
		}
		expect([wrapper.vm.includeModules, wrapper.vm.includeDeelnames, wrapper.vm.includeGebruik, wrapper.vm.includeConnections]).toEqual([false, false, false, false])
		await switches[1].setValue(true)
		expect([wrapper.vm.includeModules, wrapper.vm.includeDeelnames, wrapper.vm.includeGebruik, wrapper.vm.includeConnections]).toEqual([false, true, false, false])
		await switches[3].setValue(true)
		expect([wrapper.vm.includeModules, wrapper.vm.includeDeelnames, wrapper.vm.includeGebruik, wrapper.vm.includeConnections]).toEqual([false, true, false, true])
	})

	it('formats durations as milliseconds, seconds or minutes', async () => {
		const wrapper = await mountSection()
		expect([wrapper.vm.formatTime(0.25), wrapper.vm.formatTime(12.34), wrapper.vm.formatTime(125)]).toEqual(['250ms', '12.3s', '2m 5s'])
	})
})
