/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the object view shows date and time values: the display text per
 * schema format, the value an HTML date or time input takes, the Date the
 * date picker takes, and the text of a theme reference.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

const TIME_PATTERN = /^\d{2}:\d{2}(:\d{2})?$/
const DATE_ONLY_PATTERN = /^\d{4}-\d{2}-\d{2}$/
const DATE_START_PATTERN = /^\d{4}-\d{2}-\d{2}|^\d{1,2}-\d{1,2}-\d{4}/

/**
 * Whether a value is an HH:MM or HH:MM:SS string.
 *
 * @param {*} value The value.
 * @return {boolean} Whether it is.
 */
export function isTimeString(value) {
	return typeof value === 'string' && TIME_PATTERN.test(value)
}

/**
 * Whether a value is a YYYY-MM-DD string.
 *
 * @param {*} value The value.
 * @return {boolean} Whether it is.
 */
function isDateOnlyString(value) {
	return typeof value === 'string' && DATE_ONLY_PATTERN.test(value)
}

/**
 * Whether a string starts like a date (YYYY-MM-DD or M-D-YYYY) and has a
 * date separator.
 *
 * @param {*} value The value.
 * @return {boolean} Whether it does.
 */
export function startsLikeDate(value) {
	return (
		typeof value === 'string'
		&& (value.includes('T') || value.includes('-'))
		&& DATE_START_PATTERN.test(value)
	)
}

/**
 * A date value in the local date format; a YYYY-MM-DD string is read at noon
 * so the day does not shift with the timezone.
 *
 * @param {*} value The value.
 * @return {string} The text.
 */
function displayDate(value) {
	if (isDateOnlyString(value)) {
		return new Date(value + 'T12:00:00').toLocaleDateString()
	}
	return new Date(value).toLocaleDateString()
}

/**
 * A time value as given when it is a time string, else in the local time
 * format.
 *
 * @param {*} value The value.
 * @return {string} The text.
 */
function displayTime(value) {
	return isTimeString(value) ? value : new Date(value).toLocaleTimeString()
}

const DISPLAY_FORMATTERS = new Map([
	['date', displayDate],
	['time', displayTime],
	['date-time', (value) => new Date(value).toLocaleString()],
])

/**
 * A value shown for its schema format; a value of any other format, or one
 * that cannot be formatted, is shown as it is.
 *
 * @param {*} value The value.
 * @param {string|undefined} format The schema format.
 * @return {*} The text.
 */
export function formatForDisplay(value, format) {
	const formatter = DISPLAY_FORMATTERS.get(format)
	if (!formatter) return value
	try {
		return formatter(value)
	} catch {
		return value
	}
}

const INPUT_FORMATTERS = new Map([
	['date', (date) => date.toISOString().split('T')[0]],
	['time', (date) => date.toTimeString().split(' ')[0].substring(0, 5)],
	['datetime-local', (date) => date.toISOString().substring(0, 16)],
])

/**
 * The value an HTML input of a date or time type takes: '' when the value is
 * no date, the value as it is for any other input type.
 *
 * @param {*} value The value.
 * @param {string} inputType The input type.
 * @return {*} The input value.
 */
export function inputValueFor(value, inputType) {
	try {
		const date = new Date(value)
		if (isNaN(date.getTime())) return ''
		const formatter = INPUT_FORMATTERS.get(inputType)
		return formatter ? formatter(date) : value
	} catch {
		return value
	}
}

/**
 * A value read as a Date for its format: a YYYY-MM-DD date at noon, a time
 * string on today's date, anything else as it is.
 *
 * @param {*} value The value.
 * @param {string|undefined} format The schema format.
 * @return {Date} The date.
 */
function readPickerDate(value, format) {
	if (format === 'date' && isDateOnlyString(value)) {
		return new Date(value + 'T12:00:00')
	}
	if (format === 'time' && isTimeString(value)) {
		const today = new Date().toISOString().split('T')[0]
		return new Date(today + 'T' + value)
	}
	return new Date(value)
}

/**
 * The Date the date picker takes for a value, or null when it is no date.
 *
 * @param {*} value The value.
 * @param {string|undefined} format The schema format.
 * @return {Date|null} The date.
 */
export function pickerDateFor(value, format) {
	try {
		const date = readPickerDate(value, format)
		return isNaN(date.getTime()) ? null : date
	} catch {
		return null
	}
}

/**
 * A theme id shown by the theme's title, '#id' for a theme without one, the
 * id itself for an unknown theme.
 *
 * @param {Array} themes The themes.
 * @param {*} id The theme id.
 * @return {*} The text.
 */
export function themeLabel(themes, id) {
	const theme = themes.find((t) => t.id === id)
	return theme ? theme.title || `#${theme.id}` : id
}
