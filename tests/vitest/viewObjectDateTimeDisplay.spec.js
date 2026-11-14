// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for how the object view shows a property value: the
 * display text (themes by title, times without seconds, date strings in the
 * local format, arrays and objects as JSON), the date/time display per
 * format, the value for a date or time input and the Date for the picker.
 * The process runs in UTC and the clock is frozen, so the expectations hold
 * on every machine; locale-formatted texts are compared with the same
 * toLocale* call.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest'

process.env.TZ = 'UTC'

vi.mock('../../src/store/store.js', () => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: {
		getCollection: () => ({
			results: [
				{ id: 't1', title: 'Theme One' },
				{ id: 't2', title: '' },
			],
		}),
	},
}))

const { default: ViewObject } =
	await import('../../src/modals/object/ViewObject.vue')

const SCHEMA = {
	day: { type: 'string', format: 'date' },
	clock: { type: 'string', format: 'time' },
	moment: { type: 'string', format: 'date-time' },
	plain: { type: 'string' },
	count: { type: 'number' },
}

/**
 * A stand-in for the component with the given form data and every method
 * bound to it.
 *
 * @param {object} formData The form data.
 * @return {object} The stand-in.
 */
function viewModel(formData = {}) {
	const vm = { formData, getSchemaProperties: () => SCHEMA }
	for (const [name, fn] of Object.entries(ViewObject.methods)) {
		if (!(name in vm)) {
			vm[name] = fn.bind(vm)
		}
	}
	return vm
}

const ISO = '2026-01-02T03:04:05Z'
const local = (value) => new Date(value).toLocaleString()

beforeAll(() => {
	vi.useFakeTimers()
	vi.setSystemTime(new Date('2026-03-04T10:00:00Z'))
})

afterAll(() => {
	vi.useRealTimers()
})

describe('ViewObject getDisplayValue', () => {
	it('shows themes by title, falling back to the id', () => {
		const vm = viewModel()
		expect(vm.getDisplayValue('themes', ['t1', 't2', 'tX'])).toBe(
			'Theme One, #t2, tX',
		)
		expect(vm.getDisplayValue('themes', 't1')).toBe('Theme One')
		expect(viewModel({ themes: ['t2'] }).getDisplayValue('themes', 't1')).toBe(
			'#t2',
		)
		expect(vm.getDisplayValue('themes', undefined)).toBe(undefined)
	})

	it('shows an edited value', () => {
		const show = (key, edited) =>
			viewModel({ [key]: edited }).getDisplayValue(key, 'original')
		expect(show('clock', '03:04:05')).toBe('03:04')
		expect(show('clock', '3:04')).toBe('3:04')
		expect(show('plain', '03:04:05')).toBe('03:04:05')
		expect(show('moment', ISO)).toBe(local(ISO))
		expect(show('plain', '2026-01-02')).toBe('2026-01-02')
		expect(show('plain', null)).toBe(null)
		expect(show('count', 5)).toBe(5)
		expect(show('plain', ['a'])).toEqual(['a'])
	})

	it('shows an original value', () => {
		const show = (key, value) => viewModel().getDisplayValue(key, value)
		expect(show('plain', null)).toBe('')
		expect(show('plain', undefined)).toBe('')
		expect(show('clock', '03:04:05')).toBe('03:04')
		expect(show('clock', 'noon')).toBe('noon')
		expect(show('plain', ISO)).toBe(local(ISO))
		expect(show('plain', '2026-01-02')).toBe(local('2026-01-02'))
		expect(show('plain', '1-2-2026')).toBe(local('1-2-2026'))
		expect(show('plain', '1899-01-02')).toBe('1899-01-02')
		expect(show('plain', 'some text')).toBe('some text')
		expect(show('plain', ['a', 1])).toBe('["a",1]')
		expect(show('plain', { a: 1 })).toBe('{"a":1}')
		expect(show('count', 0)).toBe(0)
		expect(show('count', false)).toBe(false)
	})
})

describe('ViewObject formatDateTimeValue', () => {
	it('formats per format', () => {
		const vm = viewModel()
		expect(vm.formatDateTimeValue('day', '')).toBe('')
		expect(vm.formatDateTimeValue('day', null)).toBe('')
		expect(vm.formatDateTimeValue('day', '2026-01-02')).toBe(
			new Date('2026-01-02T12:00:00').toLocaleDateString(),
		)
		expect(vm.formatDateTimeValue('day', ISO)).toBe(
			new Date(ISO).toLocaleDateString(),
		)
		expect(vm.formatDateTimeValue('clock', '03:04')).toBe('03:04')
		expect(vm.formatDateTimeValue('clock', '03:04:05')).toBe('03:04:05')
		expect(vm.formatDateTimeValue('clock', ISO)).toBe(
			new Date(ISO).toLocaleTimeString(),
		)
		expect(vm.formatDateTimeValue('moment', ISO)).toBe(local(ISO))
		expect(vm.formatDateTimeValue('plain', ISO)).toBe(ISO)
		expect(vm.formatDateTimeValue('unknown', 7)).toBe(7)
		expect(vm.formatDateTimeValue('day', 'garbage')).toBe('Invalid Date')
	})
})

describe('ViewObject getDateTimeValue', () => {
	it('gives the value for the HTML input type', () => {
		const vm = viewModel()
		expect(vm.getDateTimeValue('day', '')).toBe('')
		expect(vm.getDateTimeValue('day', ISO)).toBe('2026-01-02')
		expect(vm.getDateTimeValue('clock', ISO)).toBe('03:04')
		expect(vm.getDateTimeValue('moment', ISO)).toBe('2026-01-02T03:04')
		expect(vm.getDateTimeValue('plain', ISO)).toBe(ISO)
		expect(vm.getDateTimeValue('day', 'garbage')).toBe('')
		expect(viewModel({ day: '2026-05-06' }).getDateTimeValue('day', ISO)).toBe(
			'2026-05-06',
		)
		expect(viewModel({ day: '' }).getDateTimeValue('day', ISO)).toBe('')
	})
})

describe('ViewObject getDateTimePickerValue', () => {
	it('gives a Date for the picker, or null', () => {
		const vm = viewModel()
		const iso = (key, value) =>
			vm.getDateTimePickerValue(key, value)?.toISOString() ?? null
		expect(vm.getDateTimePickerValue('day', '')).toBe(null)
		expect(iso('day', '2026-01-02')).toBe('2026-01-02T12:00:00.000Z')
		expect(iso('day', ISO)).toBe('2026-01-02T03:04:05.000Z')
		expect(iso('clock', '03:04')).toBe('2026-03-04T03:04:00.000Z')
		expect(iso('clock', '03:04:05')).toBe('2026-03-04T03:04:05.000Z')
		expect(iso('clock', ISO)).toBe('2026-01-02T03:04:05.000Z')
		expect(iso('moment', ISO)).toBe('2026-01-02T03:04:05.000Z')
		expect(iso('plain', 'garbage')).toBe(null)
		expect(iso('clock', 'noon')).toBe(null)
		expect(
			viewModel({ moment: '2026-07-08T09:10:11Z' })
				.getDateTimePickerValue('moment', ISO)
				.toISOString(),
		).toBe('2026-07-08T09:10:11.000Z')
	})
})
