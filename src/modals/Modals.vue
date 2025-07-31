<template>
	<div>
		<!-- Object Modals -->
		<ObjectModal v-if="navigationStore.modal === 'organisatie'" />
		<ViewObject v-if="navigationStore.modal === 'viewOrganisatie'" />
		<UploadObject v-if="navigationStore.modal === 'uploadOrganisatie'" />
		<LockObject v-if="navigationStore.modal === 'lockOrganisatie'" />
		<MigrationObject v-if="navigationStore.modal === 'migrationOrganisatie'" />
		<MergeObject v-if="navigationStore.modal === 'mergeOrganisatie'" />
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
}
</script>
