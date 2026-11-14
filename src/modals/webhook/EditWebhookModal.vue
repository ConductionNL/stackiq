<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<!--
 Add or edit a catalogue webhook (platform-properties, REQ-PLAT-005): its
 address, name, the actions and the catalogue entries it follows. Emits
 `save` with the form; the section writes it through OpenRegister's webhooks
 API, which delivers, retries and signs.

 @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
-->

<template>
	<NcModal
		v-if="show"
		:name="webhook ? t('stackiq', 'Edit webhook') : t('stackiq', 'Add webhook')"
		size="normal"
		@close="$emit('close')">
		<form class="edit-webhook" data-testid="edit-webhook" @submit.prevent="save">
			<h2 class="edit-webhook__title">
				{{ webhook ? t('stackiq', 'Edit webhook') : t('stackiq', 'Add webhook') }}
			</h2>
			<NcTextField
				v-model="form.url"
				:label="t('stackiq', 'Address')"
				type="url"
				:error="problem === 'url' && touched"
				:helperText="t('stackiq', 'The address of the other system, starting with https://.')"
				required />
			<NcTextField
				v-model="form.name"
				:label="t('stackiq', 'Name')"
				:helperText="t('stackiq', 'Optional; the address is used when it is empty.')" />

			<fieldset class="edit-webhook__group">
				<legend>{{ t('stackiq', 'Send when an entry is') }}</legend>
				<NcCheckboxRadioSwitch
					v-for="action in actionOptions"
					:key="action.id"
					v-model="form.actions"
					:value="action.id"
					type="checkbox"
					:data-testid="'webhook-action-' + action.id">
					{{ action.label }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<fieldset class="edit-webhook__group">
				<legend>{{ t('stackiq', 'For') }}</legend>
				<NcCheckboxRadioSwitch
					v-for="subject in subjectOptions"
					:key="subject.id"
					v-model="form.subjects"
					:value="subject.id"
					type="checkbox"
					:data-testid="'webhook-subject-' + subject.id">
					{{ subject.label }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<NcCheckboxRadioSwitch v-model="form.enabled" type="switch">
				{{ t('stackiq', 'Active') }}
			</NcCheckboxRadioSwitch>

			<NcNoteCard v-if="touched && problemText" type="error">
				{{ problemText }}
			</NcNoteCard>

			<div class="edit-webhook__buttons">
				<NcButton variant="secondary" @click="$emit('close')">
					{{ t('stackiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					type="submit"
					:disabled="saving"
					data-testid="edit-webhook-save">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
					</template>
					{{ t('stackiq', 'Save') }}
				</NcButton>
			</div>
		</form>
	</NcModal>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import { formProblem, webhookForm } from '../../utils/catalogueWebhooks.js'

/**
 * The add/edit dialog of a catalogue webhook.
 *
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
 */
export default {
	name: 'EditWebhookModal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcTextField,
	},

	props: {
		/** Whether the modal is open. */
		show: {
			type: Boolean,
			default: false,
		},

		/** The webhook edited, or null to add one. */
		webhook: {
			type: Object,
			default: null,
		},

		/** The catalogue register and schemas. */
		scope: {
			type: Object,
			required: true,
		},

		/** Whether the save is in flight. */
		saving: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'save'],

	data() {
		return {
			form: webhookForm(this.webhook, this.scope),
			touched: false,
		}
	},

	computed: {
		/**
		 * The actions to choose from.
		 *
		 * @return {Array<{id: string, label: string}>} Options.
		 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
		 */
		actionOptions() {
			return [
				{ id: 'created', label: t('stackiq', 'Created') },
				{ id: 'updated', label: t('stackiq', 'Changed') },
				{ id: 'deleted', label: t('stackiq', 'Deleted') },
			]
		},

		/**
		 * The catalogue entries to choose from.
		 *
		 * @return {Array<{id: string, label: string}>} Options.
		 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
		 */
		subjectOptions() {
			return [
				{ id: 'module', label: t('stackiq', 'Applications') },
				{ id: 'contract', label: t('stackiq', 'Contracts') },
				{ id: 'usage', label: t('stackiq', 'Applications in use') },
			]
		},

		/**
		 * What keeps the form from being saved, or ''.
		 *
		 * @return {string} The problem.
		 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
		 */
		problem() {
			return formProblem(this.form, this.scope)
		},

		/**
		 * The problem in words.
		 *
		 * @return {string} The text, or ''.
		 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
		 */
		problemText() {
			return {
				url: t('stackiq', 'Enter an address that starts with http:// or https://.'),
				actions: t('stackiq', 'Choose when the webhook is sent.'),
				subjects: t('stackiq', 'Choose the catalogue entries the webhook follows.'),
				scope: t('stackiq', 'The catalogue register and its schemas are not configured yet.'),
			}[this.problem] || ''
		},
	},

	watch: {
		/**
		 * Start from the webhook again each time the modal opens.
		 *
		 * @param {boolean} open Whether it opened.
		 * @return {void}
		 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
		 */
		show(open) {
			if (open) {
				this.form = webhookForm(this.webhook, this.scope)
				this.touched = false
			}
		},
	},

	methods: {
		t,

		/**
		 * Hand the form to the section, once nothing is wrong with it.
		 *
		 * @return {void}
		 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
		 */
		save() {
			this.touched = true
			if (this.problem !== '') {
				return
			}
			this.$emit('save', { ...this.form })
		},
	},
}
</script>

<style scoped>
.edit-webhook {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.edit-webhook__title {
	margin: 0;
}

.edit-webhook__group {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 16px;
	border: none;
	padding: 0;
	margin: 0;
}

.edit-webhook__group legend {
	font-weight: bold;
	margin-bottom: 4px;
}

.edit-webhook__buttons {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
