// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for how the object view validates a property: the
 * date check, the validation class per property (metadata, not in the
 * schema, not in the object, valid or invalid per type and constraint) and
 * the error message for each failed check.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/store.js', () => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: {},
}))

const { default: ViewObject } =
	await import('../../src/modals/object/ViewObject.vue')

const SCHEMA = {
	name: { type: 'string' },
	fixed: { type: 'string', const: 'yes' },
	when: { type: 'string', format: 'date-time' },
	needed: { type: 'string', required: true },
	count: { type: 'number' },
	flag: { type: 'boolean' },
	list: { type: 'array' },
	map: { type: 'object' },
	other: { type: 'file' },
	untyped: {},
	numberConst: { type: 'number', const: 3 },
}

/**
 * A stand-in for the component showing the given object, with every method
 * bound to it.
 *
 * @param {object|null} currentObject The object shown.
 * @return {object} The stand-in.
 */
function viewModel(currentObject) {
	const vm = { currentObject, getSchemaProperties: () => SCHEMA }
	for (const [name, fn] of Object.entries(ViewObject.methods)) {
		if (!(name in vm)) {
			vm[name] = fn.bind(vm)
		}
	}
	return vm
}

const OBJECT = Object.fromEntries(
	[...Object.keys(SCHEMA), 'extra'].map((key) => [key, null]),
)

/**
 * The validation class for one property of the full object.
 *
 * @param {string} key The property.
 * @param {*} value The value.
 * @return {string} The class.
 */
function cls(key, value) {
	return viewModel(OBJECT).getPropertyValidationClass(key, value)
}

/**
 * The error message for one property.
 *
 * @param {string} key The property.
 * @param {*} value The value.
 * @return {string} The message.
 */
function message(key, value) {
	return viewModel(OBJECT).getPropertyErrorMessage(key, value)
}

describe('ViewObject isValidDate', () => {
	it('accepts only date-shaped strings after 1900', () => {
		const vm = viewModel(OBJECT)
		const results = [
			null,
			'',
			7,
			'test 12',
			'2026-01',
			'2026-01-02',
			'1-2-2026',
			'2026-01-02T03:04:05Z',
			'1899-12-31',
			'hello world 2026',
			'2026-13-45',
			'12-31-2026',
		].map((value) => vm.isValidDate(value))
		expect(results).toEqual([
			false,
			false,
			false,
			false,
			false,
			true,
			true,
			true,
			false,
			false,
			false,
			true,
		])
	})
})

describe('ViewObject getPropertyValidationClass', () => {
	it('classes metadata, unknown and new properties', () => {
		expect(cls('@self', {})).toBe('')
		expect(cls('extra', 'x')).toBe('property-warning')
		expect(cls('missing', 'x')).toBe('')
		expect(viewModel({}).getPropertyValidationClass('name', 'x')).toBe(
			'property-new',
		)
		expect(viewModel(null).getPropertyValidationClass('name', 'x')).toBe(
			'property-new',
		)
		expect(viewModel(null).getPropertyValidationClass('extra', 'x')).toBe('')
	})

	it('classes values per type and constraint', () => {
		const cases = [
			['name', 'x'],
			['name', 5],
			['name', null],
			['name', ''],
			['needed', ''],
			['needed', undefined],
			['needed', 'v'],
			['fixed', 'yes'],
			['fixed', 'no'],
			['when', '2026-01-02T03:04:05Z'],
			['when', 'soon'],
			['count', 1],
			['count', NaN],
			['count', '1'],
			['flag', false],
			['flag', 'false'],
			['list', []],
			['list', {}],
			['map', {}],
			['map', []],
			['map', 'x'],
			['other', 'anything'],
			['untyped', 5],
			['numberConst', 4],
		]
		expect(cases.map(([key, value]) => cls(key, value))).toEqual([
			'property-valid',
			'property-invalid',
			'property-valid',
			'property-valid',
			'property-invalid',
			'property-invalid',
			'property-valid',
			'property-valid',
			'property-invalid',
			'property-valid',
			'property-invalid',
			'property-valid',
			'property-invalid',
			'property-invalid',
			'property-valid',
			'property-invalid',
			'property-valid',
			'property-invalid',
			'property-valid',
			'property-invalid',
			'property-invalid',
			'property-valid',
			'property-valid',
			'property-valid',
		])
	})
})

describe('ViewObject getPropertyErrorMessage', () => {
	it('names the first failed check', () => {
		expect(message('extra', 'x')).toBe(
			"Property 'extra' is not defined in the current schema. This property exists in the object but is not part of the schema definition.",
		)
		expect(message('needed', '')).toBe(
			"Required property 'needed' is missing or empty",
		)
		expect(message('needed', null)).toBe(
			"Required property 'needed' is missing or empty",
		)
		expect(message('name', null)).toBe(
			"Property 'name' should be string but is object",
		)
		expect(message('list', {})).toBe(
			"Property 'list' should be array but is object",
		)
		expect(message('map', [])).toBe(
			"Property 'map' should be object but is array",
		)
		expect(message('when', 'soon')).toBe(
			"Property 'when' should be a valid date-time format",
		)
		expect(message('fixed', 'no')).toBe(
			"Property 'fixed' should be 'yes' but is 'no'",
		)
		expect(message('numberConst', 4)).toBe(
			"Property 'numberConst' should be '3' but is '4'",
		)
		expect(message('name', 'fine')).toBe("Property 'name' has an invalid value")
		expect(message('untyped', undefined)).toBe(
			"Property 'untyped' should be undefined but is undefined",
		)
	})
})
