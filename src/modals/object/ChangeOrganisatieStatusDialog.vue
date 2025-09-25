/**
 * ChangeOrganisatieStatusDialog.vue
 * Dialog for changing organisatie status with confirmation
 * @category Components
 * @package softwarecatalog
 * @author Ruben Linde
 * @copyright 2024
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @link https://github.com/opencatalogi/softwarecatalog
 */

<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog v-if="navigationStore.dialog === 'changeOrganisatieStatus'"
		:name="navigationStore.dialogProperties?.dialogTitle || 'Status Wijzigen'"
		size="normal"
		:can-close="false">
		<p v-if="success === null">
			Weet je zeker dat je de status van <b>{{ getOrganisatieName() }}</b> wilt wijzigen naar <b>{{ navigationStore.dialogProperties?.newStatus }}</b>?
			<br>
			<span v-if="navigationStore.dialogProperties?.action === 'activeren'" class="status-change-info">
				Deze organisatie wordt geactiveerd en zal zichtbaar zijn voor gebruikers.
			</span>
			<span v-else-if="navigationStore.dialogProperties?.action === 'deactiveren'" class="status-change-info">
				Deze organisatie wordt gedeactiveerd en zal niet meer zichtbaar zijn voor gebruikers.
			</span>
		</p>

		<NcNoteCard v-if="success" type="success">
			<p>Status succesvol gewijzigd naar {{ navigationStore.dialogProperties?.newStatus }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ success === null ? 'Annuleren' : 'Sluiten' }}
			</NcButton>
			<NcButton
				v-if="success === null"
				:disabled="loading"
				type="primary"
				@click="changeStatus()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<CheckCircle v-if="!loading && navigationStore.dialogProperties?.action === 'activeren'" :size="20" />
					<CloseCircle v-if="!loading && navigationStore.dialogProperties?.action === 'deactiveren'" :size="20" />
				</template>
				{{ navigationStore.dialogProperties?.action === 'activeren' ? 'Activeren' : 'Deactiveren' }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

import Cancel from 'vue-material-design-icons/Cancel.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'

export default {
	name: 'ChangeOrganisatieStatusDialog',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		Cancel,
		CheckCircle,
		CloseCircle,
	},
	data() {
		return {
			loading: false,
			success: null,
			error: null,
		}
	},
	methods: {
		/**
		 * Get the organisation name for display
		 * @return {string} The organisation name
		 */
		getOrganisatieName() {
			const organisatie = objectStore.getActiveObject('organisatie')
			return organisatie?.naam || organisatie?.name || organisatie?.['@self']?.name || 'Onbekende organisatie'
		},

		/**
		 * Close the dialog
		 * @return {void}
		 */
		closeDialog() {
			this.success = null
			this.error = null
			this.loading = false
			navigationStore.setDialog(false)
		},

		/**
		 * Change the organisation status
		 * @return {Promise<void>}
		 */
		async changeStatus() {
			this.loading = true
			this.error = null

			try {
				const organisatie = objectStore.getActiveObject('organisatie')
				const newStatus = navigationStore.dialogProperties?.newStatus

				if (!organisatie || !organisatie.id || !newStatus) {
					throw new Error('Organisatie of nieuwe status ontbreekt')
				}

				// Prepare the patch data - only include changed properties
				const patchData = {
					status: newStatus,
				}

				// If activating the organisation, set the @self metadata to own itself
				// This ensures the organisation owns itself immediately upon activation
				if (newStatus.toLowerCase() === 'actief') {
					const organisatieUuid = organisatie.id || organisatie.uuid || organisatie['@self']?.id
					
					// Set the owner and organisation in @self metadata to the organisation's own UUID
					patchData['@self'] = {
						...organisatie['@self'],
						owner: organisatieUuid,
						organisation: organisatieUuid
					}
					
					console.info('Setting @self owner and organisation properties to own UUID during activation:', {
						organisatieId: organisatieUuid,
						ownerProperty: patchData['@self'].owner,
						selfOrganisationProperty: patchData['@self'].organisation
					})
				}

				// Update only the status (and @self if activating) using PATCH
				const updatedOrganisatie = await objectStore.patchObject('organisatie', organisatie.id, patchData)

				this.success = true

				// Refresh the collection to show the updated status
				objectStore.fetchCollection('organisatie')

				// Auto-close after 2 seconds on success
				setTimeout(() => {
					this.closeDialog()
				}, 2000)

			} catch (error) {
				console.error('Error changing organisation status:', error)
				this.error = error.message || 'Er is een fout opgetreden bij het wijzigen van de status'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.status-change-info {
	font-size: 14px;
	color: var(--color-text-lighter);
	margin-top: 8px;
	display: block;
}
</style>
