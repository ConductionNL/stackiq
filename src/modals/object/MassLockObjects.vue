/**
 * @file MassLockObjects.vue
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
		<div v-if="success === null" class="lock-step">
			<NcNoteCard type="info">
				Locking objects prevents other users from modifying them until they are unlocked. You can specify an optional process name to indicate why they're locked and a duration after which they will automatically unlock. Only the user who locked the objects or an administrator can unlock them before the duration expires.
			</NcNoteCard>

			<SelectedObjectsList
				:title="(objectStore.selectedObjects?.length || 0) === 1 ? 'Publication to Lock' : 'Selected Publications'"
				:show-remove="true" />

			<div v-if="!success" class="formContainer">
				<NcTextField
					v-model="process"
					label="Process Name (optional)"
					:disabled="loading" />
				<NcTextField
					v-model="duration"
					type="number"
					label="Duration in seconds (optional)"
					:disabled="loading" />
			</div>
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>Publication{{ originalSelectedCount > 1 ? 's' : '' }} successfully locked</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success === null ? 'Cancel' : 'Close' }}
			</NcButton>
			<NcButton v-if="success === null"
				:disabled="loading || (objectStore.selectedObjects?.length || 0) === 0"
				type="primary"
				@click="lockObjects()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<LockOutline v-if="!loading" :size="20" />
				</template>
				Lock
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
	NcTextField,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import SelectedObjectsList from '../../components/SelectedObjectsList.vue'

export default {
	name: 'MassLockObjects',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		SelectedObjectsList,
		// Icons
		LockOutline,
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
			process: '',
			duration: 3600,
		}
	},

	computed: {
		/**
		 * Get the objects to operate on from selected objects
		 * @return {Array<object>} Array of objects to lock
		 */
		objectsToLock() {
			return objectStore.selectedObjects || []
		},

		/**
		 * Get the dialog title based on number of objects
		 * @return {string} Dialog title
		 */
		dialogTitle() {
			const count = this.objectsToLock.length
			if (count === 1) {
				return 'Lock publication'
			}
			return `Lock ${count} publication${count !== 1 ? 's' : ''}`
		},
	},
	mounted() {
		this.initializeSelection()
	},
	methods: {
		initializeSelection() {
			// Store the original count for success message
			this.originalSelectedCount = objectStore.selectedObjects?.length || 0
		},
		closeDialog() {
			// Clear any pending timeout that might reopen the dialog
			if (this.closeModalTimeout) {
				clearTimeout(this.closeModalTimeout)
				this.closeModalTimeout = null
			}
			navigationStore.setDialog(false)
		},
		handleDialogClose(isOpen) {
			if (!isOpen) {
				this.closeDialog()
			}
		},
		async lockObjects() {
			this.loading = true

			try {
				// Get the objects to lock
				const objectsToProcess = [...this.objectsToLock]

				// Use the store's mass lock method
				const { successful, failed } = await objectStore.massLockObjects(
					objectsToProcess,
					this.process || null,
					this.duration || null,
				)

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
					this.error = `Failed to lock ${failed.length} object${failed.length > 1 ? 's' : ''}`
				}

			} catch (error) {
				this.success = false
				this.error = error.message || 'An error occurred while locking objects'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.lock-step {
	padding: 0;
}

.step-title {
	margin-top: 0 !important;
	margin-bottom: 16px;
	color: var(--color-main-text);
}

.formContainer {
	margin-top: 1rem;
	display: flex;
	flex-direction: column;
	gap: 1rem;
}
</style>

<style>
/* Ensure mass action dialogs appear on top of other modals */
.mass-action-dialog {
	z-index: 10000 !important;
}
</style>
