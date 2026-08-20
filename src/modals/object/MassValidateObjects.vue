/** * @file MassValidateObjects.vue * @module Modals/Object * @author Your Name *
@copyright 2024 Your Organization * @license EUPL-1.2
https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12 * @version 1.0.0 */

<script setup>
import { catalogStore, navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		:name="dialogTitle"
		:canClose="true"
		size="normal"
		class="mass-action-dialog"
		@update:open="handleDialogClose">
		<!-- Object Selection Review -->
		<div v-if="success === null" class="validate-step">
			<NcNoteCard type="info">
				<strong>{{
					t('softwarecatalog', 'When to use mass validation:')
				}}</strong
				><br />
				{{
					t(
						'softwarecatalog',
						'• After updating the schema to apply new validation rules',
					)
				}}<br />
				{{
					t(
						'softwarecatalog',
						'• When publications need to be re-enriched with updated name/description logic',
					)
				}}<br />
				{{
					t(
						'softwarecatalog',
						'• To refresh computed properties or auto-generated fields',
					)
				}}<br />
				{{
					t(
						'softwarecatalog',
						'• After changing schema configuration that affects existing publications',
					)
				}}<br /><br />
				{{
					t(
						'softwarecatalog',
						'Publications will be saved without modification to trigger validation and enrichment processes against the current schema.',
					)
				}}
			</NcNoteCard>

			<SelectedObjectsList
				:title="
					(objectStore.selectedObjects?.length || 0) === 1
						? t('softwarecatalog', 'Publication to Validate')
						: t('softwarecatalog', 'Selected Publications')
				"
				:showRemove="true" />
		</div>

		<NcNoteCard v-if="success" type="success">
			<p>
				{{
					originalSelectedCount > 1
						? t('softwarecatalog', 'Publications successfully validated')
						: t('softwarecatalog', 'Publication successfully validated')
				}}
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
					success === null
						? t('softwarecatalog', 'Cancel')
						: t('softwarecatalog', 'Close')
				}}
			</NcButton>
			<NcButton
				v-if="success === null"
				:disabled="
					loading || (objectStore.selectedObjects?.length || 0) === 0
				"
				variant="primary"
				@click="validateObjects()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<CheckCircle v-if="!loading" :size="20" />
				</template>
				{{ t('softwarecatalog', 'Validate') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import SelectedObjectsList from '../../components/SelectedObjectsList.vue'

export default {
	name: 'MassValidateObjects',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		SelectedObjectsList,
		// Icons
		CheckCircle,
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
		 *
		 * @return {Array<object>} Array of objects to validate
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		objectsToValidate() {
			return objectStore.selectedObjects || []
		},

		/**
		 * Get the dialog title based on number of objects
		 *
		 * @return {string} Dialog title
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		dialogTitle() {
			const count = this.objectsToValidate.length
			if (count === 1) {
				return this.t('softwarecatalog', 'Validate publication')
			}
			return this.t('softwarecatalog', 'Validate {count} publications', {
				count,
			})
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
		 * @param isOpen
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
		async validateObjects() {
			this.loading = true

			try {
				// Get the objects to validate
				const objectsToProcess = [...this.objectsToValidate]

				// Use the store's mass validate method
				const { successful, failed } =
					await objectStore.massValidateObjects(objectsToProcess)

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
					this.error = this.t(
						'softwarecatalog',
						'Failed to validate {count} objects',
						{ count: failed.length },
					)
				}
			} catch (error) {
				this.success = false
				this.error =
					error.message
					|| this.t(
						'softwarecatalog',
						'An error occurred while validating objects',
					)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.validate-step {
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
