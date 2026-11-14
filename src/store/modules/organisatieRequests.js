/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Request helpers shared by the organisatie store's API actions: the
 * request options, and the checks that turn an answer into data or an Error.
 *
 * @spec openspec/specs/fe-stores/spec.md
 */

/**
 * Options for a JSON request carrying the Nextcloud request token.
 *
 * @param {string} method HTTP method.
 * @param {object|Array} [body] The body, JSON-encoded when given.
 * @return {object} fetch options.
 */
export function tokenRequest(method, body) {
	const options = {
		method,
		headers: {
			'Content-Type': 'application/json',
			requesttoken: OC.requestToken,
		},
	}
	if (body !== undefined) {
		options.body = JSON.stringify(body)
	}
	return options
}

/**
 * The Error for a failed answer: the server's message, else the HTTP status.
 *
 * @param {Response} response The answer.
 * @param {string} [message] The server's message.
 * @return {Error}
 */
export function httpError(response, message) {
	return new Error(message || `HTTP error! status: ${response.status}`)
}

/**
 * The JSON body of a successful HTTP answer; a failed one throws its status.
 *
 * @param {Response} response The answer.
 * @return {Promise<object>} The body.
 */
export async function okJson(response) {
	if (!response.ok) {
		throw httpError(response)
	}
	return response.json()
}

/**
 * The body when it reports success; else an Error with its message.
 *
 * @param {object} data The body.
 * @param {string} fallbackMessage The message when the body has none.
 * @return {object} The body.
 */
export function requireSuccess(data, fallbackMessage) {
	if (!data.success) {
		throw new Error(data.message || fallbackMessage)
	}
	return data
}

/**
 * The JSON body; a failed answer throws the body's message.
 *
 * @param {Response} response The answer.
 * @return {Promise<object>} The body.
 */
export async function messageJson(response) {
	const data = await response.json()
	if (!response.ok) {
		throw httpError(response, data.message)
	}
	return data
}

/**
 * The JSON body, empty when unreadable; a failed answer throws the body's error.
 *
 * @param {Response} response The answer.
 * @return {Promise<object>} The body.
 */
export async function lenientJson(response) {
	const data = await response.json().catch(() => ({}))
	if (!response.ok) {
		throw httpError(response, data.error)
	}
	return data
}

/**
 * Throw the body's message, or the fallback, when the answer failed.
 *
 * @param {Response} response The answer.
 * @param {string} fallbackMessage The message when the body has none.
 * @return {Promise<void>}
 */
export async function assertOk(response, fallbackMessage) {
	if (!response.ok) {
		const errorData = await response.json()
		throw new Error(errorData.message || fallbackMessage)
	}
}
