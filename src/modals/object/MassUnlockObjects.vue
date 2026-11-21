<!--
  - SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
/** * @file MassUnlockObjects.vue * @module Modals/Object * @author Your Name *
@copyright 2024 Your Organization * @license EUPL-1.2
https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12 * @version 1.0.0 */

<script setup>
import { objectStore } from '../../store/store.js'
</script>

<template>
	<MassObjectDialog
		:title="dialogTitle"
		stepClass="unlock-step"
		noteType="warning"
		:singleTitle="t('stackiq', 'Publication to Unlock')"
		:successOne="t('stackiq', 'Publication successfully unlocked')"
		:successMany="t('stackiq', 'Publications successfully unlocked')"
		buttonVariant="warning"
		:buttonLabel="t('stackiq', 'Unlock')"
		:action="massAction">
		<template #note>
				{{
					t(
						'stackiq',
						'Objects will be unlocked and made available for editing by other users. Only objects that are currently locked can be unlocked.',
					)
				}}
		</template>
		<template #icon>
			<LockOpenOutline :size="20" />
		</template>
	</MassObjectDialog>
</template>

<script>
import LockOpenOutline from 'vue-material-design-icons/LockOpenOutline.vue'
import MassObjectDialog from './MassObjectDialog.vue'

export default {
	name: 'MassUnlockObjects',
	components: {
		MassObjectDialog,
		// Icons
		LockOpenOutline,
	},

	computed: {
		/**
		 * Get the objects to operate on from selected objects
		 *
		 * @return {Array<object>} Array of objects to unlock
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		objectsToUnlock() {
			return objectStore.selectedObjects || []
		},

		/**
		 * Get the dialog title based on number of objects
		 *
		 * @return {string} Dialog title
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		dialogTitle() {
			const count = this.objectsToUnlock.length
			if (count === 1) {
				return this.t('stackiq', 'Unlock publication')
			}
			return this.t('stackiq', 'Unlock {count} publications', {
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
				run: () => objectStore.massUnlockObjects([...this.objectsToUnlock]),

				failedText: (count) =>
					this.t('stackiq', 'Failed to unlock {count} objects', { count }),

				errorText: () =>
					this.t('stackiq', 'An error occurred while unlocking objects'),
			}
		},
	},
}
</script>
