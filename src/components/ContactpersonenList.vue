<template>
	<div class="contactpersonen-list">
		<div v-if="loading" class="loading">
			<NcLoadingIcon :size="20" />
			{{ t('softwarecatalog', 'Loading contactpersonen...') }}
		</div>

		<div v-else-if="error" class="error">
			<NcNoteCard type="error">
				{{ error }}
			</NcNoteCard>
		</div>

		<div v-else-if="contactpersonen.length === 0" class="empty">
			<NcEmptyContent
				:name="t('softwarecatalog', 'No contactpersonen found')"
				:description="
					t('softwarecatalog', 'This organisation has no contactpersonen.')
				">
				<template #icon>
					<AccountMultiple :size="64" />
				</template>
			</NcEmptyContent>
		</div>

		<div v-else class="contactpersonen-table">
			<table class="compact-table">
				<thead>
					<tr>
						<th scope="col">{{ t('softwarecatalog', 'Name') }}</th>
						<th scope="col">{{ t('softwarecatalog', 'Email') }}</th>
						<th scope="col">{{ t('softwarecatalog', 'Status') }}</th>
						<th scope="col">{{ t('softwarecatalog', 'Groups') }}</th>
						<th scope="col">{{ t('softwarecatalog', 'Actions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="contactpersoon in contactpersonen"
						:key="contactpersoon.id">
						<td class="name-cell">
							{{ getContactpersoonName(contactpersoon) }}
						</td>
						<td class="email-cell">
							{{
								contactpersoon.data.email
								|| contactpersoon.data['e-mailadres']
								|| '-'
							}}
						</td>
						<td class="status-cell">
							<span
								v-if="
									contactpersoon.user.hasUser
									&& !contactpersoon.user.disabled
								"
								class="status-chip status-success">
								{{ t('softwarecatalog', 'User') }}
							</span>
							<span
								v-else-if="
									contactpersoon.user.hasUser
									&& contactpersoon.user.disabled
								"
								class="status-chip status-warning">
								{{ t('softwarecatalog', 'Disabled') }}
							</span>
							<span v-else class="status-chip status-tertiary">
								{{ t('softwarecatalog', 'No User') }}
							</span>
						</td>
						<td class="groups-cell">
							<div
								v-if="
									contactpersoon.user.hasUser
									&& getFilteredGroups(contactpersoon).length > 0
								"
								class="groups">
								<span
									v-for="group in getFilteredGroups(
										contactpersoon,
									)"
									:key="group"
									class="group-chip">
									{{ formatGroupName(group) }}
								</span>
							</div>
							<span v-else class="no-groups">-</span>
						</td>
						<td class="actions-cell">
							<NcActions>
								<!-- Convert to User Action -->
								<NcActionButton
									v-if="!contactpersoon.user.hasUser"
									:closeAfterClick="true"
									:disabled="contactpersoon.loading"
									@click="convertToUser(contactpersoon)">
									<template #icon>
										<NcLoadingIcon
											v-if="contactpersoon.loading"
											:size="20" />
										<AccountPlus v-else :size="20" />
									</template>
									{{
										contactpersoon.loading
											? t('softwarecatalog', 'Converting...')
											: t('softwarecatalog', 'Convert to User')
									}}
								</NcActionButton>

								<!-- Change Password Action -->
								<NcActionButton
									v-if="contactpersoon.user.hasUser"
									:closeAfterClick="true"
									@click="openPasswordDialog(contactpersoon)">
									<template #icon>
										<Key :size="20" />
									</template>
									{{ t('softwarecatalog', 'Change Password') }}
								</NcActionButton>

								<!-- Manage Groups Action -->
								<NcActionButton
									v-if="contactpersoon.user.hasUser"
									:closeAfterClick="true"
									@click="openGroupsDialog(contactpersoon)">
									<template #icon>
										<AccountGroup :size="20" />
									</template>
									{{ t('softwarecatalog', 'Manage Groups') }}
								</NcActionButton>

								<!-- Disable User Action -->
								<NcActionButton
									v-if="
										contactpersoon.user.hasUser
										&& !contactpersoon.user.disabled
									"
									:closeAfterClick="true"
									@click="disableUser(contactpersoon)">
									<template #icon>
										<CloseCircle :size="20" />
									</template>
									{{ t('softwarecatalog', 'Disable User') }}
								</NcActionButton>

								<!-- Enable User Action -->
								<NcActionButton
									v-if="
										contactpersoon.user.hasUser
										&& contactpersoon.user.disabled
									"
									:closeAfterClick="true"
									@click="enableUser(contactpersoon)">
									<template #icon>
										<CheckCircle :size="20" />
									</template>
									{{ t('softwarecatalog', 'Enable User') }}
								</NcActionButton>
							</NcActions>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Password Change Dialog — own file per ADR-004/ADR-012 -->
		<ChangePasswordDialog
			v-if="showPasswordDialog"
			:username="selectedContactpersoon?.user?.username"
			@saved="closePasswordDialog"
			@close="closePasswordDialog" />

		<!-- Groups Management Dialog — own file per ADR-004/ADR-012 -->
		<ManageUserGroupsDialog
			v-if="showGroupsDialog"
			:contactpersoon="selectedContactpersoon"
			@groupsLoaded="onGroupsLoaded"
			@saved="onGroupsSaved"
			@close="closeGroupsDialog" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import {
	NcActionButton,
	NcActions,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import Key from 'vue-material-design-icons/Key.vue'
import ChangePasswordDialog from '../dialogs/ChangePasswordDialog.vue'
import ManageUserGroupsDialog from '../dialogs/ManageUserGroupsDialog.vue'
import { useOrganisatieStore } from '../store/modules/organisatie.js'

export default {
	name: 'ContactpersonenList',

	components: {
		NcActions,
		NcActionButton,
		NcLoadingIcon,
		NcNoteCard,
		NcEmptyContent,
		AccountMultiple,
		AccountPlus,
		AccountGroup,
		Key,
		CheckCircle,
		CloseCircle,
		ChangePasswordDialog,
		ManageUserGroupsDialog,
	},

	props: {
		organisationId: {
			type: String,
			required: true,
		},

		organisationData: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			showPasswordDialog: false,
			showGroupsDialog: false,
			selectedContactpersoon: null,
			userStatusRefreshInProgress: false,
			userStatusRefreshTimeout: null,
			userInfoLoaded: false,
		}
	},

	computed: {
		/**
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		organisatieStore() {
			return useOrganisatieStore()
		},

		/**
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		contactpersonen() {
			// Use contactpersonen from organisation data if available, otherwise fall back to store
			if (
				this.organisationData.contactpersonen
				&& Array.isArray(this.organisationData.contactpersonen)
			) {
				return this.processContactpersonen(
					this.organisationData.contactpersonen,
				)
			}
			return this.organisatieStore.getContactpersonen
		},

		/**
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		loading() {
			return this.organisatieStore.isLoading
		},

		/**
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		error() {
			return this.organisatieStore.getError
		},

		/**
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		availableGroups() {
			return this.organisatieStore.getAvailableGroups
		},
	},

	/**
	 * @spec openspec/specs/fe-organizations/spec.md
	 */
	async mounted() {
		await this.loadData()
		// Load user info and groups to get status information
		await this.loadUserInfoAndGroups()
	},

	/**
	 * @spec openspec/specs/fe-organizations/spec.md
	 */
	beforeUnmount() {
		// Clean up timeouts to prevent memory leaks
		if (this.userStatusRefreshTimeout) {
			clearTimeout(this.userStatusRefreshTimeout)
		}
	},

	// Watchers removed to prevent infinite loops
	// User info and groups will be loaded only when explicitly requested

	methods: {
		/**
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		async loadData() {
			try {
				// Only fetch available groups, contactpersonen come from organisation data
				await this.organisatieStore.fetchAvailableGroups()

				// If no organisation data provided, fall back to fetching contactpersonen separately
				if (!this.organisationData.contactpersonen) {
					await this.organisatieStore.fetchContactpersonen(
						this.organisationId,
					)
				}
			} catch (error) {
				console.error('Error loading contactpersonen data:', error)
			}
		},

		/**
		 * Process contactpersonen data from organisation object to match expected format.
		 *
		 * @param {Array} rawContactpersonen - Raw contactpersonen data from organisation.
		 * @return {Array} Processed contactpersonen with user information.
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		processContactpersonen(rawContactpersonen) {
			return rawContactpersonen.map((contactpersoon) => {
				const contactId = contactpersoon.id || contactpersoon.uuid
				const data = contactpersoon.data || contactpersoon
				const hasUser = !!data.username

				// Debug logging to understand the data structure.
				console.info('Processing contactpersoon:', {
					contactId,
					data,
					hasUser,
					userFromAPI: contactpersoon.user,
					usernameFromData: data.username,
					disabledFromAPI: contactpersoon.user?.disabled,
					disabledFromData: data.disabled,
					groupsFromAPI: contactpersoon.user?.groups,
					groupsFromData: data.groups,
				})

				return {
					id: contactId,
					data,
					user: {
						hasUser,
						username: data.username || '',
						// Use groups from API user object if available, otherwise from data.
						groups: contactpersoon.user?.groups || data.groups || [],
						// Use user.disabled from API, fallback to data.disabled.
						disabled:
							contactpersoon.user?.disabled || data.disabled || false,
					},
					// Include loading state from organisation data.
					loading: contactpersoon.loading || false,
				}
			})
		},

		/**
		 * Load user info and available groups in parallel
		 *
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		async loadUserInfoAndGroups() {
			// Prevent multiple simultaneous calls
			if (this.userStatusRefreshInProgress) {
				console.info('User info loading already in progress, skipping...')
				return
			}

			// Prevent multiple calls per session
			if (this.userInfoLoaded) {
				console.info('User info already loaded in this session, skipping...')
				return
			}

			if (!this.organisationData || !this.organisationData.contactpersonen) {
				console.info('No organisation data available for user info loading')
				return
			}

			this.userStatusRefreshInProgress = true

			try {
				console.info(
					'Starting SINGLE parallel loading of user info and available groups',
				)

				// Get all contact person IDs, filter out empty ones
				const contactpersoonIds = this.organisationData.contactpersonen
					.map((cp) => cp.id || cp.uuid)
					.filter((id) => id && id.trim() !== '')

				console.info(
					'Loading user info for contactpersons:',
					contactpersoonIds.length,
				)
				console.info('Contactpersoon IDs:', contactpersoonIds)

				// Load available groups and bulk user info in parallel - but only once
				const promises = [this.organisatieStore.fetchAvailableGroups()]

				// Only add bulk user info request if we have contactpersonen
				if (contactpersoonIds.length > 0) {
					promises.push(
						this.organisatieStore.getBulkUserInfo(contactpersoonIds),
					)
				} else {
					promises.push(Promise.resolve({}))
				}

				const [availableGroups, bulkUserInfo] = await Promise.all(promises)

				console.info('Received bulk user info:', bulkUserInfo)

				// Update contactpersonen with user info
				if (bulkUserInfo && Object.keys(bulkUserInfo).length > 0) {
					this.updateContactpersonenWithUserInfo(bulkUserInfo)
				}

				console.info(
					'Completed SINGLE parallel loading of user info and available groups',
				)
				this.userInfoLoaded = true
			} catch (error) {
				console.error('Error loading user info and groups:', error)
			} finally {
				this.userStatusRefreshInProgress = false
			}
		},

		/**
		 * Update contactpersonen with bulk user info.
		 *
		 * @param {object} bulkUserInfo - User info object keyed by contactpersoon ID.
		 * @return {void}
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		updateContactpersonenWithUserInfo(bulkUserInfo) {
			if (!this.organisationData.contactpersonen) return

			this.organisationData.contactpersonen.forEach(
				(contactpersoon, index) => {
					const contactpersoonId = contactpersoon.id || contactpersoon.uuid
					const userInfo = bulkUserInfo[contactpersoonId]

					if (userInfo) {
						console.info(
							`Updating contactpersoon ${contactpersoonId} with user info:`,
							userInfo,
						)

						// Ensure user object exists.
						if (!contactpersoon.user) {
							contactpersoon.user = {}
						}

						// Update user object.
						contactpersoon.user.hasUser = userInfo.hasUser
						contactpersoon.user.username = userInfo.username
						contactpersoon.user.groups = userInfo.groups || []
						contactpersoon.user.disabled = !userInfo.enabled // Map enabled to disabled.
						contactpersoon.user.displayName = userInfo.displayName
						contactpersoon.user.lastLogin = userInfo.lastLogin

						// Update data object for consistency.
						if (contactpersoon.data) {
							contactpersoon.data.disabled = !userInfo.enabled // Map enabled to disabled.
							contactpersoon.data.groups = userInfo.groups || [] // Also set groups in data.
							contactpersoon.data.username = userInfo.username // Also set username in data.
						}

						// Force reactivity update.
						// eslint-disable-next-line vue/no-mutating-props -- @TODO: fix this.
						this.organisationData.contactpersonen[index] = contactpersoon
					}
				},
			)
		},

		/**
		 * Refresh user statuses from Nextcloud for all contact persons
		 *
		 * @deprecated Use loadUserInfoAndGroups() instead for better performance
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		async refreshUserStatuses() {
			console.info(
				'refreshUserStatuses called - redirecting to loadUserInfoAndGroups',
			)
			await this.loadUserInfoAndGroups()
		},

		/**
		 * Public method to refresh user statuses - can be called from parent component
		 *
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		async refreshUserData() {
			console.info('Public refreshUserData called')
			await this.loadUserInfoAndGroups()
		},

		/**
		 * Get contactperson name.
		 *
		 * @param {object} contactpersoon - The contact person object.
		 * @return {string} The contact person's name.
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		getContactpersoonName(contactpersoon) {
			const data = contactpersoon.data
			return (
				data.name
				|| data.name
				|| data.voornaam + ' ' + data.achternaam
				|| data.email
				|| data['e-mailadres']
				|| 'Unknown'
			)
		},

		/**
		 * Filter groups to only show those available in the modal.
		 *
		 * @param {object} contactpersoon - The contact person object.
		 * @return {Array} Filtered array of group IDs.
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		getFilteredGroups(contactpersoon) {
			if (
				!contactpersoon.user.groups
				|| contactpersoon.user.groups.length === 0
			) {
				return []
			}

			// Get list of available group IDs from the store.
			const availableGroupIds = this.availableGroups.map((g) => g.id)

			// Filter user groups to only include those in availableGroups.
			return contactpersoon.user.groups.filter((groupId) =>
				availableGroupIds.includes(groupId),
			)
		},

		/**
		 * Format group name.
		 *
		 * @param {string} groupId - The group ID.
		 * @return {string} Formatted group name.
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		formatGroupName(groupId) {
			const groupMap = {
				'gebruik-beheerder': 'Gebruik Beheerder',
				'aanbod-beheerder': 'Aanbod Beheerder',
				'gebruik-raadpleger': 'Gebruik Raadpleger',
			}
			return groupMap[groupId] || groupId
		},

		/**
		 * @param contactpersoon
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		async convertToUser(contactpersoon) {
			console.info('convertToUser called with:', contactpersoon)
			console.info(
				'organisationData.contactpersonen:',
				this.organisationData.contactpersonen,
			)

			// Find the contactpersoon in the organisation data and set loading state
			const contactIndex = this.organisationData.contactpersonen.findIndex(
				(cp) => (cp.id || cp.uuid) === contactpersoon.id,
			)

			console.info('Found contactpersoon at index:', contactIndex)

			if (contactIndex === -1) {
				showError(
					this.t(
						'softwarecatalog',
						'Contactpersoon not found in organisation data',
					),
				)
				return
			}

			// Set loading state on the specific contactpersoon - ensure it's an object first
			const contactObject = this.organisationData.contactpersonen[contactIndex]
			if (typeof contactObject === 'object' && contactObject !== null) {
				contactObject.loading = true
			} else {
				console.error('Contactpersoon is not an object:', contactObject)
				showError(
					this.t(
						'softwarecatalog',
						'Invalid contactpersoon data structure',
					),
				)
				return
			}

			try {
				const result = await this.organisatieStore.convertToUser(
					contactpersoon.id,
				)

				console.info('Convert to user result:', result)

				// Replace the contactpersoon object with the updated one from the API
				if (result.contactpersoon) {
					// The API returns the contactpersoon object directly, we need to structure it properly
					const updatedContactpersoon = {
						id:
							result.contactpersoon.id
							|| result.contactpersoon['@self']?.id,

						uuid:
							result.contactpersoon.id
							|| result.contactpersoon['@self']?.id,

						data: {
							voornaam: result.contactpersoon.voornaam,
							achternaam: result.contactpersoon.achternaam,
							'e-mailadres': result.contactpersoon['e-mailadres'],
							name: result.contactpersoon.name,
							organisatie: result.contactpersoon.organisatie,
							username: result.contactpersoon.username,
							groups: result.contactpersoon.groups || [],
						},

						loading: false, // Clear loading state
					}

					console.info(
						'Replacing contactpersoon at index:',
						contactIndex,
						updatedContactpersoon,
					)

					// Replace the entire contactpersoon object in the organisation data
					// eslint-disable-next-line vue/no-mutating-props -- @TODO: fix this.
					this.organisationData.contactpersonen.splice(
						contactIndex,
						1,
						updatedContactpersoon,
					)
				}

				// Refresh user info for all contactpersonen to ensure the newly created user shows correct status
				console.info('Refreshing user info after successful conversion...')
				await this.refreshUserData()

				showSuccess(
					this.t('softwarecatalog', 'User account created successfully'),
				)
			} catch (error) {
				console.error('Error in convertToUser:', error)
				showError(
					this.t(
						'softwarecatalog',
						'Failed to create user account: {error}',
						{
							error: error.message,
						},
					),
				)

				// Clear loading state on error - ensure it's an object first
				const contactObject =
					this.organisationData.contactpersonen[contactIndex]
				if (typeof contactObject === 'object' && contactObject !== null) {
					contactObject.loading = false
				}
			}
		},

		/**
		 * @param contactpersoon
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		openPasswordDialog(contactpersoon) {
			this.selectedContactpersoon = contactpersoon
			// ChangePasswordDialog is mounted fresh (v-if) on every open, so its
			// own data() is the reset that used to be spelled out here.
			this.showPasswordDialog = true
		},

		/**
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		closePasswordDialog() {
			this.showPasswordDialog = false
			this.selectedContactpersoon = null
		},

		/**
		 * Open groups management dialog.
		 * ManageUserGroupsDialog reads the user's CURRENT groups itself and
		 * reports them back through `groups-loaded`.
		 *
		 * @param {object} contactpersoon - The contact person object.
		 * @return {void}
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		openGroupsDialog(contactpersoon) {
			this.selectedContactpersoon = contactpersoon
			this.showGroupsDialog = true
		},

		/**
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		closeGroupsDialog() {
			this.showGroupsDialog = false
			this.selectedContactpersoon = null
		},

		/**
		 * The groups dialog read the user's current groups — mirror them into the
		 * local data so the table shows the correct groups.
		 *
		 * @param {Array} groups - Array of group IDs.
		 * @return {void}
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		onGroupsLoaded(groups) {
			if (!this.selectedContactpersoon) return
			this.updateContactpersoonGroups(this.selectedContactpersoon.id, groups)
		},

		/**
		 * The groups dialog saved a new membership — mirror it into the local data
		 * and close the dialog.
		 *
		 * @param {Array} groups - Array of group IDs.
		 * @return {void}
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		onGroupsSaved(groups) {
			if (this.selectedContactpersoon) {
				this.updateContactpersoonGroups(
					this.selectedContactpersoon.id,
					groups,
				)
			}
			this.closeGroupsDialog()
		},

		/**
		 * Disable a user account
		 *
		 * @param {object} contactpersoon - The contact person object
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		async disableUser(contactpersoon) {
			try {
				await this.organisatieStore.disableUser(contactpersoon.id)
				showSuccess(this.t('softwarecatalog', 'User disabled successfully'))

				// Update the local contactpersoon data to reflect disabled status
				this.updateContactpersoonStatus(contactpersoon.id, true)
			} catch (error) {
				showError(
					this.t('softwarecatalog', 'Failed to disable user: {error}', {
						error: error.message,
					}),
				)
			}
		},

		/**
		 * Enable a user account
		 *
		 * @param {object} contactpersoon - The contact person object
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		async enableUser(contactpersoon) {
			try {
				await this.organisatieStore.enableUser(contactpersoon.id)
				showSuccess(this.t('softwarecatalog', 'User enabled successfully'))

				// Update the local contactpersoon data to reflect enabled status
				this.updateContactpersoonStatus(contactpersoon.id, false)
			} catch (error) {
				showError(
					this.t('softwarecatalog', 'Failed to enable user: {error}', {
						error: error.message,
					}),
				)
			}
		},

		/**
		 * Update the disabled status of a contactpersoon in the local data.
		 *
		 * @param {string} contactpersoonId - The ID of the contact person.
		 * @param {boolean} disabled - Whether the user is disabled.
		 * @return {void}
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		updateContactpersoonStatus(contactpersoonId, disabled) {
			// Find and update the contactpersoon in the organisation data.
			if (this.organisationData.contactpersonen) {
				const contactIndex = this.organisationData.contactpersonen.findIndex(
					(cp) => (cp.id || cp.uuid) === contactpersoonId,
				)

				if (contactIndex !== -1) {
					// Update the disabled status in both user and data objects.
					const contactpersoon =
						this.organisationData.contactpersonen[contactIndex]

					// Update in the user object (primary source).
					if (contactpersoon.user) {
						contactpersoon.user.disabled = disabled
					}

					// Also update in data object for consistency.
					if (contactpersoon.data) {
						contactpersoon.data.disabled = disabled
					}

					// Force reactivity update.
					// eslint-disable-next-line vue/no-mutating-props -- @TODO: fix this.
					this.organisationData.contactpersonen[contactIndex] =
						contactpersoon
				}
			}
		},

		/**
		 * Update the groups of a contactpersoon in the local data.
		 *
		 * @param {string} contactpersoonId - The ID of the contact person.
		 * @param {Array} groups - Array of group IDs.
		 * @return {void}
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		updateContactpersoonGroups(contactpersoonId, groups) {
			// Find and update the contactpersoon in the organisation data.
			if (this.organisationData.contactpersonen) {
				const contactIndex = this.organisationData.contactpersonen.findIndex(
					(cp) => (cp.id || cp.uuid) === contactpersoonId,
				)

				if (contactIndex !== -1) {
					// Update the groups in both user and data objects.
					const contactpersoon =
						this.organisationData.contactpersonen[contactIndex]

					// Update in the user object (primary source).
					if (contactpersoon.user) {
						contactpersoon.user.groups = [...groups]
					}

					// Also update in data object for consistency.
					if (contactpersoon.data) {
						contactpersoon.data.groups = [...groups]
					}

					// Force reactivity update.
					// eslint-disable-next-line vue/no-mutating-props -- @TODO: fix this.
					this.organisationData.contactpersonen[contactIndex] =
						contactpersoon
				}
			}
		},
	},
}
</script>

<style scoped>
.contactpersonen-list {
	padding: 8px;
}

.loading,
.error {
	padding: 16px;
	text-align: center;
}

.contactpersonen-table {
	margin-top: 8px;
}

.compact-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 14px;
}

.compact-table th,
.compact-table td {
	padding: 8px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.compact-table th {
	font-weight: bold;
	background-color: var(--color-background-hover);
	font-size: 13px;
}

.name-cell {
	font-weight: 500;
	max-width: 150px;
}

.email-cell {
	max-width: 200px;
	word-break: break-all;
}

.status-cell {
	width: 80px;
}

.groups-cell {
	max-width: 150px;
}

.actions-cell {
	width: 60px;
}

.groups {
	display: flex;
	flex-wrap: wrap;
	gap: 2px;
}

.no-groups {
	color: var(--color-text-lighter);
	font-size: 12px;
}

.status-chip {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 500;
	text-align: center;
}

.status-success {
	background-color: #d4edda;
	color: #155724;
	border: 1px solid #c3e6cb;
}

.status-tertiary {
	background-color: #f8f9fa;
	color: #6c757d;
	border: 1px solid #dee2e6;
}

.status-warning {
	background-color: #fff3cd;
	color: #856404;
	border: 1px solid #ffeaa7;
}

.group-chip {
	display: inline-block;
	padding: 4px 8px;
	margin: 2px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 500;
	background-color: #e3f2fd;
	color: #1565c0;
	border: 1px solid #90caf9;
	white-space: nowrap;
}

/* The password / groups dialog styles live with their dialogs in
   src/dialogs/ChangePasswordDialog.vue and src/dialogs/ManageUserGroupsDialog.vue. */
</style>
