/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The value the object view stores for a date, time or date-time input: a
 * Date from the picker formatted per schema format (a date in the local
 * timezone, a time as HH:MM, anything else as an ISO string), and a typed-in
 * datetime-local string completed with seconds and a UTC zone.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

const DATE_TIME_FORMATS = ['date', 'time', 'date-time']

/**
 * A date as YYYY-MM-DD in the local timezone, or '' for no valid Date.
 *
 * @param {Date|undefined} date The date.
 * @return {string} The date string.
 */
function toLocalDateString(date) {
	if (!(date instanceof Date) || isNaN(date.getTime())) return ''
	const yyyy = date.getFullYear()
	const mm = String(date.getMonth() + 1).padStart(2, '0')
	const dd = String(date.getDate()).padStart(2, '0')
	return `${yyyy}-${mm}-${dd}`
}

/**
 * A date's local time of day as HH:MM.
 *
 * @param {Date} date The date.
 * @return {string} The time.
 */
function toTimeOfDay(date) {
	return date.toTimeString().split(' ')[0].substring(0, 5)
}

/**
 * A date as an ISO string.
 *
 * @param {Date} date The date.
 * @return {string} The ISO string.
 */
function toIsoString(date) {
	return date.toISOString()
}

const DATE_FORMATTERS = new Map([
	['date', toLocalDateString],
	['time', toTimeOfDay],
	['date-time', toIsoString],
])

/**
 * The stored form of a Date for a format, ISO for any other format, or ''
 * when the Date cannot be formatted.
 *
 * @param {Date} date The date.
 * @param {string|undefined} format The schema format.
 * @return {string} The stored value.
 */
function dateToStorage(date, format) {
	const formatter = DATE_FORMATTERS.get(format) ?? toIsoString
	try {
		return formatter(date)
	} catch {
		return ''
	}
}

/**
 * The stored form of a typed-in value: a datetime-local value (16
 * characters) gains seconds and a UTC zone, any other value stays as it is.
 *
 * @param {string|number} value The typed-in value.
 * @param {string} format The schema format.
 * @return {string|number} The stored value.
 */
function stringToStorage(value, format) {
	if (format === 'date-time' && value.length === 16) {
		return value + ':00.000Z'
	}
	return value
}

/**
 * The date, time or date-time format of a schema property, or null when the
 * property is not a string in one of those formats.
 *
 * @param {object|undefined} schemaProperty The schema property.
 * @return {string|null} The format.
 */
export function dateTimeFormatOf(schemaProperty) {
	if (!schemaProperty || schemaProperty.type !== 'string') {
		return null
	}
	const format = schemaProperty.format
	return DATE_TIME_FORMATS.includes(format) ? format : null
}

/**
 * The stored form of an input value for a date, time or date-time property:
 * '' when empty, a Date formatted per format, a string completed when it is
 * a datetime-local value.
 *
 * @param {Date|string|number|null|undefined} value The input value.
 * @param {string} format The schema format.
 * @return {string|number} The stored value.
 */
export function dateTimeToStorage(value, format) {
	if (!value) {
		return ''
	}
	if (value instanceof Date) {
		return dateToStorage(value, format)
	}
	return stringToStorage(value, format)
}

/**
 * The stored form of a date picker value: a valid Date formatted per format,
 * '' for null or undefined, any other value as it is.
 *
 * @param {Date|string|null|undefined} value The picker value.
 * @param {string|undefined} format The schema format.
 * @return {Date|string} The stored value.
 */
export function pickerValueToStorage(value, format) {
	if (value instanceof Date && !isNaN(value.getTime())) {
		return dateToStorage(value, format)
	}
	if (value === null || value === undefined) {
		return ''
	}
	return value
}
