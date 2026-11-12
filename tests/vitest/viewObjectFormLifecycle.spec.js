// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for how the object view fills its form and saves
 * it: the form data and JSON copy of an existing object, the auto-selection
 * of a single catalog, register and schema for a new object, and the save
 * request, the stored result, the messages and the timers for creating,
 * updating and failing. fetch, the stores and the clock are stand-ins.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { getRequestToken } from '@nextcloud/auth'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const stores = vi.hoisted(() => ({
	catalogStore: { fetchPublications: vi.fn() },
	navigationStore: {},
	objectStore: {
		availableSchemas: [{ id: 7, title: 'Seven' }],
		catalogs: [],
		getCollection: vi.fn(),
		setActiveObject: vi.fn(),
	},
}))

vi.mock('../../src/store/store.js', () => stores)

const { default: ViewObject } =
	await import('../../src/modals/object/ViewObject.vue')

const { catalogStore, objectStore } = stores

/**
 * A stand-in for the component with the given state and every method bound
 * to it; $nextTick runs its callback at once.
 *
 * @param {object} state The component state.
 * @return {object} The stand-in.
 */
function viewModel(state) {
	const vm = {
		formData: {},
		jsonData: '',
		error: null,
		success: null,
		isSaving: false,
		closeModal: vi.fn(),
		$nextTick: (fn) => fn(),
		buildCompleteObjectData: () => ({ payload: true }),
		...state,
	}
	for (const [name, fn] of Object.entries(ViewObject.methods)) {
		if (!(name in vm)) {
			vm[name] = fn.bind(vm)
		}
	}
	return vm
}

beforeEach(() => {
	vi.useFakeTimers()
	objectStore.getCollection.mockReset()
	objectStore.setActiveObject.mockReset()
	catalogStore.fetchPublications.mockReset()
})

