<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

SharedUsageCell.vue

Table cell widget `sharedUsage`: on Applications in use, the column Shared
names the organisation that runs a shared usage for the cooperation and how
many organisations share it ("Drechtsteden-Zuid, 3"). Empty for a usage
without participants.

@spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
-->

<template>
	<span
		v-if="summary"
		class="shared-usage-cell"
		data-testid="shared-usage-cell"
		:title="n('stackiq', 'Shared by {count} organisation', 'Shared by {count} organisations', summary.count, { count: summary.count })">
		{{ t('stackiq', '{owner}, {count}', { owner: ownerLabel, count: summary.count }) }}
	</span>
</template>

<script>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { sharedSummary } from '../../utils/sharedUsage.js'

export default {
	name: 'SharedUsageCell',

	props: {
		/** The cell value: the usage's participants. */
		value: {
			type: [Array, String, Object],
			default: null,
		},

		/** The usage row. */
		row: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			ownerName: '',
		}
	},

	computed: {
		/**
		 * @return {{owner: string, ownerName: string, count: number}|null} The share summary.
		 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
		 */
		summary() {
			return sharedSummary({ ...(this.row ?? {}), participants: Array.isArray(this.value) ? this.value : this.row?.participants })
		},

		/**
		 * @return {string} The owner's name, else its uuid, else "a cooperation".
		 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
		 */
		ownerLabel() {
			return this.summary?.ownerName || this.ownerName || this.summary?.owner || t('stackiq', 'a cooperation')
		},
	},

	watch: {
		'summary.owner': {
			immediate: true,
			/**
			 * Read the owner's name when the row only holds its uuid.
			 *
			 * @param {string} owner The owner uuid.
			 * @return {Promise<void>}
			 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
			 */
			async handler(owner) {
				this.ownerName = ''
				if (!owner || this.summary?.ownerName) {
					return
				}
				try {
					// Loaded on demand: the cell registry is imported where the store is not wanted.
					const [{ objectStore }, { ensureObjectTypeOrSlug }] = await Promise.all([
						import('../../store/store.js'),
						import('../../utils/objectTypes.js'),
					])
					ensureObjectTypeOrSlug('organization')
					const organisation = await objectStore.fetchObject('organization', owner)
					if (owner === this.summary?.owner && typeof organisation?.name === 'string') {
						this.ownerName = organisation.name
					}
				} catch {
					// The uuid stays: a name the reader cannot fetch is not shown.
				}
			},
		},
	},

	methods: {
		n,
		t,
	},
}
</script>
