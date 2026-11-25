/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Data model page's New data entity (architecture-data-model-and-ggm,
 * REQ-DMG-004). An entity is an AMEF `element`, and that schema requires an
 * ArchiMate `identifier` and a `properties` list that the generic create form
 * never asks for, so a straight save was refused on OpenRegister main. This
 * handler owns the create: it fills a fresh `id-<uuid>` identifier and an
 * empty properties list, then writes the object through OpenRegister's
 * objects API, so RBAC, multitenancy and the audit trail apply as usual.
 * The AMEF schema stays as the import needs it.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * A fresh ArchiMate identifier, `id-<uuid>`, the form the import's identifiers take.
 *
 * @return {string}
 */
export function newArchimateIdentifier() {
	const random = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
		? crypto.randomUUID()
		: `${Date.now().toString(16)}-${Math.random().toString(16).slice(2)}`
	return `id-${random}`
}

/**
 * The body a new drawn data entity is saved with: the form's fields, plus the
 * identifier and properties list the element schema requires when the form
 * did not supply them.
 *
 * @param {object} formData The create form's values (with the page's createDefaults).
 * @param {function(): string} [newIdentifier] Makes a fresh ArchiMate identifier.
 * @return {object}
 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
 */
export function drawnDataEntityBody(formData, newIdentifier = newArchimateIdentifier) {
	const data = { ...(formData || {}) }
	const identifier = typeof data.identifier === 'string' ? data.identifier.trim() : ''
	return {
		...data,
		identifier: identifier !== '' ? identifier : newIdentifier(),
		properties: Array.isArray(data.properties) ? data.properties : [],
	}
}

/**
 * The register or schema a create context names, as a path segment.
 *
 * @param {string|number|object|null|undefined} value An id, a slug, or the resolved register/schema object.
 * @return {string}
 */
function segmentOf(value) {
	if (value !== null && typeof value === 'object') {
		return String(value.id ?? value.slug ?? '')
	}
	return value === null || value === undefined ? '' : String(value)
}

/**
 * The create-override for the Data model page.
 *
 * @param {object} [options] The collaborators.
 * @param {{post: function(string, object): Promise<{data: object}>}} [options.http] An axios-like client.
 * @param {function(): string} [options.newIdentifier] Makes a fresh ArchiMate identifier.
 * @return {function(object, {register: (string|number|object), schema: (string|number|object)}): Promise<object>}
 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
 */
export function createDrawnDataEntityHandler({ http = axios, newIdentifier = newArchimateIdentifier } = {}) {
	return async function createDrawnDataEntity(formData, ctx = {}) {
		const register = segmentOf(ctx.register)
		const schema = segmentOf(ctx.schema)
		if (register === '' || schema === '') {
			throw new Error('The data model has no register or schema configured.')
		}
		const url = generateUrl(`/apps/openregister/api/objects/${encodeURIComponent(register)}/${encodeURIComponent(schema)}`)
		try {
			return (await http.post(url, drawnDataEntityBody(formData, newIdentifier))).data
		} catch (error) {
			const message = error?.response?.data?.message ?? error?.response?.data?.error
			throw new Error(typeof message === 'string' && message !== '' ? message : (error?.message || 'Save failed'), { cause: error })
		}
	}
}

export const createDrawnDataEntity = createDrawnDataEntityHandler()
