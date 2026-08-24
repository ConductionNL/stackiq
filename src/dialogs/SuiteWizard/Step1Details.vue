<!--
 - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 - SPDX-License-Identifier: EUPL-1.2
 -->

<!--
 Step 1 of the suite-creation wizard — the suite's own basics (naam,
 beschrijvingKort, beschrijvingLang, website). `naam`/`beschrijvingKort`
 mirror the `suite` schema's own `required` array.

 @spec openspec/specs/suite-wizard/spec.md#requirement-the-wizard-shall-guide-suite-creation-through-details-application-attachment-and-confirmation-steps
-->
<template>
	<div class="suite-wizard-step1">
		<NcTextField
			:modelValue="payload.name"
			:label="t('stackiq', 'Name') + ' *'"
			:placeholder="t('stackiq', 'e.g. Centric Leefomgeving')"
			required
			@update:modelValue="onField('name', $event)" />

		<NcTextField
			:modelValue="payload.shortDescription"
			:label="t('stackiq', 'Short description') + ' *'"
			:placeholder="t('stackiq', 'A brief summary of the suite')"
			required
			@update:modelValue="onField('shortDescription', $event)" />

		<NcTextArea
			v-model="beschrijvingLangModel"
			:label="t('stackiq', 'Long description')"
			:placeholder="
				t(
					'stackiq',
					'A detailed description of the suite and what it covers',
				)
			" />

		<NcTextField
			:modelValue="payload.website"
			:label="t('stackiq', 'Website')"
			:placeholder="t('stackiq', 'https://example.com/suite')"
			type="url"
			@update:modelValue="onField('website', $event)" />
	</div>
</template>

<script>
import { NcTextArea, NcTextField } from '@nextcloud/vue'
import { isDetailsStepValid } from '../../utils/suiteWizard.js'

export default {
	name: 'Step1Details',

	components: {
		NcTextField,
		NcTextArea,
	},

	props: {
		/** The wizard's accumulated step data (CnWizardDialog's stepData). */
		payload: {
			type: Object,
			required: true,
		},
	},

	emits: ['update:payload'],

	computed: {
		/**
		 * `v-model` bridge for `NcTextArea` — writes through
		 * `onField` so `_step1Valid` recomputes on every keystroke.
		 *
		 * @return {string} The current long description.
		 * @spec openspec/specs/suite-wizard/spec.md#requirement-the-wizard-shall-guide-suite-creation-through-details-application-attachment-and-confirmation-steps
		 */
		beschrijvingLangModel: {
			/**
			 * @return {string} The current long description.
			 * @spec openspec/specs/suite-wizard/spec.md#requirement-the-wizard-shall-guide-suite-creation-through-details-application-attachment-and-confirmation-steps
			 */
			get() {
				return this.payload.longDescription || ''
			},

			/**
			 * @param {string} value The new long description.
			 * @return {void}
			 * @spec openspec/specs/suite-wizard/spec.md#requirement-the-wizard-shall-guide-suite-creation-through-details-application-attachment-and-confirmation-steps
			 */
			set(value) {
				this.onField('longDescription', value)
			},
		},
	},

	methods: {
		t,

		/**
		 * Merge one field update into the wizard's shared stepData and
		 * recompute this step's validity flag, which
		 * `SuiteWizardDialog`'s `validate` hook reads.
		 *
		 * @param {string} key The stepData field to update.
		 * @param {string} value The new value.
		 * @return {void}
		 * @spec openspec/specs/suite-wizard/spec.md#requirement-the-wizard-must-require-at-least-one-attached-application-before-advancing-past-the-applications-step
		 */
		onField(key, value) {
			const merged = { ...this.payload, [key]: value }
			merged._step1Valid = isDetailsStepValid(merged)
			this.$emit('update:payload', merged)
		},
	},
}
</script>

<style scoped>
.suite-wizard-step1 {
	display: flex;
	flex-direction: column;
	gap: 16px;
}
</style>
