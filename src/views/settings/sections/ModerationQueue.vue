<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<!--
 - @copyright Copyright (c) 2026 Conduction B.V. <info@conduction.nl>
 - @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 -
 - Moderation queue admin section: lists entries of `type` awaiting moderation
 - (organization: registratiestatus=pending, default; beoordeeling:
 - status=pending, stackiq#375) and offers approve/reject per item.
 - Approve flips organisatie to active + publishes it (publicatiedatum=now);
 - for beoordeeling it flips status to approved (the schema's own status-
 - conditioned public RBAC rule does the rest — no publicatiedatum involved).
 - Reject leaves the entry hidden. One generalised component instance is
 - reused per moderated type (`type` prop) rather than a second component —
 - see ModerationService's docblock. Rendered inside the admin settings
 - panel — admin-gated by the IDelegatedSettings framework and by the
 - AuthorizedAdminSetting attribute on every ModerationController method; NOT
 - registered in the in-app router.
 -
 - @spec openspec/specs/catalog-ratings/spec.md#requirement-review-moderation-must-reuse-the-existing-moderation-queue-mechanism-not-a-second-one
 -->

<template>
	<AlwaysVisibleSection
		:name="name"
		:description="description"
		:loading="loading"
		:loadingText="loadingText"
		:showRefreshButton="true"
		:refreshing="loading"
		:refreshButtonText="t('stackiq', 'Refresh queue')"
		@refresh="loadPending">
		<NcEmptyContent
			v-if="items.length === 0"
			:name="t('stackiq', 'Nothing to moderate')"
			:description="emptyDescription">
			<template #icon>
				<CheckCircle :size="40" />
			</template>
		</NcEmptyContent>

		<ul v-else class="moderation-list">
			<li v-for="item in items" :key="item.id" class="moderation-item">
				<div class="moderation-info">
					<span class="moderation-title">{{ itemTitle(item) }}</span>
					<span
						v-if="itemSubtitle(item)"
						class="moderation-subtitle help-text">
						{{ itemSubtitle(item) }}
					</span>
				</div>
				<div class="moderation-actions">
					<NcButton
						variant="success"
						:disabled="busyId === item.id"
						@click="approve(item)">
						<template #icon>
							<NcLoadingIcon
								v-if="busyId === item.id && busyAction === 'approve'"
								:size="20" />
							<Check v-else :size="20" />
						</template>
						{{ t('stackiq', 'Approve') }}
					</NcButton>
					<NcButton
						variant="error"
						:disabled="busyId === item.id"
						@click="reject(item)">
						<template #icon>
							<NcLoadingIcon
								v-if="busyId === item.id && busyAction === 'reject'"
								:size="20" />
							<Close v-else :size="20" />
						</template>
						{{ t('stackiq', 'Reject') }}
					</NcButton>
				</div>
			</li>
		</ul>
	</AlwaysVisibleSection>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { defineComponent } from 'vue'
import Check from 'vue-material-design-icons/Check.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Close from 'vue-material-design-icons/Close.vue'
import AlwaysVisibleSection from '../../../components/AlwaysVisibleSection.vue'
import { apiRequest } from '../../../utils/adminApi.js'
import {
	moderationItemSubtitle,
	moderationItemTitle,
} from '../../../utils/moderationItem.js'

/**
 * Generalised moderation queue admin section — reused for BOTH the
 * organisatie (anonymous registration) and beoordeeling (review) moderated
 * types via the `type` prop, per stackiq#375 ("reuse the
 * ModerationQueue.vue pattern... do not invent a second moderation
 * mechanism").
 *
 * @spec openspec/specs/open-data-publishing/spec.md
 * @spec openspec/specs/catalog-ratings/spec.md#requirement-review-moderation-must-reuse-the-existing-moderation-queue-mechanism-not-a-second-one
 */
/**
 * A label with its first letter in upper case.
 *
 * @param {string} label The label.
 * @return {string} The capitalised label.
 */
function capitalized(label) {
	return label.charAt(0).toUpperCase() + label.slice(1)
}

export default defineComponent({
	name: 'ModerationQueue',
	components: {
		AlwaysVisibleSection,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		Check,
		Close,
		CheckCircle,
	},

	props: {
		/**
		 * The moderated object type — matches ModerationService's `$type`
		 * parameter. Defaults to the original 'organization' behavior so the
		 * existing settings-page instance needs no changes.
		 */
		type: {
			type: String,
			default: 'organization',
		},

		/** Section title. Defaults to the original organisatie copy. */
		name: {
			type: String,
			default: () => t('stackiq', 'Registration moderation'),
		},

		/** Section description. Defaults to the original organisatie copy. */
		description: {
			type: String,
			default: () =>
				t(
					'stackiq',
					'Review anonymous catalog registrations. Approving an entry publishes it; rejecting leaves it hidden.',
				),
		},

		/** Loading-state copy. Defaults to the original organisatie copy. */
		loadingText: {
			type: String,
			default: () => t('stackiq', 'Loading pending registrations…'),
		},

		/** Empty-state copy. Defaults to the original organisatie copy. */
		emptyDescription: {
			type: String,
			default: () =>
				t('stackiq', 'There are no pending registrations right now.'),
		},

		/**
		 * Lower-case singular noun used to build the approve/reject toast
		 * messages ('registration', default — matches the original
		 * organisatie copy exactly; 'review' for beoordeeling).
		 */
		entityLabel: {
			type: String,
			default: 'registration',
		},
	},

	/**
	 * @return {object} Component data.
	 */
	data() {
		return {
			loading: false,
			busyId: null,
			busyAction: null,
			items: [],
		}
	},

	/**
	 * Load the pending queue on mount.
	 *
	 * @spec openspec/specs/open-data-publishing/spec.md
	 */
	async created() {
		await this.loadPending()
	},

	methods: {
		t,
		itemTitle: moderationItemTitle,
		itemSubtitle: moderationItemSubtitle,

		/**
		 * Load the pending entries (of `type`) from the admin endpoint.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/open-data-publishing/spec.md
		 * @spec openspec/specs/catalog-ratings/spec.md#requirement-review-moderation-must-reuse-the-existing-moderation-queue-mechanism-not-a-second-one
		 */
		async loadPending() {
			this.loading = true
			try {
				const data = await apiRequest(
					`moderation/pending?type=${encodeURIComponent(this.type)}`,
				)
				this.items = Array.isArray(data.items) ? data.items : []
			} catch (error) {
				showError(
					t('stackiq', 'Could not load the moderation queue')
						+ ': '
						+ error.message,
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Approve a pending entry.
		 *
		 * @param {object} item - The entry data bag (carries `id`).
		 * @return {Promise<void>}
		 * @spec openspec/specs/open-data-publishing/spec.md
		 */
		async approve(item) {
			await this.decide(item, 'approve')
		},

		/**
		 * Reject a pending entry (stays hidden).
		 *
		 * @param {object} item - The entry data bag (carries `id`).
		 * @return {Promise<void>}
		 * @spec openspec/specs/open-data-publishing/spec.md
		 */
		async reject(item) {
			await this.decide(item, 'reject')
		},

		/**
		 * Shared approve/reject path: check the entry has an identifier, then decide.
		 *
		 * @param {object} item   - The entry data bag.
		 * @param {string} action - 'approve' or 'reject'.
		 * @return {Promise<void>}
		 * @spec openspec/specs/open-data-publishing/spec.md
		 */
		async decide(item, action) {
			const uuid = item.id || item.uuid
			if (!uuid) {
				showError(
					t('stackiq', '{label} has no identifier', {
						label: capitalized(this.entityLabel),
					}),
				)
				return
			}
			await this.postDecision(uuid, action)
		},

		/**
		 * POST the decision, toast, then refresh; the entry is busy meanwhile.
		 *
		 * @param {string} uuid The entry's identifier.
		 * @param {string} action 'approve' or 'reject'.
		 * @return {Promise<void>}
		 * @spec openspec/specs/open-data-publishing/spec.md
		 */
		async postDecision(uuid, action) {
			this.busyId = uuid
			this.busyAction = action
			try {
				const route = this.decisionRoute(uuid, action)
				await apiRequest(route, { method: 'POST' })
				showSuccess(this.decisionMessage(action))
				await this.loadPending()
			} catch (error) {
				this.decisionFailed(error)
			} finally {
				this.busyId = null
				this.busyAction = null
			}
		},

		/**
		 * The moderation route for a decision on one entry.
		 *
		 * @param {string} uuid The entry's identifier.
		 * @param {string} action 'approve' or 'reject'.
		 * @return {string} The route, relative to the admin API.
		 */
		decisionRoute(uuid, action) {
			return `moderation/${encodeURIComponent(uuid)}/${action}?type=${encodeURIComponent(this.type)}`
		},

		/**
		 * The success toast for a decision.
		 *
		 * @param {string} action 'approve' or 'reject'.
		 * @return {string} The translated message.
		 */
		decisionMessage(action) {
			const label = capitalized(this.entityLabel)
			if (action === 'approve') {
				return t('stackiq', '{label} approved and published', { label })
			}
			return t('stackiq', '{label} rejected', { label })
		},

		/**
		 * Show that the decision could not be saved.
		 *
		 * @param {Error} error What went wrong.
		 * @return {void}
		 */
		decisionFailed(error) {
			showError(
				t('stackiq', 'Could not update the {label}', {
					label: this.entityLabel,
				})
					+ ': '
					+ error.message,
			)
		},
	},
})
</script>

<style scoped>
.help-text {
	font-size: 12px;
	color: var(--color-text-lighter);
	margin: 0;
}

.moderation-list {
	list-style: none;
	margin: 0;
	padding: 0;
	max-width: 720px;
}

.moderation-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 1rem;
	padding: 0.75rem 0;
	border-bottom: 1px solid var(--color-border);
}

.moderation-info {
	display: flex;
	flex-direction: column;
	gap: 0.15rem;
	min-width: 0;
}

.moderation-title {
	font-weight: 600;
	overflow-wrap: break-word;
}

.moderation-actions {
	display: flex;
	gap: 0.5rem;
	flex-shrink: 0;
}
</style>
