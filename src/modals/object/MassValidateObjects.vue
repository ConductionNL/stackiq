<!--
  - SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
/** * @file MassValidateObjects.vue * @module Modals/Object * @author Your Name *
@copyright 2024 Your Organization * @license EUPL-1.2
https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12 * @version 1.0.0 */

<script setup>
import { objectStore } from '../../store/store.js'
</script>

<template>
	<MassObjectDialog
		:title="dialogTitle"
		stepClass="validate-step"
		noteType="info"
		:singleTitle="t('stackiq', 'Publication to Validate')"
		:successOne="t('stackiq', 'Publication successfully validated')"
		:successMany="t('stackiq', 'Publications successfully validated')"
		buttonVariant="primary"
		:buttonLabel="t('stackiq', 'Validate')"
		:action="massAction">
		<template #note>
				<strong>{{ t('stackiq', 'When to use mass validation:') }}</strong
				><br />
				{{
					t(
						'stackiq',
						'• After updating the schema to apply new validation rules',
					)
				}}<br />
				{{
					t(
						'stackiq',
						'• When publications need to be re-enriched with updated name/description logic',
					)
				}}<br />
				{{
					t(
						'stackiq',
						'• To refresh computed properties or auto-generated fields',
					)
				}}<br />
				{{
					t(
						'stackiq',
						'• After changing schema configuration that affects existing publications',
					)
				}}<br /><br />
				{{
					t(
						'stackiq',
						'Publications will be saved without modification to trigger validation and enrichment processes against the current schema.',
					)
				}}
		</template>
		<template #icon>
			<CheckCircle :size="20" />
		</template>
	</MassObjectDialog>
</template>

<script>
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import MassObjectDialog from './MassObjectDialog.vue'

export default {
	name: 'MassValidateObjects',
	components: {
		MassObjectDialog,
		// Icons
		CheckCircle,
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
				return this.t('stackiq', 'Validate publication')
			}
			return this.t('stackiq', 'Validate {count} publications', {
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
					objectStore.massValidateObjects([...this.objectsToValidate]),

				failedText: (count) =>
					this.t('stackiq', 'Failed to validate {count} objects', {
						count,
					}),

				errorText: () =>
					this.t('stackiq', 'An error occurred while validating objects'),
			}
		},
	},
}
</script>
