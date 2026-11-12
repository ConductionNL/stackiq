/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The request the object view sends to publish or depublish an object: the
 * object with the chosen date when the modal has one (PUT to the object),
 * else the publish or depublish endpoint (POST, or an empty PUT from the
 * modal), and the error for a refused response.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { getRequestToken } from '@nextcloud/auth'

/**
 * A publish or depublish action.
 *
 * @typedef {object} PublicationAction
 * @property {string} verb publish or depublish.
 * @property {string} field The @self field the date goes in: published or depublished.
 * @property {boolean} modal Whether the action comes from the date modal.
 * @property {Date|string|null} date The date chosen in the modal.
 */

/**
 * Whether an action carries a date from the modal.
 *
 * @param {PublicationAction} action The action.
 * @return {*} Truthy when it does.
 */
function isDated(action) {
	return action.modal && action.date
}

/**
 * The endpoint of an action, from the object's URL: the object itself for a
 * dated action, else its publish or depublish endpoint.
 *
 * @param {string} objectUrl The object's API URL.
 * @param {PublicationAction} action The action.
 * @return {string} The endpoint.
 */
export function publicationEndpoint(objectUrl, action) {
	return isDated(action) ? objectUrl : `${objectUrl}/${action.verb}`
}

/**
 * The object with the action's date in its @self, as an ISO string for a
 * Date.
 *
 * @param {object} object The object.
 * @param {PublicationAction} action The action.
 * @return {object} The body.
 */
function datedObject(object, action) {
	return {
		...object,
		'@self': {
			...object['@self'],
			[action.field]:
				action.date instanceof Date
					? action.date.toISOString()
					: action.date,
		},
	}
}

/**
 * The fetch options of an action: a JSON PUT from the modal (the dated
 * object, or {} without a date), else a bare POST.
 *
 * @param {object} object The object.
 * @param {PublicationAction} action The action.
 * @return {object} The options.
 */
export function publicationInit(object, action) {
	const body = isDated(action) ? datedObject(object, action) : {}
	return {
		method: action.modal ? 'PUT' : 'POST',
		headers: action.modal
			? { 'Content-Type': 'application/json', requesttoken: getRequestToken() }
			: { requesttoken: getRequestToken() },
		body: action.modal ? JSON.stringify(body) : undefined,
	}
}

/**
 * Throws the error of a refused publish or depublish response.
 *
 * @param {Response} response The response.
 * @param {string} verb publish or depublish.
 * @return {Promise<void>} Resolves for an accepted response.
 */
export async function assertPublicationDone(response, verb) {
	if (response.ok) {
		return
	}
	const errorText = await response.text()
	throw new Error(
		`Failed to ${verb} object: ${response.status} ${response.statusText} - ${errorText}`,
	)
}
