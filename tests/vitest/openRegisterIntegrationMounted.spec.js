// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * OpenRegisterIntegration as rendered: the not-installed warning, the tab
 * titles' change markers, the register selects on the General tab, the
 * schema rows / empty / no-register states on the Voorzieningen and AMEF
 * tabs, the save-gating, and the save/refresh/mount store wiring.
 *
 * @spec openspec/specs/fe-settings-ui/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({}))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('../../src/store/store.js', () => ({ settingsStore: store }))

const { default: OpenRegisterIntegration } = await import('../../src/views/settings/sections/OpenRegisterIntegration.vue')

const stubs = {
	AlwaysVisibleSection: {
		props: ['loading', 'canSave', 'saving'],
		emits: ['save', 'refresh'],
		template: '<section :data-loading="String(loading)"><button data-testid="save" :disabled="!canSave || saving" @click="$emit(\'save\')" /><button data-testid="refresh" @click="$emit(\'refresh\')" /><slot /></section>',
	},
	NcNoteCard: { props: ['type'], template: '<div :data-note="type"><slot /></div>' },
	NcSelect: {
		props: ['modelValue', 'options', 'inputLabel', 'disabled'],
		template: '<select :data-label="inputLabel" :data-options="options.length" :disabled="disabled"><option v-for="(o, i) in options" :key="i" /></select>',
	},
	StandardTabs: {
		props: ['tabs', 'activeTab'],
		template: '<div data-testid="tabs" :data-titles="tabs.map(t => t.title).join(\'|\')"><slot /></div>',
	},
}

/**
 * Seed the store double.
 *
 * @param {object} extra Fields to merge in.
 * @return {void}
 */
function seed(extra = {}) {
	Object.assign(store, {
		loadingOpenRegisterConfig: false,
		isLoadingRegisters: false,
		isLoadingVoorzieningenSchemas: false,
		isLoadingAmefSchemas: false,
		versionInfo: { openRegisterEnabled: true },
		configuration: {},
		registerOptions: [{ value: 'r1', label: 'Register 1' }, { value: 'r2', label: 'Register 2' }],
		voorzieningenSchemaOptions: [{ value: 's1', label: 'Schema 1' }],
		amefSchemaOptions: [{ value: 'a1', label: 'Element' }],
		voorzieningenSchemas: [],
		amefSchemas: [],
		voorzieningenRegister: null,
		amefRegister: null,
		loadOpenRegisterEssentials: vi.fn(async () => {}),
		saveConfiguration: vi.fn(async () => {}),
		...extra,
	})
}

async function mountSection(extra) {
	seed(extra)
	const wrapper = mount(OpenRegisterIntegration, { global: { stubs } })
	await flushPromises()
	return wrapper
}

const note = (wrapper, text) => wrapper.findAll('[data-note]').find((n) => n.text().includes(text))

beforeEach(() => {
	vi.clearAllMocks()
	vi.spyOn(console, 'error').mockImplementation(() => {})
	for (const key of Object.keys(store)) delete store[key]
})

describe('OpenRegisterIntegration mounted', () => {
	it('warns and hides the tabs when OpenRegister is not installed', async () => {
		const wrapper = await mountSection({ versionInfo: { openRegisterEnabled: false } })
		expect(note(wrapper, 'OpenRegister is not installed')).toBeTruthy()
		expect(wrapper.find('[data-testid="tabs"]').exists()).toBe(false)
		expect(store.loadOpenRegisterEssentials).toHaveBeenCalledTimes(1)
	})

	it('renders the register selects and plain tab titles with no changes', async () => {
		const wrapper = await mountSection()
		expect(wrapper.find('[data-testid="tabs"]').attributes('data-titles')).toBe('General Configuration|Voorzieningen|AMEF')
		expect(wrapper.find('[data-label="Select Voorzieningen Register"]').attributes('data-options')).toBe('2')
		expect(wrapper.find('[data-label="Select AMEF Register"]').attributes('data-options')).toBe('2')
		expect(note(wrapper, 'Please select a Voorzieningen register')).toBeTruthy()
		expect(note(wrapper, 'Please select an AMEF register') || note(wrapper, 'AMEF register')).toBeTruthy()
		expect(wrapper.find('[data-testid="save"]').attributes('disabled')).toBe('')
	})

	it('marks a tab title and renders one schema select per row when a register with schemas is chosen', async () => {
		const configuration = Object.fromEntries(
			OpenRegisterIntegration.computed.voorzieningenItems.call({}).map((item, i) => [item.key, { schema: i === 0 ? { value: 's1' } : {} }]),
		)
		const wrapper = await mountSection({
			voorzieningenRegister: { value: 'r1' },
			voorzieningenSchemas: [{ id: 's1' }],
			configuration,
		})
		expect(wrapper.find('[data-testid="tabs"]').attributes('data-titles')).toBe('General Configuration|Voorzieningen *|AMEF')
		expect(wrapper.find('[data-label="Organisatie Schema"]').exists()).toBe(true)
		expect(wrapper.findAll('.object-type-section')).toHaveLength(13)
		expect(note(wrapper, 'no schemas')).toBeFalsy()
		expect(wrapper.find('[data-testid="save"]').attributes('disabled')).toBeUndefined()
	})

	it('shows the no-schemas warning when the chosen register is empty', async () => {
		const wrapper = await mountSection({ amefRegister: { value: 'r2' }, amefSchemas: [] })
		expect(note(wrapper, 'The selected AMEF register has no schemas')).toBeTruthy()
		expect(wrapper.findAll('[data-label="Element Schema"]')).toHaveLength(0)
	})

	it('saves and refreshes through the store with the right toasts', async () => {
		const wrapper = await mountSection()
		await wrapper.find('[data-testid="refresh"]').trigger('click')
		await flushPromises()
		expect(store.loadOpenRegisterEssentials).toHaveBeenCalledTimes(2)
		expect(dialogs.showSuccess).toHaveBeenLastCalledWith('OpenRegister configuration refreshed successfully')

		store.saveConfiguration.mockRejectedValueOnce(new Error('boom'))
		await wrapper.vm.saveConfiguration()
		expect(dialogs.showError).toHaveBeenLastCalledWith('Failed to save OpenRegister configuration: boom')
		expect(wrapper.vm.saving).toBe(false)
	})

	it('reports a failed essentials load on mount', async () => {
		const wrapper = await mountSection({ loadOpenRegisterEssentials: vi.fn(async () => { throw new Error('down') }) })
		expect(dialogs.showError).toHaveBeenCalledWith('Failed to load OpenRegister configuration: down')
		expect(wrapper.exists()).toBe(true)
	})
})
