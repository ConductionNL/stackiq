/** * @file MassDeleteObject.vue * @module Modals/Object * @author Your Name *
@copyright 2024 Your Organization * @license EUPL-1.2
https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12 * @version 1.0.0 */

<script setup>
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<MassObjectDialog
		:title="dialogTitle"
		stepClass="delete-step"
		noteType="info"
		:singleTitle="t('stackiq', 'Publication to Delete')"
		:successOne="t('stackiq', 'Publication successfully deleted')"
		:successMany="t('stackiq', 'Publications successfully deleted')"
		buttonVariant="error"
		:buttonLabel="t('stackiq', 'Delete')"
		:action="massAction">
		<template #note="{ closeDialog }">
				{{
					t(
						'stackiq',
						'Publications will be soft deleted and moved to the',
					)
				}}
				<a
					href="#"
					class="deleted-link"
					@click.prevent="navigateToDeleted(closeDialog)"
					>{{ t('stackiq', 'deleted publications section') }}</a
				>{{
					t(
						'stackiq',
						". They will be retained according to their schema's configured retention period and automatically permanently deleted afterwards.",
					)
				}}
		</template>
		<template #icon>
			<TrashCanOutline :size="20" />
		</template>
	</MassObjectDialog>
</template>

<script>
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import MassObjectDialog from './MassObjectDialog.vue'

export default {
	name: 'MassDeleteObject',
	components: {
		MassObjectDialog,
		// Icons
		TrashCanOutline,
	},

	computed: {
		/**
		 * Get the objects to operate on from selected objects
		 *
		 * @return {Array<object>} Array of objects to delete
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		objectsToDelete() {
			return objectStore.selectedObjects || []
		},

		/**
		 * Get the dialog title based on number of objects
		 *
		 * @return {string} Dialog title
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		dialogTitle() {
			const count = objectStore.selectedObjects?.length || 0
			if (count === 1) {
				return this.t('stackiq', 'Delete publication')
			}
			return this.t('stackiq', 'Delete {count} publications', {
				count,
			})
		},

		/**
		 * The store call and the texts of this mass action.
		 *
		 * @return {object} The action, as runMassObjectAction takes it
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		massAction() {
			return {
				run: () =>
					objectStore.massDeleteObjects([
						...(objectStore.selectedObjects || []),
					]),

				failedText: (count) =>
					this.t('stackiq', 'Failed to delete {count} objects', { count }),

				errorText: () =>
					this.t('stackiq', 'An error occurred while deleting objects'),
			}
		},
	},

	methods: {
		/**
		 * @param {() => void} closeDialog Closes the dialog
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		navigateToDeleted(closeDialog) {
			// Close the dialog first
			closeDialog()
			// Navigate to the deleted objects section
			navigationStore.setSelected('deleted')
		},
	},
}
</script>

<style scoped>
.deleted-link {
	color: var(--color-primary);
	text-decoration: underline;
	cursor: pointer;
}

.deleted-link:hover {
	color: var(--color-primary-hover);
}
</style>
