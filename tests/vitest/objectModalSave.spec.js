// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The object modal's option lists (catalogs, the registers of the chosen
 * catalog, the schemas both share), its title, saving (create and update
 * URLs, form or JSON body, refusals, the stored result and refreshes) and the
 * form/JSON synchronisation.
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
 * A modal instance: its data, props, bound methods and computed getters.
 *
 * @param {object} [state] Data and props to override.
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
	return vm
}

let fetchMock

beforeEach(() => {
	vi.useFakeTimers()
	globalThis.OC = { requestToken: 'tok' }
	fetchMock = vi.fn()
	vi.stubGlobal('fetch', fetchMock)
	const catalogs = [
		{ id: 'c1', title: 'Catalogus', registers: [1, 2], schemas: [10, 12] },
		{ id: 'c2', title: 'Leeg', registers: [], schemas: [] },
	]
	Object.assign(stores.objectStore, {
		getCollection: () => ({ results: catalogs }),
		availableRegisters: [
			{ id: 1, title: 'Register een', schemas: [{ id: 10 }, { id: 11 }] },
			{ id: 2, title: 'Register twee', schemas: [] },
			{ id: 3, title: 'Elders', schemas: [] },
		],
		availableSchemas: [
			{ id: 10, title: 'Schema tien', properties: { title: { type: 'string' } } },
			{ id: 11, title: 'Schema elf' },
			{ id: 12, title: 'Schema twaalf' },
		],
		getActiveObject: vi.fn(() => ({ '@self': { id: 'obj-1' } })),
		activeObjects: { other: { id: 'x' } },
		relatedData: {},
		fetchCollection: vi.fn(),
	})
	stores.catalogStore.refreshPublications = vi.fn()
	stores.catalogStore.fetchPublications = vi.fn()
	stores.navigationStore.setModal = vi.fn()
})

afterEach(() => {
	vi.useRealTimers()
	vi.unstubAllGlobals()
	delete globalThis.OC
})

describe('option lists and title', () => {
	it('offers the catalogs, the chosen catalog registers and the schemas both share', () => {
		const vm = modal()

		expect(vm.catalogOptions).toEqual([{ id: 'c1', label: 'Catalogus' }, { id: 'c2', label: 'Leeg' }])
		expect([vm.registerOptions, vm.schemaOptions]).toEqual([[], []])

		vm.selectedCatalogus = { id: 'c1' }
		expect(vm.registerOptions).toEqual([{ id: 1, label: 'Register een' }, { id: 2, label: 'Register twee' }])
		expect(vm.schemaOptions).toEqual([])

		vm.selectedRegister = { id: 1 }
		expect(vm.schemaOptions).toEqual([{ id: 10, label: 'Schema tien' }])

		vm.selectedSchema = { id: 10 }
		expect(vm.schemaProperties).toEqual({ title: { type: 'string' } })
		vm.selectedSchema = { id: 11 }
		expect(vm.schemaProperties).toEqual({})
	})

	it('titles the modal after the object type and mode', () => {
		expect(modal({ isNewObject: true }).dialogTitle).toBe('Add Publication')
		expect(modal({ objectTypeKey: 'module' }).dialogTitle).toBe('Edit Module')
	})
})

