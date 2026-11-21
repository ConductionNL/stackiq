<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Add or edit a catalogue webhook: the address another system listens on and
  - the catalogue events sent to it. Own file per ADR-012; the section stores
  - what it emits through OpenRegister's webhooks API.
  -
  - @spec openspec/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
  -->
<template>
	<NcDialog
		:name="webhook ? t('stackiq', 'Edit webhook') : t('stackiq', 'Add webhook')"
		size="small"
		@closing="$emit('close')">
		<form class="edit-webhook" @submit.prevent="save">
			<NcTextField
				v-model="url"
				type="url"
				:label="t('stackiq', 'Address')"
				:helperText="t('stackiq', 'The address of the other system that receives the change.')"
				required />
			<NcTextField v-model="name" :label="t('stackiq', 'Name')" />
			<fieldset class="edit-webhook__events">
				<legend>{{ t('stackiq', 'Send when') }}</legend>
				<NcCheckboxRadioSwitch
					v-for="event in events"
					:key="event.id"
					:modelValue="chosen.includes(event.id)"
					:data-event="event.id"
					@update:modelValue="toggle(event.id, $event)">
					{{ event.label }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<div class="edit-webhook__actions">
				<NcButton variant="secondary" @click="$emit('close')">
					{{ t('stackiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					type="submit"
					data-testid="webhook-save"
					:disabled="!valid || saving">
					{{ t('stackiq', 'Save') }}
				</NcButton>
			</div>
		</form>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcTextField } from '@nextcloud/vue'

export default {
	name: 'EditWebhookModal',
	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcTextField },
	props: {
		/** The webhook to edit (with its chosen events as `eventIds`), or null to add one. */
		webhook: {
			type: Object,
			default: null,
		},

		/** The catalogue events to choose from. */
		events: {
			type: Array,
			required: true,
		},

		/** Whether the section is storing the webhook. */
		saving: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'save'],

	data() {
		return {
			url: this.webhook?.url || '',
			name: this.webhook?.name || '',
			chosen: [...(this.webhook?.eventIds || [])],
		}
	},

	computed: {
		/**
		 * @return {boolean} Whether an http(s) address and at least one event are given.
		 * @spec openspec/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
		 */
		valid() {
			return /^https?:\/\/\S+$/i.test(this.url.trim()) && this.chosen.length > 0
		},
	},

	methods: {
		t,

		/**
		 * Choose or drop an event.
		 *
		 * @param {string} id The event.
		 * @param {boolean} on Whether it is chosen.
		 * @return {void}
		 * @spec openspec/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
		 */
		toggle(id, on) {
			this.chosen = on ? [...new Set([...this.chosen, id])] : this.chosen.filter((event) => event !== id)
		},

		/**
		 * Emit what was entered, when it is complete.
		 *
		 * @return {void}
		 * @spec openspec/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
		 */
		save() {
			if (!this.valid) {
				return
			}
			this.$emit('save', { name: this.name, url: this.url.trim(), events: [...this.chosen] })
		},
	},
}
</script>

<style scoped>
.edit-webhook {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.edit-webhook__events {
	border: none;
	padding: 0;
}

.edit-webhook__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
