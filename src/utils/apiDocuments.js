/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The two OpenAPI documents the API documentation page shows: OpenRegister's
 * generated document of the catalogue register (the catalogue objects) and the
 * document of the endpoints this app adds (`GET /api/openapi.json`). This module
 * only says where each one lives and reads what a fetch returns; the page renders
 * them with the library's CnApiReference, so no third-party viewer is loaded.
 *
 * @spec openspec/changes/archive/2026-11-25-sharing-generated-api-docs/specs/generated-api-docs/spec.md#requirement-req-gad-002-a-developer-reads-both-documents-on-one-page-in-stackiq
 */

/** The slug of the register that holds the catalogue objects. */
export const CATALOGUE_REGISTER_SLUG = 'stackiq'

/** The app's own OpenAPI document (OpenApiController::show). */
export const APP_DOCUMENT_PATH = '/apps/stackiq/api/openapi.json'

/**
 * The tabs of the page, in order: the catalogue objects first, then the app's endpoints.
 *
 * @type {Array<{id: string, title: string, downloadName: string}>}
 */
export const API_DOCUMENT_TABS = [
	{
		id: 'catalogue',
		title: 'Catalogue objects',
		downloadName: 'stackiq-catalogue-objects',
	},
	{
		id: 'endpoints',
		title: 'Stackiq endpoints',
		downloadName: 'stackiq-endpoints',
	},
]

/**
 * The id of the catalogue register in the app settings, or null when it is not configured.
 *
 * @param {object|null|undefined} settings The app settings (`GET /api/settings`).
 * @return {string|number|null}
 *
 * @spec openspec/changes/archive/2026-11-25-sharing-generated-api-docs/tasks.md#task-3-api-documentation-page
 */
export function catalogueRegisterId(settings) {
	const registers = Array.isArray(settings?.availableRegisters)
		? settings.availableRegisters
		: []
	const register = registers.find(
		(candidate) => candidate?.slug === CATALOGUE_REGISTER_SLUG,
	)
	const id = register?.id
	return id === undefined || id === null || id === '' ? null : id
}

/**
 * The path of each document, or null for one that cannot be fetched yet.
 *
 * @param {object|null|undefined} settings The app settings.
 * @return {{catalogue: string|null, endpoints: string}}
 *
 * @spec openspec/changes/archive/2026-11-25-sharing-generated-api-docs/tasks.md#task-3-api-documentation-page
 */
export function documentPaths(settings) {
	const registerId = catalogueRegisterId(settings)
	return {
		catalogue:
			registerId === null
				? null
				: `/apps/openregister/api/registers/${encodeURIComponent(String(registerId))}/oas`,
		endpoints: APP_DOCUMENT_PATH,
	}
}

/**
 * The parsed document from a response body, or null when it is not an OpenAPI or Swagger document.
 *
 * A body can arrive as a string when the server did not send a JSON content type.
 *
 * @param {object|string|null|undefined} data The response body.
 * @return {object|null}
 *
 * @spec openspec/changes/archive/2026-11-25-sharing-generated-api-docs/tasks.md#task-3-api-documentation-page
 */
export function readDocument(data) {
	let document = data
	if (typeof document === 'string') {
		try {
			document = JSON.parse(document)
		} catch {
			return null
		}
	}
	if (
		document === null
		|| typeof document !== 'object'
		|| Array.isArray(document)
	) {
		return null
	}
	return typeof document.openapi === 'string'
		|| typeof document.swagger === 'string'
		? document
		: null
}

/**
 * The message for a document that could not be loaded.
 *
 * @param {{response?: {status?: number}}|null|undefined} error The request error, if any.
 * @param {function(string): string} translate Translates an English text.
 * @return {string}
 *
 * @spec openspec/changes/archive/2026-11-25-sharing-generated-api-docs/tasks.md#task-3-api-documentation-page
 */
export function documentFailure(error, translate) {
	const status = error?.response?.status
	if (status === 404) {
		return translate('This API document is not available on this instance.')
	}
	if (status === 401 || status === 403) {
		return translate('You may not read this API document.')
	}
	return translate('The API document could not be loaded.')
}
