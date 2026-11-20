<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - On the page of an entry mirrored from a peer catalogue: "From: <catalogue>"
  - with a link to the entry at the peer. Nothing shows for this catalogue's
  - own entries. The page itself keeps a mirror read only (manifest
  - `readOnlyWhen` on `_source.instance`).
  -
  - @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-mirrored-entries-show-where-they-came-from-req-fed-011
  -->
<template>
	<NcNoteCard v-if="provenance" type="info" data-testid="provenance-notice">
		<span>{{ t('stackiq', 'From: {catalogue}', { catalogue: provenance.name }) }}</span>
		<a
			v-if="provenance.url"
			class="provenance-link"
			:href="provenance.url"
			target="_blank"
			rel="noopener noreferrer">
			{{ t('stackiq', 'Open the original') }}
		</a>
	</NcNoteCard>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcNoteCard } from '@nextcloud/vue'
import { objectStore } from '../../store/store.js'
import { ensureObjectTypeOrSlug } from '../../utils/objectTypes.js'
import { provenanceOf } from '../../utils/provenance.js'

export default {
	name: 'ProvenanceNotice',
	components: { NcNoteCard },
	props: {
		/** The record's id. */
		objectId: {
			type: String,
			default: '',
		},

		/** The object type the record is read as. */
		objectType: {
			type: String,
			default: 'organization',
		},
	},

	data() {
		return {
			provenance: null,
		}
	},

	/**
	 * @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-mirrored-entries-show-where-they-came-from-req-fed-011
	 */
	async mounted() {
		if (!this.objectId) {
			return
		}
		try {
			this.provenance = provenanceOf(await this.loadRecord())
		} catch {
			// No notice when the record cannot be read; the page shows its own error.
		}
	},

	methods: {
		t,

		/**
		 * The record's data, read through the object store.
		 *
		 * @return {Promise<object>} The record's fields.
		 * @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-mirrored-entries-show-where-they-came-from-req-fed-011
		 */
		async loadRecord() {
			if (!objectStore.settings && typeof objectStore.fetchSettings === 'function') {
				await objectStore.fetchSettings()
			}
			ensureObjectTypeOrSlug(this.objectType)
			const record = await objectStore.fetchObject(this.objectType, this.objectId)
			return record?.object || record || {}
		},
	},
}
</script>

<style scoped>
.provenance-link {
	margin-inline-start: 0.5rem;
	text-decoration: underline;
}
</style>
