/**
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Organisatie Store Module
 *
 * Manages organisatie data and contactpersonen operations
 *
 * @package
 * @author Ruben Linde
 * @copyright 2024
 * @license EUPL-1.2
 * @version 1.0.0
 * @see https://github.com/ConductionNL/stackiq
 */

import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import {
	assertOk,
	httpError,
	lenientJson,
	messageJson,
	okJson,
	plainPost,
	requireSuccess,
	tokenRequest,
} from './organisatieRequests.js'

export const useOrganisatieStore = defineStore('organization', {
	state: () => ({
		/** @type {Array} */
		contactpersonen: [],
		/** @type {boolean} */
		loading: false,
		/** @type {string|null} */
		error: null,
		/** @type {Array} */
		availableGroups: [],
	}),

	getters: {
		/**
		 * Get contactpersonen for current organisation
		 *
		 * @param {object} state - The store state
		 * @return {Array} Array of contactpersonen
		 */
		getContactpersonen: (state) => state.contactpersonen,

		/**
		 * Check if store is loading
		 *
		 * @param {object} state - The store state
		 * @return {boolean} Loading state
		 */
		isLoading: (state) => state.loading,

		/**
		 * Get current error
		 *
		 * @param {object} state - The store state
		 * @return {string|null} Current error message
		 */
		getError: (state) => state.error,

		/**
		 * Get available groups for user assignment
		 *
		 * @param {object} state - The store state
		 * @return {Array} Available groups
		 */
		getAvailableGroups: (state) => state.availableGroups,
	},

	actions: {
		/**
		 * Fetch contactpersonen for an organisation
		 *
		 * @param {string} organisationId - The organisation ID
		 * @return {Promise<void>}
		 * @spec openspec/specs/fe-stores/spec.md
		 */
		async fetchContactpersonen(organisationId) {
			this.loading = true
			this.error = null

			try {
				const url = generateUrl(
					'/apps/stackiq/api/contactpersonen/organisation/{organisationId}',
					{
						organisationId,
					},
				)

				const response = await fetch(url, tokenRequest('GET'))
				const data = requireSuccess(await okJson(response), 'Failed to fetch contactpersonen')
				this.contactpersonen = data.contactpersonen || []
			} catch (error) {
				console.error('Error fetching contactpersonen:', error)
				this.error = error.message
				this.contactpersonen = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Convert a contactpersoon to a user account
		 *
		 * @param {string} contactpersoonId - The contactpersoon ID
		 * @return {Promise<object>} Result of conversion
		 * @spec openspec/specs/fe-stores/spec.md
		 */
		async convertToUser(contactpersoonId) {
			// Don't set global loading state for individual contactpersoon actions
			// The component will handle its own loading state
			this.error = null

			try {
				const url = generateUrl(
					'/apps/stackiq/api/contactpersonen/{contactpersoonId}/convert-to-user',
					{
						contactpersoonId,
					},
				)

				const response = await fetch(url, tokenRequest('POST'))
				const data = await response.json()

				if (!response.ok || !data.success) {
					throw httpError(response, data.message)
				}

				// Return the full response data including the updated contactpersoon object
				// The component will handle updating the local data
				return data
			} catch (error) {
				console.error('Error converting to user:', error)
				this.error = error.message
				throw error
			}
		},

		/**
		 * Change user password
		 *
		 * @param {string} username - The username
		 * @param {string} newPassword - The new password
		 * @return {Promise<object>} Result of password change
		 * @spec openspec/specs/fe-stores/spec.md
		 */
		async changePassword(username, newPassword) {
			// Don't set global loading state for individual contactpersoon actions
			this.error = null

			try {
				const url = generateUrl(
					'/apps/stackiq/api/contactpersonen/change-password',
				)

				const response = await fetch(url, tokenRequest('POST', { username, newPassword }))
				return requireSuccess(await okJson(response), 'Failed to change password')
			} catch (error) {
				console.error('Error changing password:', error)
				this.error = error.message
				throw error
			}
		},

		/**
		 * Update user groups
		 *
		 * @param {string} username - The username
		 * @param {Array} groups - Array of group names
		 * @return {Promise<object>} Result of group update
		 * @spec openspec/specs/fe-stores/spec.md
		 */
		async updateUserGroups(username, groups) {
			// Don't set global loading state for individual contactpersoon actions
			this.error = null

			try {
				const url = generateUrl(
					'/apps/stackiq/api/contactpersonen/update-groups',
				)

				const response = await fetch(url, tokenRequest('POST', { username, groups }))
				const data = requireSuccess(await okJson(response), 'Failed to update user groups')

				// Update the contactpersoon in the local state
				const contactpersoon = this.contactpersonen.find(
					(cp) => cp.user.username === username,
				)
				if (contactpersoon) {
					contactpersoon.user.groups = data.groups || []
				}
				return data
			} catch (error) {
				console.error('Error updating user groups:', error)
				this.error = error.message
				throw error
			}
		},

		/**
		 * Fetch user info and available groups for a specific contactpersoon
		 *
		 * @param {string} contactpersoonId - The contactpersoon ID
		 * @return {Promise<object>} User info and available groups
		 * @spec openspec/specs/fe-stores/spec.md
		 */
		async fetchUserInfo(contactpersoonId) {
			try {
				const url = generateUrl(
					'/apps/stackiq/api/contactpersonen/{contactpersoonId}/user-info',
					{
						contactpersoonId,
					},
				)

				const response = await fetch(url, tokenRequest('GET'))
				const data = requireSuccess(await okJson(response), 'Failed to fetch user info')
				this.availableGroups = data.availableGroups || []
				return data.userInfo || {}
			} catch (error) {
				console.error('Error fetching user info:', error)
				this.error = error.message
				this.availableGroups = []
				throw error
			}
		},

		/**
		 * Fetch contact persons with user details for an organization
		 *
		 * @param {string} organizationUuid - The organization UUID
		 * @return {Promise<Array>} Array of contact persons with user details
		 * @spec openspec/specs/fe-stores/spec.md
		 */
		async fetchContactPersonsWithUserDetails(organizationUuid) {
			try {
				const url = generateUrl(
					`/apps/stackiq/api/contactpersonen/organisation/${organizationUuid}/with-user-details`,
				)

				const response = await fetch(url, tokenRequest('GET'))
				const data = requireSuccess(await okJson(response), 'Failed to fetch contact persons with user details')
				console.info(
					'Successfully fetched contact persons with user details:',
					data,
				)
				return data.data || []
			} catch (error) {
				console.error(
					'Error fetching contact persons with user details:',
					error,
				)
				this.error = error.message
				return []
			}
		},

		/**
		 * Fetch available groups for user assignment (fallback method)
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/fe-stores/spec.md
		 */
		async fetchAvailableGroups() {
			try {
				const url = generateUrl(
					'/apps/stackiq/api/contactpersonen/available-groups',
				)

				const response = await fetch(url, tokenRequest('GET'))
				const data = requireSuccess(await okJson(response), 'Failed to fetch available groups')
				this.availableGroups = data.groups || []
			} catch (error) {
				console.error('Error fetching available groups:', error)
				this.error = error.message
				this.availableGroups = []
			}
		},

		/**
		 * Clear error state
		 *
		 * @spec openspec/specs/fe-stores/spec.md
		 */
		clearError() {
			this.error = null
		},

		/**
		 * Clear contactpersonen data
		 *
		 * @spec openspec/specs/fe-stores/spec.md
		 */
		clearContactpersonen() {
			this.contactpersonen = []
		},

		/**
		 * Disable a user account
		 *
		 * @param {string} contactpersoonId - The contactpersoon UUID to disable
		 * @return {Promise<void>}
		 * @spec openspec/specs/fe-stores/spec.md
		 */
		async disableUser(contactpersoonId) {
			try {
				const response = await fetch(
					`/index.php/apps/stackiq/api/contactpersonen/${encodeURIComponent(contactpersoonId)}/disable`,
					plainPost(),
				)
				await assertOk(response, 'Failed to disable user')
			} catch (error) {
				console.error('Error disabling user:', error)
				throw error
			}
		},

		/**
		 * Enable a user account
		 *
		 * @param {string} contactpersoonId - The contactpersoon UUID to enable
		 * @return {Promise<void>}
		 * @spec openspec/specs/fe-stores/spec.md
		 */
		async enableUser(contactpersoonId) {
			try {
				const response = await fetch(
					`/index.php/apps/stackiq/api/contactpersonen/${encodeURIComponent(contactpersoonId)}/enable`,
					plainPost(),
				)
				await assertOk(response, 'Failed to enable user')
			} catch (error) {
				console.error('Error enabling user:', error)
				throw error
			}
		},

		/**
		 * Preview an organisation merge: per-relation-type counts, no writes.
		 * Admin-only server-side (403 surfaces as a thrown Error here).
		 *
		 * @param {string} sourceUuid - The source organisation UUID (merged away).
		 * @param {string} targetUuid - The target organisation UUID (merge destination).
		 * @return {Promise<object>} `{sourceUuid, targetUuid, counts, blockers}`.
		 * @spec openspec/specs/organisation-merge/spec.md#requirement-the-system-shall-preview-a-merge-with-per-relation-type-counts-before-any-write
		 */
		async dryRunMerge(sourceUuid, targetUuid) {
			const url = generateUrl(
				'/apps/stackiq/api/organisaties/{sourceUuid}/merge/dry-run',
				{
					sourceUuid,
				},
			)

			return messageJson(await fetch(url, tokenRequest('POST', { targetUuid })))
		},

		/**
		 * Execute an organisation merge: re-point every relation type, migrate
		 * NC group membership, tombstone the source. Idempotent — safe to call
		 * again against a partially or fully completed merge.
		 *
		 * @param {string} sourceUuid - The source organisation UUID (merged away).
		 * @param {string} targetUuid - The target organisation UUID (merge destination).
		 * @return {Promise<object>} `{operationId, sourceUuid, targetUuid, status, counts}`.
		 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
		 */
		async executeMerge(sourceUuid, targetUuid) {
			const url = generateUrl(
				'/apps/stackiq/api/organisaties/{sourceUuid}/merge',
				{
					sourceUuid,
				},
			)

			// 409 (blockers) still returns a structured body — surface its message.
			return messageJson(await fetch(url, tokenRequest('POST', { targetUuid, confirm: true })))
		},

		/**
		 * Get user info for multiple contactpersonen in one request
		 *
		 * @param {Array<string>} contactpersoonIds - Array of contactpersoon UUIDs
		 * @return {Promise<object>} Bulk user info object keyed by contactpersoon ID
		 * @spec openspec/specs/fe-stores/spec.md
		 */
		async getBulkUserInfo(contactpersoonIds) {
			try {
				console.info(
					'Store: Getting bulk user info for IDs:',
					contactpersoonIds,
				)

				const response = await fetch(
					'/index.php/apps/stackiq/api/contactpersonen/bulk-user-info',
					plainPost({ contactpersoonIds }),
				)

				console.info(
					'Store: Bulk user info response status:',
					response.status,
				)

				if (!response.ok) {
					const errorData = await response.json()
					console.error('Store: Bulk user info error response:', errorData)
					throw new Error(
						errorData.message || 'Failed to get bulk user info',
					)
				}

				const data = await response.json()
				console.info('Store: Bulk user info success response:', data)
				return data.userInfo || {}
			} catch (error) {
				console.error('Store: Error getting bulk user info:', error)
				throw error
			}
		},

		// ==========================================
		// Self-service colleague access (multi-org-membership)
		// ==========================================

		/**
		 * Fetch an organisation's current members (Nextcloud user ids).
		 * Consumes OpenRegister's own `GET /api/organisations/{uuid}`
		 * directly — already gated by `hasAccessToOrganisation()` — rather
		 * than adding a Stackiq read endpoint for the same data.
		 *
		 * @param {string} uuid The organisation UUID.
		 * @return {Promise<string[]>} The member user ids.
		 * @spec openspec/specs/multi-org-membership/spec.md#requirement-membership-mutations-must-be-delegated-to-openregister-s-organisationservice-not-reimplemented-req-006
		 */
		async fetchMembers(uuid) {
			const url = generateUrl('/apps/openregister/api/organisations/{uuid}', {
				uuid,
			})

			const response = await fetch(url, tokenRequest('GET'))

			if (!response.ok) {
				const errorData = await response.json().catch(() => ({}))
				throw httpError(response, errorData.error)
			}

			const data = await response.json()
			return data?.organisation?.users || []
		},

		/**
		 * Grant an existing Nextcloud user access to an organisation.
		 * Server-side, this is authorized by Stackiq's
		 * `beheerder`-of-this-organisation guard, then delegated to
		 * OpenRegister's own `OrganisationService::joinOrganisation()`.
		 *
		 * @param {string} uuid The organisation UUID.
		 * @param {string} userId The existing Nextcloud user id to grant access to.
		 * @return {Promise<object>} The success response body.
		 * @spec openspec/specs/multi-org-membership/spec.md#requirement-granting-or-revoking-organisation-access-must-be-restricted-to-a-beheerder-of-that-organisation-req-004
		 */
		async grantAccess(uuid, userId) {
			const url = generateUrl(
				'/apps/stackiq/api/organisations/{uuid}/members',
				{ uuid },
			)

			return lenientJson(await fetch(url, tokenRequest('POST', { userId })))
		},

		/**
		 * Revoke an existing member's access to an organisation.
		 *
		 * @param {string} uuid The organisation UUID.
		 * @param {string} userId The Nextcloud user id to revoke access from.
		 * @return {Promise<object>} The success response body.
		 * @spec openspec/specs/multi-org-membership/spec.md#requirement-granting-or-revoking-organisation-access-must-be-restricted-to-a-beheerder-of-that-organisation-req-004
		 */
		async revokeAccess(uuid, userId) {
			const url = generateUrl(
				'/apps/stackiq/api/organisations/{uuid}/members/{userId}',
				{ uuid, userId },
			)

			return lenientJson(await fetch(url, tokenRequest('DELETE')))
		},
	},
})
