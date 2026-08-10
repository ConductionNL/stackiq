<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Group-membership dialog for the Nextcloud user account behind a
  - contactpersoon. Extracted from ContactpersonenList.vue per ADR-004/ADR-012:
  - a dialog lives in its own file under src/dialogs/ and is imported by its
  - parent.
  -
  - The dialog owns the selection it is editing: it fetches the user's CURRENT
  - groups on mount (falling back to the groups already on the contactpersoon if
  - that call fails), toggles them locally, and writes them back through the
  - organisatie store.
  -
  - It does NOT touch the parent's `organisationData` — the freshly-read groups
  - are reported up via `groups-loaded`, and the saved ones via `saved`, so the
  - list row stays the parent's responsibility.
  -
  - @spec openspec/specs/fe-organizations/spec.md
  -->

<template>
	<NcDialog
		:name="t('softwarecatalog', 'Manage User Groups')"
		size="normal"
		@closing="$emit('close')">
		<div class="groups-dialog">
			<p class="dialog-description">
				{{
					t("softwarecatalog", "Select groups for user: {username}", {
						username: contactpersoon?.user?.username,
					})
				}}
			</p>

			<div class="groups-selection">
				<NcCheckboxRadioSwitch
					v-for="group in availableGroups"
					:key="group.id"
					:model-value="selectedGroups.includes(group.id)"
					type="checkbox"
					class="compact-checkbox"
					@update:model-value="toggleGroup(group.id, $event)">
					{{ group.name }}
					<template #description>
						{{ group.description }}
					</template>
				</NcCheckboxRadioSwitch>
			</div>

			<div class="dialog-actions">
				<NcButton variant="secondary" @click="$emit('close')">
					{{ t("softwarecatalog", "Cancel") }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="groupsLoading"
					@click="saveGroups">
					<template #icon>
						<NcLoadingIcon v-if="groupsLoading" :size="20" />
					</template>
					{{ t("softwarecatalog", "Save") }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcLoadingIcon,
} from '@nextcloud/vue'

import { showSuccess, showError } from '@nextcloud/dialogs'
import { useOrganisatieStore } from '../store/modules/organisatie.js'

export default {
	name: 'ManageUserGroupsDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcLoadingIcon,
	},

	props: {
		/**
		 * The contact person whose user-group membership is being edited.
		 * Needs `id` and `user.username` / `user.groups`.
		 */
		contactpersoon: {
			type: Object,
			required: true,
		},
	},

	emits: ['close', 'saved', 'groups-loaded'],

	data() {
		return {
			selectedGroups: [],
			groupsLoading: false,
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
		availableGroups() {
			return this.organisatieStore.getAvailableGroups
		},
	},

	/**
	 * @spec openspec/specs/fe-organizations/spec.md
	 */
	async mounted() {
		try {
			// Fetch user-specific info to get current groups.
			const userInfo = await this.organisatieStore.fetchUserInfo(
				this.contactpersoon.id,
			)
			this.selectedGroups = [...(userInfo.groups || [])]

			// Report the fresh groups so the list row shows the correct value.
			this.$emit('groups-loaded', userInfo.groups || [])
		} catch (error) {
			console.error('Error fetching user info for groups dialog:', error)
			// Fallback to existing groups.
			this.selectedGroups = [...this.contactpersoon.user.groups]
			// Note: Available groups should already be loaded by the parent.
		}
	},

	methods: {
		/**
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
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

		/**
		 * Save user groups.
		 * @return {Promise<void>}
		  * @spec openspec/specs/fe-organizations/spec.md
		 */
		async saveGroups() {
			this.groupsLoading = true

			try {
				await this.organisatieStore.updateUserGroups(
					this.contactpersoon.user.username,
					this.selectedGroups,
				)

				showSuccess(
					this.t('softwarecatalog', 'User groups updated successfully'),
				)
				this.$emit('saved', [...this.selectedGroups])
			} catch (error) {
				showError(
					this.t('softwarecatalog', 'Failed to update user groups: {error}', {
						error: error.message,
					}),
				)
			} finally {
				this.groupsLoading = false
			}
		},
	},
}
</script>

<style scoped>
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

.dialog-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 12px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}
</style>
