// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the object view's derived state: which property rows are
 * shown when constant/immutable ones are hidden, the files pagination
 * figures, the catalog -> register -> schema option cascade (only schemas
 * both the register and the catalog carry), and the theme picker mapping.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({
	collections: {},
	pagination: {},
	availableRegisters: [],
	availableSchemas: [],
}))

vi.mock('../../src/store/store.js', () => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: {
		get availableRegisters() {
			return store.availableRegisters
		},
		get availableSchemas() {
			return store.availableSchemas
		},
		getCollection: (type) => store.collections[type],
		getPagination: (key) => store.pagination[key],
	},
}))

const { default: ViewObject } =
	await import('../../src/modals/object/ViewObject.vue')

const computed = ViewObject.computed

beforeEach(() => {
	store.collections = {
		catalog: {
			results: [
				{ id: 'c1', title: 'Catalog One', registers: ['r1', 'r3'], schemas: ['s1', 's3'] },
				{ id: 'c2', title: 'Catalog Two' },
			],
		},
		theme: { results: [{ id: 't1', title: 'Theme One' }, { id: 't2' }] },
	}
	store.availableRegisters = [
		{ id: 'r1', title: 'Register One', schemas: [{ id: 's1' }, { id: 's2' }, { id: 's3' }] },
		{ id: 'r2', title: 'Register Two', schemas: [{ id: 's1' }] },
		{ id: 'r3', title: 'Register Three' },
	]
	store.availableSchemas = [
		{ id: 's1', title: 'Schema One' },
		{ id: 's2', title: 'Schema Two' },
		{ id: 's3', title: 'Schema Three' },
	]
	store.pagination = {
		publication_files: { pages: 4, total: 37, limit: 10 },
	}
})

describe('ViewObject property row filtering', () => {
	const rows = [['name', 'x'], ['code', 'C'], ['created', 'now']]
	const isConstantOrImmutable = (key) => key === 'code' || key === 'created'

	it('shows every row when constant properties are shown', () => {
		const ctx = { showConstantProperties: true, objectProperties: rows, isConstantOrImmutable }
		expect(computed.filteredObjectProperties.call(ctx)).toBe(rows)
	})

	it('drops constant and immutable rows otherwise', () => {
		const ctx = { showConstantProperties: false, objectProperties: rows, isConstantOrImmutable }
		expect(computed.filteredObjectProperties.call(ctx)).toEqual([['name', 'x']])
	})

	it('reports whether any row is constant or immutable', () => {
		expect(computed.hasConstantOrImmutableProperties.call({
			objectProperties: rows, isConstantOrImmutable,
		})).toBe(true)
		expect(computed.hasConstantOrImmutableProperties.call({
			objectProperties: [['name', 'x']], isConstantOrImmutable,
		})).toBe(false)
	})
})

describe('ViewObject files pagination', () => {
	it('reads pages, total and page size from the files pagination', () => {
		expect(computed.filesTotalPages.call({})).toBe(4)
		expect(computed.filesTotalItems.call({})).toBe(37)
		expect(computed.filesCurrentPageSize.call({})).toBe(10)
	})

	it('is partly selected only when some but not all files are selected', () => {
		expect(computed.someFilesSelected.call({ selectedAttachments: ['f1'], allFilesSelected: false })).toBe(true)
		expect(computed.someFilesSelected.call({ selectedAttachments: ['f1'], allFilesSelected: true })).toBe(false)
		expect(computed.someFilesSelected.call({ selectedAttachments: [], allFilesSelected: false })).toBe(false)
	})
})

