// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for how the object view turns a date, time or
 * date-time input into the value it stores: a typed-in string and a Date from
 * the picker, per schema format, including empty, invalid and non-date
 * properties. Dates are built from local parts (or from UTC for the ISO
 * outputs) so the expectations hold in every timezone.
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
	day: { type: 'string', format: 'date' },
	clock: { type: 'string', format: 'time' },
	moment: { type: 'string', format: 'date-time' },
	mail: { type: 'string', format: 'email' },
	plain: { type: 'string' },
	count: { type: 'number', format: 'date' },
}

const LOCAL = new Date(2026, 0, 2, 3, 4, 5)
const UTC = new Date(Date.UTC(2026, 0, 2, 3, 4, 5))
const INVALID = new Date('not a date')

/**
 * A stand-in for the component with the given form data.
 *
 * @param {*} formData The form data before the update.
 * @return {object} The stand-in.
 */
function viewModel(formData = {}) {
	return {
		formData,
		getSchemaProperties: () => SCHEMA,
	}
}

/**
 * The stored value processDateTimeValue gives one property.
 *
 * @param {string} key The property.
 * @param {*} value The input value.
 * @return {*} The stored value.
 */
function processed(key, value) {
	return ViewObject.methods.processDateTimeValue.call(viewModel(), key, value)
}

/**
 * The form data after handleDateTimeUpdate for one property.
 *
 * @param {string} key The property.
 * @param {*} value The picker value.
 * @param {*} formData The form data before the update.
 * @return {object} The form data after it.
 */
function updated(key, value, formData = {}) {
	const vm = viewModel(formData)
	ViewObject.methods.handleDateTimeUpdate.call(vm, key, value)
	return vm.formData
}

describe('ViewObject processDateTimeValue', () => {
	it('passes through properties that are not a date, time or date-time string', () => {
		expect(processed('unknown', LOCAL)).toBe(LOCAL)
		expect(processed('count', LOCAL)).toBe(LOCAL)
		expect(processed('plain', '')).toBe('')
		expect(processed('mail', null)).toBe(null)
		expect(processed('plain', 7)).toBe(7)
	})

	it('stores an empty value as an empty string', () => {
		for (const key of ['day', 'clock', 'moment']) {
			expect(processed(key, '')).toBe('')
			expect(processed(key, null)).toBe('')
			expect(processed(key, undefined)).toBe('')
			expect(processed(key, 0)).toBe('')
		}
	})

	it('formats a Date per format', () => {
		expect(processed('day', LOCAL)).toBe('2026-01-02')
		expect(processed('clock', LOCAL)).toBe('03:04')
		expect(processed('moment', UTC)).toBe('2026-01-02T03:04:05.000Z')
	})

	it('handles an invalid Date per format', () => {
		expect(processed('day', INVALID)).toBe('')
		expect(processed('clock', INVALID)).toBe('Inval')
		expect(processed('moment', INVALID)).toBe('')
	})

	it('keeps strings, completing a datetime-local value', () => {
		expect(processed('day', '2026-01-02')).toBe('2026-01-02')
		expect(processed('clock', '03:04')).toBe('03:04')
		expect(processed('clock', '3:4')).toBe('3:4')
		expect(processed('moment', '2026-01-02T03:04')).toBe(
			'2026-01-02T03:04:00.000Z',
		)
		expect(processed('moment', '2026-01-02T03:04:05Z')).toBe(
			'2026-01-02T03:04:05Z',
		)
		expect(processed('moment', 5)).toBe(5)
	})
})

describe('ViewObject handleDateTimeUpdate', () => {
	it('formats a valid Date per format, ISO for any other format', () => {
		expect(updated('day', LOCAL)).toEqual({ day: '2026-01-02' })
		expect(updated('clock', LOCAL)).toEqual({ clock: '03:04' })
		expect(updated('moment', UTC)).toEqual({
			moment: '2026-01-02T03:04:05.000Z',
		})
		expect(updated('plain', UTC)).toEqual({
			plain: '2026-01-02T03:04:05.000Z',
		})
		expect(updated('unknown', UTC)).toEqual({
			unknown: '2026-01-02T03:04:05.000Z',
		})
	})

	it('stores null and undefined as an empty string', () => {
		expect(updated('day', null)).toEqual({ day: '' })
		expect(updated('moment', undefined)).toEqual({ moment: '' })
	})

	it('keeps an invalid Date and any other value as given', () => {
		expect(updated('day', INVALID).day).toBe(INVALID)
		expect(updated('clock', '03:04')).toEqual({ clock: '03:04' })
		expect(updated('moment', '')).toEqual({ moment: '' })
	})

	it('replaces form data that is not an object and keeps other fields', () => {
		expect(updated('day', null, [1, 2])).toEqual({ day: '' })
		expect(updated('day', null, null)).toEqual({ day: '' })
		expect(updated('day', null, { other: 1 })).toEqual({ other: 1, day: '' })
	})
})
