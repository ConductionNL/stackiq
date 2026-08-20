/**
 * Pure helpers for GrantOrganisationAccessModal.vue — extracted so the
 * grant/revoke payload logic is unit-testable without mounting the
 * component (matches the project's established "pure-logic unit tests"
 * convention).
 *
 * @module modals/grantOrganisationAccess
 * @author Ruben Linde
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * @spec openspec/specs/multi-org-membership/spec.md#requirement-granting-access-must-only-target-an-existing-nextcloud-user-req-005
 */

/**
 * Extract a Nextcloud user id from whatever shape `NcSelectUsers` emits.
 * Different versions of the component surface the picked option as `.id`,
 * `.user`, or (defensively) a bare string — this normalises all three so
 * the grant call always sends a plain user id string.
 *
 * @param {object|string|null} selection The `NcSelectUsers` v-model value.
 * @return {string|null} The resolved user id, or null when nothing is selected.
 */
export function extractUserId(selection) {
	if (!selection) return null
	if (typeof selection === 'string') return selection
	return selection.id || selection.user || null
}

/**
 * Remove a user id from a member list — used to update the locally-rendered
 * member list after a successful revoke, without a full refetch.
 *
 * @param {string[]} members The current member list.
 * @param {string} userId The user id to remove.
 * @return {string[]} A new array with the user id removed.
 */
export function removeMember(members, userId) {
	return (members || []).filter((id) => id !== userId)
}
