// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for how the object modal initialises: the catalogue it
 * selects (the active one, the only one, or none), whether the object is
 * new, the form and JSON of an existing object, and the register and schema
 * it selects, with every store call it makes and the debug line on the
 * development host. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'

const trace = []
const world = { active: {}, catalogs: [], transferData: null }

vi.mock('../../src/store/store.js', () => ({
	catalogStore: {},
	navigationStore: {
		getTransferData: () => {
			trace.push(['getTransferData'])
			return world.transferData
		},
	},
	objectStore: {
		getActiveObject: (type) => {
			trace.push(['getActiveObject', type])
			return world.active[type] ?? null
		},
		setActiveObject: (type, value) => {
			trace.push(['setActiveObject', type, value])
			world.active[type] = value
		},
		getCollection: (type) => {
			trace.push(['getCollection', type])
			return { results: world.catalogs }
		},
		availableRegisters: [
			{ id: 1, title: 'Register one' },
			{ id: 2, title: 'Register two' },
		],
		availableSchemas: [
			{ id: 10, title: 'Schema ten' },
			{ id: 11, title: 'Schema eleven' },
		],
	},
}))

const { default: ObjectModal } =
	await import('../../src/modals/object/ObjectModal.vue')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/objectModalInit.json')

const CATALOGS = {
	none: [],
	'one, single register and schema': [
		{ id: 'c1', title: 'Cat one', registers: [1], schemas: [10] },
	],
	'one, single register, two schemas': [
		{ id: 'c1', title: 'Cat one', registers: [2], schemas: [10, 11] },
	],
	'one, unknown register': [
		{ id: 'c1', title: 'Cat one', registers: [9], schemas: [10] },
	],
	two: [
		{ id: 'c1', title: 'Cat one', registers: [1, 2], schemas: [10] },
		{ id: 'c2', title: 'Cat two', registers: [2], schemas: [11] },
	],
}

const ACTIVE_CATALOGS = { none: null, second: { id: 'c2' }, unknown: { id: 'zz' } }

const OBJECTS = {
	none: null,
	empty: {},
	existing: {
		id: 'o1',
		'@self': { id: 'o1', register: '1', schema: 11 },
		title: 'T',
	},
	'existing, unknown register': { '@self': { id: 'o2', register: 7, schema: 7 } },
	'without ids': { title: 'draft', '@self': {} },
}

const TRANSFERS = {
	none: null,
	create: 'create',
	ignore: 'ignore selectedCatalogus',
	other: 'x',
}

/**
 * The component's methods bound to a plain object.
 *
 * @return {object} The fake instance.
 */
function instance() {
	const vm = {
		objectTypeKey: 'object',
		selectedCatalogus: 'before',
		selectedRegister: 'before',
		selectedSchema: 'before',
		isNewObject: 'before',
		formData: 'before',
		jsonData: 'before',
	}
	for (const [name, fn] of Object.entries(ObjectModal.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

/**
 * Initialise once and record the calls and the state.
 *
 * @param {object} setup Catalogs, active catalog, active object and transfer data.
 * @return {object} The trace and state.
 */
function record(setup) {
	trace.length = 0
	world.catalogs = structuredClone(setup.catalogs)
	world.active = {
		catalog: structuredClone(setup.activeCatalog),
		object: structuredClone(setup.object),
	}
	world.transferData = setup.transferData
	const vm = instance()
	vm.initializeData()
	return {
		trace: structuredClone(trace),
		state: structuredClone({
			selectedCatalogus: vm.selectedCatalogus,
			selectedRegister: vm.selectedRegister ?? 'undefined',
			selectedSchema: vm.selectedSchema ?? 'undefined',
			isNewObject: vm.isNewObject,
			formData: vm.formData,
			jsonData: vm.jsonData,
		}),
	}
}

describe('ObjectModal initialisation golden', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
		vi.restoreAllMocks()
	})

	it('selects the same catalogue, register and schema and loads the same form', () => {
		const out = {}
		for (const [catalogsName, catalogs] of Object.entries(CATALOGS)) {
			for (const [activeName, activeCatalog] of Object.entries(
				ACTIVE_CATALOGS,
			)) {
				for (const [objectName, object] of Object.entries(OBJECTS)) {
					for (const [transferName, transferData] of Object.entries(
						TRANSFERS,
					)) {
						const key = `${catalogsName} | active ${activeName} | object ${objectName} | transfer ${transferName}`
						out[key] = record({
							catalogs,
							activeCatalog,
							object,
							transferData,
						})
					}
				}
			}
		}
		vi.stubGlobal('location', { hostname: 'nextcloud.local' })
		vi.spyOn(console, 'log').mockImplementation((...args) =>
			trace.push(['console.log', ...structuredClone(args)]),
		)
		out['debug host'] = record({
			catalogs: CATALOGS['one, single register and schema'],
			activeCatalog: null,
			object: OBJECTS.existing,
			transferData: null,
		})
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(out, null, '\t') + '\n')
		}
		expect(out).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
