// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * OpenRegisterIntegration's two-way bindings: choosing a register writes it to
 * the store and hands it to the store's change handler, choosing a schema
 * writes it into that row's configuration and revalidates, and switching tab
 * shows that tab's panel.
 *
 * @spec openspec/specs/fe-settings-ui/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({}))

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../src/store/store.js', () => ({ settingsStore: store }))

const { default: OpenRegisterIntegration } = await import('../../src/views/settings/sections/OpenRegisterIntegration.vue')

const stubs = {
	AlwaysVisibleSection: { template: '<section><slot /></section>' },
	NcNoteCard: { template: '<div><slot /></div>' },
	// A select that picks its first option when clicked, as a user choosing one would.
	NcSelect: {
		props: ['modelValue', 'options', 'inputLabel'],
		emits: ['update:modelValue'],
		template: '<button :data-label="inputLabel" @click="$emit(\'update:modelValue\', options[0])" />',
	},
	StandardTabs: {
		props: ['tabs', 'activeTab'],
		emits: ['update:activeTab'],
		template: '<div><button v-for="t in tabs" :key="t.key" :data-tab="t.key" @click="$emit(\'update:activeTab\', t.key)" /><slot /></div>',
	},
}

/**
 * Seed the store with one register and one schema option per tab.
 *
 * @param {object} extra Fields to merge in.
 * @return {void}
 */
function seed(extra = {}) {
	const rows = (items) => Object.fromEntries(items.map((item) => [item.key, { schema: null }]))
	Object.assign(store, {
		loadingOpenRegisterConfig: false,
		isLoadingRegisters: false,
		isLoadingVoorzieningenSchemas: false,
		isLoadingAmefSchemas: false,
		versionInfo: { openRegisterEnabled: true },
		configuration: {
			...rows(OpenRegisterIntegration.computed.voorzieningenItems.call({})),
			...rows(OpenRegisterIntegration.computed.amefItems.call({})),
		},
		registerOptions: [{ value: 'r1', label: 'Register 1' }],
		voorzieningenSchemaOptions: [{ value: 's1', label: 'Organisatie' }],
		amefSchemaOptions: [{ value: 'a1', label: 'Element' }],
		voorzieningenSchemas: [{ id: 's1' }],
		amefSchemas: [{ id: 'a1' }],
		voorzieningenRegister: { value: 'r1' },
		amefRegister: { value: 'r1' },
		loadOpenRegisterEssentials: vi.fn(async () => {}),
		saveConfiguration: vi.fn(async () => {}),
		handleVoorzieningenRegisterChange: vi.fn(),
		handleAmefRegisterChange: vi.fn(),
		validateConfiguration: vi.fn(),
		...extra,
	})
}

async function mountSection(extra) {
	seed(extra)
	const wrapper = mount(OpenRegisterIntegration, { global: { stubs } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('OpenRegisterIntegration bindings', () => {
	it('writes a chosen Voorzieningen register to the store and passes it to the store handler', async () => {
		const wrapper = await mountSection({ voorzieningenRegister: null })
		await wrapper.find('[data-label="Select Voorzieningen Register"]').trigger('click')

		expect(store.voorzieningenRegister).toEqual({ value: 'r1', label: 'Register 1' })
		expect(store.handleVoorzieningenRegisterChange).toHaveBeenCalledWith({ value: 'r1', label: 'Register 1' })
		expect(store.handleAmefRegisterChange).not.toHaveBeenCalled()
	})

	it('writes a chosen AMEF register to the store and passes it to the store handler', async () => {
		const wrapper = await mountSection({ amefRegister: null })
		await wrapper.find('[data-label="Select AMEF Register"]').trigger('click')

		expect(store.amefRegister).toEqual({ value: 'r1', label: 'Register 1' })
		expect(store.handleAmefRegisterChange).toHaveBeenCalledWith({ value: 'r1', label: 'Register 1' })
		expect(store.handleVoorzieningenRegisterChange).not.toHaveBeenCalled()
	})

	it('writes a chosen schema into its row and revalidates, on both tabs', async () => {
		const wrapper = await mountSection()
		const [voorziening] = OpenRegisterIntegration.computed.voorzieningenItems.call({})
		const [amef] = OpenRegisterIntegration.computed.amefItems.call({})

		await wrapper.find(`[data-label="${voorziening.title}"]`).trigger('click')
		expect(store.configuration[voorziening.key].schema).toEqual({ value: 's1', label: 'Organisatie' })

		await wrapper.find(`[data-label="${amef.title}"]`).trigger('click')
		expect(store.configuration[amef.key].schema).toEqual({ value: 'a1', label: 'Element' })
		expect(store.validateConfiguration).toHaveBeenCalledTimes(2)
	})

	it('shows the panel of the tab chosen', async () => {
		const wrapper = await mountSection()
		const panels = () => wrapper.findAll('.tab-panel').map((panel) => panel.element.style.display !== 'none')
		expect(panels()).toEqual([true, false, false])

		await wrapper.find('[data-tab="amef"]').trigger('click')
		expect(panels()).toEqual([false, false, true])

		await wrapper.find('[data-tab="voorzieningen"]').trigger('click')
		expect(panels()).toEqual([false, true, false])
	})
})
