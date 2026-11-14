<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Asks a supplier to confirm declining a usage another organisation claims
  - of its product. Declining keeps the usage as that organisation's record and
  - tells it the supplier does not recognise the claim.
  -
  - @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
  -->

<template>
	<NcDialog
		:name="t('stackiq', 'Decline claimed usage')"
		size="small"
		data-testid="decline-claim-dialog"
		@closing="$emit('close')">
		<p>
			{{
				t('stackiq', '{organisation} says it uses {product}. Decline this claim? The usage stays theirs, and they see that you do not recognise it.', {
					organisation: claim.organisation,
					product: claim.module,
				})
			}}
		</p>
		<template #actions>
			<NcButton :disabled="busy" @click="$emit('close')">
				{{ t('stackiq', 'Cancel') }}
			</NcButton>
			<NcButton
variant="error"
				:disabled="busy"
				data-testid="decline-claim-confirm"
				@click="$emit('confirm')">
				{{ t('stackiq', 'Decline') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'DeclineClaimDialog',
	components: { NcButton, NcDialog },
	props: {
		/** The claim: organisation and module names. */
		claim: {
			type: Object,
			required: true,
		},

		/** Whether the decline is being sent. */
		busy: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'confirm'],

	methods: { t },
}
</script>
