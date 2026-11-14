/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The user details of a contact person in the contact person list: shaped
 * from the organisation data (API user first, then the contact's own data)
 * and updated from bulk user info.
 *
 * @spec openspec/specs/fe-organizations/spec.md
 */

/**
 * What a contact person is processed with.
 *
 * @typedef {object} ContactSummary
 * @property {string} contactId The contact person's id or uuid.
 * @property {object} data The contact person's data.
 * @property {boolean} hasUser Whether the contact person has a user.
 */

/**
 * The key a contact person is known by: its id, else its uuid.
 *
 * @param {object} contactpersoon The contact person.
 * @return {string} The key.
 */
export function contactpersoonKey(contactpersoon) {
	return contactpersoon.id || contactpersoon.uuid
}

/**
 * The groups of a contact person's user: from the API user, else from its
 * data, else none.
 *
 * @param {object} contactpersoon The contact person.
 * @param {object} data The contact person's data.
 * @return {Array} The groups.
 */
function userGroups(contactpersoon, data) {
	return contactpersoon.user?.groups || data.groups || []
}

/**
 * Whether a contact person's user is disabled: per the API user, else per
 * its data.
 *
 * @param {object} contactpersoon The contact person.
 * @param {object} data The contact person's data.
 * @return {boolean} Whether it is.
 */
function userDisabled(contactpersoon, data) {
	return contactpersoon.user?.disabled || data.disabled || false
}

/**
 * The user of a processed contact person.
 *
 * @param {object} contactpersoon The contact person.
 * @param {ContactSummary} summary Its id, data and whether it has a user.
 * @return {object} The user.
 */
export function contactUser(contactpersoon, summary) {
	return {
		hasUser: summary.hasUser,
		username: summary.data.username || '',
		// Use groups from API user object if available, otherwise from data.
		groups: userGroups(contactpersoon, summary.data),
		// Use user.disabled from API, fallback to data.disabled.
		disabled: userDisabled(contactpersoon, summary.data),
	}
}

/**
 * Sets a contact person's user and data fields from bulk user info; an
 * enabled user is not disabled.
 *
 * @param {object} contactpersoon The contact person, updated in place.
 * @param {object} userInfo The user info.
 */
export function applyUserInfo(contactpersoon, userInfo) {
	if (!contactpersoon.user) {
		contactpersoon.user = {}
	}
	assignUser(contactpersoon.user, userInfo)
	if (contactpersoon.data) {
		assignUserData(contactpersoon.data, userInfo)
	}
}

/**
 * Sets the user fields of a contact person's user.
 *
 * @param {object} user The user, updated in place.
 * @param {object} userInfo The user info.
 */
function assignUser(user, userInfo) {
	user.hasUser = userInfo.hasUser
	user.username = userInfo.username
	user.groups = userInfo.groups || []
	user.disabled = !userInfo.enabled // Map enabled to disabled.
	user.displayName = userInfo.displayName
	user.lastLogin = userInfo.lastLogin
}

/**
 * Sets the user fields kept in a contact person's data.
 *
 * @param {object} data The data, updated in place.
 * @param {object} userInfo The user info.
 */
function assignUserData(data, userInfo) {
	data.disabled = !userInfo.enabled // Map enabled to disabled.
	data.groups = userInfo.groups || [] // Also set groups in data.
	data.username = userInfo.username // Also set username in data.
}
