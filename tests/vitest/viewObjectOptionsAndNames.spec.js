// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the object view's register and schema options
 * (the catalog's registers, the schemas both the register and the catalog
 * list), a property's display name (its usable title or its key), and the
 * markdown editor value written back to the form.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { afterEach, describe, expect, it, vi } from 'vitest'

const stores = vi.hoisted(() => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: {
		availableRegisters: [
			{
				id: 'r1',
				title: 'Register 1',
				schemas: [{ id: 's1' }, { id: 's2' }, { id: 's3' }],
			},
			{ id: 'r2', title: 'Register 2' },
			{ id: 'r3', title: 'Register 3', schemas: [{ id: 's1' }] },
		],
		availableSchemas: [
			{ id: 's3', title: 'Schema 3' },
			{ id: 's1', title: 'Schema 1' },
			{ id: 's2', title: 'Schema 2' },
		],
		getCollection: () => ({
			results: [
				{
					id: 'c1',
					registers: ['r1', 'r2', 'rX'],
					schemas: ['s1', 's3', 'sX'],
				},
				{ id: 'c2' },
			],
		}),
	},
}))

vi.mock('../../src/store/store.js', () => stores)

const { default: ViewObject } =
	await import('../../src/modals/object/ViewObject.vue')

/**
 * A stand-in for the component with the given state and every method bound.
 *
 * @param {object} state The component state.
 * @return {object} The stand-in.
 */
function viewModel(state = {}) {
	const vm = { formData: {}, ...state }
	for (const [name, fn] of Object.entries(ViewObject.methods)) {
		if (!(name in vm)) {
			vm[name] = fn.bind(vm)
		}
	}
	return vm
}

afterEach(() => {
	vi.restoreAllMocks()
})

describe('ViewObject register and schema options', () => {
	const options = (name, state) => ViewObject.computed[name].call(viewModel(state))

	it('offers the catalog registers', () => {
		expect(
			options('registerOptions', { selectedCatalog: { id: 'c1' } }),
		).toEqual([
			{ id: 'r1', label: 'Register 1' },
			{ id: 'r2', label: 'Register 2' },
		])
		expect(
			options('registerOptions', { selectedCatalog: { id: 'c2' } }),
		).toEqual([])
		expect(
			options('registerOptions', { selectedCatalog: { id: 'cX' } }),
		).toEqual([])
		expect(options('registerOptions', { selectedCatalog: null })).toEqual([])
	})

	it('offers the schemas both the register and the catalog list', () => {
		const sel = (register, catalog) => ({
			selectedRegister: register && { id: register },
			selectedCatalog: catalog && { id: catalog },
		})
		expect(options('schemaOptions', sel('r1', 'c1'))).toEqual([
			{ id: 's3', label: 'Schema 3' },
			{ id: 's1', label: 'Schema 1' },
		])
		expect(options('schemaOptions', sel('r2', 'c1'))).toEqual([])
		expect(options('schemaOptions', sel('r1', 'c2'))).toEqual([])
		expect(options('schemaOptions', sel('rX', 'c1'))).toEqual([])
		expect(options('schemaOptions', sel('r1', 'cX'))).toEqual([])
		expect(options('schemaOptions', sel(null, 'c1'))).toEqual([])
		expect(options('schemaOptions', sel('r1', null))).toEqual([])
	})
})

describe('ViewObject getPropertyDisplayName', () => {
	it('shows a usable title, else the key', () => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		const vm = viewModel({
			getSchemaProperties: () => ({
				titled: { title: 'Nice title' },
				blank: { title: '   ' },
				placeholder: { title: ' New ' },
				numeric: { title: 5 },
				none: {},
			}),
		})
		expect(
			['titled', 'blank', 'placeholder', 'numeric', 'none', 'unknown'].map(
				(k) => vm.getPropertyDisplayName(k),
			),
		).toEqual([
			'Nice title',
			'blank',
			'placeholder',
			'numeric',
			'none',
			'unknown',
		])
		expect(vm.getPropertyDisplayName('')).toBe('Unknown Property')
		expect(vm.getPropertyDisplayName(7)).toBe('Unknown Property')
		expect(console.warn.mock.calls).toEqual([
			['Invalid key passed to getPropertyDisplayName:', ''],
			['Invalid key passed to getPropertyDisplayName:', 7],
		])
	})
})

describe('ViewObject updateMarkdownValue', () => {
	it('writes the markdown, else the HTML, else a string back to the form', () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const vm = viewModel()
		const written = []
		vm.updatePropertyValue = (key, value) => written.push([key, value])
		vm.updateMarkdownValue('a', {
			getMarkdown: () => '# md',
			getHTML: () => '<h1>',
		})
		vm.updateMarkdownValue('b', { getHTML: () => '<p>' })
		vm.updateMarkdownValue('c', 'plain')
		vm.updateMarkdownValue('d', null)
		vm.updateMarkdownValue('e', { getMarkdown: 'not a function' })
		vm.updateMarkdownValue('f', {
			getMarkdown: () => {
				throw new Error('broken')
			},
		})
		expect(written).toEqual([
			['a', '# md'],
			['b', '<p>'],
			['c', 'plain'],
			['d', ''],
			['e', ''],
			['f', ''],
		])
		expect(warn).toHaveBeenCalledTimes(1)
		expect(warn.mock.calls[0][0]).toBe(
			'Error getting content from markdown editor:',
		)
	})
})
