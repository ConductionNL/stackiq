/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the object view's property validation: date strings,
 * per-type value checks, the error message chosen for a field, and the
 * presence class.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	isValidDateString,
	isValidPropertyValue,
	presenceClass,
	propertyErrorMessage,
} from '../../src/modals/object/propertyValidation.js'

describe('isValidDateString', () => {
	it('accepts date-shaped strings after 1900', () => {
		expect(isValidDateString('2026-03-05')).toBe(true)
		expect(isValidDateString('2026-03-05T09:07:00Z')).toBe(true)
		expect(isValidDateString('3-5-2026')).toBe(true)
	})

	it('rejects short, shapeless, invalid and early dates and non-strings', () => {
		expect(isValidDateString('2026-3')).toBe(false)
		expect(isValidDateString('yesterday at noon')).toBe(false)
		expect(isValidDateString('2026-13-45')).toBe(false)
		expect(isValidDateString('1850-01-01')).toBe(false)
		expect(isValidDateString(20260305)).toBe(false)
	})
})

describe('isValidPropertyValue', () => {
	it('accepts an empty value only when not required', () => {
		expect(isValidPropertyValue('', { type: 'string' })).toBe(true)
		expect(isValidPropertyValue(null, { type: 'string', required: true })).toBe(false)
		expect(isValidPropertyValue(undefined, { type: 'number', required: true })).toBe(false)
	})

	it('checks each type', () => {
		expect(isValidPropertyValue('x', { type: 'string' })).toBe(true)
		expect(isValidPropertyValue(1, { type: 'string' })).toBe(false)
		expect(isValidPropertyValue('2026-03-05T09:00:00Z', { type: 'string', format: 'date-time' })).toBe(true)
		expect(isValidPropertyValue('soon', { type: 'string', format: 'date-time' })).toBe(false)
		expect(isValidPropertyValue('a', { type: 'string', const: 'a' })).toBe(true)
		expect(isValidPropertyValue('b', { type: 'string', const: 'a' })).toBe(false)
		expect(isValidPropertyValue(1.5, { type: 'number' })).toBe(true)
		expect(isValidPropertyValue(NaN, { type: 'number' })).toBe(false)
		expect(isValidPropertyValue('1', { type: 'number' })).toBe(false)
		expect(isValidPropertyValue(false, { type: 'boolean' })).toBe(true)
		expect(isValidPropertyValue('false', { type: 'boolean' })).toBe(false)
		expect(isValidPropertyValue([], { type: 'array' })).toBe(true)
		expect(isValidPropertyValue({}, { type: 'array' })).toBe(false)
		expect(isValidPropertyValue({}, { type: 'object' })).toBe(true)
		expect(isValidPropertyValue([], { type: 'object' })).toBe(false)
		expect(isValidPropertyValue('anything', { type: 'integer' })).toBe(true)
	})
})

describe('propertyErrorMessage', () => {
	it('explains a property missing from the schema', () => {
		expect(propertyErrorMessage({ key: 'x', value: 1 })).toBe(
			"Property 'x' is not defined in the current schema. This property exists in the object but is not part of the schema definition.",
		)
	})

	it('reports required, then type, then constraint problems', () => {
		expect(propertyErrorMessage({ key: 'a', value: '', property: { type: 'string', required: true } }))
			.toBe("Required property 'a' is missing or empty")
		expect(propertyErrorMessage({ key: 'b', value: [1], property: { type: 'object' } }))
			.toBe("Property 'b' should be object but is array")
		expect(propertyErrorMessage({ key: 'c', value: 'soon', property: { type: 'string', format: 'date-time' } }))
			.toBe("Property 'c' should be a valid date-time format")
		expect(propertyErrorMessage({ key: 'd', value: 'b', property: { type: 'string', const: 'a' } }))
			.toBe("Property 'd' should be 'a' but is 'b'")
		expect(propertyErrorMessage({ key: 'e', value: 'ok', property: { type: 'string' } }))
			.toBe("Property 'e' has an invalid value")
	})
})

describe('presenceClass', () => {
	it('marks schema properties new and stray object properties as a warning', () => {
		expect(presenceClass({ type: 'string' }, true)).toBe('property-new')
		expect(presenceClass(undefined, true)).toBe('property-warning')
		expect(presenceClass(undefined, false)).toBe('')
	})
})
