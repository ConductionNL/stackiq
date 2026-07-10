/**
 * @file MassDeleteObject.vue
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
		<div v-if="success === null" class="delete-step">
			<NcNoteCard type="info">
				{{ t('softwarecatalog', 'Publications will be soft deleted and moved to the') }}
				<a href="#" class="deleted-link" @click.prevent="navigateToDeleted">{{ t('softwarecatalog', 'deleted publications section') }}</a>{{ t('softwarecatalog', '. They will be retained according to their schema\'s configured retention period and automatically permanently deleted afterwards.') }}
			</NcNoteCard>

			<SelectedObjectsList
				:title="(objectStore.selectedObjects?.length || 0) === 1 ? t('softwarecatalog', 'Publication to Delete') : t('softwarecatalog', 'Selected Publications')"
				:show-remove="true" />
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>{{ originalSelectedCount > 1 ? t('softwarecatalog', 'Publications successfully deleted') : t('softwarecatalog', 'Publication successfully deleted') }}</p>
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
				type="error"
				@click="deleteObject()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<TrashCanOutline v-if="!loading" :size="20" />
				</template>
				{{ t('softwarecatalog', 'Delete') }}
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
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import SelectedObjectsList from '../../components/SelectedObjectsList.vue'

export default {
	name: 'MassDeleteObject',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		SelectedObjectsList,
		// Icons
		TrashCanOutline,
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
		 * @return {Array<object>} Array of objects to delete
		  * @spec openspec/changes/retrofit-2026-05-26-fe-object-modals/tasks.md#task-7
		 */
		objectsToDelete() {
			return objectStore.selectedObjects || []
		},

		/**
		 * Get the dialog title based on number of objects
		 * @return {string} Dialog title
		  * @spec openspec/changes/retrofit-2026-05-26-fe-object-modals/tasks.md#task-7
		 */
		dialogTitle() {
			const count = objectStore.selectedObjects?.length || 0
			if (count === 1) {
				return this.t('softwarecatalog', 'Delete publication')
			}
			return this.t('softwarecatalog', 'Delete {count} publications', { count })
		},
	},

	mounted() {
		this.initializeSelection()
	},
	methods: {
		/**
		 * @spec openspec/changes/retrofit-2026-05-26-fe-object-modals/tasks.md#task-7
		 */
		initializeSelection() {
			// Store the original count for success message
			this.originalSelectedCount = objectStore.selectedObjects?.length || 0
		},
		/**
		 * @spec openspec/changes/retrofit-2026-05-26-fe-object-modals/tasks.md#task-7
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
		 * @spec openspec/changes/retrofit-2026-05-26-fe-object-modals/tasks.md#task-7
		 */
		navigateToDeleted() {
			// Close the dialog first
			this.closeDialog()
			// Navigate to the deleted objects section
			navigationStore.setSelected('deleted')
		},
		/**
		 * @spec openspec/changes/retrofit-2026-05-26-fe-object-modals/tasks.md#task-7
		 */
		async deleteObject() {
			this.loading = true

			try {
				// Get the objects to delete
				const objectsToProcess = [...(objectStore.selectedObjects || [])]

				// Use the store's mass delete method
				const { successful, failed } = await objectStore.massDeleteObjects(objectsToProcess)

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
					this.error = this.t('softwarecatalog', 'Failed to delete {count} objects', { count: failed.length })
				}

			} catch (error) {
				this.success = false
				this.error = error.message || this.t('softwarecatalog', 'An error occurred while deleting objects')
			} finally {
				this.loading = false
			}
		},
		/**
		 * @spec openspec/changes/retrofit-2026-05-26-fe-object-modals/tasks.md#task-7
		 */
		handleDialogClose(isOpen) {
			if (!isOpen) {
				this.closeDialog()
			}
		},
	},
}
</script>

<style scoped>
.delete-step {
	padding: 0;
}

.step-title {
	margin-top: 0 !important;
	margin-bottom: 16px;
	color: var(--color-main-text);
}

.deleted-link {
	color: var(--color-primary);
	text-decoration: underline;
	cursor: pointer;
}

.deleted-link:hover {
	color: var(--color-primary-hover);
}
</style>

<style scoped>
/* Ensure mass action dialogs appear on top of other modals */
.mass-action-dialog {
	z-index: 10000 !important;
}
</style>
