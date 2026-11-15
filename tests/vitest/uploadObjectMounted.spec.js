// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The upload dialog mounted against a store double: the three stages
 * (register, schema, mapping + JSON), the back and edit buttons that step
 * back a stage, the Upload button's guard on an incomplete selection or
 * invalid JSON, the payload it saves, and the success and error states.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const stores = vi.hoisted(() => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: {},
}))

vi.mock('../../src/store/store.js', () => stores)
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton' },
	NcDialog: { name: 'NcDialog' },
	NcLoadingIcon: { name: 'NcLoadingIcon' },
	NcNoteCard: { name: 'NcNoteCard' },
	NcSelect: { name: 'NcSelect' },
}))
vi.mock('vue-codemirror6', () => ({ default: { name: 'CodeMirror', render: () => null } }))

const { default: UploadObject } =
	await import('../../src/modals/object/UploadObject.vue')

/**
 * A stub rendering its slots, with its name and disabled state readable.
 *
 * @param {string} name The component name.
 * @param {string} tag The element.
 * @return {object} The stub.
 */
function stub(name, tag = 'div') {
	return defineComponent({
		name,
		inheritAttrs: false,
		props: ['name', 'disabled', 'type', 'variant', 'inputLabel', 'loading', 'options'],
		emits: ['click'],
		setup(props, { slots, emit }) {
			return () => h(tag, {
				class: ['stub', name],
				'data-label': props.inputLabel,
				'data-type': props.type,
				disabled: props.disabled === true ? '' : undefined,
				onClick: () => emit('click'),
			}, [
				...(slots.icon ? slots.icon() : []),
				...(slots.default ? slots.default() : []),
				...(slots.actions ? slots.actions() : []),
			])
		},
	})
}

const stubs = {
	NcDialog: stub('NcDialog', 'section'),
	NcButton: stub('NcButton', 'button'),
	NcLoadingIcon: stub('NcLoadingIcon', 'span'),
	NcNoteCard: stub('NcNoteCard'),
	NcSelect: stub('NcSelect'),
	CodeMirror: stub('CodeMirror'),
	Cancel: stub('Cancel', 'span'),
	Upload: stub('Upload', 'span'),
	ArrowLeft: stub('ArrowLeft', 'span'),
	AutoFix: stub('AutoFix', 'span'),
}

/**
 * Mount the dialog and let its option lists load.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountDialog() {
	const wrapper = mount(UploadObject, { global: { stubs } })
	await flushPromises()
	return wrapper
}

/**
 * The component instance proxy (with <script setup> the wrapper vm does not
 * write through to the options-API data).
 *
 * @param {object} wrapper The wrapper.
 * @return {object} The instance proxy.
 */
function vm(wrapper) {
	return wrapper.vm.$.proxy
}

/**
 * The button whose text is exactly the given label.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} label The label.
 * @return {object|undefined} The button.
 */
function button(wrapper, label) {
	return wrapper.findAll('button.NcButton').find((b) => b.text() === label)
}

/**
 * The input labels of the selects on screen.
 *
 * @param {object} wrapper The wrapper.
 * @return {Array<string>} The labels.
 */
function selects(wrapper) {
	return wrapper.findAll('.NcSelect').map((s) => s.attributes('data-label'))
}

beforeEach(() => {
	stores.navigationStore.setModal = vi.fn()
	stores.catalogStore.schemaList = [{ id: 's1', title: 'Schema One' }]
	stores.catalogStore.catalogiList = [{ id: 'r1', title: 'Register One' }]
	stores.catalogStore.refreshSchemaList = vi.fn(async () => {})
	stores.catalogStore.refreshCatalogiList = vi.fn(async () => {})
	stores.objectStore.getMappings = vi.fn(async () => ({ data: [{ id: 'm1', name: 'Mapping One' }] }))
	stores.objectStore.saveObject = vi.fn(async () => ({ response: { ok: true } }))
})

afterEach(() => {
	vi.useRealTimers()
})

