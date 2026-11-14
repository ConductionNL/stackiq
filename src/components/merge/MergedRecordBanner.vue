<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - The Merged into banner on the page of a merged application: a merged
  - record keeps its page, so an old link still works, and the banner sends
  - the reader to the record it was merged into. Renders nothing for a record
  - that is not merged. A body widget, because no built-in widget shows a
  - relation only when a status field has one value.
  -
  - @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-004-merged-applications-and-services-shall-leave-the-lists-and-point-readers-to-the-survivor
  -->
<template>
	<NcNoteCard
		v-if="survivorId"
		type="warning"
		class="merged-record-banner"
		data-testid="merged-record-banner">
		{{ t('stackiq', 'This entry was merged into another one.') }}
		<router-link :to="{ name: detailRoute, params: { id: survivorId } }">
			{{ t('stackiq', 'Merged into {name}', { name: survivorName || survivorId }) }}
		</router-link>
	</NcNoteCard>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcNoteCard } from '@nextcloud/vue'
import { objectStore } from '../../store/store.js'
import { survivorOf } from '../../utils/mergedRecord.js'
import { ensureObjectType } from '../../utils/objectTypes.js'

export default {
	name: 'MergedRecordBanner',

	components: {
		NcNoteCard,
	},

	props: {
		/** The record's uuid (CnDetailPage passes `@objectId`). */
		objectId: {
			type: String,
			required: true,
		},

		/** The record's object type in the store. */
		objectType: {
			type: String,
			default: 'module',
		},

		/** The named route of the survivor's page. */
		detailRoute: {
			type: String,
			default: 'ModuleDetail',
		},
	},

	data() {
		return {
			survivorId: null,
			survivorName: '',
		}
	},

	watch: {
		objectId: {
			immediate: true,
			/**
			 * Load the record whenever the page shows another one.
			 *
			 * @return {void}
			 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,

		/**
		 * Load the record and, when it is merged, the survivor's name.
		 *
		 * A record or survivor that cannot be read shows no banner, or the
		 * survivor's uuid instead of its name: the banner is a pointer, never
		 * a reason for the page to fail.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-004-merged-applications-and-services-shall-leave-the-lists-and-point-readers-to-the-survivor
		 */
		async load() {
			this.survivorId = null
			this.survivorName = ''
			try {
				ensureObjectType(this.objectType)
				const record = await objectStore.fetchObject(this.objectType, this.objectId)
				this.survivorId = survivorOf(record)
			} catch {
				return
			}
			if (!this.survivorId) {
				return
			}
			try {
				const survivor = await objectStore.fetchObject(this.objectType, this.survivorId)
				this.survivorName = survivor?.name || ''
			} catch {
				this.survivorName = ''
			}
		},
	},
}
</script>

<style scoped>
.merged-record-banner a {
	font-weight: bold;
	text-decoration: underline;
}
</style>
