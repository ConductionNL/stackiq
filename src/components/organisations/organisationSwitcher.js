/**
 * Pure helpers for OrganisationSwitcher.vue — extracted so the switch
 * decision logic is unit-testable without mounting the component (matches
 * the project's established "pure-logic unit tests" convention).
 *
 * @module components/organisations/organisationSwitcher
 * @author Ruben Linde
 * @copyright 2026 Conduction B.V.
 * @license AGPL-3.0-or-later
 *
 * @spec openspec/specs/multi-org-membership/spec.md#requirement-the-organisation-switcher-must-list-only-the-authenticated-user-s-own-organisations-req-003
 */

/**
 * Resolve the display name of the active organisation.
 *
 * @param {Array<{uuid: string, naam: string}>} organisations The user's own organisations.
 * @param {string|null} activeOrganisationUuid The currently-active organisation's UUID.
 * @param {string} fallback Text to show when no active organisation resolves.
 * @return {string} The resolved display name, or the fallback.
 */
export function resolveActiveOrganisationName(organisations, activeOrganisationUuid, fallback) {
	const active = (organisations || []).find((org) => org.uuid === activeOrganisationUuid)
	return active?.naam || fallback
}

/**
 * The user's organisations excluding the currently-active one — the
 * switch-target list.
 *
 * @param {Array<{uuid: string}>} organisations The user's own organisations.
 * @param {string|null} activeOrganisationUuid The currently-active organisation's UUID.
 * @return {Array} The organisations other than the active one.
 */
export function resolveOtherOrganisations(organisations, activeOrganisationUuid) {
	return (organisations || []).filter((org) => org.uuid !== activeOrganisationUuid)
}

/**
 * Resolve the error message for a refused switch, or null when the switch
 * succeeded. Never trusts the response as successful without `responseOk`
 * being explicitly true — REQ-001's negative scenario (switch to a
 * non-member organisation is refused) depends on this always surfacing an
 * error instead of silently proceeding.
 *
 * @param {boolean} responseOk Whether the HTTP response was ok (2xx).
 * @param {object|null} body The parsed response body, if any.
 * @param {string} fallbackMessage Message to use when the body carries no `error` field.
 * @return {string|null} The error message, or null when the switch succeeded.
 */
export function resolveSwitchError(responseOk, body, fallbackMessage) {
	if (responseOk === true) return null
	return body?.error || fallbackMessage
}
