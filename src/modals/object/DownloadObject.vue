<!--
  - SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<script setup>
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.modal === 'downloadObject'"
		:name="
			t('stackiq', 'Download {name}', {
				name:
					objectStore.objectItem?.['@self']?.name
					|| objectStore.objectItem?.name
					|| objectStore.objectItem?.['@self']?.title
					|| objectStore.objectItem?.id
					|| t('stackiq', 'Publication'),
			})
		"
		size="normal"
		:canClose="false">
		<NcNoteCard v-if="success" type="success">
			<p>{{ t('stackiq', 'Object successfully downloaded') }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success ? t('stackiq', 'Close') : t('stackiq', 'Cancel') }}
			</NcButton>
		</template>

		<div v-if="!success" class="formContainer">
			<div class="json-editor">
				<label>{{ t('stackiq', 'Object (JSON)') }}</label>
				<div :class="`codeMirrorContainer ${getTheme()}`">
					<CodeMirror
						v-model="objectItem.object"
						:basic="true"
						placeholder='{ "key": "value" }'
						:dark="getTheme() === 'dark'"
						:linter="jsonParseLinter()"
						:lang="json()"
						:tabSize="2" />
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { json, jsonParseLinter } from '@codemirror/lang-json'
import { NcButton, NcDialog, NcNoteCard } from '@nextcloud/vue'
import CodeMirror from 'vue-codemirror6'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import { getTheme } from '../../services/getTheme.js'

export default {
	name: 'DownloadObject',
	components: {
		// components
		NcDialog,
		NcButton,
		NcNoteCard,
		CodeMirror,
		// icons
		Cancel,
	},

	data() {
		return {
			// store
			objectStore,
			navigationStore,
			// state
			success: null,
			loading: false,
			error: false,
			closeModalTimeout: null,
		}
	},

	/**
	 * @spec openspec/specs/fe-object-modals/spec.md
	 */
	mounted() {
		if (objectStore.objectItem?.id) {
			this.downloadObject()
		}
	},

	methods: {
		json,
		jsonParseLinter,
		getTheme,
		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeModalTimeout)
			this.success = null
			this.loading = false
			this.error = false
		},

		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		async downloadObject() {
			this.loading = true

			try {
				const response = await objectStore.downloadObject(
					objectStore.objectItem,
				)
				this.success = response.ok
				this.error = false
				if (response.ok) {
					this.closeModalTimeout = setTimeout(this.closeModal, 2000)
				}
			} catch (error) {
				this.success = false
				this.error =
					error.message
					|| this.t(
						'stackiq',
						'An error occurred while downloading the object',
					)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.json-editor {
	position: relative;
	margin-bottom: 2.5rem;
}

.json-editor label {
	display: block;
	margin-bottom: 0.5rem;
	font-weight: bold;
}

/* CodeMirror */
.codeMirrorContainer {
	margin-block-start: 6px;
}

.codeMirrorContainer :deep(.cm-content) {
	border-radius: 0 !important;
	border: none !important;
}

.codeMirrorContainer :deep(.cm-editor) {
	outline: none !important;
}
</style>

<style scoped src="./codeMirrorJsonTheme.css"></style>
