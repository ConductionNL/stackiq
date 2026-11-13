/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Whether the object view lets a property be edited: a const property never,
 * an immutable one not once it has a value; the warning shown instead, the
 * names that mark a property immutable, and the clicks that do not select
 * a row.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

const IMMUTABLE_NAME_PATTERNS = ['immutable', 'readonly', 'constant']

/**
 * Whether a value counts as set: not null, undefined or ''.
 *
 * @param {*} value The value.
 * @return {boolean} Whether it does.
 */
function hasValue(value) {
	return value !== null && value !== undefined && value !== ''
}

/**
 * Whether an immutable schema property already holds a value.
 *
 * @param {object|undefined} schemaProperty The schema property.
 * @param {*} value The value.
 * @return {boolean} Whether it does.
 */
function isFrozenByValue(schemaProperty, value) {
	return Boolean(schemaProperty?.immutable) && hasValue(value)
}

/**
 * Whether a property may be edited: a property outside the schema always, a
 * const property never, an immutable one only while it has no value.
 *
 * @param {object|undefined} schemaProperty The schema property.
 * @param {*} value The value.
 * @return {boolean} Whether it may.
 */
export function isEditable(schemaProperty, value) {
	if (!schemaProperty) return true
	if (schemaProperty.const !== undefined) return false
	return !isFrozenByValue(schemaProperty, value)
}

/**
 * The warning shown for a property that may not be edited, or null.
 *
 * @param {object|undefined} schemaProperty The schema property.
 * @param {*} value The value.
 * @return {string|null} The warning.
 */
export function editabilityWarning(schemaProperty, value) {
	if (schemaProperty?.const !== undefined) {
		return `This property is constant and must always be '${schemaProperty.const}'. Const properties cannot be modified to maintain data integrity.`
	}
	if (isFrozenByValue(schemaProperty, value)) {
		return `This property is immutable and cannot be changed once it has a value. Current value: '${value}'. Immutable properties preserve data consistency.`
	}
	return null
}

/**
 * Whether a property's name marks it immutable (immutable, readonly or
 * constant, in any case).
 *
 * @param {string} key The property key.
 * @return {boolean} Whether it does.
 */
export function isImmutableByName(key) {
	return IMMUTABLE_NAME_PATTERNS.some((pattern) =>
		key.toLowerCase().includes(pattern),
	)
}

/**
 * Whether a schema property is constant (has a const) and whether it is
 * immutable (readOnly or immutable).
 *
 * @param {object} schemaProperty The schema property.
 * @return {{isConstant: boolean, isImmutable: boolean}} The flags.
 */
export function schemaLockFlags(schemaProperty) {
	return {
		isConstant: schemaProperty.const !== undefined,
		isImmutable:
			schemaProperty.readOnly === true || schemaProperty.immutable === true,
	}
}

/**
 * Whether a click landed on an input, a button or a value input container,
 * which do not select the row.
 *
 * @param {Event} event The click.
 * @return {*} Truthy when it did.
 */
export function isControlClick(event) {
	return (
		event.target.tagName === 'INPUT'
		|| event.target.tagName === 'BUTTON'
		|| event.target.closest('.value-input-container')
	)
}

/**
 * Theme references as ids.
 *
 * @param {Array} themes The themes, as objects or ids.
 * @return {Array} The ids.
 */
export function themeIdList(themes) {
	return themes.map((theme) => (typeof theme === 'object' ? theme.id : theme))
}