describe('ViewObject catalog, register and schema options', () => {
	it('lists every catalog by title', () => {
		expect(computed.catalogOptions.call({})).toEqual([
			{ id: 'c1', label: 'Catalog One' },
			{ id: 'c2', label: 'Catalog Two' },
		])
	})

	it('lists only the registers of the selected catalog', () => {
		expect(computed.registerOptions.call({ selectedCatalog: { id: 'c1' } })).toEqual([
			{ id: 'r1', label: 'Register One' },
			{ id: 'r3', label: 'Register Three' },
		])
	})

	it('has no registers without a known catalog or when it lists none', () => {
		expect(computed.registerOptions.call({ selectedCatalog: null })).toEqual([])
		expect(computed.registerOptions.call({ selectedCatalog: { id: 'missing' } })).toEqual([])
		expect(computed.registerOptions.call({ selectedCatalog: { id: 'c2' } })).toEqual([])
	})

	it('lists only schemas both the register and the catalog carry', () => {
		expect(computed.schemaOptions.call({
			selectedCatalog: { id: 'c1' },
			selectedRegister: { id: 'r1' },
		})).toEqual([
			{ id: 's1', label: 'Schema One' },
			{ id: 's3', label: 'Schema Three' },
		])
	})

	it('has no schemas without both selections, an unknown one, or no overlap', () => {
		expect(computed.schemaOptions.call({ selectedCatalog: { id: 'c1' }, selectedRegister: null })).toEqual([])
		expect(computed.schemaOptions.call({ selectedCatalog: null, selectedRegister: { id: 'r1' } })).toEqual([])
		expect(computed.schemaOptions.call({ selectedCatalog: { id: 'c1' }, selectedRegister: { id: 'nope' } })).toEqual([])
		expect(computed.schemaOptions.call({ selectedCatalog: { id: 'nope' }, selectedRegister: { id: 'r1' } })).toEqual([])
		expect(computed.schemaOptions.call({ selectedCatalog: { id: 'c1' }, selectedRegister: { id: 'r3' } })).toEqual([])
		expect(computed.schemaOptions.call({ selectedCatalog: { id: 'c2' }, selectedRegister: { id: 'r2' } })).toEqual([])
	})

	it('has a selected schema only once properties are shown', () => {
		expect(computed.hasSelectedSchema.call({ selectedSchema: { id: 's1' }, showProperties: true })).toBe(true)
		expect(computed.hasSelectedSchema.call({ selectedSchema: { id: 's1' }, showProperties: false })).toBe(false)
		expect(computed.hasSelectedSchema.call({ selectedSchema: null, showProperties: true })).toBe(false)
	})

	it('is complete only with a catalog, register and schema', () => {
		const all = { selectedCatalog: { id: 'c' }, selectedRegister: { id: 'r' }, selectedSchema: { id: 's' } }
		expect(computed.allSelectionsComplete.call(all)).toEqual({ id: 's' })
		expect(computed.allSelectionsComplete.call({ ...all, selectedRegister: null })).toBeFalsy()
		expect(computed.allSelectionsComplete.call({ ...all, selectedCatalog: null })).toBeFalsy()
	})

	it('shows the published icon only for an object with @self', () => {
		expect(computed.shouldShowPublishedIcon.call({ currentObject: { '@self': { id: 'x' } } })).toEqual({ id: 'x' })
		expect(computed.shouldShowPublishedIcon.call({ currentObject: { id: 'x' } })).toBeFalsy()
		expect(computed.shouldShowPublishedIcon.call({ currentObject: null })).toBeFalsy()
	})
})

describe('ViewObject themes', () => {
	it('labels themes by title, or by #id without one', () => {
		expect(computed.themeOptions.call({})).toEqual([
			{ id: 't1', label: 'Theme One' },
			{ id: 't2', label: '#t2' },
		])
	})

	it('has no theme options when the collection has no results', () => {
		store.collections.theme = {}
		expect(computed.themeOptions.call({})).toEqual([])
	})

	it('maps the form theme ids to options, keeping an unknown id as its own label', () => {
		const ctx = { formData: { themes: ['t1', 't2', 'gone'] } }
		expect(computed.themeFormData.get.call(ctx)).toEqual([
			{ id: 't1', label: 'Theme One' },
			{ id: 't2', label: '#t2' },
			{ id: 'gone', label: 'gone' },
		])
	})

	it('has no selected themes when the form carries none or a non-array', () => {
		expect(computed.themeFormData.get.call({ formData: {} })).toEqual([])
		expect(computed.themeFormData.get.call({ formData: { themes: 't1' } })).toEqual([])
	})

	it('stores only the ids of the selected themes', () => {
		const ctx = { formData: { themes: [] } }
		computed.themeFormData.set.call(ctx, [{ id: 't1', label: 'Theme One' }, 't9'])
		expect(ctx.formData.themes).toEqual(['t1', 't9'])
	})
})
