// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * UploadObject and DownloadObject, run against plain instances: the select
 * options built from the stores, the object posted on upload, the JSON
 * helpers, the download on mount, and the state each leaves after success,
 * failure and close.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({
	navigationStore: { setModal: vi.fn() },
	objectStore: {
		objectItem: null,
		getMappings: vi.fn(),
		saveObject: vi.fn(),
		downloadObject: vi.fn(),
	},
	catalogStore: {
		schemaList: [],
		catalogiList: [],
		refreshSchemaList: vi.fn(),
		refreshCatalogiList: vi.fn(),
	},
}))

vi.mock('../../src/store/store.js', () => store)

const { default: UploadObject } = await import('../../src/modals/object/UploadObject.vue')
const { default: DownloadObject } = await import('../../src/modals/object/DownloadObject.vue')

/**
 * A plain instance of a component: data, bound methods and a t() that answers its text.
 *
 * @param {object} Component The component.
 * @param {object} overrides Data to set on top of the defaults.
 * @return {object} The instance.
 */
function instance(Component, overrides = {}) {
	const self = { ...Component.data.call({}), t: (app, text) => text, ...overrides }
	for (const [name, method] of Object.entries(Component.methods)) {
		self[name] = method.bind(self)
	}
	return self
}

/**
 * Let pending promise callbacks run.
 *
 * @return {Promise<void>}
 */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0))

beforeEach(() => {
	vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
	for (const fn of [store.navigationStore.setModal, store.objectStore.getMappings, store.objectStore.saveObject, store.objectStore.downloadObject, store.catalogStore.refreshSchemaList, store.catalogStore.refreshCatalogiList]) {
		fn.mockReset()
	}
	store.objectStore.objectItem = null
})

afterEach(() => {
	vi.useRealTimers()
})