afterEach(() => {
	vi.useRealTimers()
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('ViewObject initializeData', () => {
	it('copies an existing object into the form, themes as ids', () => {
		const nested = { deep: [1, 2] }
		const vm = viewModel({
			currentObject: {
				id: 'x',
				'@self': { id: 'x' },
				name: 'N',
				nested,
				themes: [{ id: 't1' }, 't2'],
				gone: undefined,
			},
		})
		vm.initializeData()
		expect(vm.formData).toEqual({
			name: 'N',
			nested: { deep: [1, 2] },
			themes: ['t1', 't2'],
		})
		expect(vm.formData.nested).not.toBe(nested)
		expect(vm.jsonData).toBe(
			JSON.stringify(
				{ name: 'N', nested, themes: [{ id: 't1' }, 't2'], gone: undefined },
				null,
				2,
			),
		)
	})

	it('keeps a value JSON cannot copy in the form, then fails on the JSON copy', () => {
		const big = { n: 1n }
		const vm = viewModel({ currentObject: { big } })
		expect(() => vm.initializeData()).toThrow(TypeError)
		expect(vm.formData.big).toBe(big)
		expect(vm.jsonData).toBe('')
	})

	it('auto-selects a single catalog, register and schema for a new object', () => {
		objectStore.getCollection.mockReturnValue({
			results: [{ id: 'c1', title: 'Cat' }],
		})
		const vm = viewModel({
			currentObject: null,
			formData: { stale: 1 },
			registerOptions: [{ id: 'r1' }],
			schemaOptions: [{ id: 's1' }],
		})
		vm.initializeData()
		expect(objectStore.getCollection).toHaveBeenCalledWith('catalog')
		expect(vm.formData).toEqual({})
		expect(vm.jsonData).toBe('{}')
		expect(vm.selectedCatalog).toEqual({ id: 'c1', label: 'Cat' })
		expect(vm.selectedRegister).toEqual({ id: 'r1' })
		expect(vm.selectedSchema).toEqual({ id: 's1' })
	})

	it('stops auto-selecting at the first choice that is not single', () => {
		objectStore.getCollection.mockReturnValue({
			results: [{ id: 'c1', title: 'Cat' }],
		})
		const manyRegisters = viewModel({
			currentObject: null,
			registerOptions: [{ id: 'r1' }, { id: 'r2' }],
			schemaOptions: [{ id: 's1' }],
		})
		manyRegisters.initializeData()
		expect(manyRegisters.selectedCatalog).toEqual({ id: 'c1', label: 'Cat' })
		expect(manyRegisters.selectedRegister).toBeUndefined()
		expect(manyRegisters.selectedSchema).toBeUndefined()

		const manySchemas = viewModel({
			currentObject: null,
			registerOptions: [{ id: 'r1' }],
			schemaOptions: [],
		})
		manySchemas.initializeData()
		expect(manySchemas.selectedRegister).toEqual({ id: 'r1' })
		expect(manySchemas.selectedSchema).toBeUndefined()

		objectStore.getCollection.mockReturnValue({
			results: [{ id: 'c1' }, { id: 'c2' }],
		})
		const manyCatalogs = viewModel({
			currentObject: null,
			registerOptions: [{ id: 'r1' }],
		})
		manyCatalogs.initializeData()
		expect(manyCatalogs.selectedCatalog).toBeUndefined()
		expect(manyCatalogs.selectedRegister).toBeUndefined()
	})
})

describe('ViewObject saveObject', () => {
	const okResponse = (body) => ({ ok: true, json: async () => body })

	it('refuses to create without catalog, register and schema', async () => {
		const fetch = vi.fn()
		vi.stubGlobal('fetch', fetch)
		const vm = viewModel({
			isNewObject: true,
			selectedSchema: { id: 's' },
			selectedRegister: null,
			selectedCatalog: { id: 'c' },
		})
		await vm.saveObject()
		expect(fetch).not.toHaveBeenCalled()
		expect(vm.error).toBe(
			'Please select catalog, register, and schema before saving',
		)
		expect(vm.isSaving).toBe(false)
	})

	it('creates an object and keeps the modal open', async () => {
		const fetch = vi.fn(async () =>
			okResponse({ id: 'new', '@self': { schema: '7' } }),
		)
		vi.stubGlobal('fetch', fetch)
		const vm = viewModel({
			isNewObject: true,
			selectedSchema: { id: 's1' },
			selectedRegister: { id: 'r1' },
			selectedCatalog: { id: 'c1' },
			formData: { a: 1 },
		})
		await vm.saveObject()
		expect(fetch).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/r1/s1',
			{
				method: 'POST',
				headers: { 'Content-Type': 'application/json', requesttoken: getRequestToken() },
				body: '{"payload":true}',
			},
		)
		expect(objectStore.setActiveObject).toHaveBeenCalledWith('publication', {
			id: 'new',
			'@self': { schema: { id: 7, title: 'Seven' } },
		})
		expect(vm.formData).toEqual({})
		expect(vm.success).toBe('Publication created successfully')
		expect(vm.error).toBe(null)
		expect(vm.isSaving).toBe(false)
		expect(catalogStore.fetchPublications).toHaveBeenCalledTimes(1)
		vi.advanceTimersByTime(3000)
		expect(vm.closeModal).not.toHaveBeenCalled()
		expect(vm.success).toBe(null)
	})

	it('updates an object and closes the modal after a second', async () => {
		const fetch = vi.fn(async () =>
			okResponse({ id: 'o', '@self': { schema: 8 } }),
		)
		vi.stubGlobal('fetch', fetch)
		const vm = viewModel({
			isNewObject: false,
			currentObject: {
				id: 'o',
				'@self': { register: { uuid: 'ru' }, schema: 'sx', id: 'self-id' },
			},
		})
		await vm.saveObject()
		expect(fetch.mock.calls[0][0]).toBe(
			'/index.php/apps/openregister/api/objects/ru/sx/self-id',
		)
		expect(fetch.mock.calls[0][1].method).toBe('PUT')
		expect(objectStore.setActiveObject).toHaveBeenCalledWith('publication', {
			id: 'o',
			'@self': { schema: undefined },
		})
		expect(vm.success).toBe('Publication updated successfully')
		vi.advanceTimersByTime(999)
		expect(vm.closeModal).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)
		expect(vm.closeModal).toHaveBeenCalledTimes(1)
		vi.advanceTimersByTime(2000)
		expect(vm.success).toBe(null)
	})

	it('reports a failed response and a failed request', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		vi.stubGlobal(
			'fetch',
			vi.fn(async () => ({
				ok: false,
				status: 500,
				statusText: 'Server Error',
				text: async () => 'boom',
			})),
		)
		const vm = viewModel({
			isNewObject: false,
			currentObject: { id: 'o', '@self': { register: 'r', schema: 's' } },
		})
		await vm.saveObject()
		expect(vm.error).toBe(
			'Failed to update publication: 500 Server Error - boom',
		)
		expect(vm.success).toBe(false)
		expect(vm.isSaving).toBe(false)
		expect(objectStore.setActiveObject).not.toHaveBeenCalled()

		vi.stubGlobal(
			'fetch',
			vi.fn(async () => {
				throw new Error('')
			}),
		)
		const creating = viewModel({
			isNewObject: true,
			selectedSchema: { id: 's' },
			selectedRegister: { id: 'r' },
			selectedCatalog: { id: 'c' },
		})
		await creating.saveObject()
		expect(creating.error).toBe('Failed to save object')
		expect(console.error).toHaveBeenCalledTimes(2)
	})
})
