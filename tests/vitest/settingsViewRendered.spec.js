// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The settings view, mounted: what it renders while loading, without Open
 * Register and for a register without schemas, and how its register and
 * schema pickers, the catalog location field and both save buttons reach the
 * settings API. fetch is doubled; the pickers are stand-ins that emit the
 * value a test picks.
 *
 * @spec openspec/specs/fe-settings-ui/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/store.js', () => ({
	navigationStore: {},
	objectStore: {},
	settingsStore: {},
}))

const { default: Settings } = await import('../../src/views/settings/Settings.vue')

const REGISTERS = [
	{ id: 1, title: 'Main', schemas: [] },
	{ id: 2, title: 'Publication register', schemas: [{ id: 20, title: 'module' }, { id: 21, title: 'Usage' }] },
]

/** A picker stand-in: it shows its label and emits the value a test picks. */
function Picker (name) {
  return {
	name,
	props: ['modelValue', 'options', 'inputLabel', 'label', 'disabled'],
	emits: ['update:modelValue'],
	template: '<div :class="$options.name"><slot name="icon" /></div>',
}
}

/**
 * A fetch answering each URL from a table, recording its calls.
 *
 * @param {object} settings The GET settings body.
 * @param {object} posts Per-URL POST answers: a response, or an Error to throw.
 * @return {Function} The mock.
 */
function serve(settings, posts = {}) {
	return vi.fn(async (url, options) => {
		if (!options) {
			return { ok: true, json: async () => settings }
		}
		const answer = posts[url] ?? { ok: true }
		if (answer instanceof Error) {
			throw answer
		}
		return { ...answer, json: async () => ({}) }
	})
}

/**
 * Mount the view and let it load.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountIt() {
	const wrapper = mount(Settings, {
		global: {
			mocks: { t: (app, text) => text },
			stubs: { NcSelect: Picker('NcSelect'), NcTextField: Picker('NcTextField') },
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * The view's button with the given text.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} text The button text.
 * @return {object} The button.
 */
function button(wrapper, text) {
	return wrapper.findAll('button').find((b) => b.text().includes(text))
}

beforeEach(() => {
	globalThis.OC = { requestToken: 'tok' }
	vi.spyOn(console, 'error').mockImplementation(() => {})
	vi.spyOn(console, 'info').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
	delete globalThis.fetch
})

describe('Settings view, rendered', () => {
	it('shows only loading icons until the settings arrive, and keeps them when the load fails', async () => {
		globalThis.fetch = vi.fn(async () => { throw new Error('offline') })
		const wrapper = await mountIt()
		expect(wrapper.findAll('.loading-icon')).toHaveLength(2)
		expect(wrapper.find('.register-selection').exists()).toBe(false)
		expect(console.error).toHaveBeenCalledWith('Failed to load settings:', expect.any(Error))
	})

	it('warns when Open Register is missing, and disables the register picker', async () => {
		globalThis.fetch = serve({ openRegisters: false, objectTypes: [], availableRegisters: [], configuration: {} })
		const wrapper = await mountIt()
		expect(wrapper.find('.note-card-stub').text()).toContain('Open Register is not installed.')
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('disabled')).toBe(true)
		expect(button(wrapper, 'Save Configuration').attributes('disabled')).toBeDefined()
	})

	it('warns when the picked register has no schemas', async () => {
		globalThis.fetch = serve({ openRegisters: true, objectTypes: ['module'], availableRegisters: structuredClone(REGISTERS), configuration: {} })
		const wrapper = await mountIt()
		await wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', { label: 'Main', value: '1' })
		await flushPromises()
		expect(wrapper.find('.note-card-stub').text()).toContain('The selected register has no schemas.')
		expect(wrapper.find('.schema-configuration').exists()).toBe(false)
	})

	it('offers a schema picker per object type and saves the picked schemas', async () => {
		const fetchMock = serve({ openRegisters: true, objectTypes: ['module', 'usage'], availableRegisters: structuredClone(REGISTERS), configuration: {} })
		globalThis.fetch = fetchMock
		const wrapper = await mountIt()
		const pickers = wrapper.findAllComponents({ name: 'NcSelect' })
		expect(pickers).toHaveLength(3)
		expect(pickers[0].props('modelValue')).toEqual({ label: 'Publication register', value: '2' })
		expect(pickers[1].props('modelValue')).toEqual({ label: 'module', value: '20' })
		expect(pickers[2].props('modelValue')).toEqual({ label: 'Usage', value: '21' })
		expect(pickers[2].props('options')).toEqual([])

		await pickers[1].vm.$emit('update:modelValue', null)
		expect(wrapper.findAllComponents({ name: 'NcSelect' })[2].props('options')).toEqual([{ label: 'module', value: '20' }])
		await button(wrapper, 'Save Configuration').trigger('click')
		await flushPromises()

		const [url, options] = fetchMock.mock.calls.at(-1)
		expect(url).toBe('/index.php/apps/stackiq/api/settings')
		expect(JSON.parse(options.body)).toEqual({
			module_source: 'openregister', module_register: '2', module_schema: '',
			usage_source: 'openregister', usage_register: '2', usage_schema: '21',
		})
		expect(wrapper.find('.loading-icon-stub').exists()).toBe(false)
	})

	it('saves a changed catalog location, keeping it unsaved when the request throws', async () => {
		const fetchMock = serve(
			{ openRegisters: true, objectTypes: [], availableRegisters: [], configuration: {}, catalogLocation: 'https://old.example' },
			{ '/index.php/apps/stackiq/api/settings/catalog-location': new Error('offline') },
		)
		globalThis.fetch = fetchMock
		const wrapper = await mountIt()
		const field = wrapper.findComponent({ name: 'NcTextField' })
		expect(field.props('modelValue')).toBe('https://old.example')
		expect(field.find('svg').exists()).toBe(true)
		expect(button(wrapper, 'Save Catalog Location').attributes('disabled')).toBeDefined()

		await field.vm.$emit('update:modelValue', 'https://new.example')
		const save = button(wrapper, 'Save Catalog Location')
		expect(save.attributes('disabled')).toBeUndefined()
		await save.trigger('click')
		await flushPromises()

		const [url, options] = fetchMock.mock.calls.at(-1)
		expect(url).toBe('/index.php/apps/stackiq/api/settings/catalog-location')
		expect(JSON.parse(options.body)).toEqual({ catalogLocation: 'https://new.example' })
		expect(console.error).toHaveBeenCalledWith('Failed to save catalog location:', expect.any(Error))
		expect(wrapper.vm.catalogLocationChanged).toBe(true)
		expect(wrapper.vm.savingCatalogLocation).toBe(false)
	})
})
