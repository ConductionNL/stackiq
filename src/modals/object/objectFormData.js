/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The form data of the object view and its save request: the editable copy
 * of an object (without id and metadata, deep-copied where JSON allows),
 * theme references as ids, and the JSON request that saves an object with
 * the error for a refused one.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

const OBJECTS_API = '/index.php/apps/openregister/api/objects'

/**
 * A deep copy of a value through JSON, or the value itself when JSON cannot
 * copy it.
 *
 * @param {*} value The value.
 * @return {*} The copy.
 */
function jsonCopy(value) {
	try {
		return JSON.parse(JSON.stringify(value))
	} catch {
		return value
	}
}

/**
 * The editable properties of an object, each deep-copied: every enumerable
 * key except @self and id.
 *
 * @param {object} object The object.
 * @return {object} The properties.
 */
export function editableProperties(object) {
	const filtered = {}
	for (const key in object) {
		if (key !== '@self' && key !== 'id') {
			filtered[key] = jsonCopy(object[key])
		}
	}
	return filtered
}

/**
 * A copy of the editable properties for the form: a JSON copy when that
 * gives an object, else a shallow copy.
 *
 * @param {object} properties The editable properties.
 * @return {object} The form data.
 */
export function formDataOf(properties) {
	try {
		const parsedData = JSON.parse(JSON.stringify(properties))
		if (typeof parsedData === 'object' && !Array.isArray(parsedData)) {
			return parsedData
		}
		return { ...properties }
	} catch {
		return { ...properties }
	}
}

/**
 * Theme references as ids.
 *
 * @param {Array} themes The themes, as objects or ids.
 * @return {Array} The ids.
 */
export function themeIds(themes) {
	return themes.map((theme) => (typeof theme === 'object' ? theme.id : theme))
}

/**
 * The endpoint that creates an object in a register and schema.
 *
 * @param {string} registerId The register id.
 * @param {string} schemaId The schema id.
 * @return {string} The endpoint.
 */
export function createEndpoint(registerId, schemaId) {
	return `${OBJECTS_API}/${registerId}/${schemaId}`
}

/**
 * The endpoint that updates an object, from its register and schema ids and
 * its own id.
 *
 * @param {{registerId: string, schemaId: string}} ids The register and schema ids.
 * @param {string} objectId The object id.
 * @return {string} The endpoint.
 */
export function updateEndpoint(ids, objectId) {
	return `${OBJECTS_API}/${ids.registerId}/${ids.schemaId}/${objectId}`
}

/**
 * The fetch options that send object data as JSON.
 *
 * @param {string} method The HTTP method.
 * @param {object} objectData The object data.
 * @return {object} The options.
 */
export function jsonRequest(method, objectData) {
	return {
		method,
		headers: {
			'Content-Type': 'application/json',
		},
		body: JSON.stringify(objectData),
	}
}

/**
 * Throws the save error for a refused response: the action, the status and
 * the response text.
 *
 * @param {Response} response The response.
 * @param {boolean} isCreating Whether the object was being created.
 * @return {Promise<void>} Resolves for an accepted response.
 */
export async function assertSaved(response, isCreating) {
	if (response.ok) {
		return
	}
	const errorText = await response.text()
	throw new Error(
		`Failed to ${isCreating ? 'create' : 'update'} publication: ${response.status} ${response.statusText} - ${errorText}`,
	)
}
