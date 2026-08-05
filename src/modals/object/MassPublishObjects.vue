/**
 * @file MassPublishObjects.vue
 * @module Modals/Object
 * @author Your Name
 * @copyright 2024 Your Organization
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
		<div v-if="success === null" class="publish-step">
			<NcNoteCard type="info">
				{{ t('softwarecatalog', 'Objects will be published with the current date and time. If any objects have a depublication date set, it will be removed to make them fully published.') }}
			</NcNoteCard>

			<SelectedObjectsList
				:title="(objectStore.selectedObjects?.length || 0) === 1 ? t('softwarecatalog', 'Publication to Publish') : t('softwarecatalog', 'Selected Publications')"
				:show-remove="true" />
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>{{ originalSelectedCount > 1 ? t('softwarecatalog', 'Objects successfully published') : t('softwarecatalog', 'Object successfully published') }}</p>
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
				variant="primary"
				@click="publishObjects()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Publish v-if="!loading" :size="20" />
				</template>
				{{ t('softwarecatalog', 'Publish') }}
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
import Publish from 'vue-material-design-icons/Publish.vue'
import SelectedObjectsList from '../../components/SelectedObjectsList.vue'

export default {
	name: 'MassPublishObjects',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		SelectedObjectsList,
		// Icons
		Publish,
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
		 * @return {Array<object>} Array of objects to publish
		  * @spec openspec/specs/fe-object-modals/spec.md
		 */
		objectsToPublish() {
			return objectStore.selectedObjects || []
		},

		/**
		 * Get the dialog title based on number of objects
		 * @return {string} Dialog title
		  * @spec openspec/specs/fe-object-modals/spec.md
		 */
		dialogTitle() {
			const count = objectStore.selectedObjects?.length || 0
			if (count === 1) {
				return this.t('softwarecatalog', 'Publish publication')
			}
			return this.t('softwarecatalog', 'Publish {count} publications', { count })
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
		async publishObjects() {
			this.loading = true

			try {
				// Get the objects to publish
				const objectsToProcess = [...(objectStore.selectedObjects || [])]

				// Use the store's mass publish method
				const { successful, failed } = await objectStore.massPublishObjects(objectsToProcess)

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
					this.error = this.t('softwarecatalog', 'Failed to publish {count} objects', { count: failed.length })
				}

			} catch (error) {
				this.success = false
				this.error = error.message || this.t('softwarecatalog', 'An error occurred while publishing objects')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.publish-step {
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
