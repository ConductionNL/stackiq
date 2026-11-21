/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The input the object view renders for a schema property: the HTML input
 * type, the component, and the date picker type. A string property is told
 * apart by its format, any other property by its type.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

const STRING_INPUT_TYPES = new Map([
	['date', 'date'],
	['time', 'time'],
	['date-time', 'datetime-local'],
	['email', 'email'],
	['url', 'url'],
	['uri', 'url'],
	['password', 'password'],
])

const TYPE_INPUT_TYPES = new Map([
	['number', 'number'],
	['integer', 'number'],
	['boolean', 'checkbox'],
])

const STRING_COMPONENTS = new Map([
	['date', 'NcDateTimePicker'],
	['date-time', 'NcDateTimePicker'],
	['time', 'NcDateTimePicker'],
	['text', 'NcTextArea'],
	['markdown', 'Editor'],
])

const TYPE_COMPONENTS = new Map([
	['boolean', 'NcCheckboxRadioSwitch'],
	['array', 'NcTextFieldArray'],
])

const PICKER_TYPES = new Map([
	['date', 'date'],
	['time', 'time'],
])

/**
 * The HTML input type for a schema property, 'text' for none.
 *
 * @param {object|undefined} schemaProperty The schema property.
 * @return {string} The input type.
 */
export function inputTypeOf(schemaProperty) {
	if (!schemaProperty) return 'text'
	if (schemaProperty.type === 'string') {
		return STRING_INPUT_TYPES.get(schemaProperty.format) ?? 'text'
	}
	return TYPE_INPUT_TYPES.get(schemaProperty.type) ?? 'text'
}

/**
 * The input component for a schema property, NcTextField for none.
 *
 * @param {object|undefined} schemaProperty The schema property.
 * @return {string} The component name.
 */
export function inputComponentOf(schemaProperty) {
	if (!schemaProperty) return 'NcTextField'
	if (schemaProperty.type === 'string') {
		return STRING_COMPONENTS.get(schemaProperty.format) ?? 'NcTextField'
	}
	return TYPE_COMPONENTS.get(schemaProperty.type) ?? 'NcTextField'
}

/**
 * The NcDateTimePicker type for a schema property's format, 'datetime' for
 * any format other than date or time.
 *
 * @param {object|undefined} schemaProperty The schema property.
 * @return {string} The picker type.
 */
export function pickerTypeOf(schemaProperty) {
	return PICKER_TYPES.get(schemaProperty?.format) ?? 'datetime'
}
