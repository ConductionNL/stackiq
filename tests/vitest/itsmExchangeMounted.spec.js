// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * ItsmExchange as rendered: the flow-engine-missing and set-up banners, the
 * desk and organisation selects, the TOPdesk-only template field, the set-up
 * button's enablement and label, and the refusal note after a failed set-up.
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
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

const notes = (wrapper) => wrapper.findAll('[data-note]').map((n) => [n.attributes('data-note'), n.text()])

beforeEach(() => {
	api.calls = []
	api.config = { desks: DESKS }
	api.setup = null
	vi.clearAllMocks()
	organisations([{ id: 'o1', name: 'Gemeente A' }, { id: 's1', name: 'Vendor', type: 'Supplier' }])
})

describe('ItsmExchange mounted', () => {
	it('renders a fresh set-up with the button disabled until a desk and organisation are chosen', async () => {
		const wrapper = await mountSection()
		expect(wrapper.find('section').attributes('data-loading')).toBe('false')
		expect(notes(wrapper)).toEqual([])
		expect(wrapper.find('[data-label="Service desk"]').attributes('data-options')).toBe('2')
		expect(wrapper.find('[data-label="Your organisation"]').attributes('data-options')).toBe('1')
		expect(wrapper.find('[data-label="TOPdesk asset template id"]').exists()).toBe(false)
		const button = wrapper.find('[data-testid="setup"]')
		expect([button.attributes('disabled'), button.text(), button.find('[data-testid="swap"]').exists()]).toEqual(['', 'Set up the exchange', true])

		await wrapper.find('[data-label="Service desk"]').setValue('1')
		expect(wrapper.find('[data-testid="setup"]').attributes('disabled')).toBe('')
		expect(wrapper.find('[data-label="TOPdesk asset template id"]').exists()).toBe(false)
		await wrapper.find('[data-label="Your organisation"]').setValue('0')
		expect(wrapper.find('[data-testid="setup"]').attributes('disabled')).toBeUndefined()
	})

	it('shows the template field only for TOPdesk and posts its value', async () => {
		api.setup = { flows: { a: 1, b: 2 } }
		const wrapper = await mountSection()
		await wrapper.find('[data-label="Service desk"]').setValue('0')
		await wrapper.find('[data-label="Your organisation"]').setValue('0')
		const template = wrapper.find('[data-label="TOPdesk asset template id"]')
		expect(template.exists()).toBe(true)
		await template.setValue('tpl-9')
		api.config = { desks: DESKS, enabled: true, desk: 'topdesk', setUpAt: '2026-05-01T10:00:00Z' }
		await wrapper.find('[data-testid="setup"]').trigger('click')
		await flushPromises()
		expect(api.calls.slice(1)).toEqual([
			['itsm/setup', { method: 'POST', body: { desk: 'topdesk', organisation: 'o1', templateId: 'tpl-9' } }],
			['itsm/config'],
		])
		expect(dialogs.showSuccess).toHaveBeenCalledWith('2 flows are set up. The first import runs tonight.')
		const date = new Date('2026-05-01T10:00:00Z').toLocaleDateString()
		expect(notes(wrapper)).toEqual([['success', `Set up for TOPdesk on ${date}. The import runs every night at 02:00.`]])
		expect(wrapper.find('[data-testid="setup"]').text()).toBe('Set up again')
	})

	it('renders an existing set-up preselected, and the refusal after a failed set-up', async () => {
		api.config = { desks: DESKS, enabled: true, desk: 'servicenow', organisation: 'o1', setUpAt: '2026-03-02T08:00:00Z' }
		api.setup = new Error('The service desk source is missing in integriq.')
		const wrapper = await mountSection()
		const date = new Date('2026-03-02T08:00:00Z').toLocaleDateString()
		expect(notes(wrapper)).toEqual([['success', `Set up for ServiceNow on ${date}. The import runs every night at 02:00.`]])
		expect(wrapper.find('[data-testid="setup"]').attributes('disabled')).toBeUndefined()
		await wrapper.find('[data-testid="setup"]').trigger('click')
		await flushPromises()
		expect(notes(wrapper)).toEqual([
			['success', `Set up for ServiceNow on ${date}. The import runs every night at 02:00.`],
			['error', 'The service desk source is missing in integriq.'],
		])
		expect(dialogs.showSuccess).not.toHaveBeenCalled()
	})

	it('warns when the flow engine is missing, and when the organisations cannot be loaded', async () => {
		api.config = { desks: DESKS, available: false, enabled: true }
		http.get.mockRejectedValue(new Error('down'))
		const wrapper = await mountSection()
		expect(notes(wrapper)).toEqual([
			['warning', "OpenRegister's flow engine is not available, so the exchange cannot run."],
			['warning', 'The organisations could not be loaded. Reload the page to try again.'],
		])
		expect(wrapper.find('[data-label="Your organisation"]').attributes('data-loading')).toBe('false')
	})

	it('shows the spinner while the set-up is running', async () => {
		const wrapper = await mountSection()
		await wrapper.find('[data-label="Service desk"]').setValue('1')
		await wrapper.find('[data-label="Your organisation"]').setValue('0')
		wrapper.vm.saving = true
		await wrapper.vm.$nextTick()
		const button = wrapper.find('[data-testid="setup"]')
		expect([button.attributes('disabled'), button.find('[data-testid="spinner"]').exists(), button.find('[data-testid="swap"]').exists()]).toEqual(['', true, false])
	})
})
