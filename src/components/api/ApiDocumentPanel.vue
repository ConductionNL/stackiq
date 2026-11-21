<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<!--
  One OpenAPI document on the API documentation page: fetched once from this
  instance with the user's session, then rendered by the library's
  CnApiReference (operations, parameters, responses, models, Download).

  @spec openspec/changes/sharing-generated-api-docs/specs/generated-api-docs/spec.md#requirement-req-gad-002-a-developer-reads-both-documents-on-one-page-in-stackiq
-->
<template>
	<div class="api-document-panel">
		<NcLoadingIcon
			v-if="loading"
			:name="t('stackiq', 'Loading the API document')" />
		<NcNoteCard v-else-if="failure" type="warning">
			{{ failure }}
		</NcNoteCard>
		<CnApiReference
			v-else-if="document"
			:document="document"
			:downloadName="downloadName" />
	</div>
</template>

<script>
import { CnApiReference } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { documentFailure, readDocument } from '../../utils/apiDocuments.js'

export default {
	name: 'ApiDocumentPanel',
	components: { CnApiReference, NcLoadingIcon, NcNoteCard },
	props: {
		/** The document's path on this instance, or empty when it cannot be fetched. */
		path: {
			type: String,
			default: '',
		},

		/** File name stem for the download. */
		downloadName: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: false,
			document: null,
			failure: '',
		}
	},

	watch: {
		path: {
			immediate: true,
			/**
			 * Load the document again when the path changes.
			 *
			 * @spec openspec/changes/sharing-generated-api-docs/tasks.md#task-3-api-documentation-page
			 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,

		/**
		 * Fetch and read the document at `path`.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/sharing-generated-api-docs/tasks.md#task-3-api-documentation-page
		 */
		async load() {
			this.document = null
			this.failure = ''
			if (!this.path) {
				this.failure = t(
					'stackiq',
					'This API document is not available on this instance.',
				)
				return
			}
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl(this.path))
				this.document = readDocument(data)
				if (this.document === null) {
					this.failure = t(
						'stackiq',
						'The API document could not be loaded.',
					)
				}
			} catch (error) {
				this.failure = documentFailure(error, (text) => t('stackiq', text))
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
