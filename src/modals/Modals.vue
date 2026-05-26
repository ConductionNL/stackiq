<template>
	<div>
		<!-- Organisatie Modals -->
		<ObjectModal v-if="navigationStore.modal === 'organisatie'"
			object-type-key="organisatie" />
		<ViewObject v-if="navigationStore.modal === 'viewOrganisatie'" />
		<UploadObject v-if="navigationStore.modal === 'uploadOrganisatie'" />
		<LockObject v-if="navigationStore.modal === 'lockOrganisatie'" />
		<MigrationObject v-if="navigationStore.modal === 'migrationOrganisatie'" />
		<MergeObject v-if="navigationStore.modal === 'mergeOrganisatie'" />

		<!-- Generic Object Edit Modal for other object types (contactpersoon, etc.) -->
		<ObjectModal v-if="genericObjectModalType"
			:object-type-key="genericObjectModalType" />

		<!-- View modals for other object types -->
		<ViewObject v-if="navigationStore.modal === 'viewContactpersoon'" />
	</div>
</template>

<script>
import { navigationStore } from '../store/store.js'
import ObjectModal from './object/ObjectModal.vue'
import ViewObject from './object/ViewObject.vue'
import UploadObject from './object/UploadObject.vue'
import LockObject from './object/LockObject.vue'
import MigrationObject from './object/MigrationObject.vue'
import MergeObject from './object/MergeObject.vue'

/**
 * Object types that should use the generic ObjectModal for editing.
 * 'organisatie' is excluded because it has its own dedicated condition above.
 */
const GENERIC_MODAL_OBJECT_TYPES = [
	'contactpersoon',
	'gebruik',
	'contract',
	'koppeling',
	'module',
	'suite',
	'dienst',
	'kwetsbaarheid',
	'beoordeeling',
	'compliancy',
	'moduleVersie',
	'sector',
]

export default {
	name: 'Modals',
	components: {
		ObjectModal,
		ViewObject,
		UploadObject,
		LockObject,
		MigrationObject,
		MergeObject,
	},
	/**
	 * @spec exclude Pinia store wiring in setup() — bootstrap plumbing
	 */
	setup() {
		return {
			navigationStore,
		}
	},
	computed: {
		/**
		 * Returns the object type if the current modal matches a generic object type,
		 * or null if the modal is not a generic object edit modal.
		 *
		 * @return {string|null} The object type key, or null
		  * @spec exclude computed passthrough of navigation store modal type — DI getter
		 */
		genericObjectModalType() {
			const modal = navigationStore.modal
			if (!modal || typeof modal !== 'string') {
				return null
			}
			return GENERIC_MODAL_OBJECT_TYPES.includes(modal) ? modal : null
		},
	},
}
</script>
