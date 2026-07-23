<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<CnWidgetWrapper
		:title="t('softwarecatalog', 'Merge organisation')"
		title-icon-position="left"
		:show-refresh="false"
		:show-request-feature="false">
		<template #title-icon>
			<CnIcon name="CallMerge" :size="20" />
		</template>

		<div class="organisation-merge-panel">
			<NcLoadingIcon v-if="loading" :size="32" :name="t('softwarecatalog', 'Loading merge status')" />

			<template v-else>
				<!-- Already merged (tombstoned) — read-only redirect notice, no controls. -->
				<template v-if="isTombstoned">
					<NcNoteCard type="warning">
						{{ t('softwarecatalog', 'This organisation has been merged and is no longer active.') }}
					</NcNoteCard>
					<NcButton v-if="mergedInto" @click="goToTarget">
						<template #icon>
							<ArrowRight :size="20" />
						</template>
						{{ t('softwarecatalog', 'Go to the organisation it was merged into') }}
					</NcButton>
				</template>

				<!-- Active organisation — merge controls. Hidden entirely for non-admins
				     (backend enforces 403 regardless; this avoids offering a control
				     that can never succeed). -->
				<template v-else-if="isAdmin">
					<p class="organisation-merge-panel__intro">
						{{ t('softwarecatalog', 'Fold this organisation into another one (gemeentelijke herindeling or leveranciersovername). Every contract, usage record, contact person, offering and compliance record is re-pointed to the target; this organisation is then marked as merged, never deleted.') }}
					</p>

					<NcSelect v-model="selectedTarget"
						:options="targetOptions"
						:input-label="t('softwarecatalog', 'Target organisation')"
						:placeholder="t('softwarecatalog', 'Select the organisation to merge into')"
						label="label"
						track-by="value"
						:loading="loadingTargets"
						:disabled="previewing || busy"
						@open="loadTargetOptions" />

					<NcNoteCard v-for="blocker in blockers" :key="blocker.type" type="error">
						{{ blocker.message }}
					</NcNoteCard>

					<NcNoteCard v-if="error" type="error">
						{{ error }}
					</NcNoteCard>

					<NcNoteCard v-if="success" type="success">
						{{ t('softwarecatalog', 'Organisation successfully merged.') }}
					</NcNoteCard>

					<NcButton v-if="!success"
						type="secondary"
						:disabled="!selectedTarget || previewing || busy"
						@click="preview">
						<template #icon>
							<NcLoadingIcon v-if="previewing" :size="20" />
							<Eye v-else :size="20" />
						</template>
						{{ t('softwarecatalog', 'Preview merge') }}
					</NcButton>
				</template>
			</template>
		</div>

		<MergeOrganisationConfirmDialog
			:show="showConfirm"
			:source-name="sourceName"
			:target-name="selectedTarget ? selectedTarget.label : ''"
			:counts="dryRunCounts"
			:busy="busy"
			:error="confirmError"
			@confirm="execute"
			@cancel="showConfirm = false" />
	</CnWidgetWrapper>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { CnWidgetWrapper, CnIcon } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'

import Eye from 'vue-material-design-icons/Eye.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'

import { organisatieStore, settingsStore } from '../../store/store.js'
import MergeOrganisationConfirmDialog from '../../modals/object/MergeOrganisationConfirmDialog.vue'

/**
 * @class OrganisationMergePanel
 * @module Components/Organisations
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * OrganisatieDetail body widget (VNG Softwarecatalogus #141 —
 * gemeentelijke herindeling / leveranciersovername). Admin-only: hidden for
 * non-admin viewers (the backend also enforces this with a 403 + explicit
 * per-object guard — see MergeController — this is a display-only
 * convenience, not the authorization boundary).
 *
 * Flow: pick a target organisation -> "Preview merge" runs a dry-run
 * (no writes) -> a confirm dialog shows the per-relation-type counts ->
 * confirming calls execute. An already-tombstoned source renders a
 * read-only redirect notice instead of merge controls.
 *
 * @spec openspec/specs/organisation-merge/spec.md
 */
