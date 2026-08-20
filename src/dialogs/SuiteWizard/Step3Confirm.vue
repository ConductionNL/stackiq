<!--
 - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 - SPDX-License-Identifier: EUPL-1.2
 -->

<!--
 Step 3 of the suite-creation wizard — review before submit. Read-only; the
 actual save happens in SuiteWizardDialog's @submit handler.

 @spec openspec/specs/suite-wizard/spec.md#requirement-submitting-the-wizard-shall-create-a-suite-object-with-the-attached-applications
-->
<template>
	<div class="suite-wizard-step3">
		<dl class="suite-wizard-step3__summary">
			<dt>{{ t('softwarecatalog', 'Name') }}</dt>
			<dd>{{ payload.name }}</dd>

			<dt>{{ t('softwarecatalog', 'Short description') }}</dt>
			<dd>{{ payload.shortDescription }}</dd>

			<template v-if="payload.website">
				<dt>{{ t('softwarecatalog', 'Website') }}</dt>
				<dd>{{ payload.website }}</dd>
			</template>
		</dl>

		<h3 class="suite-wizard-step3__heading">
			{{
				t('softwarecatalog', 'Applications ({count})', {
					count: applicationNames.length,
				})
			}}
		</h3>
		<ul v-if="applicationNames.length" class="suite-wizard-step3__apps">
			<li v-for="name in applicationNames" :key="name">
				{{ name }}
			</li>
		</ul>
		<p v-else class="suite-wizard-step3__empty">
			{{ t('softwarecatalog', 'No applications attached yet.') }}
		</p>
	</div>
</template>

<script>
import { summarizeApplications } from '../../utils/suiteWizard.js'

export default {
	name: 'Step3Confirm',

	props: {
		/** The wizard's accumulated step data (CnWizardDialog's stepData). */
		payload: {
			type: Object,
			required: true,
		},
	},

	computed: {
		/**
		 * The attached applications' names, for the review list.
		 *
		 * @return {Array<string>}
		 * @spec openspec/specs/suite-wizard/spec.md#requirement-submitting-the-wizard-shall-create-a-suite-object-with-the-attached-applications
		 */
		applicationNames() {
			return summarizeApplications(this.payload.applications)
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.suite-wizard-step3__summary {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
	margin: 0 0 20px;
}

.suite-wizard-step3__summary dt {
	color: var(--color-text-maxcontrast);
}

.suite-wizard-step3__summary dd {
	margin: 0;
}

.suite-wizard-step3__heading {
	margin: 0 0 8px;
	font-size: 1rem;
}

.suite-wizard-step3__apps {
	margin: 0;
	padding-left: 20px;
}

.suite-wizard-step3__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
