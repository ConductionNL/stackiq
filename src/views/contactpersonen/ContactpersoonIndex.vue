/**
 * ContactpersoonIndex.vue
 * Component for displaying and managing contactpersonen using GenericObjectTable
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
		object-type="contactpersoon"
		object-type-plural="contactpersonen"
		:title="t('softwarecatalog', 'Contactpersonen')"
		:description="t('softwarecatalog', 'Manage your contactpersonen and their information')"
		:empty-icon="AccountMultiple"
		:card-icon="AccountMultiple"
		:properties="contactpersoonProperties"
		:object-actions="contactpersoonObjectActions"
		:mass-actions="contactpersoonMassActions"
		:actions="contactpersoonActions"
		:add-action="addContactpersoonAction"
		:help-url="'https://conduction.gitbook.io/softwarecatalog-nextcloud/beheerders/contactpersonen'"
		card-display-mode="properties"
		@mounted="onMounted" />
</template>

<script>
import GenericObjectTable from '../../components/GenericObjectTable.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
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
	name: 'ContactpersoonIndex',
	components: {
		GenericObjectTable,
	},
	data() {
		return {
			contactpersoonProperties: [
				{
					id: 'voornaam',
					label: 'Voornaam',
					key: 'voornaam',
					sortable: true,
					searchable: true,
				},
				{
					id: 'achternaam',
					label: 'Achternaam',
					key: 'achternaam',
					sortable: true,
					searchable: true,
				},
				{
					id: 'e-mailadres',
					label: 'E-mailadres',
					key: 'e-mailadres',
					sortable: true,
					searchable: true,
				},
				{
					id: 'telefoonnummer',
					label: 'Telefoonnummer',
					key: 'telefoonnummer',
					sortable: true,
					searchable: true,
				},
				{
					id: 'functie',
					label: 'Functie',
					key: 'functie',
					sortable: true,
					searchable: true,
				},
				{
					id: 'organisatie',
					label: 'Organisatie',
					key: 'organisatie.value',
					sortable: false,
					searchable: true,
				},
			],
			contactpersoonObjectActions: [
				{
					id: 'view',
					label: 'View',
					icon: Eye,
					handler: (contactpersoon) => {
						objectStore.setActiveObject('contactpersoon', contactpersoon)
						navigationStore.setModal('viewContactpersoon')
					},
				},
				{
					id: 'edit',
					label: 'Edit',
					icon: Pencil,
					handler: (contactpersoon) => {
						objectStore.setActiveObject('contactpersoon', contactpersoon)
						navigationStore.setModal('contactpersoon')
					},
				},
				{
					id: 'copy',
					label: 'Copy',
					icon: ContentCopy,
					handler: (contactpersoon) => {
						objectStore.setActiveObject('contactpersoon', contactpersoon)
						navigationStore.setDialog('copyObject', {
							objectType: 'contactpersoon',
							dialogTitle: 'Contactpersoon',
						})
					},
				},
				{
					id: 'delete',
					label: 'Delete',
					icon: TrashCanOutline,
					handler: (contactpersoon) => {
						objectStore.setActiveObject('contactpersoon', contactpersoon)
						navigationStore.setDialog('deleteObject', {
							objectType: 'contactpersoon',
							dialogTitle: 'Contactpersoon',
						})
					},
				},
			],
			contactpersoonMassActions: [
				{
					id: 'massDelete',
					label: 'Delete Selected',
					icon: Delete,
					handler: () => {
						navigationStore.setDialog('massDeleteObjects', {
							objectType: 'contactpersoon',
							dialogTitle: 'Contactpersonen',
						})
					},
				},
				{
					id: 'massPublish',
					label: 'Publish Selected',
					icon: PublishIcon,
					handler: () => {
						navigationStore.setDialog('massPublishObjects', {
							objectType: 'contactpersoon',
							dialogTitle: 'Contactpersonen',
						})
					},
				},
				{
					id: 'massDepublish',
					label: 'Depublish Selected',
					icon: PublishOffIcon,
					handler: () => {
						navigationStore.setDialog('massDepublishObjects', {
							objectType: 'contactpersoon',
							dialogTitle: 'Contactpersonen',
						})
					},
				},
			],
			contactpersoonActions: [
				{
					id: 'add',
					label: 'Add Contactpersoon',
					icon: Plus,
					primary: true,
					handler: () => {
						objectStore.clearActiveObject('contactpersoon')
						navigationStore.setModal('contactpersoon')
					},
				},
				{
					id: 'refresh',
					label: 'Refresh',
					icon: Refresh,
					handler: () => {
						objectStore.fetchCollection('contactpersoon')
					},
					disabled: () => objectStore.isLoading('contactpersoon'),
				},
				{
					id: 'help',
					label: 'Help',
					icon: HelpCircleOutline,
					handler: () => {
						window.open('https://conduction.gitbook.io/softwarecatalog-nextcloud/beheerders/contactpersonen', '_blank')
					},
				},
			],
			addContactpersoonAction: {
				id: 'add',
				label: 'Add Contactpersoon',
				icon: Plus,
				handler: () => {
					objectStore.clearActiveObject('contactpersoon')
					navigationStore.setModal('contactpersoon')
				},
			},
		}
	},
	methods: {
		/**
		 * Handle component mount - initialize settings and fetch contactpersonen
		 * @return {Promise<void>}
		 */
		async onMounted() {
			console.info('ContactpersoonIndex mounted, initializing...')
			try {
				// Ensure settings are loaded first (this will also register object types)
				if (!objectStore.settings) {
					console.info('Loading settings before fetching contactpersonen...')
					await objectStore.fetchSettings()
				}

				// Fetch contactpersonen collection
				console.info('Fetching contactpersonen...')
				await objectStore.fetchCollection('contactpersoon')
			} catch (error) {
				console.error('Error initializing ContactpersoonIndex:', error)
				// Show error to user if needed
			}
		},
	},
}
</script>
