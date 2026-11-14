<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - On a usage page: when the supplier declined the usage this organisation
  - claims of its product, a notice says the supplier does not recognise it,
  - with the date. Nothing shows for an accepted or unanswered usage.
  -
  - @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
  -->
<template>
	<NcNoteCard v-if="declinedOn !== null" type="warning" data-testid="claim-declined-notice">
		{{ t('stackiq', 'The supplier does not recognise this usage (declined on {date}).', { date: declinedOn }) }}
	</NcNoteCard>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcNoteCard } from '@nextcloud/vue'
import { objectStore } from '../../store/store.js'
import { ensureObjectTypeOrSlug } from '../../utils/objectTypes.js'
import { claimDate } from '../organisations/claimedUsage.js'

export default {
	name: 'ClaimAnswerNotice',
	components: { NcNoteCard },
	props: {
		/** The usage. */
		objectId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			declinedOn: null,
		}
	},

	/**
	 * @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
	 */
	async mounted() {
		if (!this.objectId) {
			return
		}
		try {
			if (!objectStore.settings && typeof objectStore.fetchSettings === 'function') {
				await objectStore.fetchSettings()
			}
			ensureObjectTypeOrSlug('usage')
			const usage = await objectStore.fetchObject('usage', this.objectId)
			const data = usage?.object || usage || {}
			if (data.providerResponse === 'declined') {
				this.declinedOn = claimDate(data.providerResponseAt)
			}
		} catch {
			// No notice when the usage cannot be read; the page shows its own error.
		}
	},

	methods: { t },
}
</script>
