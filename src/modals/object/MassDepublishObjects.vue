/**
 * @file MassDepublishObjects.vue
 * @module Modals/Object
 * @author Your Name
 * @copyright 2024 Your Organization
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 */

<script setup>
import { objectStore, navigationStore, catalogStore } from '../../store/store.js'
</script>

<template>
	<NcDialog :name="dialogTitle"
		:can-close="true"
		size="normal"
		class="mass-action-dialog"
		@update:open="handleDialogClose">
		<!-- Object Selection Review -->
		<div v-if="success === null" class="depublish-step">
			<NcNoteCard type="warning">
				{{ t('softwarecatalog', 'Objects will be depublished with the current date and time. This will make them unavailable to the public while keeping their published date intact.') }}
			</NcNoteCard>

			<SelectedObjectsList
				:title="(objectStore.selectedObjects?.length || 0) === 1 ? t('softwarecatalog', 'Publication to Depublish') : t('softwarecatalog', 'Selected Publications')"
				:show-remove="true" />
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>{{ originalSelectedCount > 1 ? t('softwarecatalog', 'Objects successfully depublished') : t('softwarecatalog', 'Object successfully depublished') }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success === null ? t('softwarecatalog', 'Cancel') : t('softwarecatalog', 'Close') }}
			</NcButton>
			<NcButton v-if="success === null"
				:disabled="loading || (objectStore.selectedObjects?.length || 0) === 0"
				variant="error"
				@click="depublishObjects()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<PublishOff v-if="!loading" :size="20" />
				</template>
				{{ t('softwarecatalog', 'Depublish') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import SelectedObjectsList from '../../components/SelectedObjectsList.vue'

export default {
	name: 'MassDepublishObjects',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		SelectedObjectsList,
		// Icons
		PublishOff,
		Cancel,
	},

	props: {
		// No props needed - always uses selected objects from store
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

	computed: {
		/**
		 * Get the objects to operate on from selected objects
		 * @return {Array<object>} Array of objects to depublish
		  * @spec openspec/specs/fe-object-modals/spec.md
		 */
		objectsToDepublish() {
			return objectStore.selectedObjects || []
		},

		/**
		 * Get the dialog title based on number of objects
		 * @return {string} Dialog title
		  * @spec openspec/specs/fe-object-modals/spec.md
		 */
		dialogTitle() {
			const count = this.objectsToDepublish.length
			if (count === 1) {
				return this.t('softwarecatalog', 'Depublish publication')
			}
			return this.t('softwarecatalog', 'Depublish {count} publications', { count })
		},
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
		async depublishObjects() {
			this.loading = true

			try {
				// Get the objects to depublish
				const objectsToProcess = [...this.objectsToDepublish]

				// Use the store's mass depublish method
				const { successful, failed } = await objectStore.massDepublishObjects(objectsToProcess)

				if (successful.length > 0) {
					this.success = true
					// Refresh publications using catalogStore
					catalogStore.fetchPublications()

					// Only auto-close if there are no failures
					if (failed.length === 0) {
						this.closeModalTimeout = setTimeout(() => {
							this.closeDialog()
						}, 2000)
					}
				}

				if (failed.length > 0) {
					this.error = this.t('softwarecatalog', 'Failed to depublish {count} objects', { count: failed.length })
				}

			} catch (error) {
				this.success = false
				this.error = error.message || this.t('softwarecatalog', 'An error occurred while depublishing objects')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.depublish-step {
	padding: 0;
}

.step-title {
	margin-top: 0 !important;
	margin-bottom: 16px;
	color: var(--color-main-text);
}
</style>

<style scoped>
/* Ensure mass action dialogs appear on top of other modals */
.mass-action-dialog {
	z-index: 10000 !important;
}
</style>
