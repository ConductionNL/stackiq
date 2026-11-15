// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The settings view's own actions: which register and schemas it offers and
 * picks, what it saves, and how it loads the configuration and the catalog
 * location.
 *
 * @spec openspec/specs/fe-settings-ui/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/store.js', () => ({
	navigationStore: {},
	objectStore: {},
	settingsStore: {},
}))

const { default: Settings } = await import('../../src/views/settings/Settings.vue')

const REGISTERS = [
	{ id: 1, title: 'Main', schemas: [{ id: 10, title: 'Module' }, { id: 11, title: 'Usage' }] },
	{ id: 2, title: 'Publication register', schemas: [{ id: 20, title: 'module' }] },
	{ id: 3, title: 'Empty', schemas: [] },
]

/**
 * A view instance over its data, computeds and methods, without mounting.
 *
 * @param {object} overrides Data overrides.
 * @return {object} The instance.
 */
function view(overrides = {}) {
	const vm = {
		...Settings.data(),
		...overrides,
	}
	vm.settings = { objectTypes: ['module', 'usage'], availableRegisters: structuredClone(REGISTERS), configuration: {}, ...(overrides.settings ?? {}) }
	for (const [name, fn] of Object.entries(Settings.methods)) {
		vm[name] = fn.bind(vm)
	}
	for (const [name, fn] of Object.entries(Settings.computed)) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm), configurable: true })
	}
	return vm
}

/**
 * A fetch answering JSON, recording its calls.
 *
 * @param {object|Error} body The JSON body, or the error to throw.
 * @param {boolean} ok Whether the response is ok.
 * @return {Function} The mock.
 */
function answer(body, ok = true) {
	return vi.fn(async () => {
		if (body instanceof Error) {
			throw body
		}
		return { ok, json: async () => body }
	})
}

