/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the object view describes an object from its metadata: its display name and schema name
 * for the modal title, and the parts of its metadata rows (a register or
 * schema reference by title, the lock description, the timestamps).
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

const TIMESTAMP_ROWS = [
	['Created', 'created', 'Not set'],
	['Updated', 'updated', 'Not set'],
	['Published', 'published', 'Not published'],
	['Depublished', 'depublished', 'Not depublished'],
]

/**
 * Whether a value is a non-null object.
 *
 * @param {*} value The value.
 * @return {boolean} Whether it is.
 */
function isObject(value) {
	return Boolean(value) && typeof value === 'object'
}

/**
 * A schema object's name for the modal title.
 *
 * @param {object} schema The schema.
 * @return {string} The name.
 */
function schemaTitle(schema) {
	return schema.title || schema.name || schema.id || 'Publication'
}

/**
 * A timestamp in the local format, or the fallback when there is none.
 *
 * @param {string|undefined} value The timestamp.
 * @param {string} fallback The text without one.
 * @return {string} The text.
 */
export function localeTimestamp(value, fallback) {
	return value ? new Date(value).toLocaleString() : fallback
}

/**
 * The name the modal title shows for an object.
 *
 * @param {object} object The object.
 * @return {string} The name.
 */
export function objectDisplayName(object) {
	return (
		object['@self']?.name
		|| object.name
		|| object.title
		|| object.id
		|| 'Untitled'
	)
}

/**
 * The schema name the modal title shows for an object: of its own schema
 * object, else of its @self schema object, else its @self schema string.
 *
 * @param {object} object The object.
 * @return {string} The schema name.
 */
export function objectSchemaName(object) {
	if (isObject(object.schema)) {
		return schemaTitle(object.schema)
	}
	const selfSchema = object['@self']?.schema
	if (isObject(selfSchema)) {
		return schemaTitle(selfSchema)
	}
	return typeof selfSchema === 'string' ? selfSchema : 'Publication'
}

/**
 * A register or schema object by its title, name or id.
 *
 * @param {object} reference The register or schema.
 * @return {*} The text shown.
 */
function objectReferenceTitle(reference) {
	return reference.title || reference.name || reference.id || reference
}

/**
 * A register or schema id by the title of the matching available item.
 *
 * @param {string} id The id.
 * @param {Array} available The available registers or schemas.
 * @return {string} The text shown.
 */
function idReferenceTitle(id, available) {
	const match = available.find((item) => item.id === id)
	return match?.title || id
}

/**
 * A register or schema reference as shown in the metadata: an object by its
 * title, name or id, an id by the title of the matching available item.
 *
 * @param {object|string|undefined} reference The reference.
 * @param {Array} available The available registers or schemas.
 * @return {*} The text shown.
 */
export function referenceTitle(reference, available) {
	if (!reference) {
		return 'Not set'
	}
	if (typeof reference === 'object') {
		return objectReferenceTitle(reference)
	}
	return idReferenceTitle(reference, available)
}

/**
 * The lock description shown in the metadata.
 *
 * @param {object|boolean|undefined} locked The lock.
 * @return {string} The description.
 */
export function lockDescription(locked) {
	if (!locked) {
		return 'Not locked'
	}
	if (typeof locked !== 'object') {
		return 'Locked'
	}
	const lockedBy = locked.lockedBy || 'Unknown user'
	const lockedAt = localeTimestamp(locked.lockedAt, 'Unknown time')
	const process = locked.process ? ` (${locked.process})` : ''
	return `Locked by ${lockedBy} at ${lockedAt}${process}`
}

/**
 * The created, updated, published and depublished rows of the metadata.
 *
 * @param {object|undefined} self The object's @self metadata.
 * @return {Array} The rows.
 */
export function timestampRows(self) {
	return TIMESTAMP_ROWS.map(([label, field, fallback]) => [
		label,
		localeTimestamp(self?.[field], fallback),
	])
}
