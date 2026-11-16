// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The download dialog mounted against reactive store doubles: the name it
 * gives the object, the download it starts on mount, the success note and
 * auto-close, the error note on a failed download, and the Cancel / Close
 * button.
 *
 * The template binds the JSON editor to `objectItem.object`, which the
 * component never defines, so the form only renders when the instance can
 * see an `objectItem`; it is provided as a global property here.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton' },
	NcDialog: { name: 'NcDialog' },
	NcNoteCard: { name: 'NcNoteCard' },
}))
vi.mock('vue-codemirror6', () => ({ default: { name: 'CodeMirror', render: () => null } }))
vi.mock('../../src/store/store.js', async () => {
	const { reactive } = await import('vue')
	return {
		navigationStore: reactive({ modal: 'downloadObject' }),
		objectStore: reactive({ objectItem: null }),
	}
})

const { navigationStore, objectStore } = await import('../../src/store/store.js')
const { default: DownloadObject } =
	await import('../../src/modals/object/DownloadObject.vue')

const t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * A stub rendering its slots, with its name and type readable.
 *
 * @param {string} name The component name.
 * @param {string} tag The element.
 * @return {object} The stub.
 */
function stub(name, tag = 'div') {
	return defineComponent({
		name,
		inheritAttrs: false,
		props: ['name', 'type', 'modelValue'],
		emits: ['click'],
		setup(props, { slots, emit }) {
			return () => h(tag, {
				class: ['stub', name],
				'data-name': props.name,
				'data-type': props.type,
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
	NcNoteCard: stub('NcNoteCard'),
	CodeMirror: stub('CodeMirror'),
}

/**
 * Mount the dialog and let the download settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountDialog() {
	const wrapper = mount(DownloadObject, {
		global: { stubs, config: { globalProperties: { t, objectItem: { object: '{"a":1}' } } } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	navigationStore.modal = 'downloadObject'
	navigationStore.setModal = vi.fn()
	objectStore.objectItem = { id: 'obj-1', name: 'Plain name', '@self': { name: 'Self name', title: 'Self title' } }
	objectStore.downloadObject = vi.fn(async () => ({ ok: true }))
})

afterEach(() => {
	vi.useRealTimers()
})

describe('DownloadObject mounted', () => {
	it.each([
		['the @self name', { id: 'x', name: 'N', '@self': { name: 'S', title: 'T' } }, 'Download S'],
		['the plain name', { id: 'x', name: 'N', '@self': { title: 'T' } }, 'Download N'],
		['the @self title', { id: 'x', '@self': { title: 'T' } }, 'Download T'],
		['the id', { id: 'x' }, 'Download x'],
		['a fallback', {}, 'Download Publication'],
	])('names the object by %s', async (label, item, expected) => {
		objectStore.objectItem = item
		objectStore.downloadObject = vi.fn(async () => ({ ok: false }))
		const wrapper = await mountDialog()
		expect(wrapper.find('section.NcDialog').attributes('data-name')).toBe(expected)
	})

	it('downloads on mount, shows success and closes after two seconds', async () => {
		vi.useFakeTimers()
		const wrapper = await mountDialog()
		expect(objectStore.downloadObject).toHaveBeenCalledWith(objectStore.objectItem)
		const note = wrapper.find('.NcNoteCard')
		expect(note.attributes('data-type')).toBe('success')
		expect(note.text()).toBe('Object successfully downloaded')
		expect(wrapper.find('.formContainer').exists()).toBe(false)
		expect(wrapper.find('button.NcButton').text()).toBe('Close')

		vi.advanceTimersByTime(2000)
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
	})

	it('shows the download error and keeps the form with a Cancel button', async () => {
		objectStore.downloadObject = vi.fn(async () => { throw new Error('Network down') })
		const wrapper = await mountDialog()
		const note = wrapper.find('.NcNoteCard')
		expect(note.attributes('data-type')).toBe('error')
		expect(note.text()).toBe('Network down')
		expect(wrapper.find('.json-editor label').text()).toBe('Object (JSON)')
		expect(wrapper.findComponent({ name: 'CodeMirror' }).props('modelValue')).toBe('{"a":1}')
		await wrapper.findComponent({ name: 'CodeMirror' }).vm.$emit('update:modelValue', '{"b":2}')
		expect(wrapper.vm.$.proxy.objectItem.object).toBe('{"b":2}')

		await wrapper.find('button.NcButton').trigger('click')
		expect(wrapper.find('button.NcButton').text()).toBe('Cancel')
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(wrapper.find('.NcNoteCard').exists()).toBe(false)
	})

	it('falls back to a generic error message', async () => {
		objectStore.downloadObject = vi.fn(async () => { throw new Error('') })
		const wrapper = await mountDialog()
		expect(wrapper.find('.NcNoteCard').text()).toBe('An error occurred while downloading the object')
	})

	it('renders nothing for another modal', async () => {
		navigationStore.modal = 'viewObject'
		const wrapper = await mountDialog()
		expect(wrapper.find('section.NcDialog').exists()).toBe(false)
	})
})
