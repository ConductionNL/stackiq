/**
 * VoorzieningIndex.vue
 * Component for displaying and managing voorzieningen using GenericObjectTable
 * @category Views
 * @package softwarecatalog
 * @author Ruben Linde
 * @copyright 2024
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @link https://github.com/opencatalogi/softwarecatalog
 */

<script setup>
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<GenericObjectTable
		object-type="voorziening"
		object-type-plural="voorzieningen"
		:title="t('softwarecatalog', 'Voorzieningen')"
		:description="t('softwarecatalog', 'Manage your voorzieningen and their specifications')"
		:empty-icon="ApplicationCog"
		:card-icon="ApplicationCog"
		:properties="voorzieningProperties"
		:object-actions="voorzieningObjectActions"
		:mass-actions="voorzieningMassActions"
		:actions="voorzieningActions"
		:add-action="addVoorzieningAction"
		:help-url="'https://conduction.gitbook.io/softwarecatalog-nextcloud/beheerders/voorzieningen'"
		card-display-mode="mixed"
		@mounted="onMounted" />
</template>

<script>
import GenericObjectTable from '../../components/GenericObjectTable.vue'
import ApplicationCog from 'vue-material-design-icons/ApplicationCog.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import PublishIcon from 'vue-material-design-icons/Publish.vue'
import PublishOffIcon from 'vue-material-design-icons/PublishOff.vue'

export default {
	name: 'VoorzieningIndex',
	components: {
		GenericObjectTable,
	},
	data() {
		return {
			voorzieningProperties: [
				{
					id: 'naam',
					label: 'Naam',
					key: 'naam',
					sortable: true,
					searchable: true,
				},
				{
					id: 'website',
					label: 'Website',
					key: 'website',
					sortable: true,
					searchable: true,
				},
				{
					id: 'beschrijvingKort',
					label: 'Korte beschrijving',
					key: 'beschrijvingKort',
					sortable: false,
					searchable: true,
				},
				{
					id: 'type',
					label: 'Type',
					key: 'type',
					sortable: true,
					searchable: true,
				},
				{
					id: 'status',
					label: 'Status',
					key: 'status',
					sortable: true,
					searchable: true,
				},
				{
					id: 'organisatieIsEigenaarVan',
					label: 'Eigenaar organisatie',
					key: 'organisatieIsEigenaarVan',
					sortable: false,
					searchable: true,
				},
			],
			voorzieningObjectActions: [
				{
					id: 'view',
					label: 'View',
					icon: Eye,
					handler: (voorziening) => {
						objectStore.setActiveObject('voorziening', voorziening)
						navigationStore.setModal('viewVoorziening')
					},
				},
				{
					id: 'edit',
					label: 'Edit',
					icon: Pencil,
					handler: (voorziening) => {
						objectStore.setActiveObject('voorziening', voorziening)
						navigationStore.setModal('voorziening')
					},
				},
				{
					id: 'copy',
					label: 'Copy',
					icon: ContentCopy,
					handler: (voorziening) => {
						objectStore.setActiveObject('voorziening', voorziening)
						navigationStore.setDialog('copyObject', {
							objectType: 'voorziening',
							dialogTitle: 'Voorziening',
						})
					},
				},
				{
					id: 'delete',
					label: 'Delete',
					icon: TrashCanOutline,
					handler: (voorziening) => {
						objectStore.setActiveObject('voorziening', voorziening)
						navigationStore.setDialog('deleteObject', {
							objectType: 'voorziening',
							dialogTitle: 'Voorziening',
						})
					},
				},
			],
			voorzieningMassActions: [
				{
					id: 'massDelete',
					label: 'Delete Selected',
					icon: Delete,
					handler: () => {
						navigationStore.setDialog('massDeleteObjects', {
							objectType: 'voorziening',
							dialogTitle: 'Voorzieningen',
						})
					},
				},
				{
					id: 'massPublish',
					label: 'Publish Selected',
					icon: PublishIcon,
					handler: () => {
						navigationStore.setDialog('massPublishObjects', {
							objectType: 'voorziening',
							dialogTitle: 'Voorzieningen',
						})
					},
				},
				{
					id: 'massDepublish',
					label: 'Depublish Selected',
					icon: PublishOffIcon,
					handler: () => {
						navigationStore.setDialog('massDepublishObjects', {
							objectType: 'voorziening',
							dialogTitle: 'Voorzieningen',
						})
					},
				},
			],
			voorzieningActions: [
				{
					id: 'add',
					label: 'Add Voorziening',
					icon: Plus,
					primary: true,
					handler: () => {
						objectStore.clearActiveObject('voorziening')
						navigationStore.setModal('voorziening')
					},
				},
				{
					id: 'refresh',
					label: 'Refresh',
					icon: Refresh,
					handler: () => {
						objectStore.fetchCollection('voorziening')
					},
					disabled: () => objectStore.isLoading('voorziening'),
				},
				{
					id: 'help',
					label: 'Help',
					icon: HelpCircleOutline,
					handler: () => {
						window.open('https://conduction.gitbook.io/softwarecatalog-nextcloud/beheerders/voorzieningen', '_blank')
					},
				},
			],
			addVoorzieningAction: {
				id: 'add',
				label: 'Add Voorziening',
				icon: Plus,
				handler: () => {
					objectStore.clearActiveObject('voorziening')
					navigationStore.setModal('voorziening')
				},
			},
		}
	},
	methods: {
		/**
		 * Handle component mount - initialize settings and fetch voorzieningen
		 * @return {Promise<void>}
		 */
		async onMounted() {
			console.info('VoorzieningIndex mounted, initializing...')
			try {
				// Ensure settings are loaded first (this will also register object types)
				if (!objectStore.settings) {
					console.info('Loading settings before fetching voorzieningen...')
					await objectStore.fetchSettings()
				}

				// Fetch voorzieningen collection
				console.info('Fetching voorzieningen...')
				await objectStore.fetchCollection('voorziening')
			} catch (error) {
				console.error('Error initializing VoorzieningIndex:', error)
				// Show error to user if needed
			}
		},
	},
}
</script>
