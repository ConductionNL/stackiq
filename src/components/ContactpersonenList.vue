<!--
ContactpersonenList.vue
Component for displaying and managing contactpersonen with user actions

@category Components
@package softwarecatalog
@author Ruben Linde
@copyright 2024
@license AGPL-3.0-or-later
@version 1.0.0
@link https://github.com/opencatalogi/softwarecatalog
-->

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
				:description="t('softwarecatalog', 'This organisation has no contactpersonen.')">
				<template #icon>
					<AccountMultiple :size="64" />
				</template>
			</NcEmptyContent>
		</div>

		<div v-else class="contactpersonen-table">
			<table class="compact-table">
				<thead>
					<tr>
						<th>{{ t('softwarecatalog', 'Name') }}</th>
						<th>{{ t('softwarecatalog', 'Email') }}</th>
						<th>{{ t('softwarecatalog', 'Status') }}</th>
						<th>{{ t('softwarecatalog', 'Groups') }}</th>
						<th>{{ t('softwarecatalog', 'Actions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="contactpersoon in contactpersonen" :key="contactpersoon.id">
						<td class="name-cell">
							{{ getContactpersoonName(contactpersoon) }}
						</td>
						<td class="email-cell">
							{{ contactpersoon.data.email || contactpersoon.data['e-mailadres'] || '-' }}
						</td>
						<td class="status-cell">
							<span v-if="contactpersoon.user.hasUser" 
								class="status-chip status-success">
								{{ t('softwarecatalog', 'User') }}
							</span>
							<span v-else 
								class="status-chip status-tertiary">
								{{ t('softwarecatalog', 'No User') }}
							</span>
						</td>
						<td class="groups-cell">
							<div v-if="contactpersoon.user.hasUser && contactpersoon.user.groups.length > 0" 
								class="groups">
								<span v-for="group in contactpersoon.user.groups" 
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
								<NcActionButton v-if="!contactpersoon.user.hasUser"
									:close-after-click="true"
									:disabled="contactpersoon.loading"
									@click="convertToUser(contactpersoon)">
									<template #icon>
										<NcLoadingIcon v-if="contactpersoon.loading" :size="20" />
										<AccountPlus v-else :size="20" />
									</template>
									{{ contactpersoon.loading ? t('softwarecatalog', 'Converting...') : t('softwarecatalog', 'Convert to User') }}
								</NcActionButton>

								<!-- Change Password Action -->
								<NcActionButton v-if="contactpersoon.user.hasUser"
									:close-after-click="true"
									@click="openPasswordDialog(contactpersoon)">
									<template #icon>
										<Key :size="20" />
									</template>
									{{ t('softwarecatalog', 'Change Password') }}
								</NcActionButton>

								<!-- Manage Groups Action -->
								<NcActionButton v-if="contactpersoon.user.hasUser"
									:close-after-click="true"
									@click="openGroupsDialog(contactpersoon)">
									<template #icon>
										<AccountGroup :size="20" />
									</template>
									{{ t('softwarecatalog', 'Manage Groups') }}
								</NcActionButton>
							</NcActions>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Password Change Dialog -->
		<NcDialog v-if="showPasswordDialog"
			:name="t('softwarecatalog', 'Change Password')"
			size="small"
			@closing="closePasswordDialog">
			<div class="password-dialog">
				<p class="dialog-description">{{ t('softwarecatalog', 'Change password for user: {username}', { username: selectedContactpersoon?.user?.username }) }}</p>
				
				<div class="password-input">
					<NcTextField
						:value="newPassword"
						type="password"
						:label="t('softwarecatalog', 'New password')"
						:placeholder="t('softwarecatalog', 'Enter new password')"
						class="compact-input"
						@update:value="newPassword = $event" />
				</div>

				<div class="dialog-actions">
					<NcButton type="secondary" @click="closePasswordDialog">
						{{ t('softwarecatalog', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" 
						:disabled="passwordLoading || !newPassword || newPassword.length < 8"
						@click="savePassword">
						<template #icon>
							<NcLoadingIcon v-if="passwordLoading" :size="20" />
						</template>
						{{ t('softwarecatalog', 'Save') }}
					</NcButton>
				</div>
			</div>
		</NcDialog>

		<!-- Groups Management Dialog -->
		<NcDialog v-if="showGroupsDialog"
			:name="t('softwarecatalog', 'Manage User Groups')"
			size="normal"
			@closing="closeGroupsDialog">
			<div class="groups-dialog">
				<p class="dialog-description">{{ t('softwarecatalog', 'Select groups for user: {username}', { username: selectedContactpersoon?.user?.username }) }}</p>
				
				<div class="groups-selection">
					<NcCheckboxRadioSwitch v-for="group in availableGroups"
						:key="group.id"
						:checked="selectedGroups.includes(group.id)"
						type="checkbox"
						class="compact-checkbox"
						@update:checked="toggleGroup(group.id, $event)">
						{{ group.name }}
						<template #description>
							{{ group.description }}
						</template>
					</NcCheckboxRadioSwitch>
				</div>

				<div class="dialog-actions">
					<NcButton type="secondary" @click="closeGroupsDialog">
						{{ t('softwarecatalog', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" 
						:disabled="groupsLoading"
						@click="saveGroups">
						<template #icon>
							<NcLoadingIcon v-if="groupsLoading" :size="20" />
						</template>
						{{ t('softwarecatalog', 'Save') }}
					</NcButton>
				</div>
			</div>
		</NcDialog>
	</div>
</template>

<script>
import { 
	NcActions, 
	NcActionButton, 
	NcActionInput,
	NcLoadingIcon, 
	NcNoteCard, 
	NcEmptyContent,
	NcDialog,
	NcButton,
	NcCheckboxRadioSwitch,
	NcTextField
} from '@nextcloud/vue'

import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Key from 'vue-material-design-icons/Key.vue'

import { useOrganisatieStore } from '../store/modules/organisatie.js'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'ContactpersonenList',

	components: {
		NcActions,
		NcActionButton,
		NcActionInput,
		NcLoadingIcon,
		NcNoteCard,
		NcEmptyContent,
		NcDialog,
		NcButton,
		NcCheckboxRadioSwitch,
		NcTextField,
		AccountMultiple,
		AccountPlus,
		AccountGroup,
		Key
	},

	props: {
		organisationId: {
			type: String,
			required: true
		},
		organisationData: {
			type: Object,
			default: () => ({})
		}
	},

	data() {
		return {
			showPasswordDialog: false,
			showGroupsDialog: false,
			selectedContactpersoon: null,
			selectedGroups: [],
			groupsLoading: false,
			newPassword: '',
			passwordLoading: false
		}
	},

	computed: {
		organisatieStore() {
			return useOrganisatieStore()
		},

		contactpersonen() {
			// Use contactpersonen from organisation data if available, otherwise fall back to store
			if (this.organisationData.contactpersonen && Array.isArray(this.organisationData.contactpersonen)) {
				return this.processContactpersonen(this.organisationData.contactpersonen)
			}
			return this.organisatieStore.getContactpersonen
		},

		loading() {
			return this.organisatieStore.isLoading
		},

		error() {
			return this.organisatieStore.getError
		},

		availableGroups() {
			return this.organisatieStore.getAvailableGroups
		}
	},

	async mounted() {
		await this.loadData()
	},

	methods: {
		async loadData() {
			try {
				// Only fetch available groups, contactpersonen come from organisation data
				await this.organisatieStore.fetchAvailableGroups()
				
				// If no organisation data provided, fall back to fetching contactpersonen separately
				if (!this.organisationData.contactpersonen) {
					await this.organisatieStore.fetchContactpersonen(this.organisationId)
				}
			} catch (error) {
				console.error('Error loading contactpersonen data:', error)
			}
		},

		/**
		 * Process contactpersonen data from organisation object to match expected format
		 * @param {Array} rawContactpersonen - Raw contactpersonen data from organisation
		 * @return {Array} Processed contactpersonen with user information
		 */
		processContactpersonen(rawContactpersonen) {
			return rawContactpersonen.map(contactpersoon => {
				const contactId = contactpersoon.id || contactpersoon.uuid
				const data = contactpersoon.data || contactpersoon
				const hasUser = !!(data.username)
				
				return {
					id: contactId,
					data: data,
					user: {
						hasUser: hasUser,
						username: data.username || '',
						groups: data.groups || []
					},
					loading: contactpersoon.loading || false // Include loading state from organisation data
				}
			})
		},

		getContactpersoonName(contactpersoon) {
			const data = contactpersoon.data
			return data.naam || data.name || data.voornaam + ' ' + data.achternaam || data.email || data['e-mailadres'] || 'Unknown'
		},

		formatGroupName(groupId) {
			const groupMap = {
				'gebruik-beheerder': 'Gebruik Beheerder',
				'aanbod-beheerder': 'Aanbod Beheerder',
				'gebruik-raadpleger': 'Gebruik Raadpleger'
			}
			return groupMap[groupId] || groupId
		},

		async convertToUser(contactpersoon) {
			// Find the contactpersoon in the organisation data and set loading state
			const contactIndex = this.organisationData.contactpersonen.findIndex(cp => 
				(cp.id || cp.uuid) === contactpersoon.id
			)
			
			if (contactIndex === -1) {
				showError(this.t('softwarecatalog', 'Contactpersoon not found in organisation data'))
				return
			}
			
			// Set loading state on the specific contactpersoon
			this.$set(this.organisationData.contactpersonen[contactIndex], 'loading', true)
			
			try {
				const result = await this.organisatieStore.convertToUser(contactpersoon.id)
				
				console.log('Convert to user result:', result)
				
				// Replace the contactpersoon object with the updated one from the API
				if (result.contactpersoon) {
					// The API returns the contactpersoon object directly, we need to structure it properly
					const updatedContactpersoon = {
						id: result.contactpersoon.id || result.contactpersoon['@self']?.id,
						uuid: result.contactpersoon.id || result.contactpersoon['@self']?.id,
						data: {
							voornaam: result.contactpersoon.voornaam,
							achternaam: result.contactpersoon.achternaam,
							'e-mailadres': result.contactpersoon['e-mailadres'],
							naam: result.contactpersoon.naam,
							organisatie: result.contactpersoon.organisatie,
							username: result.contactpersoon.username,
							groups: result.contactpersoon.groups || []
						},
						loading: false // Clear loading state
					}
					
					console.log('Replacing contactpersoon at index:', contactIndex, updatedContactpersoon)
					
					// Replace the entire contactpersoon object in the organisation data
					this.organisationData.contactpersonen.splice(contactIndex, 1, updatedContactpersoon)
				}
				
				showSuccess(this.t('softwarecatalog', 'User account created successfully'))
			} catch (error) {
				console.error('Error in convertToUser:', error)
				showError(this.t('softwarecatalog', 'Failed to create user account: {error}', { error: error.message }))
				
				// Clear loading state on error
				this.$set(this.organisationData.contactpersonen[contactIndex], 'loading', false)
			}
		},

		openPasswordDialog(contactpersoon) {
			this.selectedContactpersoon = contactpersoon
			this.newPassword = ''
			this.showPasswordDialog = true
		},

		closePasswordDialog() {
			this.showPasswordDialog = false
			this.selectedContactpersoon = null
			this.newPassword = ''
			this.passwordLoading = false
		},

		async savePassword() {
			if (!this.newPassword || this.newPassword.length < 8) {
				showError(this.t('softwarecatalog', 'Password must be at least 8 characters long'))
				return
			}

			this.passwordLoading = true

			try {
				await this.organisatieStore.changePassword(this.selectedContactpersoon.user.username, this.newPassword)
				showSuccess(this.t('softwarecatalog', 'Password changed successfully'))
				this.closePasswordDialog()
			} catch (error) {
				showError(this.t('softwarecatalog', 'Failed to change password: {error}', { error: error.message }))
			} finally {
				this.passwordLoading = false
			}
		},

		async openGroupsDialog(contactpersoon) {
			this.selectedContactpersoon = contactpersoon
			this.showGroupsDialog = true
			
			try {
				// Fetch user-specific info to get current groups and available groups
				const userInfo = await this.organisatieStore.fetchUserInfo(contactpersoon.id)
				this.selectedGroups = [...(userInfo.groups || [])]
			} catch (error) {
				console.error('Error fetching user info for groups dialog:', error)
				// Fallback to existing groups
				this.selectedGroups = [...contactpersoon.user.groups]
				// Still fetch available groups as fallback
				await this.organisatieStore.fetchAvailableGroups()
			}
		},

		closeGroupsDialog() {
			this.showGroupsDialog = false
			this.selectedContactpersoon = null
			this.selectedGroups = []
			this.groupsLoading = false
		},

		toggleGroup(groupId, checked) {
			if (checked) {
				if (!this.selectedGroups.includes(groupId)) {
					this.selectedGroups.push(groupId)
				}
			} else {
				const index = this.selectedGroups.indexOf(groupId)
				if (index > -1) {
					this.selectedGroups.splice(index, 1)
				}
			}
		},

		async saveGroups() {
			if (!this.selectedContactpersoon) return

			this.groupsLoading = true

			try {
				await this.organisatieStore.updateUserGroups(
					this.selectedContactpersoon.user.username,
					this.selectedGroups
				)
				showSuccess(this.t('softwarecatalog', 'User groups updated successfully'))
				this.closeGroupsDialog()
			} catch (error) {
				showError(this.t('softwarecatalog', 'Failed to update user groups: {error}', { error: error.message }))
			} finally {
				this.groupsLoading = false
			}
		}
	}
}
</script>

<style scoped>
.contactpersonen-list {
	padding: 8px;
}

.loading, .error {
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

.group-chip {
	display: inline-block;
	padding: 2px 6px;
	margin: 1px;
	border-radius: 10px;
	font-size: 10px;
	font-weight: 500;
	background-color: var(--color-primary-light);
	color: var(--color-primary-element-text);
	border: 1px solid var(--color-primary);
}

.password-dialog {
	padding: 12px;
	min-width: 320px;
	max-width: 400px;
}

.groups-dialog {
	padding: 12px;
	min-width: 350px;
	max-width: 450px;
}

.dialog-description {
	margin: 0 0 12px 0;
	font-size: 14px;
	color: var(--color-text-lighter);
}

.password-input {
	margin: 12px 0;
}

.groups-selection {
	margin: 12px 0;
	max-height: 200px;
	overflow-y: auto;
}

.groups-selection .checkbox-radio-switch {
	margin-bottom: 6px;
}

.compact-checkbox {
	padding: 4px 0;
}

.compact-input {
	margin: 8px 0;
}

.dialog-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 12px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

/* Make NcTextField more compact */
.compact-input :deep(.input-field) {
	margin-bottom: 8px;
}

.compact-input :deep(.input-field__main-wrapper) {
	min-height: 36px;
}

.compact-input :deep(.input-field__input) {
	padding: 8px 12px;
	font-size: 14px;
}
</style>
