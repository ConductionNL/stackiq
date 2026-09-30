<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<NcNoteCard
		v-if="survivorId"
		type="warning"
		data-testid="merged-record-banner">
		<p>
			{{
				t(
					'stackiq',
					'This application was merged into another one and no longer appears in the lists.',
				)
			}}
		</p>
		<router-link
			:to="{ name: 'ModuleDetail', params: { id: survivorId } }"
			data-testid="merged-record-link">
			{{ survivorName ? t('stackiq', 'Merged into {name}', { name: survivorName }) : t('stackiq', 'Open the application it was merged into') }}
		</router-link>
	</NcNoteCard>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcNoteCard } from '@nextcloud/vue'
import { objectStore } from '../../store/store.js'
import { mergedIntoId } from '../../utils/recordReconciliation.js'

export default {
	name: 'MergedRecordBanner',

	components: {
		NcNoteCard,
	},

	props: {
		objectId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			survivorId: null,
			survivorName: '',
		}
	},

	watch: {
		/**
		 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-004-merged-applications-and-services-shall-leave-the-lists-and-point-readers-to-the-survivor
		 */
		objectId() {
			this.load()
		},
	},

	/**
	 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-004-merged-applications-and-services-shall-leave-the-lists-and-point-readers-to-the-survivor
	 */
	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the application and, when it was merged, the one it was merged into.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-004-merged-applications-and-services-shall-leave-the-lists-and-point-readers-to-the-survivor
		 */
		async load() {
			this.survivorId = null
			this.survivorName = ''
			try {
				this.ensureType('module')
				const module = await objectStore.fetchObject('module', this.objectId)
				const survivorId = mergedIntoId(module)
				if (!survivorId) {
					return
				}
				this.survivorId = survivorId
				const survivor = await objectStore.fetchObject('module', survivorId)
				this.survivorName = survivor?.name || ''
			} catch {
				// The banner only adds a pointer; a failed read leaves the page as it is.
			}
		},

		/**
		 * Register an object type with the store when it is not known yet.
		 *
		 * @param {string} type The schema slug.
		 * @return {void}
		 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-004-merged-applications-and-services-shall-leave-the-lists-and-point-readers-to-the-survivor
		 */
		ensureType(type) {
			if (
				typeof objectStore.registerObjectType !== 'function'
				|| objectStore.objectTypeRegistry?.[type]
			) {
				return
			}
			const config = objectStore.getSchemaConfig?.(type)
			if (config?.register && config?.schema) {
				objectStore.registerObjectType(type, config.schema, config.register)
			}
		},
	},
}
</script>
