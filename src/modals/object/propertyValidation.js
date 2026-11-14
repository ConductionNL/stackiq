/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the object view validates a property value against its schema
 * property: whether a string is a plausible date, whether a value is valid
 * for its type and constraints, and the message naming the first failed
 * check.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

const DATE_PATTERNS = [
	/^\d{4}-\d{2}-\d{2}/, // YYYY-MM-DD
	/^\d{1,2}-\d{1,2}-\d{4}/, // M-D-YYYY or MM-DD-YYYY
	/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/, // ISO datetime
]

/**
 * A property being checked: its key, its value and its schema property.
 *
 * @typedef {object} PropertyField
 * @property {string} key The property key.
 * @property {*} value The value.
 * @property {object} property The schema property.
 */

/**
 * Whether a value is a string shaped like a date. Short strings such as
 * "test 12" never are.
 *
 * @param {*} value The value.
 * @return {boolean} Whether it is.
 */
function hasDateShape(value) {
	return (
		typeof value === 'string'
		&& value.length >= 8
		&& DATE_PATTERNS.some((pattern) => pattern.test(value))
	)
}

/**
 * Whether a value is a date-shaped string that parses to a date after 1900.
 *
 * @param {*} value The value.
 * @return {boolean} Whether it is.
 */
export function isValidDateString(value) {
	if (!hasDateShape(value)) return false
	const date = new Date(value)
	return date instanceof Date && !isNaN(date) && date.getFullYear() > 1900
}

/**
 * Whether a value counts as empty: null, undefined or ''.
 *
 * @param {*} value The value.
 * @return {boolean} Whether it does.
 */
function isEmptyValue(value) {
	return value === null || value === undefined || value === ''
}

/**
 * Whether a value is a valid string for a string schema property: a valid
 * date for date-time, else equal to the const when there is one.
 *
 * @param {*} value The value.
 * @param {object} schemaProperty The schema property.
 * @return {boolean} Whether it is.
 */
function isValidString(value, schemaProperty) {
	if (typeof value !== 'string') return false
	if (schemaProperty.format === 'date-time') {
		return isValidDateString(value)
	}
	return !(schemaProperty.const && value !== schemaProperty.const)
}

/**
 * Whether a value is a plain object (not null, not an array).
 *
 * @param {*} value The value.
 * @return {boolean} Whether it is.
 */
function isObjectValue(value) {
	return typeof value === 'object' && value !== null && !Array.isArray(value)
}

const TYPE_VALIDATORS = new Map([
	['string', isValidString],
	['number', (value) => typeof value === 'number' && !isNaN(value)],
	['boolean', (value) => typeof value === 'boolean'],
	['array', (value) => Array.isArray(value)],
	['object', isObjectValue],
])

/**
 * Whether a value is valid for its schema property: an empty value when the
 * property is not required, else a value of the property's type; a value of
 * an unknown type is valid.
 *
 * @param {*} value The value.
 * @param {object} schemaProperty The schema property.
 * @return {boolean} Whether it is.
 */
export function isValidPropertyValue(value, schemaProperty) {
	if (isEmptyValue(value)) {
		return !schemaProperty.required
	}
	const validator = TYPE_VALIDATORS.get(schemaProperty.type)
	return validator ? validator(value, schemaProperty) : true
}

/**
 * The message for a required property without a value, or null.
 *
 * @param {PropertyField} field The property checked.
 * @return {string|null} The message.
 */
function requiredMessage(field) {
	if (isEmptyValue(field.value) && field.property.required) {
		return `Required property '${field.key}' is missing or empty`
	}
	return null
}

/**
 * The message for a value of another type than the schema expects, or null.
 *
 * @param {PropertyField} field The property checked.
 * @return {string|null} The message.
 */
function typeMessage(field) {
	const expectedType = field.property.type
	const actualType = Array.isArray(field.value) ? 'array' : typeof field.value
	if (expectedType !== actualType) {
		return `Property '${field.key}' should be ${expectedType} but is ${actualType}`
	}
	return null
}

/**
 * The message for a failed format or const constraint, or null.
 *
 * @param {PropertyField} field The property checked.
 * @return {string|null} The message.
 */
function constraintMessage(field) {
	const { key, value, property } = field
	if (property.format === 'date-time' && !isValidDateString(value)) {
		return `Property '${key}' should be a valid date-time format`
	}
	if (property.const && value !== property.const) {
		return `Property '${key}' should be '${property.const}' but is '${value}'`
	}
	return null
}

/**
 * The message naming the first check a property fails: not in the schema,
 * required but empty, wrong type, wrong format or const.
 *
 * @param {PropertyField} field The property checked.
 * @return {string} The message.
 */
export function propertyErrorMessage(field) {
	if (!field.property) {
		return `Property '${field.key}' is not defined in the current schema. This property exists in the object but is not part of the schema definition.`
	}
	return (
		requiredMessage(field)
		?? typeMessage(field)
		?? constraintMessage(field)
		?? `Property '${field.key}' has an invalid value`
	)
}

/**
 * The validation class of a property that is missing from the schema or from
 * the object: a warning for an object property the schema does not define,
 * 'property-new' for a schema property the object does not hold yet.
 *
 * @param {object|undefined} schemaProperty The schema property.
 * @param {boolean} existsInObject Whether the object holds the property.
 * @return {string} The class.
 */
export function presenceClass(schemaProperty, existsInObject) {
	if (schemaProperty) {
		return 'property-new'
	}
	return existsInObject ? 'property-warning' : ''
}
