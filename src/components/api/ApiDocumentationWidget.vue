<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<!--
  The body of the API documentation page (footer menu), a dashboard widget
  type registered in main.js: the OpenRegister document of the catalogue
  register and the document of the app's own endpoints, one tab each, both
  rendered in the app. Each tab fetches its document when first opened.

  @visual exclude A screenshot baseline needs a live instance serving both documents; tests/e2e/workflows/api-docs.spec.ts drives the page and is not run yet.

  @spec openspec/changes/sharing-generated-api-docs/specs/generated-api-docs/spec.md#requirement-req-gad-002-a-developer-reads-both-documents-on-one-page-in-stackiq
-->
<template>
	<div class="api-documentation">
		<h2 class="api-documentation__title">
			{{ t('stackiq', 'API documentation') }}
		</h2>
		<p class="api-documentation__intro">
			{{
				t(
					'stackiq',
					'The catalogue objects are read and written through the OpenRegister API; the endpoints the app adds on top have their own document.',
				)
			}}
		</p>
		<NcLoadingIcon
			v-if="!ready"
			:name="t('stackiq', 'Loading the API documentation')" />
		<CnTabs v-else :aria-label="t('stackiq', 'API documents')">
			<CnTab
				v-for="tab in tabs"
				:key="tab.id"
				:title="t('stackiq', tab.title)"
				lazy>
				<ApiDocumentPanel
					:path="paths[tab.id] || ''"
					:downloadName="tab.downloadName" />
			</CnTab>
		</CnTabs>
	</div>
</template>

<script>
import { CnTab, CnTabs } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon } from '@nextcloud/vue'
import ApiDocumentPanel from './ApiDocumentPanel.vue'
import { objectStore } from '../../store/store.js'
import { API_DOCUMENT_TABS, documentPaths } from '../../utils/apiDocuments.js'

export default {
	name: 'ApiDocumentationWidget',
	components: { ApiDocumentPanel, CnTab, CnTabs, NcLoadingIcon },

	data() {
		return {
			ready: false,
			tabs: API_DOCUMENT_TABS,
			paths: documentPaths(null),
		}
	},

	/**
	 * Read the catalogue register id from the app settings, then show the tabs.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/sharing-generated-api-docs/tasks.md#task-3-api-documentation-page
	 */
	async mounted() {
		try {
			if (
				!objectStore.settings
				&& typeof objectStore.fetchSettings === 'function'
			) {
				await objectStore.fetchSettings()
			}
		} catch {
			// Without the settings the catalogue tab says its document is not available.
		}
		this.paths = documentPaths(objectStore.settings)
		this.ready = true
	},

	methods: { t },
}
</script>

<style scoped>
.api-documentation {
	padding: calc(var(--default-grid-baseline) * 4);
}

.api-documentation__intro {
	color: var(--color-text-maxcontrast);
	margin-bottom: calc(var(--default-grid-baseline) * 4);
}
</style>
