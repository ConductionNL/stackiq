<!--
 - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 - SPDX-License-Identifier: EUPL-1.2
 -->

<!--
 Step 2 of the suite-creation wizard — attach EXISTING module applications.
 No inline "create new module" affordance — attaching only, per the change's
 explicit out-of-scope boundary.

 Registers `module` by SCHEMA SLUG against `voorzieningenConfig.register`
 (never via `voorzieningen_config.module_schema`, which is not reliably
 populated — see sc#392 / PortfolioReport.vue's identical fix for
 `organisatie`).

 @spec openspec/specs/suite-wizard/spec.md#requirement-the-system-shall-register-the-suite-and-module-object-types-by-schema-slug
-->
<template>
	<div class="suite-wizard-step2">
		<p class="suite-wizard-step2__intro">
			{{ t('softwarecatalog', 'Attach the applications that make up this suite. Only applications already in the catalogue can be attached — creating a new application is not part of this wizard.') }}
		</p>

		<NcNoteCard v-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>

		<NcSelect :model-value="selected"
			:options="applicationOptions"
			:loading="loading"
			:multiple="true"
			:close-on-select="false"
			:input-label="t('softwarecatalog', 'Existing applications')"
			:placeholder="t('softwarecatalog', 'Select one or more applications')"
			track-by="uuid"
			label="label"
			@update:model-value="onSelectionChange" />
	</div>
</template>

<script>
import { NcSelect, NcNoteCard } from '@nextcloud/vue'
import { objectStore } from '../../store/store.js'
import { mapApplicationOptions } from '../../utils/suiteWizard.js'

export default {
	name: 'Step2Applications',

	components: {
		NcSelect,
		NcNoteCard,
	},

	props: {
		/** The wizard's accumulated step data (CnWizardDialog's stepData). */
		payload: {
			type: Object,
			required: true,
		},
	},

	emits: ['update:payload'],

	data() {
		return {
			loading: false,
			loadError: '',
		}
	},

	computed: {
		/**
		 * Existing modules, mapped to `NcSelect` option shape.
		 *
		 * @return {Array<{uuid: string, label: string, raw: object}>}
		 */
		applicationOptions() {
			// `getCollection()` returns the paginated ENVELOPE ({ results, ... }),
			// not a bare array. Mapping lives in `mapApplicationOptions()` so it
			// is unit-testable against both shapes — reading the envelope as an
			// array threw "(intermediate value).map is not a function" and left
			// this step blank, and no test covered this computed.
			const collection = objectStore.getCollection ? objectStore.getCollection('module') : null
			return mapApplicationOptions(collection)
		},

		/**
		 * The currently-selected options, derived from `payload.applications`
		 * so re-entering this step (e.g. via "Back") keeps the selection.
		 *
		 * @return {Array<object>}
		 */
		selected() {
			const applications = this.payload.applications || []
			return applications.map((app) => ({ uuid: app.id, label: app.naam || app.id, raw: app }))
		},
	},

	mounted() {
		this.loadApplications()
	},

	methods: {
		t,

		/**
		 * Register `module` by schema slug (if not already registered) and
		 * fetch the collection the picker depends on.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/suite-wizard/spec.md#requirement-the-system-shall-register-the-suite-and-module-object-types-by-schema-slug
		 */
		async loadApplications() {
			this.loading = true
			this.loadError = ''
			try {
				if (!objectStore.settings && typeof objectStore.fetchSettings === 'function') {
					await objectStore.fetchSettings()
				}
				const voorzieningenConfig = objectStore.settings?.voorzieningen
					|| objectStore.settings?.voorzieningenConfig
					|| {}
				const registerId = voorzieningenConfig.register
				if (typeof objectStore.registerObjectType === 'function'
					&& registerId
					&& !objectStore.objectTypeRegistry?.module) {
					objectStore.registerObjectType('module', 'module', registerId, {
						registerSlug: 'voorzieningen',
						schemaSlug: 'module',
					})
				}
				if (typeof objectStore.fetchCollection === 'function') {
					await objectStore.fetchCollection('module', { _limit: 1000 })
				}
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('Step2Applications: failed to load applications', error)
				this.loadError = t('softwarecatalog', 'Could not load applications. Please try again.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Write the selected module objects (`raw`) back onto the wizard's
		 * shared stepData under `applications`, and recompute this step's
		 * validity flag (`_step2Valid`), which `SuiteWizardDialog`'s
		 * `validate` hook reads.
		 *
		 * @param {Array<{raw: object}>} options The selected NcSelect options.
		 * @return {void}
		 */
		onSelectionChange(options) {
			const applications = (options || []).map((option) => option.raw)
			this.$emit('update:payload', { ...this.payload, applications })
		},
	},
}
</script>

<style scoped>
.suite-wizard-step2 {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.suite-wizard-step2__intro {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
