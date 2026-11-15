// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The migration dialog mounted against reactive store doubles: the selected
 * objects it lists and lets you remove, the source and target cards, the
 * register / schema step with the requests it sends, the property mapping
 * step, the migrate request body, and the report shown after a success or
 * a failed response.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'tok' }))
vi.mock('@nextcloud/vue', () => Object.fromEntries([
	'NcButton', 'NcDialog', 'NcEmptyContent', 'NcLoadingIcon', 'NcNoteCard', 'NcSelect',
].map((name) => [name, { name }])))
vi.mock('../../src/store/store.js', async () => {
	const { reactive } = await import('vue')
	return {
		navigationStore: reactive({}),
		objectStore: reactive({ selectedObjects: [], availableRegisters: [], availableSchemas: [] }),
	}
})

const { navigationStore, objectStore } = await import('../../src/store/store.js')
const { default: MigrationObject } =
	await import('../../src/modals/object/MigrationObject.vue')

const API = '/index.php/apps/openregister/api'
const t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * A stub rendering its slots, with its name, label and disabled state readable.
 *
 * @param {string} name The component name.
 * @param {string} tag The element.
 * @return {object} The stub.
 */
function stub(name, tag = 'div') {
	return defineComponent({
		name,
		inheritAttrs: false,
		props: ['name', 'disabled', 'options', 'modelValue', 'inputLabel', 'ariaLabel'],
		emits: ['click', 'update:modelValue'],
		setup(props, { slots, emit }) {
			return () => h(tag, {
				class: ['stub', name],
				'data-name': props.name,
				'data-label': props.inputLabel,
				'data-aria': props.ariaLabel,
				disabled: props.disabled ? '' : undefined,
				onClick: () => emit('click'),
			}, [
				...(slots.icon ? slots.icon() : []),
				...(slots.default ? slots.default() : []),
				...(slots.description ? slots.description() : []),
				...(slots.actions ? slots.actions() : []),
			])
		},
	})
}

const stubs = Object.fromEntries([
	['NcDialog', 'section'], ['NcButton', 'button'], ['NcEmptyContent', 'div'],
	['NcLoadingIcon', 'span'], ['NcNoteCard', 'div'], ['NcSelect', 'div'],
].map(([name, tag]) => [name, stub(name, tag)]))

const OBJECTS = [
	{ id: 'o1', title: 'One', '@self': { register: 1, schema: 10 } },
	{ id: 'o2', '@self': { name: 'Two', register: 1, schema: 10 } },
]

const REPORT = {
	success: true,
	statistics: { objectsMigrated: 1, objectsFailed: 1, propertiesMapped: 2 },
	details: [
		{ objectId: 'o1', objectTitle: 'One', success: true },
		{ objectId: 'o2', success: false, error: 'Invalid value' },
	],
	warnings: ['colour was discarded'],
	errors: ['partial failure'],
}

let fetchMock
let migrateResponse

/**
 * Mount the dialog and let its register list load.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountDialog() {
	const wrapper = mount(MigrationObject, { global: { stubs, mocks: { t } } })
	await flushPromises()
	return wrapper
}

/**
 * The options-API instance proxy.
 *
 * @param {object} wrapper The wrapper.
 * @return {object} The proxy.
 */
function vm(wrapper) {
	return wrapper.vm.$.proxy
}

/**
 * The footer or row button whose text is exactly the label.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} label The label.
 * @return {object|undefined} The button.
 */
function button(wrapper, label) {
	return wrapper.findAll('button.NcButton').find((b) => b.text() === label)
}

/**
 * The select with the given input label.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} label The label.
 * @return {object} The select component.
 */
function select(wrapper, label) {
	return wrapper.findAllComponents({ name: 'NcSelect' }).find((s) => s.props('inputLabel') === label)
}

/**
 * Walk the dialog to the mapping step.
 *
 * @param {object} wrapper The wrapper.
 * @return {Promise<void>}
 */
