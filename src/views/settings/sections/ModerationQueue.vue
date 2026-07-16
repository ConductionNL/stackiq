<!--
 - @copyright Copyright (c) 2026 Conduction B.V. <info@conduction.nl>
 - @license AGPL-3.0-or-later
 -
 - Moderation queue admin section: lists anonymous registrations awaiting
 - moderation (registratiestatus=pending) and offers approve/reject per item.
 - Approve flips the entry to active + publishes it (publicatiedatum=now);
 - reject leaves it unpublished. Rendered inside the admin settings panel —
 - admin-gated by the IDelegatedSettings framework and by the
 - AuthorizedAdminSetting attribute on every ModerationController method; NOT
 - registered in the in-app router.
 -->

<template>
	<AlwaysVisibleSection
		:name="t('softwarecatalog', 'Registration moderation')"
		:description="t('softwarecatalog', 'Review anonymous catalog registrations. Approving an entry publishes it; rejecting leaves it hidden.')"
		:loading="loading"
		:loading-text="t('softwarecatalog', 'Loading pending registrations…')"
		:show-refresh-button="true"
		:refreshing="loading"
		:refresh-button-text="t('softwarecatalog', 'Refresh queue')"
		@refresh="loadPending">
		<NcEmptyContent
			v-if="items.length === 0"
			:name="t('softwarecatalog', 'Nothing to moderate')"
			:description="t('softwarecatalog', 'There are no pending registrations right now.')">
			<template #icon>
				<CheckCircle :size="40" />
			</template>
		</NcEmptyContent>

		<ul v-else class="moderation-list">
			<li v-for="item in items" :key="item.id" class="moderation-item">
				<div class="moderation-info">
					<span class="moderation-title">{{ itemTitle(item) }}</span>
					<span v-if="itemSubtitle(item)" class="moderation-subtitle help-text">
						{{ itemSubtitle(item) }}
					</span>
				</div>
				<div class="moderation-actions">
					<NcButton
						type="success"
						:disabled="busyId === item.id"
						@click="approve(item)">
						<template #icon>
							<NcLoadingIcon v-if="busyId === item.id && busyAction === 'approve'" :size="20" />
							<Check v-else :size="20" />
						</template>
						{{ t('softwarecatalog', 'Approve') }}
					</NcButton>
					<NcButton
						type="error"
						:disabled="busyId === item.id"
						@click="reject(item)">
						<template #icon>
							<NcLoadingIcon v-if="busyId === item.id && busyAction === 'reject'" :size="20" />
							<Close v-else :size="20" />
						</template>
						{{ t('softwarecatalog', 'Reject') }}
					</NcButton>
				</div>
			</li>
		</ul>
	</AlwaysVisibleSection>
</template>

<script>
import { defineComponent } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
} from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import AlwaysVisibleSection from '../../../components/AlwaysVisibleSection.vue'
import { apiRequest } from '../../../utils/adminApi.js'
import { moderationItemTitle, moderationItemSubtitle } from '../../../utils/moderationItem.js'

/**
 * Registration moderation queue admin section.
 *
 * @spec openspec/specs/open-data-publishing/spec.md
 */
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
		 * Load the pending registrations from the admin endpoint.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/open-data-publishing/spec.md
		 */
		async loadPending() {
			this.loading = true
			try {
				const data = await apiRequest('moderation/pending')
				this.items = Array.isArray(data.items) ? data.items : []
			} catch (error) {
				showError(t('softwarecatalog', 'Could not load the moderation queue') + ': ' + error.message)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Approve a pending registration (active + published).
		 *
		 * @param {object} item - The registration data bag (carries `id`).
		 * @return {Promise<void>}
		 * @spec openspec/specs/open-data-publishing/spec.md
		 */
		async approve(item) {
			await this.decide(item, 'approve', t('softwarecatalog', 'Registration approved and published'))
		},

		/**
		 * Reject a pending registration (stays hidden).
		 *
		 * @param {object} item - The registration data bag (carries `id`).
		 * @return {Promise<void>}
		 * @spec openspec/specs/open-data-publishing/spec.md
		 */
		async reject(item) {
			await this.decide(item, 'reject', t('softwarecatalog', 'Registration rejected'))
		},

		/**
		 * Shared approve/reject path: POST the decision, toast, then refresh.
		 *
		 * @param {object} item       - The registration data bag.
		 * @param {string} action     - 'approve' or 'reject'.
		 * @param {string} successMsg - Success toast message.
		 * @return {Promise<void>}
		 * @spec openspec/specs/open-data-publishing/spec.md
		 */
		async decide(item, action, successMsg) {
			const uuid = item.id || item.uuid
			if (!uuid) {
				showError(t('softwarecatalog', 'Registration has no identifier'))
				return
			}
			this.busyId = uuid
			this.busyAction = action
			try {
				await apiRequest(`moderation/${encodeURIComponent(uuid)}/${action}`, { method: 'POST' })
				showSuccess(successMsg)
				await this.loadPending()
			} catch (error) {
				showError(t('softwarecatalog', 'Could not update the registration') + ': ' + error.message)
			} finally {
				this.busyId = null
				this.busyAction = null
			}
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
	word-break: break-word;
}

.moderation-actions {
	display: flex;
	gap: 0.5rem;
	flex-shrink: 0;
}
</style>
