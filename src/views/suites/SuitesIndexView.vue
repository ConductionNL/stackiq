<!--
 - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 - SPDX-License-Identifier: EUPL-1.2
 -->

<!--
 SuitesIndexView — index of `suite` objects with the guided suite-creation
 wizard as its primary action.

 Stays `type: custom` (rather than a declarative `type: index` manifest
 page) purely because a declarative index page has no slot to inject the
 wizard's "New suite" action button — `CnIndexPage` itself self-fetches
 (`register="voorzieningen"` `schema="suite"` triggers the library's own
 self-fetch path, `CnIndexPage/useSelfFetchList.js`), identical to what a
 declarative type:index page gets for free.

 spec: openspec/specs/suite-wizard/spec.md
-->
<template>
	<div class="suites-index">
		<CnIndexPage :title="t('softwarecatalog', 'Suites')"
			:description="t('softwarecatalog', 'Application suites — bundled products made up of one or more existing applications.')"
			:show-title="true"
			icon="PackageVariant"
			register="voorzieningen"
			schema="suite"
			:columns="['naam', 'beschrijvingKort', 'website']"
			:show-add="false"
			@row-click="onRowOpen">
			<template #actions>
				<NcButton variant="primary" @click="showWizard = true">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('softwarecatalog', 'New suite') }}
				</NcButton>
			</template>
		</CnIndexPage>

		<SuiteWizardDialog v-model:show="showWizard" @created="onSuiteCreated" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import SuiteWizardDialog from '../../dialogs/SuiteWizardDialog.vue'

export default {
	name: 'SuitesIndexView',

	components: {
		CnIndexPage,
		NcButton,
		Plus,
		SuiteWizardDialog,
	},

	data() {
		return {
			showWizard: false,
		}
	},

	methods: {
		t,

		/**
		 * Navigate to a suite's detail page. `CnIndexPage` self-fetch mode
		 * emits `row-click` for navigation but does not navigate itself —
		 * this custom (`type: custom`) page owns that wiring, unlike a
		 * declarative `type: index` page where `CnPageRenderer` does it.
		 *
		 * @param {object} row The clicked suite row.
		 * @return {void}
		 * @spec openspec/specs/suite-wizard/spec.md#requirement-the-suite-index-page-shall-list-existing-suites
		 */
		onRowOpen(row) {
			const id = row && (row.id || row.uuid || row['@self']?.id)
			if (!id) return
			this.$router.push({ name: 'SuiteDetail', params: { id } }).catch(() => {})
		},

		/**
		 * A suite was created by the wizard — navigate straight to its
		 * detail page so the user sees the result of the guided flow.
		 *
		 * @param {string} suiteId The newly-created suite's id.
		 * @return {void}
		 * @spec openspec/specs/suite-wizard/spec.md#requirement-submitting-the-wizard-shall-create-a-suite-object-with-the-attached-applications
		 */
		onSuiteCreated(suiteId) {
			this.showWizard = false
			if (suiteId) {
				this.$router.push({ name: 'SuiteDetail', params: { id: suiteId } }).catch(() => {})
			}
		},
	},
}
</script>

<style scoped>
.suites-index {
	height: 100%;
}
</style>
