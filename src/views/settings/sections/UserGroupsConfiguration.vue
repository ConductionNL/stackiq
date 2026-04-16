<!--
 - @copyright Copyright (c) 2023 Ruben Linde <info@conduction.nl>
 - @license AGPL-3.0-or-later
 -
 - This program is free software: you can redistribute it and/or modify
 - it under the terms of the GNU Affero General Public License as
 - published by the Free Software Foundation, either version 3 of the
 - License, or (at your option) any later version.
 -
 - This program is distributed in the hope that it will be useful,
 - but WITHOUT ANY WARRANTY; without even the implied warranty of
 - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 - GNU Affero General Public License for more details.
 -
 - You should have received a copy of the GNU Affero General Public License
 - along with this program. If not, see <http://www.gnu.org/licenses/>.
 -->

<template>
	<AlwaysVisibleSection
		:name="t('softwarecatalog', 'User groups configuration')"
		:description="t('softwarecatalog', 'Configure user groups for different access levels and permissions')"
		:loading="loading"
		:loading-text="t('softwarecatalog', 'Loading user groups...')"
		:show-save-button="true"
		:show-refresh-button="true"
		:can-save="hasChanges"
		:saving="savingGroups"
		:save-button-text="t('softwarecatalog', 'Save user groups')"
		:has-info-content="true"
		@save="saveAllGroups"
		@refresh="loadAllGroups">
		<StandardTabs
			:tabs="[
				{ key: 'generic-groups', title: t('softwarecatalog', 'Generic groups') },
				{ key: 'organization-admin-groups', title: t('softwarecatalog', 'Organization admin groups') },
				{ key: 'super-user-groups', title: t('softwarecatalog', 'Super user groups') }
			]"
			:active-tab="activeTab"
			@update:active-tab="activeTab = $event">
			<!-- Generic Groups Tab -->
			<div v-show="activeTab === 'generic-groups'" class="tab-panel">
				<h3>{{ t('softwarecatalog', 'Generic user groups') }}</h3>
				<p>{{ t('softwarecatalog', 'Define the list of generic user groups that can be assigned to users based on their roles') }}</p>

				<div class="groups-configuration">
					<h4>{{ t('softwarecatalog', 'Current generic user groups') }}</h4>
					<div class="group-list">
						<div v-for="(group, index) in genericUserGroups" :key="index" class="group-item">
							<NcTextField
								:value="(group || '').toString()"
								:placeholder="t('softwarecatalog', 'Group name')"
								:label="t('softwarecatalog', 'Group name')"
								@update:value="updateGroupName(index, $event)" />
							<NcButton
								type="tertiary-no-background"
								:aria-label="t('softwarecatalog', 'Remove group')"
								@click="removeGroup(index)">
								<template #icon>
									<Close :size="16" />
								</template>
							</NcButton>
						</div>
					</div>

					<div class="group-actions">
						<NcButton
							type="secondary"
							@click="addGroup">
							<template #icon>
								<Plus :size="20" />
							</template>
							{{ t('softwarecatalog', 'Add group') }}
						</NcButton>
					</div>

					<div v-if="groupValidation && groupValidation.errors.length > 0" class="validation-errors">
						<NcNoteCard type="error">
							<template #icon>
								<Alert :size="20" />
							</template>
							<strong>{{ t('softwarecatalog', 'Validation errors:') }}</strong>
							<ul>
								<li v-for="error in groupValidation.errors" :key="error">
									{{ error }}
								</li>
							</ul>
						</NcNoteCard>
					</div>

					<div v-if="groupsSaveResult" class="save-results">
						<NcNoteCard v-if="groupsSaveResult.success" type="success">
							{{ t('softwarecatalog', 'Groups saved successfully!') }}
						</NcNoteCard>
						<NcNoteCard v-else type="error">
							{{ groupsSaveResult.error || t('softwarecatalog', 'Failed to save groups') }}
						</NcNoteCard>
					</div>

					<div class="groups-info">
						<h4>{{ t('softwarecatalog', 'Group information') }}</h4>
						<p>{{ t('softwarecatalog', 'These groups will be used for:') }}</p>
						<ul>
							<li><strong>{{ t('softwarecatalog', 'Role-based assignment:') }}</strong> {{ t('softwarecatalog', 'Users will be automatically assigned to groups based on their roles') }}</li>
							<li><strong>{{ t('softwarecatalog', 'Permission management:') }}</strong> {{ t('softwarecatalog', 'Groups can be used to control access to different parts of the system') }}</li>
							<li><strong>{{ t('softwarecatalog', 'Organization structure:') }}</strong> {{ t('softwarecatalog', "Special groups like 'ambtenaar' are available for manual assignment (no automatic assignment based on organization type)") }}</li>
						</ul>

						<div class="default-groups-info">
							<h5>{{ t('softwarecatalog', 'Recommended groups:') }}</h5>
							<ul>
								<li v-for="group in genericUserGroups" :key="group">
									<code>{{ group }}</code> - {{ getGroupDescription(group) }}
								</li>
							</ul>
						</div>
					</div>
				</div>
			</div>

			<!-- Organization Admin Groups Tab -->
			<div v-show="activeTab === 'organization-admin-groups'" class="tab-panel">
				<h3>{{ t('softwarecatalog', 'Organization admin groups') }}</h3>
				<p>{{ t('softwarecatalog', 'Define groups that organization administrators (first contacts) are automatically assigned to') }}</p>

				<div class="groups-configuration">
					<h4>{{ t('softwarecatalog', 'Current organization admin groups') }}</h4>
					<div class="group-list">
						<div v-for="(group, index) in organizationAdminGroups" :key="index" class="group-item">
							<NcTextField
								:value="group || ''"
								:placeholder="t('softwarecatalog', 'Group name')"
								:label="t('softwarecatalog', 'Group name')"
								@update:value="updateOrganizationAdminGroupName(index, $event)" />
							<NcButton
								type="tertiary-no-background"
								:aria-label="t('softwarecatalog', 'Remove group')"
								@click="removeOrganizationAdminGroup(index)">
								<template #icon>
									<Close :size="16" />
								</template>
							</NcButton>
						</div>
					</div>

					<div class="group-actions">
						<NcButton
							type="secondary"
							@click="addOrganizationAdminGroup">
							<template #icon>
								<Plus :size="20" />
							</template>
							{{ t('softwarecatalog', 'Add organization admin group') }}
						</NcButton>
					</div>

					<div v-if="organizationAdminGroupsSaveResult" class="save-results">
						<NcNoteCard :type="organizationAdminGroupsSaveResult.success ? 'success' : 'error'">
							{{ organizationAdminGroupsSaveResult.success ? t('softwarecatalog', 'Organization admin groups saved successfully!') : organizationAdminGroupsSaveResult.error }}
						</NcNoteCard>
					</div>

					<div class="groups-info">
						<h4>{{ t('softwarecatalog', 'Organization admin group information') }}</h4>
						<p>{{ t('softwarecatalog', 'These groups will be assigned to:') }}</p>
						<ul>
							<li><strong>{{ t('softwarecatalog', 'First contacts:') }}</strong> {{ t('softwarecatalog', 'The first contact person created for an organization') }}</li>
							<li><strong>{{ t('softwarecatalog', 'Organization administrators:') }}</strong> {{ t('softwarecatalog', 'Users designated as organization administrators') }}</li>
							<li><strong>{{ t('softwarecatalog', 'Management permissions:') }}</strong> {{ t('softwarecatalog', "Users who need to manage their organization's data") }}</li>
						</ul>
					</div>
				</div>
			</div>

			<!-- Super User Groups Tab -->
			<div v-show="activeTab === 'super-user-groups'" class="tab-panel">
				<h3>{{ t('softwarecatalog', 'Super user groups') }}</h3>
				<p>{{ t('softwarecatalog', 'Define groups that super users (system administrators) are automatically assigned to') }}</p>

				<div class="groups-configuration">
					<h4>{{ t('softwarecatalog', 'Current super user groups') }}</h4>
					<div class="group-list">
						<div v-for="(group, index) in superUserGroups" :key="index" class="group-item">
							<NcTextField
								:value="group || ''"
								:placeholder="t('softwarecatalog', 'Group name')"
								:label="t('softwarecatalog', 'Group name')"
								@update:value="updateSuperUserGroupName(index, $event)" />
							<NcButton
								type="tertiary-no-background"
								:aria-label="t('softwarecatalog', 'Remove group')"
								@click="removeSuperUserGroup(index)">
								<template #icon>
									<Close :size="16" />
								</template>
							</NcButton>
						</div>
					</div>

					<div class="group-actions">
						<NcButton
							type="secondary"
							@click="addSuperUserGroup">
							<template #icon>
								<Plus :size="20" />
							</template>
							{{ t('softwarecatalog', 'Add super user group') }}
						</NcButton>
					</div>

					<div v-if="superUserGroupsSaveResult" class="save-results">
						<NcNoteCard :type="superUserGroupsSaveResult.success ? 'success' : 'error'">
							{{ superUserGroupsSaveResult.success ? t('softwarecatalog', 'Super user groups saved successfully!') : superUserGroupsSaveResult.error }}
						</NcNoteCard>
					</div>

					<div class="groups-info">
						<h4>{{ t('softwarecatalog', 'Super user group information') }}</h4>
						<p>{{ t('softwarecatalog', 'These groups will be assigned to:') }}</p>
						<ul>
							<li><strong>{{ t('softwarecatalog', 'System administrators:') }}</strong> {{ t('softwarecatalog', 'Users with full system access') }}</li>
							<li><strong>{{ t('softwarecatalog', 'Platform managers:') }}</strong> {{ t('softwarecatalog', 'Users who manage the entire platform') }}</li>
							<li><strong>{{ t('softwarecatalog', 'Advanced permissions:') }}</strong> {{ t('softwarecatalog', 'Users who need access to all system features') }}</li>
						</ul>
					</div>
				</div>
			</div>
		</StandardTabs>

		<!-- Info Content Slot -->
		<template #info-content>
			<div class="user-groups-info">
				<h3>About User Groups</h3>
				<p>User groups in the Software Catalog are used to organize users and control access to different parts of the system. There are three types of user groups:</p>

				<h4>Generic User Groups</h4>
				<p>These are general-purpose groups that can be assigned to any user based on their role or function. Common examples include:</p>
				<ul>
					<li><strong>ambtenaar</strong> - For government employees (manual assignment only)</li>
					<li><strong>leverancier</strong> - For suppliers and vendors</li>
					<li><strong>consultant</strong> - For external consultants</li>
					<li><strong>beheerder</strong> - For system administrators</li>
				</ul>

				<h4>Organization Admin Groups</h4>
				<p>These groups are automatically assigned to users who are designated as organization administrators or first contacts. They typically have elevated permissions within their organization scope.</p>

				<h4>Super User Groups</h4>
				<p>These groups are reserved for system administrators and platform managers who need access to all system features and data across all organizations.</p>

				<h4>Group Assignment</h4>
				<p>Users are assigned to groups based on:</p>
				<ul>
					<li>Their role in the organization (automatic assignment)</li>
					<li>Whether they are the first contact for an organization (automatic assignment)</li>
					<li>Their system-level permissions (automatic assignment)</li>
					<li>Manual assignment by administrators for special groups (e.g. 'ambtenaar')</li>
				</ul>

				<h4>Best Practices</h4>
				<ul>
					<li>Keep group names descriptive and consistent</li>
					<li>Avoid creating too many groups - this can complicate management</li>
					<li>Use generic groups for most users, admin groups sparingly</li>
					<li>Test group assignments after making changes</li>
				</ul>
			</div>
		</template>
	</AlwaysVisibleSection>
