<!--
SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
SPDX-License-Identifier: EUPL-1.2

The dialog shared by the mass object actions (publish, depublish, lock,
unlock, validate, delete): the selected objects to review, the outcome notes
and the cancel/run buttons. Each action supplies its texts, its note, its
icon, an optional form and the action to run.

@spec openspec/specs/fe-object-modals/spec.md
-->

<script setup>
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		:name="title"
		:canClose="true"
		size="normal"
		class="mass-action-dialog"
		@update:open="handleDialogClose">
		<!-- Object Selection Review -->
		<div v-if="success === null" :class="stepClass">
			<NcNoteCard :type="noteType">
				<slot name="note" :closeDialog="closeDialog" />
			</NcNoteCard>

			<SelectedObjectsList
				:title="
					(objectStore.selectedObjects?.length || 0) === 1
						? singleTitle
						: t('stackiq', 'Selected Publications')
				"
				:showRemove="true" />
			<slot name="form" :success="success" :loading="loading" />
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>
				{{ originalSelectedCount > 1 ? successMany : successOne }}
			</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{
					success === null ? t('stackiq', 'Cancel') : t('stackiq', 'Close')
				}}
			</NcButton>
			<NcButton
				v-if="success === null"
				:disabled="
					loading || (objectStore.selectedObjects?.length || 0) === 0
				"
				:variant="buttonVariant"
				@click="runAction()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<slot v-if="!loading" name="icon" />
				</template>
				{{ buttonLabel }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import SelectedObjectsList from '../../components/SelectedObjectsList.vue'
import { runMassObjectAction } from '../../utils/massObjectAction.js'

export default {
	name: 'MassObjectDialog',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		SelectedObjectsList,
		// Icons
		Cancel,
	},

	props: {
		/** The dialog title. */
		title: { type: String, required: true },
		/** The class of the review step. */
		stepClass: { type: String, required: true },
		/** The type of the explanation note. */
		noteType: { type: String, default: 'info' },
		/** The list title when one object is selected. */
		singleTitle: { type: String, required: true },
		/** The success text for one object. */
		successOne: { type: String, required: true },
		/** The success text for several objects. */
		successMany: { type: String, required: true },
		/** The variant of the run button. */
		buttonVariant: { type: String, default: 'primary' },
		/** The label of the run button. */
		buttonLabel: { type: String, required: true },
		/** The action: run(), failedText(count) and errorText(), as runMassObjectAction takes it. */
		action: { type: Object, required: true },
	},

	data() {
		return {
			success: null,
			loading: false,
			error: false,
			result: null,
			closeModalTimeout: null,
			originalSelectedCount: 0,
		}
	},

	mounted() {
		this.initializeSelection()
	},

	methods: {
		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		initializeSelection() {
			// Store the original count for success message
			this.originalSelectedCount = objectStore.selectedObjects?.length || 0
		},

		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		closeDialog() {
			// Clear any pending timeout that might reopen the dialog
			if (this.closeModalTimeout) {
				clearTimeout(this.closeModalTimeout)
				this.closeModalTimeout = null
			}
			navigationStore.setDialog(false)
		},

		/**
		 * @param {boolean} isOpen Whether the dialog stays open
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		handleDialogClose(isOpen) {
			if (!isOpen) {
				this.closeDialog()
			}
		},

		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		async runAction() {
			await runMassObjectAction(this, this.action)
		},
	},
}
</script>

<style scoped>
.publish-step,
.depublish-step,
.lock-step,
.unlock-step,
.validate-step,
.delete-step {
	padding: 0;
}
</style>

<style scoped>
/* Ensure mass action dialogs appear on top of other modals */
.mass-action-dialog {
	z-index: 10000 !important;
}
</style>
