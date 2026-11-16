<!--
 - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 - SPDX-License-Identifier: EUPL-1.2
 -->

<!--
 EntryWizardDialog — add a record step by step through the shared
 @conduction/nextcloud-vue CnWizardDialog: its details, then the related
 records it links, then a summary to confirm. What each kind asks and saves
 is configuration in src/utils/entryWizard.js. A step with a required field
 left empty stays open and names the field; a failed save keeps the summary
 open with the error.

 Types are registered by SCHEMA SLUG against `voorzieningenConfig.register`,
 as SuiteWizardDialog does (sc#392).

 @spec openspec/changes/capability-requirements-from-the-matrix/specs/guided-entry-wizard/spec.md#requirement-req-gew-001-applications-services-and-connections-are-added-through-a-step-by-step-wizard
-->
<template>
	<CnWizardDialog
		v-if="show"
		ref="wizard"
		:dialogTitle="config.title()"
		:steps="wizardSteps"
		:defaults="defaults"
		:validate="validateStep"
		:cancelLabel="t('stackiq', 'Cancel')"
		:backLabel="t('stackiq', 'Back')"
		:nextLabel="t('stackiq', 'Next')"
		:submitLabel="config.submitLabel()"
		:closeLabel="t('stackiq', 'Close')"
		:successText="config.successText()"
		@submit="onSubmit"
		@close="onClose">
		<template #step-details="{ stepData, setStepData }">
			<div class="entry-wizard__step" data-testid="entry-wizard-details">
				<template v-for="field in config.fields" :key="field.key">
					<NcSelect
						v-if="field.options"
						:modelValue="field.multiple ? (stepData[field.key] || []) : (stepData[field.key] || null)"
						:options="field.options"
						:multiple="Boolean(field.multiple)"
						:inputLabel="field.required ? t('stackiq', '{field} (required)', { field: field.label() }) : field.label()"
						:data-testid="`entry-wizard-field-${field.key}`"
						@update:modelValue="(value) => setStepData({ ...stepData, [field.key]: value || (field.multiple ? [] : '') })" />
					<NcTextField
						v-else
						:modelValue="stepData[field.key]"
						:label="field.required ? t('stackiq', '{field} (required)', { field: field.label() }) : field.label()"
						:data-testid="`entry-wizard-field-${field.key}`"
						@update:modelValue="(value) => setStepData({ ...stepData, [field.key]: value })" />
				</template>
				<NcSelect
					v-for="relation in relationsOf('details')"
					:key="relation.key"
					:modelValue="stepData[relation.key]"
					:options="optionsFor(relation.schema)"
					:multiple="Boolean(relation.multiple)"
					:loading="loading"
					:inputLabel="relation.required ? t('stackiq', '{field} (required)', { field: relation.label() }) : relation.label()"
					trackBy="uuid"
					label="label"
					:data-testid="`entry-wizard-relation-${relation.key}`"
					@update:modelValue="(value) => setStepData({ ...stepData, [relation.key]: value || (relation.multiple ? [] : null) })" />
			</div>
		</template>

		<template #step-related="{ stepData, setStepData }">
			<div class="entry-wizard__step" data-testid="entry-wizard-related">
				<NcNoteCard v-if="loadError" type="error">
					{{ loadError }}
				</NcNoteCard>
				<NcSelect
					v-for="relation in relationsOf('related')"
					:key="relation.key"
					:modelValue="stepData[relation.key]"
					:options="optionsFor(relation.schema)"
					:multiple="Boolean(relation.multiple)"
					:loading="loading"
					:inputLabel="relation.required ? t('stackiq', '{field} (required)', { field: relation.label() }) : relation.label()"
					trackBy="uuid"
					label="label"
					:data-testid="`entry-wizard-relation-${relation.key}`"
					@update:modelValue="(value) => setStepData({ ...stepData, [relation.key]: value || (relation.multiple ? [] : null) })" />
			</div>
		</template>

		<template #step-confirm="{ stepData }">
			<dl class="entry-wizard__summary" data-testid="entry-wizard-summary">
				<template v-for="row in summary(stepData)" :key="row.key">
					<dt>{{ row.label }}</dt>
					<dd>{{ row.value || '—' }}</dd>
				</template>
			</dl>
		</template>
	</CnWizardDialog>
</template>

<script>
import { CnWizardDialog } from '@conduction/nextcloud-vue'
import { NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { objectStore } from '../store/store.js'
import {
	buildWizardPayload,
	validateWizardStep,
	wizardDefaults,
	wizardKind,
	wizardRelations,
	wizardSummary,
} from '../utils/entryWizard.js'
import { mapApplicationOptions } from '../utils/suiteWizard.js'

export default {
	name: 'EntryWizardDialog',

	components: {
		CnWizardDialog,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		/** Whether the wizard is shown (bind with `v-model:show`). */
		show: {
			type: Boolean,
			required: true,
		},

		/** The kind of record it adds, a key of ENTRY_WIZARD_KINDS. */
		kind: {
			type: String,
			required: true,
		},
	},

	emits: ['update:show', 'created'],

	data() {
		return {
			loading: false,
			loadError: '',
		}
	},

	computed: {
		/** The kind's configuration. */
		config() {
			return wizardKind(this.kind)
		},

		/** Fresh step data for the kind. */
		defaults() {
			return wizardDefaults(this.kind)
		},

		/** Details, related records, summary. */
		wizardSteps() {
			return [
				{ id: 'details', label: t('stackiq', 'Details') },
				{ id: 'related', label: this.config.relatedLabel() },
				{ id: 'confirm', label: t('stackiq', 'Confirm') },
			]
		},
	},

	watch: {
		show: {
			immediate: true,
			handler(shown) {
				if (shown) {
					this.loadRelated()
				}
			},
		},
	},

	methods: {
		t,

		/**
		 * Whether a step may be left, or why it stays open.
		 *
		 * @param {string} stepId The step.
		 * @param {object} stepData The data entered.
		 * @return {true|string}
		 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/guided-entry-wizard/spec.md#requirement-req-gew-001-applications-services-and-connections-are-added-through-a-step-by-step-wizard
		 */
		validateStep(stepId, stepData) {
			return validateWizardStep(this.kind, stepId, stepData)
		},

		/**
		 * The relations a step asks for.
		 *
		 * @param {string} stepId `details` or `related`.
		 * @return {Array<object>}
		 */
		relationsOf(stepId) {
			return wizardRelations(this.kind, stepId)
		},

		/**
		 * The rows of the summary step.
		 *
		 * @param {object} stepData The data entered.
		 * @return {Array<object>}
		 */
		summary(stepData) {
			return wizardSummary(this.kind, stepData)
		},

		/**
		 * The options of a related schema, from the loaded collection.
		 *
		 * @param {string} schema The schema slug.
		 * @return {Array<object>}
		 */
		optionsFor(schema) {
			const collection = objectStore.getCollection ? objectStore.getCollection(schema) : null
			return mapApplicationOptions(collection)
		},

		/**
		 * The voorzieningen register id, under either settings key.
		 *
		 * @return {string|undefined}
		 */
		registerId() {
			const settings = objectStore.settings?.voorzieningen || objectStore.settings?.voorzieningenConfig || {}
			return settings.register
		},

		/**
		 * Register a schema's object type by slug when it is not yet known.
		 *
		 * @param {string} schema The schema slug.
		 * @return {void}
		 */
		registerType(schema) {
			const registerId = this.registerId()
			if (typeof objectStore.registerObjectType === 'function' && registerId && !objectStore.objectTypeRegistry?.[schema]) {
				objectStore.registerObjectType(schema, schema, registerId, { registerSlug: 'stackiq', schemaSlug: schema })
			}
		},

		/**
		 * Load the records the related step offers.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/guided-entry-wizard/spec.md#requirement-req-gew-001-applications-services-and-connections-are-added-through-a-step-by-step-wizard
		 */
		async loadRelated() {
			this.loading = true
			this.loadError = ''
			try {
				if (!objectStore.settings && typeof objectStore.fetchSettings === 'function') {
					await objectStore.fetchSettings()
				}
				const queries = new Map()
				for (const relation of this.config.relations) {
					queries.set(relation.schema, { ...(queries.get(relation.schema) || {}), ...(relation.query || {}) })
				}
				for (const [schema, query] of queries) {
					this.registerType(schema)
					if (typeof objectStore.fetchCollection === 'function') {
						await objectStore.fetchCollection(schema, { ...query, _limit: 1000 })
					}
				}
			} catch {
				this.loadError = t('stackiq', 'The records to link could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Save the record; a failure keeps the summary open with the error.
		 *
		 * @param {object} stepData The data entered.
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/guided-entry-wizard/spec.md#requirement-req-gew-001-applications-services-and-connections-are-added-through-a-step-by-step-wizard
		 */
		async onSubmit(stepData) {
			try {
				if (!objectStore.settings && typeof objectStore.fetchSettings === 'function') {
					await objectStore.fetchSettings()
				}
				this.registerType(this.config.schema)
				const created = await objectStore.saveObject(this.config.schema, buildWizardPayload(this.kind, stepData))
				this.$refs.wizard?.setResult({ success: true })
				this.$emit('created', created?.id || created?.uuid || created?.['@self']?.id)
			} catch (error) {
				this.$refs.wizard?.setError(error?.message || t('stackiq', 'The record could not be saved.'))
			}
		},

		/** Close the wizard. */
		onClose() {
			this.$emit('update:show', false)
		},
	},
}
</script>

<style scoped>
.entry-wizard__step {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.entry-wizard__summary {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
}

.entry-wizard__summary dt {
	font-weight: bold;
}
</style>
