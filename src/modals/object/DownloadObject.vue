<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.modal === 'downloadObject'"
		:name="
			t('softwarecatalog', 'Download {name}', {
				name:
					objectStore.objectItem?.['@self']?.name
					|| objectStore.objectItem?.name
					|| objectStore.objectItem?.['@self']?.title
					|| objectStore.objectItem?.id
					|| t('softwarecatalog', 'Publication'),
			})
		"
		size="normal"
		:can-close="false">
		<NcNoteCard v-if="success" type="success">
			<p>{{ t('softwarecatalog', 'Object successfully downloaded') }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{
					success
						? t('softwarecatalog', 'Close')
						: t('softwarecatalog', 'Cancel')
				}}
			</NcButton>
		</template>

		<div v-if="!success" class="formContainer">
			<div class="json-editor">
				<label>{{ t('softwarecatalog', 'Object (JSON)') }}</label>
				<div :class="`codeMirrorContainer ${getTheme()}`">
					<CodeMirror
						v-model="objectItem.object"
						:basic="true"
						placeholder='{ "key": "value" }'
						:dark="getTheme() === 'dark'"
						:linter="jsonParseLinter()"
						:lang="json()"
						:tab-size="2" />
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { getTheme } from '../../services/getTheme.js'
import { NcDialog, NcButton, NcNoteCard } from '@nextcloud/vue'
import { json, jsonParseLinter } from '@codemirror/lang-json'
import CodeMirror from 'vue-codemirror6'

import Cancel from 'vue-material-design-icons/Cancel.vue'

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
						'softwarecatalog',
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

.codeMirrorContainer.light > .vue-codemirror {
	border: 1px dotted silver;
}

.codeMirrorContainer.dark > .vue-codemirror {
	border: 1px dotted grey;
}

/* value text color */
/* string */
.codeMirrorContainer.light :deep(.ͼe) {
	color: #448c27;
}

.codeMirrorContainer.dark :deep(.ͼe) {
	color: #88c379;
}

/* boolean */
.codeMirrorContainer.light :deep(.ͼc) {
	color: #221199;
}

.codeMirrorContainer.dark :deep(.ͼc) {
	color: #8d64f7;
}

/* null */
.codeMirrorContainer.light :deep(.ͼb) {
	color: #770088;
}

.codeMirrorContainer.dark :deep(.ͼb) {
	color: #be55cd;
}

/* number */
.codeMirrorContainer.light :deep(.ͼd) {
	color: #d19a66;
}

.codeMirrorContainer.dark :deep(.ͼd) {
	color: #9d6c3a;
}

/* text cursor */
.codeMirrorContainer :deep(.cm-content) * {
	cursor: text !important;
}

/* selection color */
.codeMirrorContainer.light :deep(.cm-line)::selection,
.codeMirrorContainer.light :deep(.cm-line) ::selection {
	background-color: #d7eaff !important;
	color: black;
}

.codeMirrorContainer.dark :deep(.cm-line)::selection,
.codeMirrorContainer.dark :deep(.cm-line) ::selection {
	background-color: #8fb3e6 !important;
	color: black;
}

/* string */
.codeMirrorContainer.light :deep(.cm-line .ͼe)::selection {
	color: #2d770f;
}

.codeMirrorContainer.dark :deep(.cm-line .ͼe)::selection {
	color: #104e0c;
}

/* boolean */
.codeMirrorContainer.light :deep(.cm-line .ͼc)::selection {
	color: #221199;
}

.codeMirrorContainer.dark :deep(.cm-line .ͼc)::selection {
	color: #4026af;
}

/* null */
.codeMirrorContainer.light :deep(.cm-line .ͼb)::selection {
	color: #770088;
}

.codeMirrorContainer.dark :deep(.cm-line .ͼb)::selection {
	color: #770088;
}

/* number */
.codeMirrorContainer.light :deep(.cm-line .ͼd)::selection {
	color: #8c5c2c;
}

.codeMirrorContainer.dark :deep(.cm-line .ͼd)::selection {
	color: #623907;
}
</style>
