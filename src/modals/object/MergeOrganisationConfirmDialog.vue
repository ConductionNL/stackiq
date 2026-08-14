<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
 MergeOrganisationConfirmDialog.vue

 Confirm dialog for organisation-merge execute (VNG Softwarecatalogus #141).
 Shown only after a dry-run preview has returned per-relation-type counts —
 the caller (OrganisationMergePanel) owns the dry-run call and passes its
 result in as `counts`. This dialog itself performs no network call; it is a
 pure confirm/cancel surface so the destructive-adjacent execute step always
 has a keyboard-operable, screen-reader-announced confirmation step (WCAG 2.2
 AA — dialog role + focus management come from NcDialog).

 @spec openspec/specs/organisation-merge/spec.md#requirement-the-system-shall-preview-a-merge-with-per-relation-type-counts-before-any-write
-->
<template>
	<NcDialog
		v-if="show"
		:name="t('softwarecatalog', 'Confirm organisation merge')"
		size="normal"
		:canClose="!busy"
		@closing="$emit('cancel')">
		<div class="merge-confirm-dialog">
			<p>
				{{
					t(
						'softwarecatalog',
						'This will permanently fold {source} into {target}.',
						{ source: sourceName, target: targetName },
					)
				}}
			</p>
			<p class="merge-confirm-dialog__note">
				{{
					t(
						'softwarecatalog',
						'{source} will be marked as merged (not deleted) and will disappear from the organisations list.',
						{ source: sourceName },
					)
				}}
			</p>

			<table class="merge-confirm-dialog__counts">
				<caption class="merge-confirm-dialog__counts-caption">
					{{
						t(
							'softwarecatalog',
							'Records that will be re-pointed to {target}:',
							{ target: targetName },
						)
					}}
				</caption>
				<tbody>
					<tr v-for="row in countRows" :key="row.key">
						<th scope="row">
							{{ row.label }}
						</th>
						<td>{{ row.value }}</td>
					</tr>
				</tbody>
			</table>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton :disabled="busy" @click="$emit('cancel')">
				{{ t('softwarecatalog', 'Cancel') }}
			</NcButton>
			<NcButton variant="error" :disabled="busy" @click="$emit('confirm')">
				<template #icon>
					<NcLoadingIcon v-if="busy" :size="20" />
				</template>
				{{ t('softwarecatalog', 'Merge organisations') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

/**
 * @class MergeOrganisationConfirmDialog
 * @module Components/Organisations
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * @spec openspec/specs/organisation-merge/spec.md
 */
export default {
	name: 'MergeOrganisationConfirmDialog',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** Whether the dialog is visible. */
		show: {
			type: Boolean,
			default: false,
		},

		/** Display name of the source organisation (merged away). */
		sourceName: {
			type: String,
			default: '',
		},

		/** Display name of the target organisation (merge destination). */
		targetName: {
			type: String,
			default: '',
		},

		/** Dry-run `counts` object: `{gebruik, contract, contactpersoon, aanbod, compliancy, groupMembers}`. */
		counts: {
			type: Object,
			default: () => ({}),
		},

		/** Whether execute is currently in flight (disables actions, shows spinner). */
		busy: {
			type: Boolean,
			default: false,
		},

		/** An execute error message to surface inline, if any. */
		error: {
			type: String,
			default: '',
		},
	},

	emits: ['confirm', 'cancel'],
	computed: {
		/**
		 * Translated label/value rows for the counts table.
		 *
		 * @return {Array<{key: string, label: string, value: number}>} The rows to render.
		 * @spec openspec/specs/organisation-merge/spec.md#requirement-the-system-shall-preview-a-merge-with-per-relation-type-counts-before-any-write
		 */
		countRows() {
			const labels = {
				gebruik: t('softwarecatalog', 'Usage records'),
				contract: t('softwarecatalog', 'Contracts'),
				contactpersoon: t('softwarecatalog', 'Contact persons'),
				aanbod: t('softwarecatalog', 'Offerings'),
				compliancy: t('softwarecatalog', 'Compliance records'),
				groupMembers: t('softwarecatalog', 'Group members'),
			}

			return Object.keys(labels).map((key) => ({
				key,
				label: labels[key],
				value: this.counts?.[key] ?? 0,
			}))
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.merge-confirm-dialog__note {
	color: var(--color-text-lighter);
	font-size: 0.9em;
}

.merge-confirm-dialog__counts {
	width: 100%;
	margin-top: 12px;
	border-collapse: collapse;
}

.merge-confirm-dialog__counts-caption {
	text-align: left;
	font-weight: 600;
	margin-bottom: 8px;
}

.merge-confirm-dialog__counts th,
.merge-confirm-dialog__counts td {
	text-align: left;
	padding: 4px 8px 4px 0;
	border-bottom: 1px solid var(--color-border);
}

.merge-confirm-dialog__counts td {
	text-align: right;
}
</style>
