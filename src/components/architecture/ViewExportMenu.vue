<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<!--
  The Export menu of an architecture view: Download SVG, and Create Word
  document and Create PowerPoint slide through filinq. Without filinq the two
  document actions are disabled and say why.

  @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-003-the-document-actions-shall-say-why-they-are-unavailable-when-filinq-cannot-take-the-request
-->
<template>
	<div class="view-export">
		<NcActions :menuName="t('stackiq', 'Export')" :aria-label="t('stackiq', 'Export')">
			<template v-for="action in actions" :key="action.id">
				<NcActionLink
					v-if="action.id === 'svg'"
					:href="imageUrl"
					:download="downloadName">
					{{ action.label }}
				</NcActionLink>
				<NcActionButton
					v-else
					:disabled="action.disabled || busy"
					:title="action.reason"
					@click="createDocument(action.id)">
					{{ action.label }}
				</NcActionButton>
			</template>
			<NcActionText v-if="unavailableReason">
				{{ unavailableReason }}
			</NcActionText>
		</NcActions>
		<a
			v-if="savedLink"
			class="view-export__saved"
			:href="savedLink">
			{{ openLabel }}
		</a>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcActionButton, NcActionLink, NcActions, NcActionText } from '@nextcloud/vue'
import {
	createViewDocument,
	documentExportAvailable,
	documentSavedMessage,
	filesLink,
	openInFilesLabel,
	viewExportActions,
	viewImageUrl,
} from '../../services/viewExport.js'

export default {
	name: 'ViewExportMenu',

	components: {
		NcActionButton,
		NcActionLink,
		NcActions,
		NcActionText,
	},

	props: {
		/** The view to export. */
		viewId: {
			type: String,
			required: true,
		},

		/** The view's name, for the downloaded file. */
		viewName: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			busy: false,
			savedLink: '',
			documentExport: documentExportAvailable(),
		}
	},

	computed: {
		/**
		 * The menu's actions, in order.
		 *
		 * @return {Array<object>}
		 *
		 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-003-the-document-actions-shall-say-why-they-are-unavailable-when-filinq-cannot-take-the-request
		 */
		actions() {
			return viewExportActions(this.documentExport)
		},

		/**
		 * Why the document actions are disabled, or nothing when they are not.
		 *
		 * @return {string}
		 *
		 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-003-the-document-actions-shall-say-why-they-are-unavailable-when-filinq-cannot-take-the-request
		 */
		unavailableReason() {
			return this.actions.find((action) => action.disabled)?.reason ?? ''
		},

		/**
		 * The URL of the view's SVG image.
		 *
		 * @return {string}
		 *
		 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-001-stackiq-shall-draw-a-view-as-a-standalone-svg-from-its-stored-geometry
		 */
		imageUrl() {
			return viewImageUrl(this.viewId)
		},

		/**
		 * The file name of the downloaded SVG.
		 *
		 * @return {string}
		 *
		 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-001-stackiq-shall-draw-a-view-as-a-standalone-svg-from-its-stored-geometry
		 */
		downloadName() {
			const base = (this.viewName || 'view').replace(/[^\p{L}\p{N} _-]+/gu, '').trim() || 'view'
			return `${base}.svg`
		},

		/**
		 * The label of the link that opens a filed document.
		 *
		 * @return {string}
		 *
		 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-002-stackiq-shall-ask-filinq-for-a-word-document-or-a-powerpoint-slide-that-holds-the-view
		 */
		openLabel() {
			return openInFilesLabel()
		},
	},

	methods: {
		t,

		/**
		 * Ask filinq for a Word document or a PowerPoint slide of the view.
		 *
		 * @param {string} action `word` or `powerpoint`.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-002-stackiq-shall-ask-filinq-for-a-word-document-or-a-powerpoint-slide-that-holds-the-view
		 */
		async createDocument(action) {
			this.busy = true
			this.savedLink = ''
			try {
				const outcome = await createViewDocument(this.viewId, action)
				if (outcome.ok) {
					showSuccess(documentSavedMessage(outcome.name))
					this.savedLink = filesLink(outcome.fileId)
				} else {
					showError(outcome.error)
				}
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.view-export {
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>
