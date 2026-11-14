/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The data the object view saves: the schema properties of a schema
 * reference, the form data cleaned of non-property keys, and the complete
 * object sent on save (the object without its id, the edits and deletions,
 * defaults for missing schema properties, and edited extra properties).
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { schemaDefaultValue } from './objectPropertyRows.js'

/**
 * The sources of a saved object.
 *
 * @typedef {object} PayloadSources
 * @property {object} current The object shown, or {} for a new one.
 * @property {object} edits The cleaned form data.
 */

/**
 * Whether a key can be an object property: a non-empty string that is not
 * an array index.
 *
 * @param {*} key The key.
 * @return {boolean} Whether it can.
 */
function isPropertyKey(key) {
	return typeof key === 'string' && key.length > 0 && !/^\d+$/.test(key)
}

/**
 * Whether a form data key is a property key and not a Vue or internal key.
 *
 * @param {*} key The key.
 * @return {boolean} Whether it is.
 */
function isFormKey(key) {
	return isPropertyKey(key) && !key.startsWith('_') && !key.startsWith('$')
}

/**
 * The id a schema reference names: an object's id or uuid, or the
 * reference itself.
 *
 * @param {object|string} schemaRef The schema reference.
 * @return {*} The schema id.
 */
function schemaReferenceId(schemaRef) {
	if (typeof schemaRef === 'object') {
		return schemaRef.id || schemaRef.uuid
	}
	return schemaRef
}

/**
 * The properties of the schema with an id among the available schemas, or
 * {} when there is no such schema or it has none.
 *
 * @param {Array} schemas The available schemas.
 * @param {*} schemaId The schema id.
 * @return {object} The schema properties.
 */
export function schemaPropertiesById(schemas, schemaId) {
	const fullSchema = schemas.find((schema) => schema.id === schemaId)
	return fullSchema?.properties || {}
}

/**
 * The properties of the schema a schema reference names, or {} when the
 * reference names no id.
 *
 * @param {Array} schemas The available schemas.
 * @param {object|string} schemaRef The schema reference.
 * @return {object} The schema properties.
 */
export function referencedSchemaProperties(schemas, schemaRef) {
	const schemaId = schemaReferenceId(schemaRef)
	return schemaId ? schemaPropertiesById(schemas, schemaId) : {}
}

/**
 * An object with every schema property it lacks added at its default value.
 *
 * @param {object} objectData The object, completed in place.
 * @param {object} schemaProperties The schema properties.
 * @return {object} The object.
 */
export function withSchemaDefaults(objectData, schemaProperties) {
	for (const [key, schemaProperty] of Object.entries(schemaProperties)) {
		if (!Object.hasOwn(objectData, key)) {
			objectData[key] = schemaDefaultValue(schemaProperty)
		}
	}
	return objectData
}

/**
 * The form data without keys that cannot be properties; {} for form data
 * that is an array.
 *
 * @param {object|Array|null} formData The form data.
 * @return {object} The cleaned form data.
 */
export function cleanedFormData(formData) {
	if (Array.isArray(formData)) {
		return {}
	}
	const cleaned = {}
	for (const [key, value] of Object.entries(formData || {})) {
		if (isFormKey(key)) {
			cleaned[key] = value
		}
	}
	return cleaned
}

/**
 * A copy of an object without its id.
 *
 * @param {object} objectData The object.
 * @return {object} The copy.
 */
function withoutId(objectData) {
	const copy = {}
	for (const [key, value] of Object.entries(objectData)) {
		if (key !== 'id') {
			copy[key] = value
		}
	}
	return copy
}

/**
 * The value saved for a schema property neither edited nor on the object:
 * the const of a string property, else null so the backend sets a default.
 *
 * @param {object} schemaProperty The schema property.
 * @return {*} The value.
 */
function payloadDefault(schemaProperty) {
	return schemaProperty.type === 'string' ? schemaProperty.const || null : null
}

/**
 * Puts one schema property in the payload: its edit (removed when marked for
 * deletion), else the object's value, else the default.
 *
 * @param {object} run The payload being built and its sources.
 * @param {Array} entry The schema key and property.
 */
function applySchemaProperty(run, [key, schemaProperty]) {
	if (Object.hasOwn(run.edits, key)) {
		applyEdit(run, key)
	} else if (Object.hasOwn(run.current, key)) {
		run.payload[key] = run.current[key]
	} else {
		run.payload[key] = payloadDefault(schemaProperty)
	}
}

/**
 * Puts one edit in the payload, or removes the property when the edit marks
 * it for deletion (undefined).
 *
 * @param {object} run The payload being built and its sources.
 * @param {string} key The property key.
 */
function applyEdit(run, key) {
	if (run.edits[key] === undefined) {
		delete run.payload[key]
	} else {
		run.payload[key] = run.edits[key]
	}
}

/**
 * Puts the edited properties the schema does not define in the payload,
 * except those marked for deletion.
 *
 * @param {object} run The payload being built and its sources.
 * @param {object} schemaProperties The schema properties.
 */
function applyExtraEdits(run, schemaProperties) {
	for (const [key, value] of Object.entries(run.edits)) {
		if (
			!Object.hasOwn(schemaProperties, key)
			&& isPropertyKey(key)
			&& value !== undefined
		) {
			run.payload[key] = value
		}
	}
}

/**
 * The complete object sent on save.
 *
 * @param {PayloadSources} sources The object shown and the cleaned edits.
 * @param {object} schemaProperties The schema properties.
 * @return {object} The object data.
 */
export function objectPayload(sources, schemaProperties) {
	const run = { ...sources, payload: withoutId(sources.current) }
	for (const entry of Object.entries(schemaProperties)) {
		applySchemaProperty(run, entry)
	}
	applyExtraEdits(run, schemaProperties)
	return run.payload
}
