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
	<div class="organisatieIndex">
		<!-- Search Bar -->
		<div class="organisatieSearch">
			<NcTextField
				:value.sync="searchQuery"
				:label="t('softwarecatalog', 'Zoeken in organisaties')"
				:placeholder="t('softwarecatalog', 'Zoek op naam, website, type...')"
				trailing-button-icon="close"
				:show-trailing-button="searchQuery.length > 0"
				@trailing-button-click="clearSearch"
				@update:value="onSearchInput">
				<template #icon>
					<Magnify :size="16" />
				</template>
			</NcTextField>
		</div>

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
			:search-query="searchQuery"
			@mounted="onMounted" />
	</div>
</template>

<script>
import GenericObjectTable from '../../components/GenericObjectTable.vue'
import { NcTextField } from '@nextcloud/vue'
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
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
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'

export default {
	name: 'OrganisatieIndex',
	components: {
		GenericObjectTable,
		NcTextField,
		Magnify,
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
					id: 'goToOrganisation',
					label: 'Go to organisation',
					icon: OpenInNew,
					handler: (organisatie) => {
						this.goToOrganisation(organisatie)
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
						{ value: 'Concept', label: 'Concept' },
					],
				},
				{
					key: 'type',
					label: 'Type',
					options: [
						{ value: 'all', label: 'Alle types' },
						{ value: 'Gemeente', label: 'Gemeente' },
						{ value: 'Leverancier', label: 'Leverancier' },
						{ value: 'Samenwerking', label: 'Samenwerking' },
						{ value: 'Community', label: 'Community' },
					],
				},
			],
			searchQuery: '',
			searchDebounceTimeout: null,
			addOrganisatieAction: {
				id: 'add',
				label: 'Add Organisatie',
				icon: Plus,
				handler: () => {
					objectStore.clearActiveObject('organisatie')
					navigationStore.setModal('organisatie')
				},
			},
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
					id: 'goToOrganisation',
					label: 'Go to organisation',
					icon: OpenInNew,
					handler: (organisatie) => {
						this.goToOrganisation(organisatie)
					},
				},
				{
					id: 'activeren',
					label: 'Activeren',
					icon: CheckCircle,
					condition: (organisatie) => organisatie.status?.toLowerCase() === 'concept' || organisatie.status?.toLowerCase() === 'deactief',
					handler: (organisatie) => {
						objectStore.setActiveObject('organisatie', organisatie)
						navigationStore.setDialog('changeOrganisatieStatus', {
							objectType: 'organisatie',
							dialogTitle: 'Organisatie Activeren',
							newStatus: 'Actief',
							action: 'activeren',
						})
					},
				},
				{
					id: 'deactiveren',
					label: 'Deactiveren',
					icon: CloseCircle,
					condition: (organisatie) => organisatie.status?.toLowerCase() === 'actief',
					handler: (organisatie) => {
						objectStore.setActiveObject('organisatie', organisatie)
						navigationStore.setDialog('changeOrganisatieStatus', {
							objectType: 'organisatie',
							dialogTitle: 'Organisatie Deactiveren',
							newStatus: 'Deactief',
							action: 'deactiveren',
						})
					},
				},
				{
					id: 'delete',
					label: 'Delete',
					icon: TrashCanOutline,
					handler: (organisatie) => {
						objectStore.setActiveObject('organisatie', organisatie)
						navigationStore.setModal('deleteObject')
					},
				},
			],
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

		/**
		 * Handle search input with debouncing
		 * @param {string} value - The search input value
		 * @return {void}
		 */
		onSearchInput(value) {
			this.searchQuery = value
			
			// Clear existing timeout
			if (this.searchDebounceTimeout) {
				clearTimeout(this.searchDebounceTimeout)
			}

			// Set new timeout for 1.5 seconds
			this.searchDebounceTimeout = setTimeout(() => {
				this.performSearch()
			}, 1500)
		},

		/**
		 * Perform the actual search with API call
		 * @return {Promise<void>}
		 */
		async performSearch() {
			try {
				console.info('Performing search with query:', this.searchQuery)
				
				const searchParams = {}
				if (this.searchQuery && this.searchQuery.trim()) {
					searchParams._search = this.searchQuery.trim()
				}

				// Fetch organisaties with search parameter
				await objectStore.fetchCollection('organisatie', searchParams)
			} catch (error) {
				console.error('Error performing search:', error)
			}
		},

		/**
		 * Clear the search query and reset results
		 * @return {Promise<void>}
		 */
		async clearSearch() {
			this.searchQuery = ''
			
			// Clear any pending timeout
			if (this.searchDebounceTimeout) {
				clearTimeout(this.searchDebounceTimeout)
			}

			// Fetch all organisaties without search filter
			await objectStore.fetchCollection('organisatie')
		},

		/**
		 * Navigate to external organisation catalog
		 * @param {object} organisatie - The organisation object
		 * @return {void}
		 */
		async goToOrganisation(organisatie) {
			try {
				// Get the catalog location from settings
				const catalogLocation = objectStore.settings?.catalogLocation
				
				if (!catalogLocation) {
					console.warn('No catalog location configured')
					// Fallback: could show a notification to user
					return
				}

				// Get the organisation UUID/ID
				const organisatieId = organisatie.id || organisatie.uuid

				if (!organisatieId) {
					console.warn('Organisation has no valid ID')
					return
				}

				// First, set the active organisation in OpenRegister via Nextcloud endpoint
				const setActiveUrl = `${window.location.origin}/index.php/apps/openregister/api/organizations/${organisatieId}/set-active`
				
				try {
					await fetch(setActiveUrl, {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
						},
					})
					console.info('Active organisation set successfully')
				} catch (error) {
					console.warn('Failed to set active organisation:', error)
					// Continue anyway - the catalog might still work
				}

				// Build the target URL: catalogLocation + '/beheer'
				const targetUrl = catalogLocation.endsWith('/') 
					? `${catalogLocation}beheer`
					: `${catalogLocation}/beheer`

				// Navigate to the external catalog
				window.open(targetUrl, '_blank')

			} catch (error) {
				console.error('Error navigating to organisation:', error)
			}
		},
	},

	/**
	 * Component cleanup - clear timeouts
	 * @return {void}
	 */
	beforeDestroy() {
		if (this.searchDebounceTimeout) {
			clearTimeout(this.searchDebounceTimeout)
		}
	},
}
</script>

<style scoped>
.organisatieIndex {
	padding: 16px;
}

.organisatieSearch {
	margin-bottom: 24px;
	max-width: 400px;
}

.organisatieSearch :deep(.input-field__input) {
	border-radius: var(--border-radius);
}
</style>
