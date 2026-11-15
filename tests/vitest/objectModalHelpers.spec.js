// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The object modal's small helpers and its watchers: pretty-printing the JSON,
 * reading and writing one form field, opening the upload modal, closing (and
 * resetting) the modal, and which watcher re-reads the store or syncs the
 * form and the JSON.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const stores = vi.hoisted(() => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: {},
}))

vi.mock('../../src/store/store.js', () => stores)

const { default: ObjectModal } = await import('../../src/modals/object/ObjectModal.vue')

/**
 * A modal instance: its data, bound methods and computed getters.
 *
 * @param {object} [state] Data to override.
 * @return {object} The instance.
 */
function modal(state = {}) {
	const vm = { objectTypeKey: 'publication', ...ObjectModal.data(), ...state }
	for (const [name, fn] of Object.entries(ObjectModal.methods)) {
		vm[name] = fn.bind(vm)
	}
	for (const [name, getter] of Object.entries(ObjectModal.computed)) {
		Object.defineProperty(vm, name, { get: () => getter.call(vm), configurable: true })
	}
	vm.$nextTick = (callback) => callback()
	return vm
}

beforeEach(() => {
	vi.useFakeTimers()
	stores.navigationStore.setModal = vi.fn()
})

afterEach(() => {
	vi.useRealTimers()
})

describe('formatJSON', () => {
	it('pretty-prints valid JSON with two spaces', () => {
		const vm = modal({ jsonData: '{"title":"Kaart","tags":["a"]}' })

		vm.formatJSON()

		expect(vm.jsonData).toBe('{\n  "title": "Kaart",\n  "tags": [\n    "a"\n  ]\n}')
	})

	it('keeps invalid or empty JSON as it is', () => {
		const vm = modal({ jsonData: '{"title": ' })
		vm.formatJSON()
		expect(vm.jsonData).toBe('{"title": ')

		const empty = modal({ jsonData: '' })
		empty.formatJSON()
		expect(empty.jsonData).toBe('')
	})
})

describe('one form field', () => {
	it('reads a field, and an empty string for a missing one', () => {
		const vm = modal({ formData: { title: 'Kaart', count: 0 } })

		expect(vm.getFieldValue('title')).toBe('Kaart')
		expect(vm.getFieldValue('count')).toBe(0)
		expect(vm.getFieldValue('missing')).toBe('')
	})

	it('writes a changed value and leaves the form untouched for an equal one', () => {
		const formData = { title: 'Kaart' }
		const vm = modal({ formData })

		vm.setFieldValue('summary', 'Een kaart')
		expect(vm.formData).toEqual({ title: 'Kaart', summary: 'Een kaart' })

		const spy = vi.fn()
		vm.formData = new Proxy(formData, { set: (target, key, value) => { spy(key); target[key] = value; return true } })
		vm.setFieldValue('title', 'Kaart')
		expect(spy).not.toHaveBeenCalled()
	})
})

describe('the modal itself', () => {
	it('opens the upload files modal', () => {
		modal().openUploadFilesModal()

		expect(stores.navigationStore.setModal).toHaveBeenCalledWith('uploadFiles')
	})

	it('closes, cancels the auto-close and resets every field and selection', () => {
		const vm = modal({
			success: true,
			loading: true,
			error: 'boom',
			formData: { title: 'Kaart' },
			jsonData: '{}',
			activeTab: 'json',
			selectedCatalogus: { id: 'c1' },
			selectedRegister: { id: 1 },
			selectedSchema: { id: 10 },
			isNewObject: true,
		})
		const closed = vi.fn()
		vm.closeModalTimeout = setTimeout(closed, 2000)

		vm.closeModal()
		vi.advanceTimersByTime(5000)

		expect(stores.navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(closed).not.toHaveBeenCalled()
		expect(vm).toMatchObject({
			success: null,
			loading: false,
			error: null,
			formData: {},
			jsonData: '',
			activeTab: 'form',
			selectedCatalogus: null,
			selectedRegister: null,
			selectedSchema: null,
			isNewObject: false,
		})
	})

	it('reads the store when it is mounted', () => {
		const vm = modal()
		vm.initializeData = vi.fn()

		ObjectModal.mounted.call(vm)

		expect(vm.initializeData).toHaveBeenCalledTimes(1)
	})
})

describe('the watchers', () => {
	it('re-reads the data when the object store changes, not when it is emptied', () => {
		const vm = modal()
		vm.initializeData = vi.fn()

		ObjectModal.watch.objectStore.handler.call(vm, { objectItem: {} })
		ObjectModal.watch.objectStore.handler.call(vm, null)

		expect(vm.initializeData).toHaveBeenCalledTimes(1)
	})

	it('re-reads the data shortly after the object modal opens, and not for another modal', () => {
		const vm = modal()
		vm.initializeData = vi.fn()

		ObjectModal.watch['navigationStore.modal'].handler.call(vm, 'deleteObject')
		vi.advanceTimersByTime(50)
		expect(vm.initializeData).not.toHaveBeenCalled()

		ObjectModal.watch['navigationStore.modal'].handler.call(vm, 'objectModal')
		expect(vm.initializeData).not.toHaveBeenCalled()
		vi.advanceTimersByTime(10)
		expect(vm.initializeData).toHaveBeenCalledTimes(1)
	})

	it('updates the form from valid JSON only on the JSON tab', () => {
		const vm = modal({ activeTab: 'json' })
		vm.updateFormFromJson = vi.fn()

		ObjectModal.watch.jsonData.handler.call(vm, '{"title":"Kaart"}')
		ObjectModal.watch.jsonData.handler.call(vm, '{"title":')
		vm.activeTab = 'form'
		ObjectModal.watch.jsonData.handler.call(vm, '{"title":"Kaart"}')

		expect(vm.updateFormFromJson).toHaveBeenCalledTimes(1)
	})

	it('updates the JSON from the form only on the form tab', () => {
		const vm = modal({ activeTab: 'form' })
		vm.updateJsonFromForm = vi.fn()

		ObjectModal.watch.formData.handler.call(vm, { title: 'Kaart' })
		vm.activeTab = 'json'
		ObjectModal.watch.formData.handler.call(vm, { title: 'Kaart' })

		expect(vm.updateJsonFromForm).toHaveBeenCalledTimes(1)
	})
})