async function toMapping(wrapper) {
	await button(wrapper, 'Next').trigger('click')
	await select(wrapper, 'Target register').vm.$emit('update:modelValue', { id: 2, title: 'Target Reg' })
	await flushPromises()
	await select(wrapper, 'Target schema').vm.$emit('update:modelValue', { id: 20, title: 'Target Schema' })
	await flushPromises()
	await button(wrapper, 'Next').trigger('click')
}

beforeEach(() => {
	objectStore.selectedObjects = structuredClone(OBJECTS)
	objectStore.availableRegisters = [{ id: 1, title: 'Src Reg' }]
	objectStore.availableSchemas = [{ id: 10, title: 'Src Schema', properties: { title: { type: 'string' }, colour: {} } }]
	objectStore.refreshObjectList = vi.fn()
	navigationStore.setModal = vi.fn()
	migrateResponse = { ok: true, status: 200, json: async () => REPORT }
	fetchMock = vi.fn(async (url) => {
		if (url === `${API}/registers`) return { json: async () => ({ results: [{ id: 2, title: 'Target Reg' }] }) }
		if (url === `${API}/schemas?register=2`) return { json: async () => ({ results: [{ id: 20, title: 'Target Schema' }] }) }
		if (url === `${API}/schemas/20`) return { json: async () => ({ properties: { title: { type: 'string' }, size: { type: 'integer' } } }) }
		return migrateResponse
	})
	vi.stubGlobal('fetch', fetchMock)
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('MigrationObject mounted', () => {
	it('lists the selected objects with the source and an unset target', async () => {
		const wrapper = await mountDialog()
		expect(wrapper.find('section.NcDialog').attributes('data-name')).toBe('Migrate 2 objects')
		expect(fetchMock).toHaveBeenCalledWith(`${API}/registers`)
		expect(vm(wrapper).availableRegisters).toEqual([{ id: 2, title: 'Target Reg' }])
		expect(wrapper.findAll('.card-value').map((v) => v.text())).toEqual(['Src Reg', 'Src Schema', 'Not selected', 'Not selected'])
		expect(wrapper.findAll('.object-info strong').map((s) => s.text())).toEqual(['One', 'Two'])
		expect(wrapper.findAll('.object-id').map((s) => s.text())).toEqual(['ID: o1', 'ID: o2'])
		expect(button(wrapper, 'Cancel')).toBeTruthy()
	})

	it('removes objects and closes once none are left', async () => {
		const wrapper = await mountDialog()
		const remove = (label) => wrapper.findAll('button.NcButton').find((b) => b.attributes('data-aria') === label)

		await remove('Remove One').trigger('click')
		expect(wrapper.find('section.NcDialog').attributes('data-name')).toBe('Migrate 1 object')
		expect(navigationStore.setModal).not.toHaveBeenCalled()

		await remove('Remove Two').trigger('click')
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).props('name')).toBe('No objects selected')
		expect(button(wrapper, 'Next').attributes('disabled')).toBe('')
	})

	it('closes at once when nothing is selected', async () => {
		objectStore.selectedObjects = []
		await mountDialog()
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it('loads the schemas of the chosen register and the properties of the chosen schema', async () => {
		const wrapper = await mountDialog()
		await button(wrapper, 'Next').trigger('click')
		expect(wrapper.text()).toContain('Select Target Register and Schema')
		expect(select(wrapper, 'Target schema')).toBeUndefined()
		expect(button(wrapper, 'Next').attributes('disabled')).toBe('')

		await select(wrapper, 'Target register').vm.$emit('update:modelValue', { id: 2, title: 'Target Reg' })
		await flushPromises()
		expect(fetchMock).toHaveBeenCalledWith(`${API}/schemas?register=2`)
		expect(select(wrapper, 'Target schema').props('options')).toEqual([{ id: 20, title: 'Target Schema' }])

		await select(wrapper, 'Target schema').vm.$emit('update:modelValue', { id: 20, title: 'Target Schema' })
		await flushPromises()
		expect(fetchMock).toHaveBeenCalledWith(`${API}/schemas/20`)
		expect(wrapper.findAll('.card-value').map((v) => v.text()).slice(2)).toEqual(['Target Reg', 'Target Schema'])

		await button(wrapper, 'Back').trigger('click')
		expect(wrapper.text()).toContain('Confirm Object Selection')
	})

	it('maps same-named properties, lets you remap the rest and steps back', async () => {
		const wrapper = await mountDialog()
		await toMapping(wrapper)
		expect(wrapper.findAll('.property-name').map((s) => s.text())).toEqual(['title', 'colour'])
		expect(wrapper.findAll('.property-type').map((s) => s.text())).toEqual(['string', 'string'])
		expect(select(wrapper, 'Map title to target property').props('modelValue')).toEqual({ label: 'title (string)', value: 'title' })
		expect(select(wrapper, 'Map colour to target property').props('options')).toEqual([
			{ label: 'title (string)', value: 'title' },
			{ label: 'size (integer)', value: 'size' },
			{ label: '(Do not map)', value: null },
		])

		await select(wrapper, 'Map colour to target property').vm.$emit('update:modelValue', { label: 'size (integer)', value: 'size' })
		expect(vm(wrapper).mapping).toEqual({ title: 'title', size: 'colour' })

		await button(wrapper, 'Back').trigger('click')
		expect(vm(wrapper).step).toBe(2)
		await button(wrapper, 'Next').trigger('click')
		expect(vm(wrapper).step).toBe(3)
	})

	it('posts the migration and shows the report', async () => {
		const wrapper = await mountDialog()
		await toMapping(wrapper)
		await button(wrapper, 'Migrate Objects').trigger('click')
		await flushPromises()

		const [url, init] = fetchMock.mock.calls.find(([u]) => u === `${API}/migrate`)
		expect(url).toBe(`${API}/migrate`)
		expect(init.method).toBe('POST')
		expect(init.headers).toEqual({ 'Content-Type': 'application/json', requesttoken: 'tok' })
		expect(JSON.parse(init.body)).toEqual({
			sourceRegister: 1,
			sourceSchema: 10,
			targetRegister: 2,
			targetSchema: 20,
			objects: ['o1', 'o2'],
			mapping: { title: 'title' },
		})
		expect(objectStore.refreshObjectList).toHaveBeenCalledTimes(1)

		const text = wrapper.text()
		expect(text).toContain('Objects successfully migrated!')
		expect(text).toContain('Objects migrated: 1')
		expect(text).toContain('Objects failed: 1')
		expect(text).toContain('Properties mapped: 2')
		expect(text).toContain('Properties discarded: 0')
		expect(wrapper.findAll('.detail-header').map((d) => [d.find('strong').text(), d.find('.status').text(), d.find('.status').classes('success')])).toEqual([
			['One', 'Success', true],
			['o2', 'Failed', false],
		])
		expect(wrapper.find('.detail-error').text()).toBe('Invalid value')
		expect(wrapper.find('.warning-text').text()).toBe('colour was discarded')
		expect(wrapper.find('.error-text').text()).toBe('partial failure')
		expect(wrapper.find('.object-count').text()).toBe('2 objects')

		await button(wrapper, 'Close').trigger('click')
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
	})

	it('reports a failed migration response', async () => {
		migrateResponse = { ok: false, status: 500, json: async () => ({}) }
		const wrapper = await mountDialog()
		await toMapping(wrapper)
		await button(wrapper, 'Migrate Objects').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('Migration failed. Please check the details below.')
		expect(wrapper.find('.error-text').text()).toBe('HTTP error! status: 500')
		expect(objectStore.refreshObjectList).not.toHaveBeenCalled()
		expect(vm(wrapper).loading).toBe(false)
	})

	it('loads no properties without a source schema', async () => {
		const ctx = { sourceSchema: null, targetSchema: { id: 20 } }
		await MigrationObject.methods.loadSchemaProperties.call(ctx)
		expect(fetchMock).not.toHaveBeenCalled()
	})
})
