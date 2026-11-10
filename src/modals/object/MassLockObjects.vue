/** * @file MassLockObjects.vue * @module Modals/Object * @author Your Name *
@copyright 2024 Your Organization * @license EUPL-1.2
https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12 * @version 1.0.0 */

<script setup>
import { objectStore } from '../../store/store.js'
</script>

<template>
	<MassObjectDialog
		:title="dialogTitle"
		stepClass="lock-step"
		noteType="info"
		:singleTitle="t('stackiq', 'Publication to Lock')"
		:successOne="t('stackiq', 'Publication successfully locked')"
		:successMany="t('stackiq', 'Publications successfully locked')"
		buttonVariant="primary"
		:buttonLabel="t('stackiq', 'Lock')"
		:action="massAction">
		<template #note>
				{{
					t(
						'stackiq',
						"Locking objects prevents other users from modifying them until they are unlocked. You can specify an optional process name to indicate why they're locked and a duration after which they will automatically unlock. Only the user who locked the objects or an administrator can unlock them before the duration expires.",
					)
				}}
		</template>
		<template #form="{ loading, success }">
		<div v-if="!success" class="formContainer">
			<NcTextField
				v-model="process"
				:label="t('stackiq', 'Process Name (optional)')"
				:disabled="loading" />
			<NcTextField
				v-model="duration"
				type="number"
				:label="t('stackiq', 'Duration in seconds (optional)')"
				:disabled="loading" />
		</div>
		</template>
		<template #icon>
			<LockOutline :size="20" />
		</template>
	</MassObjectDialog>
</template>

<script>
import { NcTextField } from '@nextcloud/vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import MassObjectDialog from './MassObjectDialog.vue'

export default {
	name: 'MassLockObjects',
	components: {
		MassObjectDialog,
		NcTextField,
		// Icons
		LockOutline,
	},

	data() {
		return {
			process: '',
			duration: 3600,
		}
	},

	computed: {
		/**
		 * Get the objects to operate on from selected objects
		 *
		 * @return {Array<object>} Array of objects to lock
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		objectsToLock() {
			return objectStore.selectedObjects || []
		},

		/**
		 * Get the dialog title based on number of objects
		 *
		 * @return {string} Dialog title
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		dialogTitle() {
			const count = this.objectsToLock.length
			if (count === 1) {
				return this.t('stackiq', 'Lock publication')
			}
			return this.t('stackiq', 'Lock {count} publications', { count })
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
					objectStore.massLockObjects(
						[...this.objectsToLock],
						this.process || null,
						this.duration || null,
					),

				failedText: (count) =>
					this.t('stackiq', 'Failed to lock {count} objects', { count }),

				errorText: () =>
					this.t('stackiq', 'An error occurred while locking objects'),
			}
		},
	},
}
</script>

<style scoped>
.formContainer {
	margin-top: 1rem;
	display: flex;
	flex-direction: column;
	gap: 1rem;
}
</style>
