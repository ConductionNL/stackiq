/** * ChangeOrganisatieStatusDialog.vue * Dialog for changing organisatie status with
confirmation * @category Components * @package softwarecatalog * @author Ruben Linde
* @copyright 2024 * @license EUPL-1.2
https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12 * @version 1.0.0 *
@link https://github.com/opencatalogi/softwarecatalog */

<script setup>
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.dialog === 'changeOrganisatieStatus'"
		:name="
			navigationStore.dialogProperties?.dialogTitle
			|| t('softwarecatalog', 'Change status')
		"
		size="normal"
		:canClose="false">
		<p v-if="success === null">
			{{
				t('softwarecatalog', 'Are you sure you want to change the status of')
			}}
			<b>{{ getOrganisatieName() }}</b> {{ t('softwarecatalog', 'to') }}
			<b>{{ navigationStore.dialogProperties?.newStatus }}</b
			>?
			<br />
			<span
				v-if="navigationStore.dialogProperties?.action === 'activeren'"
				class="status-change-info">
				{{
					t(
						'softwarecatalog',
						'This organisation will be activated and will be visible to users.',
					)
				}}
			</span>
			<span
				v-else-if="
					navigationStore.dialogProperties?.action === 'deactiveren'
				"
				class="status-change-info">
				{{
					t(
						'softwarecatalog',
						'This organisation will be deactivated and will no longer be visible to users.',
					)
				}}
			</span>
		</p>

		<NcNoteCard v-if="success" type="success">
			<p>
				{{
					t('softwarecatalog', 'Status successfully changed to {status}', {
						status: navigationStore.dialogProperties?.newStatus,
					})
				}}
			</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{
					success === null
						? t('softwarecatalog', 'Cancel')
						: t('softwarecatalog', 'Close')
				}}
			</NcButton>
			<NcButton
				v-if="success === null"
				:disabled="loading"
				variant="primary"
				@click="changeStatus()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<CheckCircle
						v-if="
							!loading
							&& navigationStore.dialogProperties?.action
								=== 'activeren'
						"
						:size="20" />
					<CloseCircle
						v-if="
							!loading
							&& navigationStore.dialogProperties?.action
								=== 'deactiveren'
						"
						:size="20" />
				</template>
				{{
					navigationStore.dialogProperties?.action === 'activeren'
						? t('softwarecatalog', 'Activate')
						: t('softwarecatalog', 'Deactivate')
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
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
		 *
		 * @return {string} The organisation name
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		getOrganisatieName() {
			const organisatie = objectStore.getActiveObject('organization')
			return (
				organisatie?.name
				|| organisatie?.name
				|| organisatie?.['@self']?.name
				|| this.t('softwarecatalog', 'Unknown organisation')
			)
		},

		/**
		 * Close the dialog
		 *
		 * @return {void}
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		closeDialog() {
			this.success = null
			this.error = null
			this.loading = false
			navigationStore.setDialog(false)
		},

		/**
		 * Change the organisation status.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		async changeStatus() {
			this.loading = true
			this.error = null

			try {
				const organisatie = objectStore.getActiveObject('organization')
				const newStatus = navigationStore.dialogProperties?.newStatus

				if (!organisatie || !organisatie.id || !newStatus) {
					throw new Error(
						this.t(
							'softwarecatalog',
							'Organisation or new status is missing',
						),
					)
				}

				// Prepare the patch data - only include the status property.
				const patchData = {
					status: newStatus,
				}

				console.info('Changing organisation status:', {
					organisatieId: organisatie.id,
					currentStatus: organisatie.status,
					newStatus,
				})

				// Update only the status using PATCH.
				await objectStore.patchObject(
					'organization',
					organisatie.id,
					patchData,
				)

				this.success = true

				// If activating an organisation, store it for search filtering.
				if (newStatus === 'Actief') {
					const organisatieNaam =
						organisatie?.name
						|| organisatie?.name
						|| organisatie?.['@self']?.name

					// Store the activated organisation info in navigationStore transferData.
					navigationStore.setTransferData({
						action: 'organisationActivated',
						organisationName: organisatieNaam,
						status: 'Actief',
					})
				}
				// For deactivation, don't fetch - the organisation will just disappear from
				// the current view if the user has an active filter, which is the expected behavior.

				// Auto-close after 2 seconds on success.
				setTimeout(() => {
					this.closeDialog()
				}, 2000)
			} catch (error) {
				console.error('Error changing organisation status:', error)
				this.error =
					error.message
					|| this.t(
						'softwarecatalog',
						'An error occurred while changing the status',
					)
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