</template>

<script>
/**
 * User Groups Configuration Component
 *
 * This component handles the configuration of user groups for different
 * user types in the Software Catalog application.
 *
 * @author Ruben Linde <info@conduction.nl>
 * @copyright 2023 Conduction B.V.
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 */

import { settingsStore } from '../../../store/store.js'
import { showError, showSuccess } from '@nextcloud/dialogs'

// Components
import AlwaysVisibleSection from '../../../components/AlwaysVisibleSection.vue'
import StandardTabs from '../../../components/StandardTabs.vue'

// Nextcloud Vue components
import { NcButton, NcTextField, NcNoteCard } from '@nextcloud/vue'

// Icons
import Plus from 'vue-material-design-icons/Plus.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Alert from 'vue-material-design-icons/Alert.vue'

// Nextcloud Vue components

export default {
	name: 'UserGroupsConfiguration',

	components: {
		AlwaysVisibleSection,
		StandardTabs,
		NcButton,
		NcTextField,
		NcNoteCard,
		Plus,
		Close,
		Alert,
	},

	/**
	 * Component setup function for Composition API
	 * Provides access to the settings store
	 *
	 * @return {object} Setup object with store reference
	 */
	setup() {
		return {
			store: settingsStore,
		}
	},

	/**
	 * Component data
	 *
	 * @return {object} Component data
	 */
	data() {
		return {
			savingGroups: false,
			savingOrganizationAdminGroups: false,
			savingSuperUserGroups: false,
			groupValidation: null,
			groupsSaveResult: null,
			organizationAdminGroupsSaveResult: null,
			superUserGroupsSaveResult: null,
			activeTab: 'generic-groups', // Default active tab
		}
	},

	computed: {
		// Store-connected computed properties
		loading() { return this.store.loading },
		genericUserGroups: {
			get() { return this.store.genericUserGroups || [] },
			set(value) { this.store.genericUserGroups = value },
		},
		organizationAdminGroups: {
			get() { return this.store.organizationAdminGroups || [] },
			set(value) { this.store.organizationAdminGroups = value },
		},
		superUserGroups: {
			get() { return this.store.superUserGroups || [] },
			set(value) { this.store.superUserGroups = value },
		},

		/**
		 * Check if there are any unsaved changes
		 */
		hasChanges() {
			// This would ideally check if the current values differ from the saved values
			// For now, we'll just check if there are any groups defined
			return this.genericUserGroups.length > 0
				   || this.organizationAdminGroups.length > 0
				   || this.superUserGroups.length > 0
		},
	},

	mounted() {
		// ensure we fetch latest user groups when opening the section
		this.loadAllGroups().catch(() => {})
	},

	methods: {
		/**
		 * Get description for a group
		 *
		 * @param {string} group Group name
		 * @return {string} Group description
		 */
		getGroupDescription(group) {
			const descriptions = {
				ambtenaar: 'Government employee role (manual assignment only)',
				beheerder: 'Administrator role',
				gebruiker: 'Standard user role',
				organisatiebeheerder: 'Organization administrator role',
				superuser: 'System administrator role',
			}
			return descriptions[group] || 'Custom user group'
		},

		// Generic User Groups methods
		/**
		 * Add a new generic user group
		 *
		 * @return {void}
		 */
		addGroup() {
			this.genericUserGroups.push('')
		},

		/**
		 * Remove a generic user group
		 *
		 * @param {number} index Index of the group to remove
		 * @return {void}
		 */
		removeGroup(index) {
			this.genericUserGroups.splice(index, 1)
		},

		/**
		 * Update generic user group name
		 *
		 * @param {number} index Index of the group to update
		 * @param {string} value New group name
		 * @return {void}
		 */
		updateGroupName(index, value) {
			this.genericUserGroups[index] = value
		},

		// Organization Admin Groups methods
		/**
		 * Add a new organization admin group
		 *
		 * @return {void}
		 */
		addOrganizationAdminGroup() {
			this.organizationAdminGroups.push('')
		},

		/**
		 * Remove an organization admin group
		 *
		 * @param {number} index Index of the group to remove
		 * @return {void}
		 */
		removeOrganizationAdminGroup(index) {
			this.organizationAdminGroups.splice(index, 1)
		},

		/**
		 * Update organization admin group name
		 *
		 * @param {number} index Index of the group to update
		 * @param {string} value New group name
		 * @return {void}
		 */
		updateOrganizationAdminGroupName(index, value) {
			this.organizationAdminGroups[index] = value
		},

		// Super User Groups methods
		/**
		 * Add a new super user group
		 *
		 * @return {void}
		 */
		addSuperUserGroup() {
			this.superUserGroups.push('')
		},

		/**
		 * Remove a super user group
		 *
		 * @param {number} index Index of the group to remove
		 * @return {void}
		 */
		removeSuperUserGroup(index) {
			this.superUserGroups.splice(index, 1)
		},

		/**
		 * Update super user group name
		 *
		 * @param {number} index Index of the group to update
		 * @param {string} value New group name
		 * @return {void}
		 */
		updateSuperUserGroupName(index, value) {
			this.superUserGroups[index] = value
		},

		/**
		 * Save all groups using the centralized settings store
		 */
		async saveAllGroups() {
			try {
				// Use the settings store's centralized save method
				await this.store.saveConfiguration()
				showSuccess(t('softwarecatalog', 'All user groups saved successfully'))
			} catch (error) {
				console.error('Failed to save all groups:', error)
				showError(t('softwarecatalog', 'Failed to save user groups: {error}', { error: error.message }))
			}
		},

		/**
		 * Load all groups from the store
		 */
		async loadAllGroups() {
			try {
				await this.store.loadUserGroupsOnly()
				showSuccess(t('softwarecatalog', 'User groups reloaded successfully'))
			} catch (error) {
				console.error('Failed to reload groups:', error)
				showError(t('softwarecatalog', 'Failed to reload user groups: {error}', { error: error.message }))
			}
		},
	},
}
</script>

