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
		<NcTextField :value="payload.naam"
			:label="t('softwarecatalog', 'Name') + ' *'"
			:placeholder="t('softwarecatalog', 'e.g. Centric Leefomgeving')"
			required
			@update:value="onField('naam', $event)" />

		<NcTextField :value="payload.beschrijvingKort"
			:label="t('softwarecatalog', 'Short description') + ' *'"
			:placeholder="t('softwarecatalog', 'A brief summary of the suite')"
			required
			@update:value="onField('beschrijvingKort', $event)" />

		<NcTextArea :value.sync="beschrijvingLangModel"
			:label="t('softwarecatalog', 'Long description')"
			:placeholder="t('softwarecatalog', 'A detailed description of the suite and what it covers')" />

		<NcTextField :value="payload.website"
			:label="t('softwarecatalog', 'Website')"
			:placeholder="t('softwarecatalog', 'https://example.com/suite')"
			type="url"
			@update:value="onField('website', $event)" />
	</div>
</template>

<script>
import { NcTextField, NcTextArea } from '@nextcloud/vue'
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
		 * `.sync` bridge for `NcTextArea`'s `value` — writes through
		 * `onField` so `_step1Valid` recomputes on every keystroke.
		 *
		 * @return {string} The current long description.
		 */
		beschrijvingLangModel: {
			get() {
				return this.payload.beschrijvingLang || ''
			},
			set(value) {
				this.onField('beschrijvingLang', value)
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
