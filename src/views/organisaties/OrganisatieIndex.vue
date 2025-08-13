/**
 * OrganisatieIndex.vue
 * Component for displaying and managing organisaties using GenericObjectTable
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
import OrganisatieCard from '../../components/cards/OrganisatieCard.vue'
</script>

<template>
	<GenericObjectTable
		object-type="organisatie"
		object-type-plural="organisaties"
		:title="t('softwarecatalog', 'Organisaties')"
		:description="t('softwarecatalog', 'Manage your organisaties and their configurations')"
		:empty-icon="OfficeBuildingOutline"
		:card-icon="OfficeBuildingOutline"
		:properties="organisatieProperties"
		:object-actions="organisatieObjectActions"
		:mass-actions="organisatieMassActions"
		:actions="organisatieActions"
		:add-action="addOrganisatieAction"
		:help-url="'https://conduction.gitbook.io/softwarecatalog-nextcloud/beheerders/organisaties'"
		card-display-mode="description"
		:custom-card-component="OrganisatieCard"
		:filters="organisatieFilters"
		@mounted="onMounted" />
</template>

<script>
import GenericObjectTable from '../../components/GenericObjectTable.vue'
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
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
	name: 'OrganisatieIndex',
	components: {
		GenericObjectTable,
		// eslint-disable-next-line vue/no-unused-components
		OrganisatieCard,
	},
	data() {
		return {
			organisatieProperties: [
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
					id: 'type',
					label: 'Type',
					key: 'type',
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
					id: 'oin',
					label: 'OIN',
					key: 'oin',
					sortable: true,
					searchable: true,
				},
				{
					id: 'tooi',
					label: 'TOOI',
					key: 'tooi',
					sortable: true,
					searchable: true,
				},
				{
					id: 'rsin',
					label: 'RSIN',
					key: 'rsin',
					sortable: true,
					searchable: true,
				},
			],
			organisatieObjectActions: [
				{
					id: 'view',
					label: 'View',
					icon: Eye,
					handler: (organisatie) => {
						objectStore.setActiveObject('organisatie', organisatie)
						navigationStore.setModal('viewOrganisatie')
					},
				},
				{
					id: 'edit',
					label: 'Edit',
					icon: Pencil,
					handler: (organisatie) => {
						objectStore.setActiveObject('organisatie', organisatie)
						navigationStore.setModal('organisatie')
					},
				},
				{
					id: 'copy',
					label: 'Copy',
					icon: ContentCopy,
					handler: (organisatie) => {
						objectStore.setActiveObject('organisatie', organisatie)
						navigationStore.setDialog('copyObject', {
							objectType: 'organisatie',
							dialogTitle: 'Organisatie',
						})
					},
				},
				{
					id: 'delete',
					label: 'Delete',
					icon: TrashCanOutline,
					handler: (organisatie) => {
						objectStore.setActiveObject('organisatie', organisatie)
						navigationStore.setDialog('deleteObject', {
							objectType: 'organisatie',
							dialogTitle: 'Organisatie',
						})
					},
				},
			],
			organisatieMassActions: [
				{
					id: 'massDelete',
					label: 'Delete Selected',
					icon: Delete,
					handler: () => {
						navigationStore.setDialog('massDeleteObjects', {
							objectType: 'organisatie',
							dialogTitle: 'Organisaties',
						})
					},
				},
				{
					id: 'massPublish',
					label: 'Publish Selected',
					icon: PublishIcon,
					handler: () => {
						navigationStore.setDialog('massPublishObjects', {
							objectType: 'organisatie',
							dialogTitle: 'Organisaties',
						})
					},
				},
				{
					id: 'massDepublish',
					label: 'Depublish Selected',
					icon: PublishOffIcon,
					handler: () => {
						navigationStore.setDialog('massDepublishObjects', {
							objectType: 'organisatie',
							dialogTitle: 'Organisaties',
						})
					},
				},
			],
			organisatieActions: [
				{
					id: 'add',
					label: 'Add Organisatie',
					icon: Plus,
					primary: true,
					handler: () => {
						objectStore.clearActiveObject('organisatie')
						navigationStore.setModal('organisatie')
					},
				},
				{
					id: 'refresh',
					label: 'Refresh',
					icon: Refresh,
					handler: () => {
						objectStore.fetchCollection('organisatie')
					},
					disabled: () => objectStore.isLoading('organisatie'),
				},
				{
					id: 'help',
					label: 'Help',
					icon: HelpCircleOutline,
					handler: () => {
						window.open('https://conduction.gitbook.io/softwarecatalog-nextcloud/beheerders/organisaties', '_blank')
					},
				},
			],
			organisatieFilters: [
				{
					key: 'status',
					label: 'Status',
					options: [
						{ value: 'all', label: 'Alle statussen' },
						{ value: 'Actief', label: 'Actief' },
						{ value: 'concept', label: 'Concept' },
					],
				},
			],
			addOrganisatieAction: {
				id: 'add',
				label: 'Add Organisatie',
				icon: Plus,
				handler: () => {
					objectStore.clearActiveObject('organisatie')
					navigationStore.setModal('organisatie')
				},
			},
		}
	},
	methods: {
		/**
		 * Handle component mount - initialize settings and fetch organisaties
		 * @return {Promise<void>}
		 */
		async onMounted() {
			console.info('OrganisatieIndex mounted, initializing...')
			try {
				// Ensure settings are loaded first (this will also register object types)
				if (!objectStore.settings) {
					console.info('Loading settings before fetching organisaties...')
					await objectStore.fetchSettings()
				}

				// Fetch organisaties collection
				console.info('Fetching organisaties...')
				await objectStore.fetchCollection('organisatie')
			} catch (error) {
				console.error('Error initializing OrganisatieIndex:', error)
				// Show error to user if needed
			}
		},
	},
}
</script>