beforeEach(() => {
	globalThis.OC = { requestToken: 'tok' }
	vi.spyOn(console, 'error').mockImplementation(() => {})
	vi.spyOn(console, 'info').mockImplementation(() => {})
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('settings view computeds', () => {
	it('offers every register by title with a string id', () => {
		expect(view().registerOptions).toEqual([
			{ label: 'Main', value: '1' },
			{ label: 'Publication register', value: '2' },
			{ label: 'Empty', value: '3' },
		])
	})

	it('only has schemas for a selected register with a non-empty schema list', () => {
		expect(view().hasSchemas).toBe(false)
		expect(view({ selectedRegister: { value: '1' } }).hasSchemas).toBe(true)
		expect(view({ selectedRegister: { value: '3' } }).hasSchemas).toBe(false)
		expect(view({ selectedRegister: { value: '9' } }).hasSchemas).toBeFalsy()
	})

	it('hides schema options already used by another object type', () => {
		const vm = view({
			schemaOptions: [{ label: 'Module', value: '10' }, { label: 'Usage', value: '11' }],
			configuration: { module: { schema: { value: '10' } }, usage: { schema: null } },
		})

		expect(vm.computedSchemaOptions).toEqual([{ label: 'Usage', value: '11' }])
	})

	it('knows when the catalog location changed', () => {
		const vm = view({ catalogLocation: 'a', originalCatalogLocation: 'a' })
		expect(vm.catalogLocationChanged).toBe(false)
		vm.catalogLocation = 'b'
		expect(vm.catalogLocationChanged).toBe(true)
	})
})

describe('settings view register selection', () => {
	it('prefers the publication register and picks schemas named like the object types', () => {
		const vm = view()

		vm.autoSelectOpenCatalogiRegister()

		expect(vm.selectedRegister).toEqual({ label: 'Publication register', value: '2' })
		expect(vm.schemaOptions).toEqual([{ label: 'module', value: '20' }])
		expect(vm.configuration.module.schema).toEqual({ label: 'module', value: '20' })
		expect(vm.configuration.usage).toBeUndefined()
	})

	it('falls back to the first register, and keeps an earlier selection', () => {
		const first = view({ settings: { availableRegisters: [REGISTERS[0], REGISTERS[2]] } })
		first.autoSelectOpenCatalogiRegister()
		expect(first.selectedRegister).toEqual({ label: 'Main', value: '1' })
		expect(Object.keys(first.configuration)).toEqual(['module', 'usage'])

		const kept = view({ selectedRegister: { label: 'Empty', value: '3' }, settings: { availableRegisters: [REGISTERS[0], REGISTERS[2]] } })
		kept.autoSelectOpenCatalogiRegister()
		expect(kept.selectedRegister).toEqual({ label: 'Empty', value: '3' })
	})

	it('a register without a schema list offers no schema options', () => {
		const vm = view({ settings: { availableRegisters: [{ id: 5, title: 'Bare' }] } })

		vm.updateSchemaOptions('5')
		expect(vm.schemaOptions).toEqual([])
		vm.updateSchemaOptions('missing')
		expect(vm.schemaOptions).toEqual([])
	})

	it('changing the register clears the chosen schemas and picks matching ones again', () => {
		const vm = view({
			selectedRegister: { label: 'Main', value: '1' },
			configuration: { module: { schema: { value: '20' } }, usage: { schema: { value: '20' } } },
		})

		vm.handleRegisterChange()

		expect(vm.schemaOptions.map((o) => o.value)).toEqual(['10', '11'])
		expect(vm.configuration).toEqual({
			module: { schema: { label: 'Module', value: '10' } },
			usage: { schema: { label: 'Usage', value: '11' } },
		})
	})

	it('capitalises an object type title', () => {
		expect(view().formatTitle('module')).toBe('Module')
	})
})

describe('settings view saving and loading', () => {
	it('saves every type as openregister on the selected register, with its schema or empty', async () => {
		const fetchMock = answer({})
		vi.stubGlobal('fetch', fetchMock)
		const vm = view({
			selectedRegister: { label: 'Main', value: '1' },
			configuration: { module: { schema: { value: '10' } }, usage: { schema: null } },
		})

		await vm.saveAll()

		expect(fetchMock).toHaveBeenCalledTimes(1)
		const [url, init] = fetchMock.mock.calls[0]
		expect(url).toBe('/index.php/apps/stackiq/api/settings')
		expect(init.method).toBe('POST')
		expect(init.headers.requesttoken).toBe('tok')
		expect(JSON.parse(init.body)).toEqual({
			module_source: 'openregister',
			module_register: '1',
			module_schema: '10',
			usage_source: 'openregister',
			usage_register: '1',
			usage_schema: '',
		})
		expect(vm.saving).toBe(false)
	})

	it('does not save without a register that has schemas, and clears the busy flag on failure', async () => {
		const fetchMock = answer(new Error('offline'))
		vi.stubGlobal('fetch', fetchMock)

		await view({ selectedRegister: { value: '3' } }).saveAll()
		expect(fetchMock).not.toHaveBeenCalled()

		const vm = view({ selectedRegister: { value: '1' }, configuration: { module: { schema: null } } })
		await vm.saveAll()
		expect(fetchMock).toHaveBeenCalledTimes(1)
		expect(vm.saving).toBe(false)
	})

	it('loads the settings, the catalog location and the registers', async () => {
		vi.stubGlobal('fetch', answer({ objectTypes: ['module'], availableRegisters: structuredClone(REGISTERS), configuration: {}, catalogLocation: 'https://cat' }))
		const vm = view()

		await vm.loadSettings()

		expect(vm.loading).toBe(false)
		expect([vm.catalogLocation, vm.originalCatalogLocation]).toEqual(['https://cat', 'https://cat'])
		expect(vm.selectedRegister).toEqual({ label: 'Publication register', value: '2' })
	})

	it('a failed settings read keeps the page loading', async () => {
		vi.stubGlobal('fetch', answer(new Error('offline')))
		const vm = view()

		await vm.loadSettings()

		expect(vm.loading).toBe(true)
	})

	it('loading the configuration reports an error from the backend or the network', async () => {
		vi.stubGlobal('fetch', answer({ error: 'No file' }))
		const vm = view()
		vm.loadSettings = vi.fn()
		await vm.loadConfiguration()
		expect(vm.configurationResults).toEqual({ error: 'No file' })
		expect(vm.loadSettings).not.toHaveBeenCalled()
		expect(vm.loadingConfiguration).toBe(false)

		vi.stubGlobal('fetch', answer(new Error('offline')))
		await vm.loadConfiguration()
		expect(vm.configurationResults).toEqual({ error: 'Failed to load configuration: offline' })
	})

	it('a loaded configuration reloads the settings', async () => {
		vi.stubGlobal('fetch', answer({}))
		const vm = view()
		vm.loadSettings = vi.fn(async () => {})

		await vm.loadConfiguration()

		expect(vm.configurationResults).toEqual({ success: true })
		expect(vm.loadSettings).toHaveBeenCalledTimes(1)
	})

	it('saves a changed catalog location, and only a successful save becomes the original', async () => {
		const fetchMock = answer({})
		vi.stubGlobal('fetch', fetchMock)
		const vm = view({ catalogLocation: 'a', originalCatalogLocation: 'a' })

		await vm.saveCatalogLocation()
		expect(fetchMock).not.toHaveBeenCalled()

		vm.onCatalogLocationChange('b')
		await vm.saveCatalogLocation()
		expect(fetchMock.mock.calls[0][0]).toBe('/index.php/apps/stackiq/api/settings/catalog-location')
		expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ catalogLocation: 'b' })
		expect(vm.originalCatalogLocation).toBe('b')

		vi.stubGlobal('fetch', answer({}, false))
		vm.onCatalogLocationChange('c')
		await vm.saveCatalogLocation()
		expect(vm.originalCatalogLocation).toBe('b')
		expect(vm.savingCatalogLocation).toBe(false)
	})
})
