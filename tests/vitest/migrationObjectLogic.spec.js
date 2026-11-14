// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * MigrationObject's own logic, run against a plain instance: which objects
 * it starts from, the source register and schema it derives, the requests
 * it makes for registers, schemas and properties, the automatic property
 * mapping and its UI round trip, the step navigation, and the migration
 * request with its success and failure reports.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({
	navigationStore: { modal: 'migrate', setModal: vi.fn() },
	objectStore: {
		selectedObjects: [],
		availableRegisters: [],
		availableSchemas: [],
		refreshObjectList: vi.fn(),
	},
}))

vi.mock('../../src/store/store.js', () => store)
vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'token-1' }))

const { default: MigrationObject } = await import('../../src/modals/object/MigrationObject.vue')

/**
 * A plain instance: data, bound methods and computed getters.
 *
 * @param {object} overrides Data to set on top of the defaults.
 * @return {object} The instance.
 */
function instance(overrides = {}) {
	const self = { ...MigrationObject.data.call({}), ...overrides }
	for (const [name, method] of Object.entries(MigrationObject.methods)) {
		self[name] = method.bind(self)
	}
	for (const [name, getter] of Object.entries(MigrationObject.computed)) {
		Object.defineProperty(self, name, { get: () => getter.call(self), configurable: true })
	}
	return self
}

let requests = []

/**
 * Answer fetch calls from a map of url -> body (or an Error to throw).
 *
 * @param {object} answers The answers.
 * @param {number} status The HTTP status of every answer.
 */
function answer(answers, status = 200) {
	vi.stubGlobal('fetch', async (url, init) => {
		requests.push(init === undefined ? [url] : [url, init])
		const body = answers[url]
		if (body instanceof Error) {
			throw body
		}
		return { ok: status < 400, status, json: async () => body }
	})
}

const objects = [
	{ id: 'o1', '@self': { register: 'r1', schema: 's1' } },
	{ id: 'o2', '@self': { register: 'r1', schema: 's1' } },
]

