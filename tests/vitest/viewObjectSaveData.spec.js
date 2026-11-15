// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the data the object view saves: the schema
 * properties it works with (selected schema, the object's schema reference,
 * the current schema), the cleaned form data, the complete object sent on
 * save (edits, deletions, kept values, defaults, extra properties, in key
 * order) and the object rebuilt from an API result.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/store.js', () => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: {
		availableSchemas: [
			{ id: 's1', properties: { one: { type: 'string' } } },
			{ id: 's2' },
			{ id: 'u3', properties: { three: { type: 'number' } } },
		],
	},
}))

const { default: ViewObject } =
	await import('../../src/modals/object/ViewObject.vue')

/**
 * A stand-in for the component with the given state and every method bound
 * to it.
 *
 * @param {object} state The component state.
 * @return {object} The stand-in.
 */
function viewModel(state) {
	const vm = { ...state }
	for (const [name, fn] of Object.entries(ViewObject.methods)) {
		if (!(name in vm)) {
			vm[name] = fn.bind(vm)
		}
	}
	return vm
}

describe('ViewObject getSchemaProperties', () => {
	const props = (state) => viewModel(state).getSchemaProperties()

	it('uses the selected schema for a new object', () => {
		expect(props({ isNewObject: true, selectedSchema: { id: 's1' } })).toEqual({
			one: { type: 'string' },
		})
		expect(props({ isNewObject: true, selectedSchema: { id: 's2' } })).toEqual(
			{},
		)
		expect(props({ isNewObject: true, selectedSchema: { id: 'sX' } })).toEqual(
			{},
		)
	})

	it("uses the object's schema reference", () => {
		const of = (schema, extra = {}) =>
			props({ currentObject: { '@self': { schema } }, ...extra })
		expect(of('s1')).toEqual({ one: { type: 'string' } })
		expect(of({ id: 's1' })).toEqual({ one: { type: 'string' } })
		expect(of({ uuid: 'u3' })).toEqual({ three: { type: 'number' } })
		expect(of({ id: 's2' })).toEqual({})
		expect(of({})).toEqual({})
		expect(of('sX', { currentSchema: { properties: { c: {} } } })).toEqual({})
		expect(of('s1', { isNewObject: true })).toEqual({ one: { type: 'string' } })
	})

	it('falls back to the current schema', () => {
		expect(
			props({ currentObject: {}, currentSchema: { properties: { c: {} } } }),
		).toEqual({ c: {} })
		expect(props({ currentObject: null, currentSchema: {} })).toEqual({})
		expect(props({ isNewObject: true, selectedSchema: null })).toEqual({})
	})
})

describe('ViewObject cleanFormData', () => {
	it('keeps only property keys', () => {
		const clean = (formData) => viewModel({ formData }).cleanFormData()
		expect(clean([1, 2])).toEqual({})
		expect(clean(null)).toEqual({})
		expect(
			clean({ a: 1, '': 2, 12: 3, _x: 4, $y: 5, b: undefined, '1a': 6 }),
		).toEqual({
			a: 1,
			b: undefined,
			'1a': 6,
		})
	})
})

describe('ViewObject buildCompleteObjectData', () => {
	const SCHEMA = {
		edited: { type: 'string' },
		dropped: { type: 'string' },
		kept: { type: 'number' },
		fixed: { type: 'string', const: 'C' },
		text: { type: 'string' },
		count: { type: 'number' },
		flag: { type: 'boolean' },
		list: { type: 'array' },
		map: { type: 'object' },
		other: { type: 'file' },
	}

	it('merges the object, the edits and the schema defaults in order', () => {
		const vm = viewModel({
			currentObject: {
				id: 'x',
				'@self': { id: 'x' },
				kept: 5,
				dropped: 'd',
				loose: 'l',
				edited: 'old',
			},
			formData: {
				edited: 'new',
				dropped: undefined,
				extra: 'e',
				gone: undefined,
				7: 'n',
				_p: 'p',
			},
			getSchemaProperties: () => SCHEMA,
		})
		const data = vm.buildCompleteObjectData()
		expect(data).toEqual({
			'@self': { id: 'x' },
			kept: 5,
			loose: 'l',
			edited: 'new',
			fixed: 'C',
			text: null,
			count: null,
			flag: null,
			list: null,
			map: null,
			other: null,
			extra: 'e',
		})
		expect(Object.keys(data)).toEqual([
			'@self',
			'kept',
			'loose',
			'edited',
			'fixed',
			'text',
			'count',
			'flag',
			'list',
			'map',
			'other',
			'extra',
		])
	})

	it('works without an object', () => {
		const vm = viewModel({
			currentObject: null,
			formData: { text: 't' },
			getSchemaProperties: () => ({
				text: { type: 'string' },
				flag: { type: 'boolean' },
			}),
		})
		expect(vm.buildCompleteObjectData()).toEqual({ text: 't', flag: null })
	})
})

describe('ViewObject rebuildObjectWithSchemaProperties', () => {
	it('merges the API result and adds missing schema properties with defaults', () => {
		const vm = viewModel({
			currentObject: { a: 1, b: 2, '@self': { id: 'x', version: 1 } },
			getSchemaProperties: () => ({
				a: { type: 'number' },
				fixed: { type: 'string', const: 'C' },
				text: { type: 'string' },
				count: { type: 'number' },
				whole: { type: 'integer' },
				flag: { type: 'boolean' },
				list: { type: 'array' },
				map: { type: 'object' },
				other: { type: 'file' },
			}),
		})
		const rebuilt = vm.rebuildObjectWithSchemaProperties({
			b: 3,
			c: 4,
			'@self': { version: 2 },
		})
		expect(rebuilt).toEqual({
			a: 1,
			b: 3,
			'@self': { id: 'x', version: 2 },
			c: 4,
			fixed: 'C',
			text: '',
			count: 0,
			whole: 0,
			flag: false,
			list: [],
			map: {},
			other: '',
		})
		expect(Object.keys(rebuilt)).toEqual([
			'a',
			'b',
			'@self',
			'c',
			'fixed',
			'text',
			'count',
			'whole',
			'flag',
			'list',
			'map',
			'other',
		])
	})
})
