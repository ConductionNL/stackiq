/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the date/time storage helpers of the object view: which
 * schema properties count as date-like, and the value stored for picker
 * Dates and typed-in strings.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	dateTimeFormatOf,
	dateTimeToStorage,
	pickerValueToStorage,
} from '../../src/modals/object/dateTimeStorage.js'

describe('dateTimeFormatOf', () => {
	it('returns the format of a string property in a date-like format', () => {
		expect(dateTimeFormatOf({ type: 'string', format: 'date' })).toBe('date')
		expect(dateTimeFormatOf({ type: 'string', format: 'time' })).toBe('time')
		expect(dateTimeFormatOf({ type: 'string', format: 'date-time' })).toBe('date-time')
	})

	it('returns null for other formats, other types and no property', () => {
		expect(dateTimeFormatOf({ type: 'string', format: 'email' })).toBeNull()
		expect(dateTimeFormatOf({ type: 'string' })).toBeNull()
		expect(dateTimeFormatOf({ type: 'integer', format: 'date' })).toBeNull()
		expect(dateTimeFormatOf(undefined)).toBeNull()
	})
})

describe('dateTimeToStorage', () => {
	it('stores empty values as an empty string', () => {
		expect(dateTimeToStorage('', 'date')).toBe('')
		expect(dateTimeToStorage(null, 'date')).toBe('')
		expect(dateTimeToStorage(undefined, 'time')).toBe('')
	})

	it('formats a Date per schema format', () => {
		const date = new Date(2026, 2, 5, 9, 7, 30)
		expect(dateTimeToStorage(date, 'date')).toBe('2026-03-05')
		expect(dateTimeToStorage(date, 'time')).toBe('09:07')
		expect(dateTimeToStorage(date, 'date-time')).toBe(date.toISOString())
		expect(dateTimeToStorage(date, 'something-else')).toBe(date.toISOString())
	})

	it('stores an unformattable Date as an empty string', () => {
		expect(dateTimeToStorage(new Date('nope'), 'date')).toBe('')
		expect(dateTimeToStorage(new Date('nope'), 'date-time')).toBe('')
	})

	it('completes a datetime-local string and leaves other strings alone', () => {
		expect(dateTimeToStorage('2026-03-05T09:07', 'date-time')).toBe('2026-03-05T09:07:00.000Z')
		expect(dateTimeToStorage('2026-03-05T09:07:12', 'date-time')).toBe('2026-03-05T09:07:12')
		expect(dateTimeToStorage('2026-03-05T09:07', 'date')).toBe('2026-03-05T09:07')
	})
})

describe('pickerValueToStorage', () => {
	it('formats a valid Date per format', () => {
		const date = new Date(2026, 11, 31, 23, 59)
		expect(pickerValueToStorage(date, 'date')).toBe('2026-12-31')
		expect(pickerValueToStorage(date, undefined)).toBe(date.toISOString())
	})

	it('maps null and undefined to an empty string and passes other values through', () => {
		const invalid = new Date('nope')
		expect(pickerValueToStorage(null, 'date')).toBe('')
		expect(pickerValueToStorage(undefined, 'date')).toBe('')
		expect(pickerValueToStorage('2026-01-01', 'date')).toBe('2026-01-01')
		expect(pickerValueToStorage(invalid, 'date')).toBe(invalid)
	})
})
