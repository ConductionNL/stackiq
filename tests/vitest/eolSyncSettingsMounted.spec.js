// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * EolSyncSettings as rendered: the last-run or feed-unavailable banner, the
 * enable switch gating the source and schedule fields, the interval field
 * writing whole minutes back as seconds, and the section's save, refresh
 * and Sync now wiring.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ config: {}, status: {}, calls: [] }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (match, key) => (key in vars ? vars[key] : match)),
}))
vi.mock('../../src/utils/adminApi.js', () => ({
	apiRequest: async (route, init) => {
		api.calls.push(init === undefined ? [route] : [route, init])
		if (route === 'eol-sync/config') {
			return { config: init ? init.body : api.config }
		}
		return { status: api.status }
	},
}))

const { default: EolSyncSettings } = await import('../../src/views/settings/sections/EolSyncSettings.vue')

const stubs = {
	AlwaysVisibleSection: {
		emits: ['save', 'refresh'],
		template: '<section><button data-testid="save" @click="$emit(\'save\')" /><button data-testid="refresh" @click="$emit(\'refresh\')" /><div data-testid="header"><slot name="header-actions" /></div><slot /></section>',
	},
	NcNoteCard: { props: ['type'], template: '<div :data-note="type"><slot /></div>' },
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
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
	NcLoadingIcon: true,
	Sync: true,
}

async function mountSettings() {
	const wrapper = mount(EolSyncSettings, { global: { stubs } })
	await flushPromises()
	return wrapper
}

const field = (wrapper, label) => wrapper.find(`[data-label="${label}"]`)

beforeEach(() => {
	api.calls = []
	api.config = {}
	api.status = {}
	vi.clearAllMocks()
})

describe('EolSyncSettings mounted', () => {
	it('loads the configuration and status, and says why the feed is unavailable', async () => {
		api.status = { available: false, reason: 'openregister-not-installed' }
		const wrapper = await mountSettings()

		expect(api.calls).toEqual([['eol-sync/config'], ['eol-sync/status']])
		expect(wrapper.find('[data-note="warning"]').text()).toBe(
			'Feed unavailable: OpenRegister is not installed. Manual end-of-support entry, the EOL-approaching filter, the roadmap, and the notification rule keep working regardless.',
		)
		expect(wrapper.find('[data-note="success"]').exists()).toBe(false)
	})

	it('reports the last run when the feed is available', async () => {
		api.status = { available: true, matched: 12, skipped: 3, lastRunAt: null }
		const wrapper = await mountSettings()

		expect(wrapper.find('[data-note="success"]').text()).toBe('Last run: 12 matched, 3 skipped, at never.')
	})

	it('disables the source and schedule fields until sync is enabled', async () => {
		api.config = { enabled: false, register: 'eol', productSchema: 'p', cycleSchema: 'c', intervalSeconds: 3600 }
		const wrapper = await mountSettings()
		const labels = ['Register slug', 'eol_product schema slug', 'eol_cycle schema slug', 'Sync interval (minutes)']

		expect(labels.map((l) => field(wrapper, l).attributes('disabled'))).toEqual(['', '', '', ''])
		expect(field(wrapper, 'Register slug').element.value).toBe('eol')
		expect(field(wrapper, 'Sync interval (minutes)').element.value).toBe('60')

		await wrapper.find('[data-testid="enabled"]').setValue(true)

		expect(labels.map((l) => field(wrapper, l).attributes('disabled'))).toEqual([undefined, undefined, undefined, undefined])
	})

	it('saves the edited configuration with the interval in seconds', async () => {
		api.config = { enabled: true, register: 'eol', productSchema: 'p', cycleSchema: 'c', intervalSeconds: 86400 }
		const wrapper = await mountSettings()

		await field(wrapper, 'Register slug').setValue('my-eol')
		await field(wrapper, 'Sync interval (minutes)').setValue('15')
		await field(wrapper, 'Sync interval (minutes)').setValue('0')
		await wrapper.find('[data-testid="save"]').trigger('click')
		await flushPromises()

		expect(api.calls[2]).toEqual([
			'eol-sync/config',
			{
				method: 'POST',
				body: { enabled: true, register: 'my-eol', productSchema: 'p', cycleSchema: 'c', intervalSeconds: 900 },
			},
		])
		expect(dialogs.showSuccess).toHaveBeenCalledWith('EOL sync settings saved')
	})

	it('reloads both on refresh, and syncs from the header button', async () => {
		const wrapper = await mountSettings()

		await wrapper.find('[data-testid="refresh"]').trigger('click')
		await flushPromises()
		api.status = { available: true, matched: 4, skipped: 0 }
		await wrapper.find('[data-testid="header"] button').trigger('click')
		await flushPromises()

		expect(api.calls.slice(2)).toEqual([
			['eol-sync/config'],
			['eol-sync/status'],
			['eol-sync/trigger', { method: 'POST' }],
		])
		expect(wrapper.find('[data-note="success"]').text()).toContain('Last run: 4 matched, 0 skipped')
		expect(wrapper.find('[data-testid="header"] button').attributes('disabled')).toBeUndefined()
	})
})
