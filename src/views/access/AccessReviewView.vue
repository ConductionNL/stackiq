<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 Access review of one organisation's people (organisations-role-mapping-and-access-review).

 A thin renderer over `GET /api/access-review/{organisationUuid}`: each person
 with an account, their roles and where each comes from, last sign-in,
 account status, when and by whom access was last reviewed, and whether a
 review is due. Keep access and Remove role post to the review endpoints; a
 Nextcloud admin can also disable the account.

 @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
-->
<template>
	<div class="access-review">
		<header class="access-review__header">
			<h2 class="access-review__title">
				{{ t('stackiq', 'Access review') }}
			</h2>
			<p class="access-review__intro">
				{{ t('stackiq', 'Check whether every person still needs the roles they hold, and confirm or withdraw them.') }}
			</p>
		</header>

		<NcSelect
			v-model="selectedOrg"
			class="access-review__org"
			:options="organisationOptions"
			:inputLabel="t('stackiq', 'Organisation')"
			:placeholder="t('stackiq', 'Select an organisation')"
			trackBy="uuid"
			label="label"
			data-testid="access-review-organisation"
			@update:modelValue="loadReview" />

		<NcLoadingIcon v-if="loading" :size="44" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcEmptyContent
			v-else-if="!review"
			:name="t('stackiq', 'Select an organisation')"
			:description="t('stackiq', 'Pick an organisation above to review the access of its people.')" />

		<template v-else>
			<NcNoteCard :type="dueCount > 0 ? 'warning' : 'success'" data-testid="access-review-summary">
				{{ summary }}
			</NcNoteCard>

			<CnDataTable
				:rows="review.results"
				:columns="columns"
				rowKey="id"
				:emptyText="t('stackiq', 'No people with an account in this organisation')"
				data-testid="access-review-table">
				<template #column-person="{ row }">
					<strong>{{ row.displayName || row.username }}</strong>
					<div class="access-review__muted">
						{{ row.username }}
					</div>
				</template>
				<template #column-roles="{ row }">
					<span v-if="row.roles.length === 0" class="access-review__muted">
						{{ t('stackiq', 'No roles') }}
					</span>
					<ul v-else class="access-review__roles">
						<li v-for="role in row.roles" :key="role.role" :data-testid="'access-review-role-' + role.role">
							<span class="access-review__role">{{ role.role }}</span>
							<span class="access-review__muted">{{ sourceText(role) }}</span>
							<NcButton
								v-if="role.removable && !row.self"
								variant="tertiary"
								:disabled="busy === row.id"
								:aria-label="t('stackiq', 'Remove {role} from {person}', { role: role.role, person: row.displayName || row.username })"
								@click="withdraw(row, role.role)">
								{{ t('stackiq', 'Remove role') }}
							</NcButton>
						</li>
					</ul>
				</template>
				<template #column-lastLogin="{ row }">
					{{ row.lastLogin ? formatDate(row.lastLogin) : t('stackiq', 'Never') }}
				</template>
				<template #column-enabled="{ row }">
					{{ row.enabled ? t('stackiq', 'Enabled') : t('stackiq', 'Disabled') }}
				</template>
				<template #column-reviewed="{ row }">
					<template v-if="row.accessReviewedAt">
						{{ formatDate(row.accessReviewedAt) }}
						<div v-if="row.accessReviewedBy" class="access-review__muted">
							{{ t('stackiq', 'by {reviewer}', { reviewer: row.accessReviewedBy }) }}
						</div>
					</template>
					<template v-else>
						{{ t('stackiq', 'Never') }}
					</template>
				</template>
				<template #column-due="{ row }">
					<span :class="row.due ? 'access-review__due' : 'access-review__current'">
						{{ row.due ? t('stackiq', 'Due') : t('stackiq', 'Current') }}
					</span>
				</template>
				<template #column-actions="{ row }">
					<div class="access-review__actions">
						<NcButton
							variant="secondary"
							:disabled="row.self || busy === row.id"
							:title="row.self ? t('stackiq', 'You cannot review your own access.') : ''"
							:data-testid="'access-review-keep-' + row.id"
							@click="confirm(row)">
							{{ t('stackiq', 'Keep access') }}
						</NcButton>
						<NcButton
							v-if="isAdmin && row.enabled && !row.self"
							variant="error"
							:disabled="busy === row.id"
							:data-testid="'access-review-disable-' + row.id"
							@click="disable(row)">
							{{ t('stackiq', 'Disable account') }}
						</NcButton>
					</div>
				</template>
			</CnDataTable>
		</template>
	</div>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { useLiveCollections } from '../../composables/useLiveCollections.js'