describe('UploadObject', () => {
	it('builds the mapping, schema and register options from the stores', async () => {
		store.objectStore.getMappings.mockResolvedValue({ data: [{ id: 'm1', name: 'ZGW' }] })
		store.catalogStore.refreshSchemaList.mockResolvedValue()
		store.catalogStore.refreshCatalogiList.mockResolvedValue()
		store.catalogStore.schemaList = [{ id: 's1', title: 'Application' }]
		store.catalogStore.catalogiList = [{ id: 'r1', title: 'Portfolio' }]
		const vm = instance(UploadObject)

		UploadObject.mounted.call(vm)
		expect([vm.mappingsLoading, vm.schemasLoading, vm.registersLoading]).toEqual([true, true, true])
		vi.useRealTimers()
		await settle()

		const option = (options) => ({ multiple: false, closeOnSelect: true, options, value: null })
		expect(vm.mappings).toEqual(option([{ id: 'm1', label: 'ZGW' }]))
		expect(vm.schemas).toEqual(option([{ id: 's1', label: 'Application' }]))
		expect(vm.registers).toEqual(option([{ id: 'r1', label: 'Portfolio' }]))
		expect([vm.mappingsLoading, vm.schemasLoading, vm.registersLoading]).toEqual([false, false, false])
	})

	it('posts the parsed object with the chosen register, schema and mapping, and closes after two seconds', async () => {
		store.objectStore.saveObject.mockResolvedValue({ response: { ok: true } })
		const vm = instance(UploadObject, {
			object: '{"name":"CRM"}',
			registers: { value: { id: 'r1' } },
			schemas: { value: { id: 's1' } },
			mappings: { value: { id: 'm1' } },
		})

		await vm.uploadObject()
		await vi.waitFor(() => expect(vm.loading).toBe(false))

		expect(store.objectStore.saveObject).toHaveBeenCalledWith({
			object: { name: 'CRM' },
			register: 'r1',
			schema: 's1',
			mapping: 'm1',
			schemas: '',
		})
		expect([vm.success, vm.error]).toEqual([true, false])
		expect(store.navigationStore.setModal).not.toHaveBeenCalled()

		vi.advanceTimersByTime(2000)
		expect(store.navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(vm.object).toEqual({ json: '{}', url: '' })
		expect([vm.success, vm.error, vm.hasUpdated]).toEqual([null, false, false])
	})

	it('posts no mapping when none is chosen, and stays open on a refused upload', async () => {
		store.objectStore.saveObject.mockResolvedValue({ response: { ok: false } })
		const vm = instance(UploadObject, { registers: { value: { id: 'r1' } }, schemas: { value: { id: 's1' } } })

		await vm.uploadObject()
		await vi.waitFor(() => expect(vm.loading).toBe(false))

		expect(store.objectStore.saveObject.mock.calls[0][0].mapping).toBeNull()
		expect(vm.success).toBe(false)
		vi.advanceTimersByTime(2000)
		expect(store.navigationStore.setModal).not.toHaveBeenCalled()
	})

	it('shows the error message of a failed upload, or the generic one', async () => {
		const vm = instance(UploadObject, { registers: { value: { id: 'r1' } }, schemas: { value: { id: 's1' } } })

		store.objectStore.saveObject.mockRejectedValue(new Error('Schema validation failed'))
		await vm.uploadObject()
		await vi.waitFor(() => expect(vm.loading).toBe(false))
		expect([vm.success, vm.error]).toEqual([false, 'Schema validation failed'])

		store.objectStore.saveObject.mockRejectedValue(new Error(''))
		vm.loading = true
		await vm.uploadObject()
		await vi.waitFor(() => expect(vm.loading).toBe(false))
		expect(vm.error).toBe('An error occurred while uploading the object')
	})

	it('prettifies and validates JSON', () => {
		const vm = instance(UploadObject, { object: '{"a":1,"b":[2]}' })

		vm.prettifyJson()

		expect(vm.object).toBe('{\n  "a": 1,\n  "b": [\n    2\n  ]\n}')
		expect(vm.validateJson('{"a":1}')).toBe(true)
		expect(vm.validateJson('{a:1}')).toBe(false)
	})
})

describe('DownloadObject', () => {
	it('downloads on mount only when an object is selected', () => {
		const vm = instance(DownloadObject)
		vm.downloadObject = vi.fn()

		DownloadObject.mounted.call(vm)
		expect(vm.downloadObject).not.toHaveBeenCalled()

		store.objectStore.objectItem = { id: 'o1' }
		DownloadObject.mounted.call(vm)
		expect(vm.downloadObject).toHaveBeenCalledTimes(1)
	})

	it('downloads the selected object and closes two seconds after success', async () => {
		store.objectStore.objectItem = { id: 'o1' }
		store.objectStore.downloadObject.mockResolvedValue({ ok: true })
		const vm = instance(DownloadObject)

		await vm.downloadObject()

		expect(store.objectStore.downloadObject).toHaveBeenCalledWith({ id: 'o1' })
		expect([vm.success, vm.error, vm.loading]).toEqual([true, false, false])
		vi.advanceTimersByTime(1999)
		expect(store.navigationStore.setModal).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)
		expect(store.navigationStore.setModal).toHaveBeenCalledWith(false)
		expect([vm.success, vm.error, vm.loading]).toEqual([null, false, false])
	})

	it('stays open on a refused download and reports a failure', async () => {
		store.objectStore.downloadObject.mockResolvedValue({ ok: false })
		const vm = instance(DownloadObject)

		await vm.downloadObject()
		expect([vm.success, vm.closeModalTimeout]).toEqual([false, null])

		store.objectStore.downloadObject.mockRejectedValue(new Error('Not found'))
		await vm.downloadObject()
		expect([vm.success, vm.error, vm.loading]).toEqual([false, 'Not found', false])

		store.objectStore.downloadObject.mockRejectedValue(new Error(''))
		await vm.downloadObject()
		expect(vm.error).toBe('An error occurred while downloading the object')
	})

	it('cancels the pending auto-close when closed by hand', async () => {
		store.objectStore.downloadObject.mockResolvedValue({ ok: true })
		const vm = instance(DownloadObject)
		await vm.downloadObject()

		vm.closeModal()
		vi.advanceTimersByTime(2000)

		expect(store.navigationStore.setModal).toHaveBeenCalledTimes(1)
	})
})