<style scoped>
.generic-groups-section,
.organization-admin-groups-section,
.super-user-groups-section {
	margin-bottom: 40px;
	padding-bottom: 32px;
	border-bottom: 1px solid var(--color-border);
}

.super-user-groups-section {
	border-bottom: none;
	margin-bottom: 0;
	padding-bottom: 0;
}

.groups-configuration {
	margin-top: 20px;
}

.groups-configuration h4 {
	margin: 0 0 16px 0;
	font-weight: 600;
	color: var(--color-main-text);
}

.group-list {
	display: grid;
	gap: 12px;
	margin-bottom: 20px;
}

.group-item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.group-item .nc-text-field {
	flex: 1;
}

.group-actions {
	display: flex;
	gap: 12px;
	margin-bottom: 20px;
}

.validation-errors,
.save-results {
	margin-bottom: 20px;
}

.groups-info {
	padding: 16px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.groups-info h4,
.groups-info h5 {
	margin: 0 0 12px 0;
	font-weight: 600;
	color: var(--color-main-text);
}

.groups-info p {
	margin: 0 0 12px 0;
	color: var(--color-text-maxcontrast);
}

.groups-info ul {
	margin: 0 0 16px 0;
	padding-left: 20px;
}

.groups-info li {
	margin-bottom: 8px;
	color: var(--color-main-text);
}

.default-groups-info {
	margin-top: 16px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.default-groups-info code {
	background-color: var(--color-background-dark);
	padding: 2px 6px;
	border-radius: 4px;
	font-family: var(--font-face-monospace);
	font-size: 0.9em;
}

.loading-icon {
	display: flex;
	justify-content: center;
	margin: 40px 0;
}

/* New styles for tab implementation */
.user-groups-tabs {
	margin-bottom: 20px;
}

.tab-navigation {
	display: flex;
	gap: 10px;
	margin-bottom: 10px;
	border-bottom: 1px solid var(--color-border);
}

.tab-button {
	padding: 10px 15px;
	border: none;
	border-bottom: 2px solid transparent;
	background-color: var(--color-background-hover);
	color: var(--color-main-text);
	font-weight: 600;
	cursor: pointer;
	transition: border-bottom-color 0.3s ease;
}

.tab-button:hover {
	color: var(--color-main-text);
}

.tab-button.active {
	border-bottom-color: var(--color-primary);
	color: var(--color-primary);
}

.tab-content {
	padding: 20px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.tab-panel {
	display: none; /* Hidden by default */
}

.tab-panel.active {
	display: block; /* Show when active */
}
</style>
