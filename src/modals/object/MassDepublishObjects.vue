<!--
  - SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
/** * @file MassDepublishObjects.vue * @module Modals/Object * @author Your Name *
@copyright 2024 Your Organization * @license EUPL-1.2
https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12 * @version 1.0.0 */

<script setup>
import { objectStore } from '../../store/store.js'
</script>

<template>
	<MassObjectDialog
		:title="dialogTitle"
		stepClass="depublish-step"
		noteType="warning"
		:singleTitle="t('stackiq', 'Publication to Depublish')"
		:successOne="t('stackiq', 'Object successfully depublished')"
		:successMany="t('stackiq', 'Objects successfully depublished')"
		buttonVariant="error"
		:buttonLabel="t('stackiq', 'Depublish')"
		:action="massAction">
		<template #note>
				{{
					t(
						'stackiq',
						'Objects will be depublished with the current date and time. This will make them unavailable to the public while keeping their published date intact.',
					)
				}}
		</template>
		<template #icon>
			<PublishOff :size="20" />
		</template>
	</MassObjectDialog>
</template>

<script>
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import MassObjectDialog from './MassObjectDialog.vue'

export default {
	name: 'MassDepublishObjects',
	components: {
		MassObjectDialog,
		// Icons
		PublishOff,
	},

	computed: {
		/**
		 * Get the objects to operate on from selected objects
		 *
		 * @return {Array<object>} Array of objects to depublish
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		objectsToDepublish() {
			return objectStore.selectedObjects || []
		},

		/**
		 * Get the dialog title based on number of objects
		 *
		 * @return {string} Dialog title
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		dialogTitle() {
			const count = this.objectsToDepublish.length
			if (count === 1) {
				return this.t('stackiq', 'Depublish publication')
			}
			return this.t('stackiq', 'Depublish {count} publications', {
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
					objectStore.massDepublishObjects([...this.objectsToDepublish]),

				failedText: (count) =>
					this.t('stackiq', 'Failed to depublish {count} objects', {
						count,
					}),

				errorText: () =>
					this.t(
						'stackiq',
						'An error occurred while depublishing objects',
					),
			}
		},
	},
}
</script>
