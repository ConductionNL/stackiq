// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * The federation switch in the exchange settings (capability-rows task 21):
 * switching on or off sends PUT federation/enabled and shows the status the
 * server answers, and the line under the switch says whether this catalog is
 * registered with the directory, and if not why.
 *
 * @spec openspec/specs/federated-catalog-sync/spec.md#requirement-instance-registers-with-an-opencatalogi-directory
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	federationRegistrationText,
	normaliseFederationStatus,
} from '../../src/utils/adminApi.js'

const api = vi.hoisted(() => ({ status: {}, switched: {}, calls: [], fail: null }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/l10n', () => {
	const fill = (text, vars = {}) =>
		text.replace(/{(\w+)}/g, (match, key) => (key in vars ? vars[key] : match))
	return {
		translate: (app, text, vars) => fill(text, vars),
		translatePlural: (app, one, many, count, vars) =>
			fill(count === 1 ? one : many, vars),
	}
})
vi.mock('../../src/utils/adminApi.js', async (importOriginal) => ({
	...(await importOriginal()),
	apiRequest: async (route, init) => {
		api.calls.push(init === undefined ? [route] : [route, init])
		if (api.fail && init) {
			throw api.fail
		}
		if (route === 'federation/enabled') {
			return api.switched
		}
		return route === 'federation/status' ? api.status : {}
	},
}))

const { default: FederationSettings } =
	await import('../../src/views/settings/sections/FederationSettings.vue')