export default {
	name: 'OrganisationMergePanel',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		CnWidgetWrapper,
		CnIcon,
		Eye,
		ArrowRight,
		MergeOrganisationConfirmDialog,
	},
	props: {
		/** The organisation OR object uuid (passed by CnDetailPage as `objectId`). */
		objectId: {
			type: [String, Number],
			default: '',
		},
		/** The organisation register slug. */
		register: {
			type: String,
			default: 'voorzieningen',
		},
		/** The organisation schema slug. */
		schema: {
			type: String,
			default: 'organisatie',
		},
	},
	data() {
		return {
			loading: true,
			loadingTargets: false,
			previewing: false,
			busy: false,
			showConfirm: false,
			sourceName: '',
			status: '',
			mergedInto: '',
			targetOptions: [],
			selectedTarget: null,
			blockers: [],
			dryRunCounts: {},
			error: '',
			confirmError: '',
			success: false,
		}
	},
	computed: {
		/**
		 * Whether the source organisation is already tombstoned (merged away).
		 *
		 * @return {boolean} True when `status` equals the tombstone status.
		 * @spec openspec/specs/organisation-merge/spec.md#requirement-the-source-organisation-must-be-tombstoned-never-hard-deleted
		 */
		isTombstoned() {
			return this.status === 'samengevoegd'
		},
		/**
		 * Whether the signed-in user is an administrator (display-only gate —
		 * the backend re-checks independently on every call).
		 *
		 * @return {boolean} True for an admin user.
		 */
		isAdmin() {
			return Boolean(settingsStore.getIsAdmin)
		},
	},
	async mounted() {
		await this.loadSource()
	},
	methods: {
		t,
		/**
		 * Load the source organisation's status/mergedInto/name.
		 *
		 * @return {Promise<void>} Resolves once the source is loaded.
		 * @spec openspec/specs/organisation-merge/spec.md#requirement-the-source-organisation-must-be-tombstoned-never-hard-deleted
		 */
		async loadSource() {
			this.loading = true
			if (!this.objectId) {
				this.loading = false
				return
			}
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/{register}/{schema}/{id}',
					{ register: this.register, schema: this.schema, id: String(this.objectId) },
				)
				const { data } = await axios.get(url)
				const obj = data && data['@self'] !== undefined ? data : (data.object || data)
				this.sourceName = obj.naam || obj.name || t('softwarecatalog', 'Unknown organisation')
				this.status = obj.status || ''
				this.mergedInto = obj.mergedInto || ''
			} catch (e) {
				// Non-fatal — the panel still renders merge controls with an
				// empty source name rather than failing the whole detail page.
				this.sourceName = t('softwarecatalog', 'Unknown organisation')
			} finally {
				this.loading = false
			}
		},
		/**
		 * Lazily load candidate target organisations (every organisation
		 * except this one and any already-tombstoned source). The backend
		 * re-validates independently, so an over-inclusive list here is not a
		 * correctness risk — only a UX one, and the blocker error surfaces if
		 * a stale option is picked.
		 *
		 * @return {Promise<void>} Resolves once options are loaded.
		 * @spec openspec/specs/organisation-merge/spec.md#requirement-merge-requests-must-be-validated-and-rejected-with-blockers-before-any-write
		 */
		async loadTargetOptions() {
			if (this.targetOptions.length > 0 || this.loadingTargets) {
				return
			}
			this.loadingTargets = true
			try {
				const url = generateUrl('/apps/openregister/api/objects/{register}/{schema}', {
					register: this.register,
					schema: this.schema,
				})
				const { data } = await axios.get(url, { params: { _limit: 500 } })
				const results = data?.results || data?.data || []
				this.targetOptions = results
					.filter((org) => {
						const id = org.id || org['@self']?.id
						return String(id) !== String(this.objectId) && org.status !== 'samengevoegd'
					})
					.map((org) => ({
						value: org.id || org['@self']?.id,
						label: org.naam || org.name || org['@self']?.name || String(org.id),
					}))
			} catch (e) {
				this.error = t('softwarecatalog', 'Could not load target organisations.')
			} finally {
				this.loadingTargets = false
			}
		},
		/**
		 * Run the dry-run preview and open the confirm dialog when the merge
		 * is legal (no blockers).
		 *
		 * @return {Promise<void>} Resolves once the preview completes.
		 * @spec openspec/specs/organisation-merge/spec.md#requirement-the-system-shall-preview-a-merge-with-per-relation-type-counts-before-any-write
		 */
		async preview() {
			if (!this.selectedTarget) {
				return
			}
			this.previewing = true
			this.error = ''
			this.blockers = []
			try {
				const result = await organisatieStore.dryRunMerge(String(this.objectId), String(this.selectedTarget.value))
				this.dryRunCounts = result.counts || {}
				if (result.blockers && result.blockers.length > 0) {
					this.blockers = result.blockers
					return
				}
				this.confirmError = ''
				this.showConfirm = true
			} catch (e) {
				this.error = e.message || t('softwarecatalog', 'Could not preview the merge.')
			} finally {
				this.previewing = false
			}
		},
		/**
		 * Execute the merge after confirmation.
		 *
		 * @return {Promise<void>} Resolves once execute completes or fails.
		 * @spec openspec/specs/organisation-merge/spec.md#requirement-execute-must-re-point-every-relation-type-while-preserving-every-unrelated-field-on-each-object
		 */
		async execute() {
			if (!this.selectedTarget) {
				return
			}
			this.busy = true
			this.confirmError = ''
			try {
				await organisatieStore.executeMerge(String(this.objectId), String(this.selectedTarget.value))
				this.showConfirm = false
				this.success = true
				this.status = 'samengevoegd'
				this.mergedInto = String(this.selectedTarget.value)
				showSuccess(t('softwarecatalog', 'Organisation successfully merged.'))
			} catch (e) {
				this.confirmError = e.message || t('softwarecatalog', 'Could not merge the organisations.')
			} finally {
				this.busy = false
			}
		},
		/**
		 * Navigate to the organisation this source was merged into.
		 *
		 * @return {void}
		 * @spec openspec/specs/organisation-merge/spec.md#requirement-the-source-organisation-must-be-tombstoned-never-hard-deleted
		 */
		goToTarget() {
			if (!this.mergedInto) {
				return
			}
			this.$router.push({ name: 'OrganisatieDetail', params: { id: this.mergedInto } })
		},
	},
}
</script>

<style scoped>
.organisation-merge-panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.organisation-merge-panel__intro {
	color: var(--color-text-lighter);
	font-size: 0.9em;
}
</style>
