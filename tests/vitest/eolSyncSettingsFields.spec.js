// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * EolSyncSettings' source fields: what the admin types into the enable switch
 * and the register, product and cycle schema fields is what Save sends, and
 * Sync now shows a spinner instead of its icon while the sync runs.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ calls: [], release: null }))

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (match, key) => (key in vars ? vars[key] : match)),
}))
vi.mock('../../src/utils/adminApi.js', () => ({
	apiRequest: async (route, init) => {
		api.calls.push(init === undefined ? [route] : [route, JSON.parse(JSON.stringify(init))])
		if (route === 'eol-sync/trigger') {
			await new Promise((resolve) => { api.release = resolve })
			return { status: { available: true, matched: 0, skipped: 0 } }
		}
		if (route === 'eol-sync/config') {
			return { config: init ? init.body : {} }
		}
		return { status: {} }
	},
}))

const { default: EolSyncSettings } = await import('../../src/views/settings/sections/EolSyncSettings.vue')

const stubs = {
	AlwaysVisibleSection: {
		emits: ['save'],
		template: '<section><button data-testid="save" @click="$emit(\'save\')" /><slot name="header-actions" /><slot /></section>',
	},
	NcNoteCard: { template: '<div><slot /></div>' },
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template: '<button data-testid="sync" :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue', 'type'],
		emits: ['update:modelValue'],
		template: '<input type="checkbox" data-testid="enabled" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)">',
	},
	NcTextField: {
		props: ['modelValue', 'label', 'disabled'],
		emits: ['update:modelValue'],
		template: '<input :data-label="label" :disabled="disabled" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
	NcLoadingIcon: { template: '<i data-testid="spinner" />' },
	Sync: { template: '<i data-testid="sync-icon" />' },
}

beforeEach(() => {
	api.calls = []
	api.release = null
	vi.clearAllMocks()
})

describe('EolSyncSettings source fields', () => {
	it('sends what the admin typed into the switch and the three source fields', async () => {
		const wrapper = mount(EolSyncSettings, { global: { stubs } })
		await flushPromises()

		await wrapper.find('[data-testid="enabled"]').setValue(true)
		await wrapper.find('[data-label="Register slug"]').setValue('eol')
		await wrapper.find('[data-label="eol_product schema slug"]').setValue('product')
		await wrapper.find('[data-label="eol_cycle schema slug"]').setValue('cycle')
		await wrapper.find('[data-testid="save"]').trigger('click')
		await flushPromises()

		const [, init] = api.calls.find(([route, request]) => route === 'eol-sync/config' && request)
		expect(init.method).toBe('POST')
		expect(init.body).toMatchObject({ enabled: true, register: 'eol', productSchema: 'product', cycleSchema: 'cycle' })
	})

	it('shows a spinner on Sync now while the sync runs, then its icon again', async () => {
		const wrapper = mount(EolSyncSettings, { global: { stubs } })
		await flushPromises()
		expect(wrapper.find('[data-testid="sync-icon"]').exists()).toBe(true)

		await wrapper.find('[data-testid="sync"]').trigger('click')
		await flushPromises()
		expect(wrapper.find('[data-testid="spinner"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="sync"]').attributes('disabled')).toBe('')

		api.release()
		await flushPromises()
		expect(wrapper.find('[data-testid="spinner"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="sync-icon"]').exists()).toBe(true)
	})
})
