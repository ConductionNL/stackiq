// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * The service desk exchange sends an application only from a chosen lifecycle
 * state on: the select lists the usage lifecycle states, starts at the stored
 * choice (or "In production") and the set-up posts the chosen state.
 *
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-entries-go-to-outside-systems-only-from-a-chosen-lifecycle-state-req-plat-004
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ config: {}, setup: null, calls: [] }))
const http = vi.hoisted(() => ({ get: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('../../src/utils/adminApi.js', () => ({
	apiRequest: async (route, init) => {
		api.calls.push(init === undefined ? [route] : [route, init])
		if (route === 'itsm/setup') {
			if (api.setup instanceof Error) throw api.setup
			return api.setup
		}
		return api.config
	},
}))

const { default: ItsmExchange } = await import('../../src/views/settings/sections/ItsmExchange.vue')

const stubs = {
	AlwaysVisibleSection: {
		props: ['name', 'loading', 'loadingText'],
		template: '<section :data-name="name" :data-loading="String(loading)"><slot /></section>',
	},
	NcNoteCard: { props: ['type'], template: '<div :data-note="type"><slot /></div>' },
	NcSelect: {
		props: ['modelValue', 'options', 'inputLabel', 'loading'],
		emits: ['update:modelValue'],
		template: '<select :data-label="inputLabel" :data-options="options.length" :data-loading="String(!!loading)" @change="$emit(\'update:modelValue\', options[Number($event.target.value)])"><option v-for="(o, i) in options" :key="i" :value="i" /></select>',
	},
	NcTextField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input :data-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template: '<button data-testid="setup" :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcLoadingIcon: { template: '<i data-testid="spinner" />' },
	SwapHorizontal: { template: '<i data-testid="swap" />' },
}

const DESKS = [{ id: 'topdesk', label: 'TOPdesk' }, { id: 'servicenow', label: 'ServiceNow' }]

/**
 * Answer the voorzieningen config and the organisation list.
 *
 * @param {Array<object>} results The organisations the register returns.
 * @return {void}
 */
function organisations(results) {
	http.get.mockImplementation(async (url) => (url.endsWith('/voorzieningen/config')
		? { data: { config: { register: 'vz', organisatie_schema: 'org' } } }
		: { data: { results } }))
}

async function mountSection() {
	const wrapper = mount(ItsmExchange, { global: { stubs } })
	await flushPromises()
	return wrapper
}

const STATES = ['Acquisition', 'Planned', 'In production', 'To be phased out', 'Phased out']

beforeEach(() => {
	api.calls = []
	api.setup = { flows: { a: 1 } }
	vi.clearAllMocks()
	organisations([{ id: 'o1', name: 'Gemeente A' }])
})

describe('the lifecycle state to send from', () => {
	it('lists the lifecycle states and posts the chosen one', async () => {
		api.config = { desks: DESKS, lifecycleStates: STATES, sendFrom: 'In production' }
		const wrapper = await mountSection()
		const select = wrapper.find('[data-label="Send only from lifecycle state"]')
		expect(select.attributes('data-options')).toBe('5')

		await wrapper.find('[data-label="Service desk"]').setValue('1')
		await wrapper.find('[data-label="Your organisation"]').setValue('0')
		await select.setValue('1')
		await wrapper.find('[data-testid="setup"]').trigger('click')
		await flushPromises()

		expect(api.calls[1]).toEqual(['itsm/setup', { method: 'POST', body: { desk: 'servicenow', organisation: 'o1', templateId: '', sendFrom: 'Planned' } }])
	})

	it('starts at the stored choice, and at "In production" without one', async () => {
		api.config = { desks: DESKS, lifecycleStates: STATES, sendFrom: 'To be phased out' }
		expect((await mountSection()).vm.sendFrom).toBe('To be phased out')

		api.config = { desks: DESKS }
		const fresh = await mountSection()
		expect(fresh.vm.sendFrom).toBe('In production')
		expect(fresh.find('[data-label="Send only from lifecycle state"]').attributes('data-options')).toBe('0')
	})
})
