<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 OrganisationSwitcher — combined active-organisation label + switcher +
 self-service "manage members" entry point, rendered in CnAppRoot's
 `#header-actions` slot (the default `#tenant-badge` slot is suppressed by
 App.vue in favour of this component; see design.md's "no slot-inject
 dependency" decision).

 Self-contained: receives the user's own organisations, the active uuid, and
 whether the caller holds the `beheerder` role, as props (all sourced once
 by App.vue from the existing `/api/me` endpoint). On switch, POSTs directly
 to OpenRegister's own `/api/organisations/{uuid}/set-active` — membership
 is verified server-side there (OrganisationService::setActiveOrganisation()
 throws unless the caller is a member); this component never trusts
 anything but that response. A successful switch reloads the page so every
 view re-fetches against the new active organisation with zero risk of a
 partially-updated, cross-organisation-stale surface (REQ-002). A refused
 switch (non-member) surfaces inline and does NOT reload — the active
 organisation is unchanged.

 The "Manage members" entry (gated client-side by `isBeheerder` — a UX hint
 only, see GrantOrganisationAccessModal.vue's docblock) opens
 GrantOrganisationAccessModal.vue for the ACTIVE organisation.

 @spec openspec/specs/multi-org-membership/spec.md#requirement-switching-the-active-organisation-must-be-verified-against-server-side-membership-never-a-client-supplied-claim-req-001
-->
<template>
	<div class="organisation-switcher">
		<NcActions
			:menu-name="activeOrganisationName"
			:disabled="switching"
			class="organisation-switcher__actions">
			<template #icon>
				<NcLoadingIcon v-if="switching" :size="20" />
				<DomainIcon v-else :size="20" />
			</template>
			<NcActionButton
				v-for="organisation in otherOrganisations"
				:key="organisation.uuid"
				close-after-click
				@click="switchTo(organisation.uuid)">
				{{ organisation.naam }}
			</NcActionButton>
			<NcActionSeparator v-if="otherOrganisations.length > 0 && isBeheerder" />
			<NcActionButton
				v-if="isBeheerder"
				close-after-click
				@click="manageMembersOpen = true">
				<template #icon>
					<AccountMultipleIcon :size="20" />
				</template>
				{{ t('softwarecatalog', 'Manage members') }}
			</NcActionButton>
		</NcActions>
		<NcNoteCard v-if="errorMessage" type="error" class="organisation-switcher__error">
			{{ errorMessage }}
		</NcNoteCard>
		<GrantOrganisationAccessModal
			:open="manageMembersOpen"
			:organisation-uuid="activeOrganisationUuid"
			:organisation-name="activeOrganisationName"
			@update:open="manageMembersOpen = $event" />
	</div>
</template>

<script>
import { NcActions, NcActionButton, NcActionSeparator, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import DomainIcon from 'vue-material-design-icons/Domain.vue'
import AccountMultipleIcon from 'vue-material-design-icons/AccountMultiple.vue'
import GrantOrganisationAccessModal from '../../modals/GrantOrganisationAccessModal.vue'
import { resolveActiveOrganisationName, resolveOtherOrganisations, resolveSwitchError } from './organisationSwitcher.js'

export default {
	name: 'OrganisationSwitcher',

	components: {
		NcActions,
		NcActionButton,
		NcActionSeparator,
		NcLoadingIcon,
		NcNoteCard,
		DomainIcon,
		AccountMultipleIcon,
		GrantOrganisationAccessModal,
	},

	props: {
		/**
		 * The authenticated user's own organisations —
		 * `[{ uuid, naam, id, slug }]`, exactly as `/api/me` returns them.
		 * Never fetched by this component itself, so there is exactly one
		 * source of truth for "which organisations does this user belong to".
		 */
		organisations: {
			type: Array,
			required: true,
		},

		/**
		 * The currently-active organisation's UUID, or null.
		 */
		activeOrganisationUuid: {
			type: String,
			default: null,
		},

		/**
		 * Whether the caller holds the global `beheerder` NC group — a
		 * client-side hint gating the "Manage members" entry. The actual
		 * per-organisation authorization is re-verified server-side.
		 */
		isBeheerder: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			switching: false,
			errorMessage: '',
			manageMembersOpen: false,
		}
	},

	computed: {
		/**
		 * Display name of the active organisation, falling back to a
		 * translated placeholder when not yet resolved.
		 *
		 * @return {string}
		 * @spec openspec/specs/multi-org-membership/spec.md#requirement-the-organisation-switcher-must-list-only-the-authenticated-users-own-organisations-req-003
		 */
		activeOrganisationName() {
			return resolveActiveOrganisationName(
				this.organisations,
				this.activeOrganisationUuid,
				this.t('softwarecatalog', 'Select an organisation'),
			)
		},

		/**
		 * The user's organisations excluding the currently-active one —
		 * the switch-target list. Empty when the user belongs to zero or
		 * one organisation, in which case the dropdown still renders (for
		 * the "Manage members" entry) but with no switch targets — this
		 * mirrors CnTenantBadge's auto-hide contract for the switching
		 * affordance specifically, not the whole component.
		 *
		 * @return {Array}
		 * @spec openspec/specs/multi-org-membership/spec.md#requirement-the-organisation-switcher-must-list-only-the-authenticated-users-own-organisations-req-003
		 */
		otherOrganisations() {
			return resolveOtherOrganisations(this.organisations, this.activeOrganisationUuid)
		},
	},

	methods: {
		/**
		 * Switch the active organisation via OpenRegister's own endpoint.
		 * Membership is verified server-side there — this method never
		 * assumes the switch will succeed, and never updates any local
		 * "active organisation" state itself: a successful switch reloads
		 * the page, which re-derives everything from the new session.
		 *
		 * @param {string} uuid The organisation UUID to switch to.
		 * @return {Promise<void>}
		 * @spec openspec/specs/multi-org-membership/spec.md#requirement-switching-the-active-organisation-must-be-verified-against-server-side-membership-never-a-client-supplied-claim-req-001
		 */
		async switchTo(uuid) {
			if (uuid === this.activeOrganisationUuid || this.switching) return

			this.switching = true
			this.errorMessage = ''

			try {
				const url = generateUrl('/apps/openregister/api/organisations/{uuid}/set-active', { uuid })
				const response = await fetch(url, {
					method: 'POST',
					headers: { requesttoken: OC.requestToken },
				})

				const body = response.ok ? null : await response.json().catch(() => ({}))
				const error = resolveSwitchError(response.ok, body, this.t('softwarecatalog', 'Failed to switch organisation'))
				if (error) {
					throw new Error(error)
				}

				window.location.reload()
			} catch (error) {
				this.errorMessage = error.message
				this.switching = false
			}
		},
	},
}
</script>

<style scoped>
.organisation-switcher {
	display: flex;
	align-items: center;
}

.organisation-switcher__error {
	margin-left: 8px;
}
</style>