import { objectStore } from '../../store/store.js'
import { resolveUuid } from '../../utils/lifecyclePhase.js'
import { loadObjectTypeCollection } from '../../utils/objectTypes.js'

/**
 * The message of a refused request, or a fallback.
 *
 * @param {Error} error The axios error.
 * @param {string} fallback The text when the answer names none.
 * @return {string} The message.
 */
function messageOf(error, fallback) {
	return error?.response?.data?.message || error?.response?.data?.error || fallback
}

export default {
	name: 'AccessReviewView',
	components: {
		CnDataTable,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	/**
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
	 */
	setup() {
		useLiveCollections(objectStore, ['organization'])
		return {}
	},

	data() {
		return {
			loading: false,
			error: null,
			selectedOrg: null,
			review: null,
			busy: null,
		}
	},

	computed: {
		/**
		 * The organisations the user can see, as picker options.
		 *
		 * @return {Array<{uuid: string, label: string}>} The options.
		 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
		 */
		organisationOptions() {
			return (objectStore.getCollection('organization')?.results || []).map((org) => {
				const data = org.object || org
				const uuid = resolveUuid(org.uuid ?? org.id ?? org['@self']?.id ?? org)
				return { uuid, label: data.name || data.title || uuid }
			})
		},

		/**
		 * Whether the user is a Nextcloud admin, who may also disable accounts.
		 *
		 * @return {boolean} True for an admin.
		 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-004-a-reviewer-shall-confirm-or-withdraw-a-persons-access-and-the-decision-shall-be-recorded
		 */
		isAdmin() {
			return getCurrentUser()?.isAdmin === true
		},

		/**
		 * How many listed people are due for review.
		 *
		 * @return {number} The count.
		 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
		 */
		dueCount() {
			return (this.review?.results || []).filter((row) => row.due).length
		},

		/**
		 * The line above the table.
		 *
		 * @return {string} How many people are due, and the interval.
		 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
		 */
		summary() {
			return t('stackiq', '{due} of {total} people are due for review. A review is due when access was not reviewed in the last {days} days.', {
				due: this.dueCount,
				total: (this.review?.results || []).length,
				days: this.review?.reviewIntervalDays ?? '',
			})
		},

		/**
		 * The table columns.
		 *
		 * @return {Array<{key: string, label: string}>} The columns.
		 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
		 */
		columns() {
			return [
				{ key: 'person', label: t('stackiq', 'Person') },
				{ key: 'roles', label: t('stackiq', 'Roles') },
				{ key: 'lastLogin', label: t('stackiq', 'Last sign-in') },
				{ key: 'enabled', label: t('stackiq', 'Account') },
				{ key: 'reviewed', label: t('stackiq', 'Last reviewed') },
				{ key: 'due', label: t('stackiq', 'Review') },
				{ key: 'actions', label: t('stackiq', 'Actions') },
			]
		},
	},

	watch: {
		organisationOptions: {
			immediate: true,
			/**
			 * Select the only organisation a reviewer can see.
			 *
			 * @param {Array} options The organisation options.
			 * @return {void}
			 */
			handler(options) {
				if (!this.selectedOrg && options.length === 1) {
					this.selectedOrg = options[0]
					this.loadReview()
				}
			},
		},
	},

	/**
	 * Load the organisations the picker offers.
	 *
	 * A page opened straight from a link has no organisation collection yet,
	 * and live updates only follow a list someone loaded: without this the
	 * picker offered nothing (found on a live instance).
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
	 */
	async mounted() {
		await loadObjectTypeCollection('organization')
	},

	methods: {
		t,

		/**
		 * Load the review of the selected organisation.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
		 */
		async loadReview() {
			const uuid = this.selectedOrg?.uuid
			if (!uuid) {
				this.review = null
				return
			}
			this.loading = true
			this.error = null
			try {
				const { data } = await axios.get(generateUrl('/apps/stackiq/api/access-review/{uuid}', { uuid }))
				this.review = { ...data, results: data.results || [] }
			} catch (error) {
				this.review = null
				this.error = messageOf(error, t('stackiq', 'The access review could not be read.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Keep access: record the review and show it on the row.
		 *
		 * @param {object} row The person's row.
		 * @return {Promise<void>}
		 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-004-a-reviewer-shall-confirm-or-withdraw-a-persons-access-and-the-decision-shall-be-recorded
		 */
		async confirm(row) {
			this.busy = row.id
			try {
				const { data } = await axios.post(generateUrl('/apps/stackiq/api/access-review/{id}/confirm', { id: row.id }))
				Object.assign(row, {
					accessReviewedAt: data.accessReviewedAt,
					accessReviewedBy: data.accessReviewedBy,
					due: false,
				})
				showSuccess(t('stackiq', 'Access of {person} confirmed', { person: row.displayName || row.username }))
			} catch (error) {
				showError(messageOf(error, t('stackiq', 'The access review could not be saved.')))
			} finally {
				this.busy = null
			}
		},

		/**
		 * Remove role: withdraw it from the contact person, then reload the rows.
		 *
		 * @param {object} row The person's row.
		 * @param {string} role The role to withdraw.
		 * @return {Promise<void>}
		 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-004-a-reviewer-shall-confirm-or-withdraw-a-persons-access-and-the-decision-shall-be-recorded
		 */
		async withdraw(row, role) {
			this.busy = row.id
			try {
				await axios.post(generateUrl('/apps/stackiq/api/access-review/{id}/withdraw', { id: row.id }), { role })
				showSuccess(t('stackiq', '{role} removed from {person}', { role, person: row.displayName || row.username }))
				await this.loadReview()
			} catch (error) {
				showError(messageOf(error, t('stackiq', 'The access review could not be saved.')))
			} finally {
				this.busy = null
			}
		},

		/**
		 * Disable account, for a Nextcloud admin.
		 *
		 * @param {object} row The person's row.
		 * @return {Promise<void>}
		 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-004-a-reviewer-shall-confirm-or-withdraw-a-persons-access-and-the-decision-shall-be-recorded
		 */
		async disable(row) {
			this.busy = row.id
			try {
				await axios.post(generateUrl('/apps/stackiq/api/contactpersonen/{id}/disable', { id: row.id }))
				row.enabled = false
				showSuccess(t('stackiq', 'Account of {person} disabled', { person: row.displayName || row.username }))
			} catch (error) {
				showError(messageOf(error, t('stackiq', 'The account could not be disabled.')))
			} finally {
				this.busy = null
			}
		},

		/**
		 * Where a role comes from, in words.
		 *
		 * @param {object} role The role with its sources.
		 * @return {string} The sources, joined.
		 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-004-a-reviewer-shall-confirm-or-withdraw-a-persons-access-and-the-decision-shall-be-recorded
		 */
		sourceText(role) {
			return (role.sources || []).map((source) => {
				if (source.type === 'group') {
					return t('stackiq', 'from the group {group}', { group: source.group })
				}
				if (source.type === 'organisationType') {
					return t('stackiq', 'from the organisation type {type}', { type: source.organisationType })
				}
				return t('stackiq', 'on the contact person')
			}).join(', ')
		},

		/**
		 * A date in the user's locale.
		 *
		 * @param {string} value An ISO date.
		 * @return {string} The date.
		 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
		 */
		formatDate(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.access-review {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 4);
}

.access-review__title {
	margin: 0;
}

.access-review__intro,
.access-review__muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.access-review__org {
	max-width: 400px;
}

.access-review__roles {
	margin: 0;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 1);
}

.access-review__role {
	font-weight: bold;
	margin-inline-end: calc(var(--default-grid-baseline, 4px) * 2);
}

.access-review__due {
	color: var(--color-error-text);
	font-weight: bold;
}

.access-review__current {
	color: var(--color-success-text);
}

.access-review__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}
</style>
