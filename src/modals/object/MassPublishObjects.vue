<!--
  - SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
/** * @file MassPublishObjects.vue * @module Modals/Object * @author Your Name *
@copyright 2024 Your Organization * @license EUPL-1.2
https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12 * @version 1.0.0 */

<script setup>
import { objectStore } from '../../store/store.js'
</script>

<template>
	<MassObjectDialog
		:title="dialogTitle"
		stepClass="publish-step"
		noteType="info"
		:singleTitle="t('stackiq', 'Publication to Publish')"
		:successOne="t('stackiq', 'Object successfully published')"
		:successMany="t('stackiq', 'Objects successfully published')"
		buttonVariant="primary"
		:buttonLabel="t('stackiq', 'Publish')"
		:action="massAction">
		<template #note>
				{{
					t(
						'stackiq',
						'Objects will be published with the current date and time. If any objects have a depublication date set, it will be removed to make them fully published.',
					)
				}}
		</template>
		<template #icon>
			<Publish :size="20" />
		</template>
	</MassObjectDialog>
</template>

<script>
import Publish from 'vue-material-design-icons/Publish.vue'
import MassObjectDialog from './MassObjectDialog.vue'

export default {
	name: 'MassPublishObjects',
	components: {
		MassObjectDialog,
		// Icons
		Publish,
	},

	computed: {
		/**
		 * Get the objects to operate on from selected objects
		 *
		 * @return {Array<object>} Array of objects to publish
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		objectsToPublish() {
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
				return this.t('stackiq', 'Publish publication')
			}
			return this.t('stackiq', 'Publish {count} publications', {
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
					objectStore.massPublishObjects([
						...(objectStore.selectedObjects || []),
					]),

				failedText: (count) =>
					this.t('stackiq', 'Failed to publish {count} objects', {
						count,
					}),

				errorText: () =>
					this.t('stackiq', 'An error occurred while publishing objects'),
			}
		},
	},
}
</script>