beforeEach(() => {
	requests = []
	store.navigationStore.setModal.mockClear()
	store.objectStore.refreshObjectList.mockClear()
	store.objectStore.selectedObjects = []
	store.objectStore.availableRegisters = []
	store.objectStore.availableSchemas = []
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('starting a migration', () => {
	it('closes at once without selected objects', () => {
		const vm = instance()
		vm.initializeMigration()

		expect(vm.selectedObjects).toEqual([])
		expect(store.navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(requests).toEqual([])
	})

	it('loads the registers for the selected objects', async () => {
		store.objectStore.selectedObjects = objects
		answer({ '/index.php/apps/openregister/api/registers': { results: [{ id: 'r2' }] } })
		const vm = instance()

		vm.initializeMigration()
		await vi.waitFor(() => expect(vm.loading).toBe(false))

		expect(vm.selectedObjects).toBe(objects)
		expect(vm.availableRegisters).toEqual([{ id: 'r2' }])
		expect(requests).toEqual([['/index.php/apps/openregister/api/registers']])
	})

	it('falls back to no registers when the list is missing or the request fails', async () => {
		answer({ '/index.php/apps/openregister/api/registers': {} })
		const vm = instance()
		await vm.loadAvailableRegisters()
		expect(vm.availableRegisters).toEqual([])

		answer({ '/index.php/apps/openregister/api/registers': new Error('offline') })
		vm.availableRegisters = ['stale']
		await vm.loadAvailableRegisters()
		expect(vm.availableRegisters).toEqual([])
		expect(vm.loading).toBe(false)
	})
})

describe('the source register and schema', () => {
	it('are null without objects', () => {
		const vm = instance()
		expect(vm.sourceRegister).toBeNull()
		expect(vm.sourceSchema).toBeNull()
	})

	it('are read from the store, kept as objects, or named after their id', () => {
		store.objectStore.availableRegisters = [{ id: 'r1', title: 'Applications' }]
		store.objectStore.availableSchemas = [{ id: 's9', title: 'Other' }]
		const vm = instance({ selectedObjects: objects })

		expect(vm.sourceRegister).toEqual({ id: 'r1', title: 'Applications' })
		expect(vm.sourceSchema).toEqual({ id: 's1', title: 's1' })

		const embedded = { id: 's1', title: 'Embedded' }
		vm.selectedObjects = [{ id: 'o3', '@self': { register: { id: 'rx' }, schema: embedded } }]
		expect(vm.sourceRegister).toEqual({ id: 'rx' })
		expect(vm.sourceSchema).toBe(embedded)
	})
})

describe('choosing the target', () => {
	it('loads the schemas of the chosen register, and clears them when it is unset', async () => {
		answer({ '/index.php/apps/openregister/api/schemas?register=r2': { results: [{ id: 's2' }] } })
		const vm = instance({ targetRegister: { id: 'r2' } })

		await vm.onRegisterChange()
		expect(vm.availableSchemas).toEqual([{ id: 's2' }])

		vm.targetRegister = null
		vm.targetSchema = { id: 's2' }
		await vm.onRegisterChange()
		expect(vm.availableSchemas).toEqual([])
		expect(vm.targetSchema).toBeNull()
		expect(requests).toHaveLength(1)
	})

	it('empties the schema list when loading fails', async () => {
		answer({ '/index.php/apps/openregister/api/schemas?register=r2': new Error('offline') })
		const vm = instance({ targetRegister: { id: 'r2' }, availableSchemas: ['stale'] })

		await vm.onRegisterChange()

		expect(vm.availableSchemas).toEqual([])
		expect(vm.loading).toBe(false)
	})

	it('maps the properties both schemas share once a target schema is chosen', async () => {
		answer({
			'/index.php/apps/openregister/api/schemas/s2': {
				properties: { title: { type: 'string' }, amount: { type: 'number' }, extra: {} },
				required: ['amount'],
			},
		})
		const source = { id: 's1', properties: { title: {}, amount: { type: 'integer' }, legacy: { type: 'string' } } }
		const vm = instance({ selectedObjects: [{ id: 'o1', '@self': { register: 'r1', schema: source } }], targetSchema: { id: 's2' } })

		await vm.onSchemaChange()

		expect(vm.sourceProperties).toEqual([
			{ name: 'title', type: 'string', required: false },
			{ name: 'amount', type: 'integer', required: false },
			{ name: 'legacy', type: 'string', required: false },
		])
		expect(vm.targetProperties).toEqual([
			{ name: 'title', type: 'string', required: false },
			{ name: 'amount', type: 'number', required: true },
			{ name: 'extra', type: 'string', required: false },
		])
		expect(vm.mapping).toEqual({ title: 'title', amount: 'amount' })
		expect(vm.uiMappings).toEqual({
			title: { label: 'title (string)', value: 'title' },
			amount: { label: 'amount (number)', value: 'amount' },
		})
		expect(vm.targetPropertyOptions.at(-1)).toEqual({ label: '(Do not map)', value: null })
	})

	it('does nothing without a target schema, and keeps going when the schema cannot be read', async () => {
		const vm = instance({ selectedObjects: objects })
		await vm.onSchemaChange()
		expect(vm.sourceProperties).toEqual([])

		answer({ '/index.php/apps/openregister/api/schemas/s2': new Error('offline') })
		vm.targetSchema = { id: 's2' }
		await vm.loadSchemaProperties()
		expect(vm.targetProperties).toEqual([])
		expect(console.error).toHaveBeenCalledWith('Error loading schema properties:', expect.any(Error))
	})
})

describe('the mapping round trip', () => {
	it('turns UI choices into a target-to-source mapping and back', () => {
		const vm = instance({ targetProperties: [{ name: 'title', type: 'string' }, { name: 'name', type: 'string' }] })
		vm.uiMappings = {
			heading: { label: 'title (string)', value: 'title' },
			label: { label: 'name (string)', value: 'name' },
			skipped: { label: '(Do not map)', value: null },
			empty: null,
		}

		vm.updateMappingFromUI('heading')
		expect(vm.mapping).toEqual({ title: 'heading', name: 'label' })

		vm.mapping = { title: 'heading', gone: 'old' }
		vm.convertMappingToUI()
		expect(vm.uiMappings).toEqual({ heading: { label: 'title (string)', value: 'title' } })
	})

	it('can migrate only with a target register, a target schema and one real mapping', () => {
		const vm = instance({ targetRegister: { id: 'r2' }, targetSchema: { id: 's2' }, uiMappings: { a: { value: null } } })
		expect(vm.canMigrate).toBe(false)

		vm.uiMappings = { a: { value: 'title' } }
		expect(vm.canMigrate).toBe(true)

		vm.targetSchema = null
		expect(vm.canMigrate).toBeFalsy()
	})
})

describe('navigating the steps', () => {
	it('advances only when the step is complete and never goes below step 1', () => {
		const vm = instance()
		vm.nextStep()
		expect(vm.step).toBe(1)

		vm.selectedObjects = objects
		vm.nextStep()
		expect(vm.step).toBe(2)
		vm.nextStep()
		expect(vm.step).toBe(2)

		vm.targetRegister = { id: 'r2' }
		vm.targetSchema = { id: 's2' }
		vm.nextStep()
		expect(vm.step).toBe(3)
		vm.nextStep()
		expect(vm.step).toBe(3)

		vm.previousStep()
		vm.previousStep()
		vm.previousStep()
		expect(vm.step).toBe(1)
	})

	it('closes when the last object is removed', () => {
		const vm = instance({ selectedObjects: objects })

		vm.removeObject('o1')
		expect(vm.selectedObjects).toEqual([objects[1]])
		expect(store.navigationStore.setModal).not.toHaveBeenCalled()

		vm.removeObject('o2')
		expect(store.navigationStore.setModal).toHaveBeenCalledWith(false)
	})
})

describe('performing the migration', () => {
	/**
	 * An instance ready to migrate o1 and o2 from r1/s1 to r2/s2.
	 *
	 * @return {object} The instance.
	 */
	function ready() {
		return instance({
			step: 3,
			selectedObjects: objects,
			targetRegister: { id: 'r2' },
			targetSchema: { id: 's2' },
			targetProperties: [{ name: 'title', type: 'string' }],
			uiMappings: { heading: { label: 'title (string)', value: 'title' } },
		})
	}

	it('posts the objects and mapping, reports the result and refreshes the list', async () => {
		answer({ '/index.php/apps/openregister/api/migrate': { success: true, migrated: 2 } })
		const vm = ready()

		await vm.performMigration()

		expect(requests).toEqual([[
			'/index.php/apps/openregister/api/migrate',
			{
				method: 'POST',
				headers: { 'Content-Type': 'application/json', requesttoken: 'token-1' },
				body: JSON.stringify({
					sourceRegister: 'r1',
					sourceSchema: 's1',
					targetRegister: 'r2',
					targetSchema: 's2',
					objects: ['o1', 'o2'],
					mapping: { title: 'heading' },
				}),
			},
		]])
		expect(vm.migrationResult).toEqual({ success: true, migrated: 2 })
		expect(vm.step).toBe(4)
		expect(vm.loading).toBe(false)
		expect(store.objectStore.refreshObjectList).toHaveBeenCalledTimes(1)
	})

	it('reports an HTTP failure as an unsuccessful migration without refreshing', async () => {
		answer({ '/index.php/apps/openregister/api/migrate': {} }, 409)
		const vm = ready()

		await vm.performMigration()

		expect(vm.migrationResult).toEqual({ success: false, errors: ['HTTP error! status: 409'] })
		expect(vm.step).toBe(4)
		expect(store.objectStore.refreshObjectList).not.toHaveBeenCalled()
	})

	it('reports an error without a message with the generic text', async () => {
		answer({ '/index.php/apps/openregister/api/migrate': new Error('') })
		const vm = ready()

		await vm.performMigration()

		expect(vm.migrationResult).toEqual({ success: false, errors: ['An error occurred during migration'] })
	})

	it('sends nothing when it cannot migrate', async () => {
		answer({})
		const vm = ready()
		vm.uiMappings = {}

		await vm.performMigration()

		expect(requests).toEqual([])
		expect(vm.step).toBe(3)
	})
})