describe('UploadObject mounted', () => {
	it('loads the register, schema and mapping options on mount', async () => {
		const wrapper = await mountDialog()

		expect(vm(wrapper).registers.options).toEqual([{ id: 'r1', label: 'Register One' }])
		expect(vm(wrapper).schemas.options).toEqual([{ id: 's1', label: 'Schema One' }])
		expect(vm(wrapper).mappings.options).toEqual([{ id: 'm1', label: 'Mapping One' }])
		expect(vm(wrapper).registersLoading).toBe(false)
		expect(vm(wrapper).schemasLoading).toBe(false)
		expect(vm(wrapper).mappingsLoading).toBe(false)
	})

	it('asks for a register, then a schema, then the mapping and object', async () => {
		const wrapper = await mountDialog()
		expect(selects(wrapper)).toEqual(['Register'])
		expect(button(wrapper, 'Upload').attributes('disabled')).toBe('')

		vm(wrapper).registers.value = { id: 'r1', label: 'Register One' }
		await vm(wrapper).$nextTick()
		expect(selects(wrapper)).toEqual(['Schemas'])
		expect(wrapper.text()).toContain('Register: Register One')
		expect(button(wrapper, 'Back to Register')).toBeTruthy()

		vm(wrapper).schemas.value = { id: 's1', label: 'Schema One' }
		await vm(wrapper).$nextTick()
		expect(selects(wrapper)).toEqual(['Mappings'])
		expect(wrapper.text()).toContain('Schema: Schema One')
		expect(button(wrapper, 'Back to Schema')).toBeTruthy()
		expect(button(wrapper, 'Upload').attributes('disabled')).toBeUndefined()
	})

	it('steps back a stage with the back and edit buttons', async () => {
		const wrapper = await mountDialog()
		vm(wrapper).registers.value = { id: 'r1', label: 'Register One' }
		vm(wrapper).schemas.value = { id: 's1', label: 'Schema One' }
		await vm(wrapper).$nextTick()

		await button(wrapper, 'Back to Schema').trigger('click')
		expect(vm(wrapper).schemas.value).toBeNull()
		expect(selects(wrapper)).toEqual(['Schemas'])

		await button(wrapper, 'Back to Register').trigger('click')
		expect(vm(wrapper).registers.value).toBeNull()
		expect(selects(wrapper)).toEqual(['Register'])

		vm(wrapper).registers.value = { id: 'r1', label: 'Register One' }
		vm(wrapper).schemas.value = { id: 's1', label: 'Schema One' }
		await vm(wrapper).$nextTick()
		await button(wrapper, 'Edit Schema').trigger('click')
		expect(vm(wrapper).schemas.value).toBeNull()
		await button(wrapper, 'Edit Register').trigger('click')
		expect(vm(wrapper).registers.value).toBeNull()
	})

	it('keeps Upload disabled for invalid JSON and prettifies valid JSON', async () => {
		const wrapper = await mountDialog()
		vm(wrapper).registers.value = { id: 'r1' }
		vm(wrapper).schemas.value = { id: 's1' }
		vm(wrapper).object = '{ broken'
		await vm(wrapper).$nextTick()
		expect(button(wrapper, 'Upload').attributes('disabled')).toBe('')

		vm(wrapper).object = '{"a":1}'
		await vm(wrapper).$nextTick()
		await button(wrapper, 'Prettify').trigger('click')
		expect(vm(wrapper).object).toBe('{\n  "a": 1\n}')
	})

	it('saves the parsed object with register, schema and mapping, then auto-closes', async () => {
		vi.useFakeTimers()
		const wrapper = await mountDialog()
		vm(wrapper).registers.value = { id: 'r1' }
		vm(wrapper).schemas.value = { id: 's1' }
		vm(wrapper).mappings.value = { id: 'm1' }
		vm(wrapper).object = '{"name":"x"}'
		await vm(wrapper).$nextTick()

		await button(wrapper, 'Upload').trigger('click')
		await flushPromises()

		expect(stores.objectStore.saveObject).toHaveBeenCalledWith({
			object: { name: 'x' }, register: 'r1', schema: 's1', mapping: 'm1', schemas: '',
		})
		expect(vm(wrapper).success).toBe(true)
		expect(vm(wrapper).loading).toBe(false)
		expect(wrapper.find('.NcNoteCard').text()).toBe('Object successfully uploaded')
		expect(button(wrapper, 'Close')).toBeTruthy()
		expect(button(wrapper, 'Upload')).toBeUndefined()

		vi.advanceTimersByTime(2000)
		expect(stores.navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(vm(wrapper).success).toBeNull()
		expect(vm(wrapper).object).toEqual({ json: '{}', url: '' })
	})

	it('saves without a mapping as null', async () => {
		const wrapper = await mountDialog()
		vm(wrapper).registers.value = { id: 'r1' }
		vm(wrapper).schemas.value = { id: 's1' }
		await vm(wrapper).$nextTick()

		await button(wrapper, 'Upload').trigger('click')
		await flushPromises()

		expect(stores.objectStore.saveObject.mock.calls[0][0].mapping).toBeNull()
	})

	it('does not auto-close a response that is not ok', async () => {
		vi.useFakeTimers()
		stores.objectStore.saveObject = vi.fn(async () => ({ response: { ok: false } }))
		const wrapper = await mountDialog()
		vm(wrapper).registers.value = { id: 'r1' }
		vm(wrapper).schemas.value = { id: 's1' }
		await vm(wrapper).$nextTick()

		await button(wrapper, 'Upload').trigger('click')
		await flushPromises()
		vi.advanceTimersByTime(5000)

		expect(vm(wrapper).success).toBe(false)
		expect(stores.navigationStore.setModal).not.toHaveBeenCalled()
	})

	it('shows the error of a failed save, or a default one', async () => {
		stores.objectStore.saveObject = vi.fn(async () => { throw new Error('quota') })
		const wrapper = await mountDialog()
		vm(wrapper).registers.value = { id: 'r1' }
		vm(wrapper).schemas.value = { id: 's1' }
		await vm(wrapper).$nextTick()

		await button(wrapper, 'Upload').trigger('click')
		await flushPromises()
		expect(vm(wrapper).success).toBe(false)
		expect(wrapper.find('.NcNoteCard').text()).toBe('quota')

		stores.objectStore.saveObject = vi.fn(async () => { throw new Error('') })
		await vm(wrapper).uploadObject()
		await flushPromises()
		expect(vm(wrapper).error).toBe('An error occurred while uploading the object')
	})

	it('closes and resets on Cancel', async () => {
		const wrapper = await mountDialog()
		vm(wrapper).error = 'old'
		await vm(wrapper).$nextTick()

		await button(wrapper, 'Cancel').trigger('click')

		expect(stores.navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(vm(wrapper).error).toBe(false)
		expect(vm(wrapper).hasUpdated).toBe(false)
	})
})