describe('saving', () => {
	it('refuses without a register and schema', async () => {
		const vm = modal({ selectedRegister: { id: 1 } })

		await vm.saveObject()

		expect(vm.error).toBe('Register and schema are required')
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it('creates a new publication from the form, stores it and refreshes the publications', async () => {
		fetchMock.mockResolvedValue({ ok: true, json: async () => ({ '@self': { id: 'new-1' }, title: 'Nieuw' }) })
		const vm = modal({ isNewObject: true, selectedRegister: { id: 1 }, selectedSchema: { id: 10 }, formData: { title: 'Nieuw' } })

		await vm.saveObject()

		expect(fetchMock).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/1/10', {
			method: 'POST',
			body: JSON.stringify({ title: 'Nieuw' }),
			headers: { requesttoken: 'tok', 'Content-Type': 'application/json' },
		})
		expect([vm.success, vm.loading, vm.error]).toEqual([true, false, null])
		expect(stores.objectStore.activeObjects).toEqual({
			other: { id: 'x' },
			publication: { '@self': { id: 'new-1' }, title: 'Nieuw', id: 'new-1' },
		})
		expect(stores.objectStore.relatedData.publication).toEqual({ logs: null, uses: null, used: null, files: null })
		expect(stores.catalogStore.refreshPublications).toHaveBeenCalled()
		expect(stores.catalogStore.fetchPublications).toHaveBeenCalled()

		vi.advanceTimersByTime(2000)
		expect(stores.navigationStore.setModal).toHaveBeenCalledWith(false)
	})

	it('updates an existing object from the JSON tab and refetches its collection', async () => {
		fetchMock.mockResolvedValue({ ok: true, json: async () => ({ id: 'obj-1' }) })
		const vm = modal({
			objectTypeKey: 'module',
			selectedRegister: { id: 2 },
			selectedSchema: { id: 12 },
			activeTab: 'json',
			jsonData: '{"name":"Uit JSON"}',
		})

		await vm.saveObject()

		expect(fetchMock.mock.calls[0][0]).toBe('/index.php/apps/openregister/api/objects/2/12/obj-1')
		expect(fetchMock.mock.calls[0][1].method).toBe('PUT')
		expect(fetchMock.mock.calls[0][1].body).toBe('{"name":"Uit JSON"}')
		expect(stores.objectStore.fetchCollection).toHaveBeenCalledWith('module')
		expect(stores.catalogStore.refreshPublications).not.toHaveBeenCalled()
	})

	it('reports empty or invalid JSON without sending it', async () => {
		const vm = modal({ isNewObject: true, selectedRegister: { id: 1 }, selectedSchema: { id: 10 }, activeTab: 'json', jsonData: '  ' })

		await vm.saveObject()
		expect(vm.error).toBe('JSON data cannot be empty')

		vm.jsonData = '{oops'
		await vm.saveObject()
		expect(vm.error).toMatch(/^Invalid JSON format: /)
		expect([vm.success, vm.loading]).toEqual([false, false])
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it('shows the server message of a refused save, or a fallback', async () => {
		const vm = modal({ isNewObject: true, selectedRegister: { id: 1 }, selectedSchema: { id: 10 } })

		fetchMock.mockResolvedValueOnce({ ok: false, json: async () => ({ message: 'Validation failed' }) })
		await vm.saveObject()
		expect([vm.error, vm.success]).toEqual(['Validation failed', false])

		fetchMock.mockResolvedValueOnce({ ok: false, json: async () => { throw new Error('html') } })
		await vm.saveObject()
		expect(vm.error).toBe('Unknown error')

		fetchMock.mockRejectedValueOnce(new Error('offline'))
		await vm.saveObject()
		expect(vm.error).toBe('offline')
	})

	it('cannot update an object without an id', async () => {
		stores.objectStore.getActiveObject.mockReturnValue({})
		const vm = modal({ selectedRegister: { id: 1 }, selectedSchema: { id: 10 } })

		await expect(vm.saveObject()).rejects.toThrow('Cannot update object: no object ID found')
		expect(fetchMock).not.toHaveBeenCalled()
	})
})

describe('form and JSON', () => {
	it('copies valid JSON into the form and reports invalid JSON', () => {
		const vm = modal({ jsonData: '{"a":1}' })

		vm.updateFormFromJson()
		expect(vm.formData).toEqual({ a: 1 })

		vm.jsonData = '{'
		vm.updateFormFromJson()
		expect(vm.formData).toEqual({ a: 1 })
		expect(vm.error).toBe('Invalid JSON format')
	})

	it('writes the form as indented JSON', () => {
		const vm = modal({ formData: { a: 1 } })

		vm.updateJsonFromForm()

		expect(vm.jsonData).toBe('{\n  "a": 1\n}')
	})

	it('syncs through its watchers only from the active tab', () => {
		const vm = modal({ activeTab: 'json', jsonData: '{"b":2}' })
		ObjectModal.watch.jsonData.handler.call(vm, vm.jsonData)
		expect(vm.formData).toEqual({ b: 2 })
		ObjectModal.watch.formData.handler.call(vm, vm.formData)
		expect(vm.jsonData).toBe('{"b":2}')

		vm.activeTab = 'form'
		vm.formData = { c: 3 }
		ObjectModal.watch.formData.handler.call(vm, vm.formData)
		expect(vm.jsonData).toBe('{\n  "c": 3\n}')
	})

	it('clears the pending close when unmounted', () => {
		const vm = modal({ closeModalTimeout: setTimeout(() => stores.navigationStore.setModal(false), 2000) })

		ObjectModal.beforeUnmount.call(vm)
		vi.advanceTimersByTime(3000)

		expect(stores.navigationStore.setModal).not.toHaveBeenCalled()
	})
})
