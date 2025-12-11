/**
 * Organisatie Store Module
 *
 * Manages organisatie data and contactpersonen operations
 *
 * @category Store
 * @package
 * @author Ruben Linde
 * @copyright 2024
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @link https://github.com/opencatalogi/softwarecatalog
 */

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

export const useOrganisatieStore = defineStore('organisatie', {
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
		 * @param {object} state - The store state
		 * @return {Array} Array of contactpersonen
		 */
		getContactpersonen: (state) => state.contactpersonen,

		/**
		 * Check if store is loading
		 * @param {object} state - The store state
		 * @return {boolean} Loading state
		 */
		isLoading: (state) => state.loading,

		/**
		 * Get current error
		 * @param {object} state - The store state
		 * @return {string|null} Current error message
		 */
		getError: (state) => state.error,

		/**
		 * Get available groups for user assignment
		 * @param {object} state - The store state
		 * @return {Array} Available groups
		 */
		getAvailableGroups: (state) => state.availableGroups,
	},

	actions: {
		/**
		 * Fetch contactpersonen for an organisation
		 * @param {string} organisationId - The organisation ID
		 * @return {Promise<void>}
		 */
		async fetchContactpersonen(organisationId) {
			this.loading = true
			this.error = null

			try {
				const url = generateUrl('/apps/softwarecatalog/api/contactpersonen/organisation/{organisationId}', {
					organisationId,
				})

				const response = await fetch(url, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				if (data.success) {
					this.contactpersonen = data.contactpersonen || []
				} else {
					throw new Error(data.message || 'Failed to fetch contactpersonen')
				}
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
		 * @param {string} contactpersoonId - The contactpersoon ID
		 * @return {Promise<object>} Result of conversion
		 */
		async convertToUser(contactpersoonId) {
			// Don't set global loading state for individual contactpersoon actions
			// The component will handle its own loading state
			this.error = null

			try {
				const url = generateUrl('/apps/softwarecatalog/api/contactpersonen/{contactpersoonId}/convert-to-user', {
					contactpersoonId,
				})

				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				if (data.success) {
					// Return the full response data including the updated contactpersoon object
					// The component will handle updating the local data
					return data
				} else {
					throw new Error(data.message || 'Failed to convert to user')
				}
			} catch (error) {
				console.error('Error converting to user:', error)
				this.error = error.message
				throw error
			} finally {
				// Don't reset global loading state since we didn't set it
			}
		},

		/**
		 * Change user password
		 * @param {string} username - The username
		 * @param {string} newPassword - The new password
		 * @return {Promise<object>} Result of password change
		 */
		async changePassword(username, newPassword) {
			// Don't set global loading state for individual contactpersoon actions
			this.error = null

			try {
				const url = generateUrl('/apps/softwarecatalog/api/contactpersonen/change-password')

				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({
						username,
						newPassword,
					}),
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				if (!data.success) {
					throw new Error(data.message || 'Failed to change password')
				}

				return data
			} catch (error) {
				console.error('Error changing password:', error)
				this.error = error.message
				throw error
			} finally {
				// Don't reset global loading state since we didn't set it
			}
		},

		/**
		 * Update user groups
		 * @param {string} username - The username
		 * @param {Array} groups - Array of group names
		 * @return {Promise<object>} Result of group update
		 */
		async updateUserGroups(username, groups) {
			// Don't set global loading state for individual contactpersoon actions
			this.error = null

			try {
				const url = generateUrl('/apps/softwarecatalog/api/contactpersonen/update-groups')

				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({
						username,
						groups,
					}),
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				if (data.success) {
					// Update the contactpersoon in the local state
					const contactpersoon = this.contactpersonen.find(cp => cp.user.username === username)
					if (contactpersoon) {
						contactpersoon.user.groups = data.groups || []
					}
					return data
				} else {
					throw new Error(data.message || 'Failed to update user groups')
				}
			} catch (error) {
				console.error('Error updating user groups:', error)
				this.error = error.message
				throw error
			} finally {
				// Don't reset global loading state since we didn't set it
			}
		},

		/**
		 * Fetch user info and available groups for a specific contactpersoon
		 * @param {string} contactpersoonId - The contactpersoon ID
		 * @return {Promise<object>} User info and available groups
		 */
		async fetchUserInfo(contactpersoonId) {
			try {
				const url = generateUrl('/apps/softwarecatalog/api/contactpersonen/{contactpersoonId}/user-info', {
					contactpersoonId,
				})

				const response = await fetch(url, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				if (data.success) {
					this.availableGroups = data.availableGroups || []
					return data.userInfo || {}
				} else {
					throw new Error(data.message || 'Failed to fetch user info')
				}
			} catch (error) {
				console.error('Error fetching user info:', error)
				this.error = error.message
				this.availableGroups = []
				throw error
			}
		},

		/**
		 * Fetch contact persons with user details for an organization
		 * @param {string} organizationUuid - The organization UUID
		 * @return {Promise<Array>} Array of contact persons with user details
		 */
		async fetchContactPersonsWithUserDetails(organizationUuid) {
			try {
				const url = generateUrl(`/apps/softwarecatalog/api/contactpersonen/organisation/${organizationUuid}/with-user-details`)

				const response = await fetch(url, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				if (data.success) {
					console.info('Successfully fetched contact persons with user details:', data)
					return data.data || []
				} else {
					throw new Error(data.message || 'Failed to fetch contact persons with user details')
				}
			} catch (error) {
				console.error('Error fetching contact persons with user details:', error)
				this.error = error.message
				return []
			}
		},

		/**
		 * Fetch available groups for user assignment (fallback method)
		 * @return {Promise<void>}
		 */
		async fetchAvailableGroups() {
			try {
				const url = generateUrl('/apps/softwarecatalog/api/contactpersonen/available-groups')

				const response = await fetch(url, {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
				})

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				const data = await response.json()

				if (data.success) {
					this.availableGroups = data.groups || []
				} else {
					throw new Error(data.message || 'Failed to fetch available groups')
				}
			} catch (error) {
				console.error('Error fetching available groups:', error)
				this.error = error.message
				this.availableGroups = []
			}
		},

		/**
		 * Clear error state
		 */
		clearError() {
			this.error = null
		},

		/**
		 * Clear contactpersonen data
		 */
		clearContactpersonen() {
			this.contactpersonen = []
		},

		/**
		 * Disable a user account
		 * @param {string} contactpersoonId - The contactpersoon UUID to disable
		 * @return {Promise<void>}
		 */
		async disableUser(contactpersoonId) {
			try {
				const response = await fetch(`/index.php/apps/softwarecatalog/api/contactpersonen/${encodeURIComponent(contactpersoonId)}/disable`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
				})

				if (!response.ok) {
					const errorData = await response.json()
					throw new Error(errorData.message || 'Failed to disable user')
				}
			} catch (error) {
				console.error('Error disabling user:', error)
				throw error
			}
		},

		/**
		 * Enable a user account
		 * @param {string} contactpersoonId - The contactpersoon UUID to enable
		 * @return {Promise<void>}
		 */
		async enableUser(contactpersoonId) {
			try {
				const response = await fetch(`/index.php/apps/softwarecatalog/api/contactpersonen/${encodeURIComponent(contactpersoonId)}/enable`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
				})

				if (!response.ok) {
					const errorData = await response.json()
					throw new Error(errorData.message || 'Failed to enable user')
				}
			} catch (error) {
				console.error('Error enabling user:', error)
				throw error
			}
		},

		/**
		 * Get user info for multiple contactpersonen in one request
		 * @param {Array<string>} contactpersoonIds - Array of contactpersoon UUIDs
		 * @return {Promise<object>} Bulk user info object keyed by contactpersoon ID
		 */
		async getBulkUserInfo(contactpersoonIds) {
			try {
				console.info('Store: Getting bulk user info for IDs:', contactpersoonIds)

				const response = await fetch('/index.php/apps/softwarecatalog/api/contactpersonen/bulk-user-info', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						contactpersoonIds,
					}),
				})

				console.info('Store: Bulk user info response status:', response.status)

				if (!response.ok) {
					const errorData = await response.json()
					console.error('Store: Bulk user info error response:', errorData)
					throw new Error(errorData.message || 'Failed to get bulk user info')
				}

				const data = await response.json()
				console.info('Store: Bulk user info success response:', data)
				return data.userInfo || {}
			} catch (error) {
				console.error('Store: Error getting bulk user info:', error)
				throw error
			}
		},
	},
})
