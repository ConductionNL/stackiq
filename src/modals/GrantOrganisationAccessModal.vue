<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 GrantOrganisationAccessModal — self-service colleague access (VNG
 Softwarecatalogus #65). Lets a beheerder of the given organisation grant an
 EXISTING Nextcloud user access to it, and revoke an existing member's
 access again.

 Client-side rendering of this modal (only reachable when `App.vue`'s
 `isBeheerder` flag is true, see OrganisationSwitcher.vue) is a UX hint
 only — the actual authorization is re-verified server-side on every
 grant/revoke call by `OrganisationMembersController::authorizeBeheerder()`,
 which additionally confirms the caller belongs to THIS organisation, not
 just that they hold the `beheerder` role somewhere. A denied response
 surfaces inline here without mutating the locally-rendered member list.

 @spec openspec/specs/multi-org-membership/spec.md#requirement-granting-access-must-only-target-an-existing-nextcloud-user-req-005
-->
<template>
	<NcDialog
		:open="open"
		:name="t('softwarecatalog', 'Manage access to {name}', { name: organisationName })"
		size="normal"
		@closing="onClose">
		<div class="grant-organisation-access">
			<div class="grant-organisation-access__grant">
				<NcSelectUsers
					v-model="selectedUser"
					:input-label="t('softwarecatalog', 'Grant access to an existing Nextcloud user')"
					:multiple="false" />
				<NcButton
					variant="primary"
					:disabled="!selectedUser || granting"
					@click="onGrant">
					<template #icon>
						<NcLoadingIcon v-if="granting" :size="20" />
					</template>
					{{ t('softwarecatalog', 'Grant access') }}
				</NcButton>
			</div>

			<NcNoteCard v-if="errorMessage" type="error">
				{{ errorMessage }}
			</NcNoteCard>

			<h3>{{ t('softwarecatalog', 'Current members') }}</h3>
			<NcLoadingIcon v-if="loading" :size="32" />
			<ul v-else class="grant-organisation-access__members">
				<NcListItem
					v-for="userId in members"
					:key="userId"
					:name="userId"
					:force-display-actions="true">
					<template #icon>
						<NcAvatar :user="userId" :size="32" />
					</template>
					<template #actions>
						<NcActionButton
							:disabled="revokingUserId === userId"
							@click="onRevoke(userId)">
							<template #icon>
								<NcLoadingIcon v-if="revokingUserId === userId" :size="20" />
								<CloseIcon v-else :size="20" />
							</template>
							{{ t('softwarecatalog', 'Revoke access') }}
						</NcActionButton>
					</template>
				</NcListItem>
				<li v-if="members.length === 0" class="grant-organisation-access__empty">
					{{ t('softwarecatalog', 'No members yet.') }}
				</li>
			</ul>
		</div>

		<template #actions>
			<NcButton @click="onClose">
				{{ t('softwarecatalog', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcSelectUsers, NcLoadingIcon, NcNoteCard, NcListItem, NcActionButton, NcAvatar } from '@nextcloud/vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import { organisatieStore } from '../store/store.js'
import { extractUserId, removeMember } from './grantOrganisationAccess.js'

export default {
	name: 'GrantOrganisationAccessModal',

	components: {
		NcDialog,
		NcButton,
		NcSelectUsers,
		NcLoadingIcon,
		NcNoteCard,
		NcListItem,
		NcActionButton,
		NcAvatar,
		CloseIcon,
	},

	props: {
		/** Whether the modal is open. */
		open: {
			type: Boolean,
			default: false,
		},

		/** The organisation UUID this modal manages membership for. */
		organisationUuid: {
			type: String,
			default: null,
		},

		/** The organisation's display name, for the dialog title. */
		organisationName: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			members: [],
			loading: false,
			selectedUser: null,
			granting: false,
			revokingUserId: null,
			errorMessage: '',
		}
	},

	watch: {
		/**
		 * Reload the member list every time the dialog opens, so a stale
		 * list from a previous open (possibly a different organisation)
		 * never lingers.
		 *
		 * @param {boolean} isOpen The new `open` prop value.
		 * @return {void}
		 * @spec openspec/specs/multi-org-membership/spec.md#requirement-membership-mutations-must-be-delegated-to-openregister-s-organisationservice-not-reimplemented-req-006
		 */
		open(isOpen) {
			if (isOpen && this.organisationUuid) {
				this.loadMembers()
			}
		},
	},

	methods: {
		/**
		 * Load the organisation's current member list.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/multi-org-membership/spec.md#requirement-membership-mutations-must-be-delegated-to-openregister-s-organisationservice-not-reimplemented-req-006
		 */
		async loadMembers() {
			this.loading = true
			this.errorMessage = ''
			try {
				this.members = await organisatieStore.fetchMembers(this.organisationUuid)
			} catch (error) {
				this.errorMessage = error.message
			} finally {
				this.loading = false
			}
		},

		/**
		 * Grant the selected user access to this organisation.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/multi-org-membership/spec.md#requirement-granting-access-must-only-target-an-existing-nextcloud-user-req-005
		 */
		async onGrant() {
			const userId = extractUserId(this.selectedUser)
			if (!userId) return

			this.granting = true
			this.errorMessage = ''
			try {
				await organisatieStore.grantAccess(this.organisationUuid, userId)
				this.selectedUser = null
				await this.loadMembers()
			} catch (error) {
				this.errorMessage = error.message
			} finally {
				this.granting = false
			}
		},

		/**
		 * Revoke a member's access to this organisation.
		 *
		 * @param {string} userId The member's Nextcloud user id.
		 * @return {Promise<void>}
		 * @spec openspec/specs/multi-org-membership/spec.md#requirement-granting-or-revoking-organisation-access-must-be-restricted-to-a-beheerder-of-that-organisation-req-004
		 */
		async onRevoke(userId) {
			this.revokingUserId = userId
			this.errorMessage = ''
			try {
				await organisatieStore.revokeAccess(this.organisationUuid, userId)
				this.members = removeMember(this.members, userId)
			} catch (error) {
				this.errorMessage = error.message
			} finally {
				this.revokingUserId = null
			}
		},

		/**
		 * Close the dialog and reset transient state.
		 *
		 * @return {void}
		 * @spec exclude Dialog-close UI plumbing — no membership/authorization behaviour.
		 */
		onClose() {
			this.selectedUser = null
			this.errorMessage = ''
			this.$emit('update:open', false)
		},
	},
}
</script>

<style scoped>
.grant-organisation-access__grant {
	display: flex;
	align-items: flex-end;
	gap: 8px;
	margin-bottom: 16px;
}

.grant-organisation-access__grant > *:first-child {
	flex: 1;
}

.grant-organisation-access__members {
	list-style: none;
	margin: 0;
	padding: 0;
}

.grant-organisation-access__empty {
	color: var(--color-text-maxcontrast);
	padding: 8px 0;
}
</style>
