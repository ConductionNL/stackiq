<!--
  - SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<div>
		<StartAttestationRoundDialog
			v-if="navigationStore.dialog === 'startAttestationRound'" />
		<!-- Object Dialogs -->
		<DeleteObject v-if="navigationStore.dialog === 'deleteObject'" />
		<DownloadObject v-if="navigationStore.dialog === 'downloadObject'" />
		<ChangeOrganisatieStatusDialog
			v-if="navigationStore.dialog === 'changeOrganisatieStatus'" />
		<MassDeleteObject v-if="navigationStore.dialog === 'massDeleteObjects'" />
		<MassPublishObjects v-if="navigationStore.dialog === 'massPublishObjects'" />
		<MassDepublishObjects
			v-if="navigationStore.dialog === 'massDepublishObjects'" />
		<MassLockObjects v-if="navigationStore.dialog === 'massLockObjects'" />
		<MassUnlockObjects v-if="navigationStore.dialog === 'massUnlockObjects'" />
		<MassValidateObjects
			v-if="navigationStore.dialog === 'massValidateObjects'" />
		<MoveToOrganisationDialog
			v-if="navigationStore.dialog === 'moveToOrganisation'" />
	</div>
</template>

<script>
import ChangeOrganisatieStatusDialog from '../modals/object/ChangeOrganisatieStatusDialog.vue'
import DeleteObject from '../modals/object/DeleteObject.vue'
import DownloadObject from '../modals/object/DownloadObject.vue'
import MassDeleteObject from '../modals/object/MassDeleteObject.vue'
import MassDepublishObjects from '../modals/object/MassDepublishObjects.vue'
import MassLockObjects from '../modals/object/MassLockObjects.vue'
import MassPublishObjects from '../modals/object/MassPublishObjects.vue'
import MassUnlockObjects from '../modals/object/MassUnlockObjects.vue'
import MassValidateObjects from '../modals/object/MassValidateObjects.vue'
import MoveToOrganisationDialog from './MoveToOrganisationDialog.vue'
import StartAttestationRoundDialog from './StartAttestationRoundDialog.vue'
import { navigationStore } from '../store/store.js'

export default {
	name: 'Dialogs',
	components: {
		DeleteObject,
		DownloadObject,
		ChangeOrganisatieStatusDialog,
		MassDeleteObject,
		MassPublishObjects,
		MassDepublishObjects,
		MassLockObjects,
		MassUnlockObjects,
		MassValidateObjects,
		MoveToOrganisationDialog,
		StartAttestationRoundDialog,
	},

	/**
	 * @spec exclude Pinia store wiring in setup() — bootstrap plumbing
	 */
	setup() {
		return {
			navigationStore,
		}
	},
}
</script>
