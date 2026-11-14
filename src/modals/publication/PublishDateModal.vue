<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - PublishDateModal — asks for the moment a catalogue entry is published, for
  - "Publish on a date" in an entry's actions menu (PublicationAction.vue,
  - REQ-ODP-011). Emits the chosen moment as an ISO-8601 string.
  -->
<template>
	<NcDialog
		:name="t('stackiq', 'Publish on a date')"
		:open="open"
		size="small"
		@update:open="onOpen">
		<label class="publish-date-modal__label" for="publish-date-modal-when">
			{{ t('stackiq', 'Publication date and time') }}
		</label>
		<input
			id="publish-date-modal-when"
			v-model="when"
			class="publish-date-modal__input"
			type="datetime-local"
			data-testid="publish-date-input">
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('stackiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="isoWhen === ''"
				data-testid="publish-date-submit"
				@click="$emit('submit', isoWhen)">
				{{ t('stackiq', 'Publish') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'PublishDateModal',
	components: { NcButton, NcDialog },
	props: {
		/** Whether the dialog is open. */
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'submit'],
	data() {
		return {
			when: '',
		}
	},

	computed: {
		/**
		 * The chosen local moment as an ISO-8601 string, or '' when none or invalid.
		 *
		 * @return {string}
		 * @spec openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-an-entrys-page-publishes-or-withdraws-that-entry-req-odp-011
		 */
		isoWhen() {
			const ms = Date.parse(this.when)
			return Number.isNaN(ms) ? '' : new Date(ms).toISOString()
		},
	},

	methods: {
		t,

		/**
		 * Close when the dialog asks to.
		 *
		 * @param {boolean} isOpen The dialog's open state.
		 * @spec openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-an-entrys-page-publishes-or-withdraws-that-entry-req-odp-011
		 */
		onOpen(isOpen) {
			if (!isOpen) {
				this.$emit('close')
			}
		},
	},
}
</script>

<style scoped>
.publish-date-modal__label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}

.publish-date-modal__input {
	width: 100%;
}
</style>
