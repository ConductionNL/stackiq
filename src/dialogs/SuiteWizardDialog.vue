<!--
 - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 - SPDX-License-Identifier: EUPL-1.2
 -->

<!--
 SuiteWizardDialog — guided suite creation via the shared
 @conduction/nextcloud-vue CnWizardDialog.

 Steps: Details (naam/beschrijvingKort/beschrijvingLang/website) →
 Applications (attach EXISTING modules only) → Confirm. On submit, creates a
 `suite` object in the voorzieningen register with `applicaties` set to the
 attached modules' ids.

 Registers `suite` (and, transitively via Step2Applications, `module`) by
 SCHEMA SLUG against `voorzieningenConfig.register` — never via
 `voorzieningen_config.<x>_schema`, which is not reliably populated (sc#392).

 spec: openspec/specs/suite-wizard/spec.md
 ADR-004/ADR-012: a dialog lives in its own file under src/dialogs/.
-->
<template>
	<CnWizardDialog v-if="show"
		ref="wizard"
		:dialog-title="t('softwarecatalog', 'New suite')"
		:steps="wizardSteps"
		:defaults="defaults"
		:validate="validateStep"
		:cancel-label="t('softwarecatalog', 'Cancel')"
		:back-label="t('softwarecatalog', 'Back')"
		:next-label="t('softwarecatalog', 'Next')"
		:submit-label="t('softwarecatalog', 'Create suite')"
		:close-label="t('softwarecatalog', 'Close')"
		:success-text="t('softwarecatalog', 'Suite created.')"
		@submit="onSubmit"
		@close="onClose">
		<template #step-details="{ stepData, setStepData }">
			<Step1Details :payload="stepData" @update:payload="setStepData" />
		</template>

		<template #step-applications="{ stepData, setStepData }">
			<Step2Applications :payload="stepData" @update:payload="setStepData" />
		</template>

		<template #step-confirm="{ stepData }">
			<Step3Confirm :payload="stepData" />
		</template>
	</CnWizardDialog>
</template>

<script>
import { CnWizardDialog } from '@conduction/nextcloud-vue'
import { objectStore } from '../store/store.js'
import { isDetailsStepValid, isApplicationsStepValid, buildSuitePayload } from '../utils/suiteWizard.js'
import Step1Details from './SuiteWizard/Step1Details.vue'
import Step2Applications from './SuiteWizard/Step2Applications.vue'
import Step3Confirm from './SuiteWizard/Step3Confirm.vue'

export default {
	name: 'SuiteWizardDialog',

	components: {
		CnWizardDialog,
		Step1Details,
		Step2Applications,
		Step3Confirm,
	},

	props: {
		/** Whether the wizard dialog is shown (bind with `.sync`). */
		show: {
			type: Boolean,
			required: true,
		},
	},

	emits: ['update:show', 'created'],

	computed: {
		/**
		 * Seed values for the wizard's shared stepData.
		 *
		 * @return {object}
		 */
		defaults() {
			return {
				naam: '',
				beschrijvingKort: '',
				beschrijvingLang: '',
				website: '',
				applications: [],
				_step1Valid: false,
			}
		},

		/**
		 * The wizard's fixed 3-step sequence — details, applications
		 * (attach existing only), confirm.
		 *
		 * @return {Array<{id: string, label: string}>}
		 */
		wizardSteps() {
			return [
				{ id: 'details', label: t('softwarecatalog', 'Details') },
				{ id: 'applications', label: t('softwarecatalog', 'Applications') },
				{ id: 'confirm', label: t('softwarecatalog', 'Confirm') },
			]
		},
	},

	methods: {
		t,

		/**
		 * Per-step validation for CnWizardDialog. Returns `true` to advance
		 * or a message string to block + surface as an error banner.
		 *
		 * @param {string} stepId The step being left.
		 * @param {object} stepData The accumulated wizard data.
		 * @return {(true|string)}
		 * @spec openspec/specs/suite-wizard/spec.md#requirement-the-wizard-must-require-at-least-one-attached-application-before-advancing-past-the-applications-step
		 */
		validateStep(stepId, stepData) {
			if (stepId === 'details') {
				return isDetailsStepValid(stepData)
					? true
					: t('softwarecatalog', 'Enter a name and a short description.')
			}
			if (stepId === 'applications') {
				return isApplicationsStepValid(stepData.applications, t)
			}
			return true
		},

		/**
		 * Register the `suite` object type by schema slug against the
		 * voorzieningen register id, if not already registered.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/suite-wizard/spec.md#requirement-the-system-shall-register-the-suite-and-module-object-types-by-schema-slug
		 */
		async ensureSuiteTypeRegistered() {
			if (!objectStore.settings && typeof objectStore.fetchSettings === 'function') {
				await objectStore.fetchSettings()
			}
			const voorzieningenConfig = objectStore.settings?.voorzieningen
				|| objectStore.settings?.voorzieningenConfig
				|| {}
			const registerId = voorzieningenConfig.register
			if (typeof objectStore.registerObjectType === 'function'
				&& registerId
				&& !objectStore.objectTypeRegistry?.suite) {
				objectStore.registerObjectType('suite', 'suite', registerId, {
					registerSlug: 'voorzieningen',
					schemaSlug: 'suite',
				})
			}
		},

		/**
		 * Create the suite. On success, emit `created` + close so the
		 * parent (Suites index) can refresh; on a recoverable failure,
		 * surface the error in-place via the wizard's `setError` so the
		 * user can fix and resubmit without losing their input.
		 *
		 * @param {object} stepData The accumulated wizard data.
		 * @return {Promise<void>}
		 * @spec openspec/specs/suite-wizard/spec.md#requirement-submitting-the-wizard-shall-create-a-suite-object-with-the-attached-applications
		 */
		async onSubmit(stepData) {
			try {
				await this.ensureSuiteTypeRegistered()
				const payload = buildSuitePayload(stepData)
				const created = await objectStore.saveObject('suite', payload)
				if (this.$refs.wizard) {
					this.$refs.wizard.setResult({ success: true })
				}
				this.$emit('created', created?.id)
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('SuiteWizardDialog: failed to create suite', error)
				const message = (error && error.message) || t('softwarecatalog', 'Failed to create the suite.')
				if (this.$refs.wizard) {
					this.$refs.wizard.setError(message)
				}
			}
		},

		/**
		 * Close the wizard. `v-if="show"` unmounts it, so it always
		 * reopens fresh.
		 *
		 * @return {void}
		 */
		onClose() {
			this.$emit('update:show', false)
		},
	},
}
</script>