const stubs = {
	AlwaysVisibleSection: {
		emits: ['refresh'],
		template:
			'<section><div data-testid="header"><slot name="header-actions" /></div><slot /></section>',
	},
	NcNoteCard: {
		props: ['type'],
		template: '<div :data-note="type"><slot /></div>',
	},
	NcEmptyContent: {
		props: ['name'],
		template: '<div data-testid="empty">{{ name }}</div>',
	},
	NcButton: {
		props: ['disabled', 'variant'],
		emits: ['click'],
		template:
			'<button v-bind="$attrs" :data-variant="variant" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: {
		props: ['modelValue', 'disabled'],
		emits: ['update:modelValue'],
		template:
			'<input data-testid="peer-url" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
	NcCheckboxRadioSwitch: {
		inheritAttrs: false,
		props: ['modelValue', 'disabled', 'type'],
		emits: ['update:modelValue'],
		template:
			'<label><input type="checkbox" v-bind="$attrs" :checked="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>',
	},
	NcLoadingIcon: true,
	Sync: true,
	Plus: true,
	Delete: true,
	LanDisconnect: true,
}

async function mountSettings(status) {
	api.status = status
	const wrapper = mount(FederationSettings, { global: { stubs } })
	await flushPromises()
	return wrapper
}

const switchInput = (wrapper) => wrapper.find('[data-testid="federation-switch"]')
/**
 * The registration line under the switch.
 *
 * @param {object} wrapper The mounted section.
 * @return {string} The line.
 */
function registration(wrapper) {
	return wrapper.find('[data-testid="federation-registration"]').text()
}

/**
 * Fill placeholders as the l10n mock does.
 *
 * @param {string} text The text.
 * @param {object} vars The placeholder values.
 * @return {string} The filled text.
 */
function fill(text, vars = {}) {
	return text.replace(/{(\w+)}/g, (match, key) => (key in vars ? vars[key] : match))
}

beforeEach(() => {
	api.calls = []
	api.fail = null
	api.switched = {}
	vi.clearAllMocks()
})

describe('federationRegistrationText', () => {
	const words = (registration) =>
		federationRegistrationText(
			normaliseFederationStatus({ registration }).registration,
			fill,
		)

	it('says when this catalog was registered', () => {
		expect(words({ state: 'registered', at: '2026-11-21T17:05:09+00:00' })).toBe(
			'Registered with the directory on 2026-11-21 17:05 UTC.',
		)
		expect(words({ state: 'registered' })).toBe('Registered with the directory.')
	})

	it('says why it is not registered', () => {
		expect(words({ state: 'failed', reason: 'directory refused' })).toBe(
			'Registration with the directory failed: directory refused',
		)
		expect(words({ state: 'failed' })).toBe(
			'Registration with the directory failed: unknown reason',
		)
		expect(
			words({ state: 'unavailable', reason: 'OpenCatalogi unavailable' }),
		).toBe('Not registered: the OpenCatalogi app is not installed.')
		expect(words({ state: 'off' })).toBe(
			'Not registered: federation is switched off.',
		)
		expect(words({ state: 'none' })).toBe(
			'Not registered with the directory yet.',
		)
	})

	it('reads a missing or unknown registration as never tried', () => {
		expect(normaliseFederationStatus({}).registration).toEqual({
			state: 'none',
			reason: '',
			at: null,
		})
		expect(
			normaliseFederationStatus({
				registration: { state: 'weird', reason: 5, at: '' },
			}).registration,
		).toEqual({ state: 'none', reason: '', at: null })
	})
})

describe('the federation switch', () => {
	it('shows the switch off and the registration line from the status', async () => {
		const wrapper = await mountSettings({
			available: true,
			enabled: false,
			registration: { state: 'off' },
			peers: [],
		})

		expect(switchInput(wrapper).element.checked).toBe(false)
		expect(registration(wrapper)).toBe(
			'Not registered: federation is switched off.',
		)
		expect(wrapper.find('[data-note="warning"]').text()).toContain(
			'Switch it on below',
		)
	})

	it('switches federation on, and shows the registration the server answers', async () => {
		api.switched = {
			available: true,
			enabled: true,
			registration: { state: 'registered', at: '2026-11-21T17:05:00+00:00' },
			peers: [],
		}
		const wrapper = await mountSettings({
			available: true,
			enabled: false,
			peers: [],
		})

		await switchInput(wrapper).setValue(true)
		await flushPromises()

		expect(api.calls).toContainEqual([
			'federation/enabled',
			{ method: 'PUT', body: { enabled: true } },
		])
		expect(switchInput(wrapper).element.checked).toBe(true)
		expect(registration(wrapper)).toBe(
			'Registered with the directory on 2026-11-21 17:05 UTC.',
		)
		expect(wrapper.find('[data-note="warning"]').exists()).toBe(false)
		expect(dialogs.showSuccess).toHaveBeenCalledWith(
			'Federation is on and this catalog is registered',
		)
	})

	it('shows a failed registration with its reason and no success toast', async () => {
		api.switched = {
			available: true,
			enabled: true,
			registration: { state: 'failed', reason: 'directory refused' },
			peers: [],
		}
		const wrapper = await mountSettings({
			available: true,
			enabled: false,
			peers: [],
		})

		await switchInput(wrapper).setValue(true)
		await flushPromises()

		expect(registration(wrapper)).toBe(
			'Registration with the directory failed: directory refused',
		)
		expect(wrapper.find('.federation-registration--failed').exists()).toBe(true)
		expect(dialogs.showSuccess).not.toHaveBeenCalled()
	})

	it('switches federation off', async () => {
		api.switched = {
			available: true,
			enabled: false,
			registration: { state: 'off' },
			peers: [],
		}
		const wrapper = await mountSettings({
			available: true,
			enabled: true,
			registration: { state: 'registered' },
			peers: [],
		})

		await switchInput(wrapper).setValue(false)
		await flushPromises()

		expect(api.calls).toContainEqual([
			'federation/enabled',
			{ method: 'PUT', body: { enabled: false } },
		])
		expect(registration(wrapper)).toBe(
			'Not registered: federation is switched off.',
		)
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Federation is off')
	})

	it('says so when the switch is refused, and keeps the status', async () => {
		api.fail = new Error('Forbidden')
		const wrapper = await mountSettings({
			available: true,
			enabled: false,
			registration: { state: 'off' },
			peers: [],
		})

		await switchInput(wrapper).setValue(true)
		await flushPromises()

		expect(dialogs.showError).toHaveBeenCalledWith(
			'Could not switch federation: Forbidden',
		)
		expect(registration(wrapper)).toBe(
			'Not registered: federation is switched off.',
		)
		expect(switchInput(wrapper).attributes('disabled')).toBeUndefined()
	})
})
