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
import AddContactpersoonModal from '../../components/AddContactpersoonModal.vue'
import OrganisationModal from '../../modals/OrganisationModal.vue'
</script>

<template>
	<div class="organisatieIndex">
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
			:on-search-input="onSearchInput"
			:clear-search="clearSearch"
			:pagination-function="handlePagination"
			@mounted="onMounted" />

		<!-- Add Contactpersoon Modal -->
		<AddContactpersoonModal
			:show="showAddContactpersoonModal"
			:organisation="selectedOrganisationForContact"
			@close="closeAddContactpersoonModal"
			@contactpersoon-added="onContactpersoonAdded" />

		<!-- Organisation Management Modal -->
		<OrganisationModal
			:show="showOrganisationModal"
			:organisation="selectedOrganisation"
			:mode="organisationModalMode"
			@close="closeOrganisationModal" />
	</div>
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
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'

export default {
	name: 'OrganisatieIndex',
	components: {
		GenericObjectTable,
		// eslint-disable-next-line vue/no-unused-components
		OrganisatieCard,
		// eslint-disable-next-line vue/no-unused-components
		AddContactpersoonModal,
		// eslint-disable-next-line vue/no-unused-components
		OrganisationModal,
	},
	data() {
		return {
			// Current filter values
			currentFilters: {
				status: 'all',
				type: 'all',
			},
			// Add Contactpersoon Modal
			showAddContactpersoonModal: false,
			selectedOrganisationForContact: null,
			// Organisation Modal
			showOrganisationModal: false,
			selectedOrganisation: null,
			organisationModalMode: 'create', // 'create', 'edit', 'copy'
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
						const publicationUrl = `https://www.softwarecatalogus.nl/publicatie/${organisatie.id}`
						window.open(publicationUrl, '_blank')
					},
				},
				{
					id: 'edit',
					label: 'Edit',
					icon: Pencil,
					handler: (organisatie) => {
						this.editOrganisation(organisatie)
					},
				},
				{
					id: 'copy',
					label: 'Copy',
					icon: ContentCopy,
					handler: (organisatie) => {
						this.copyOrganisation(organisatie)
					},
				},
				{
					id: 'goToOrganisation',
					label: 'Go to organisation',
					icon: OpenInNew,
					condition: (organisatie) => organisatie.website && organisatie.website.trim().length > 0,
					handler: (organisatie) => {
						this.goToOrganisation(organisatie)
					},
				},
				{
					id: 'addContactpersoon',
					label: 'Contactpersoon toevoegen',
					icon: AccountMultiple,
					handler: (organisatie) => {
						this.addContactpersoon(organisatie)
					},
				},
				{
					id: 'activeren',
					label: 'Activeren',
					icon: CheckCircle,
					condition: (organisatie) => organisatie.status === 'Concept' || organisatie.status === 'Deactief',
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
					id: 'publish',
					label: 'Publiceren',
					icon: PublishIcon,
					condition: (organisatie) => !organisatie['@self']?.published,
					handler: (organisatie) => {
						this.publishOrganisatie(organisatie)
					},
				},
				{
					id: 'depublish',
					label: 'Depubliceren',
					icon: PublishOffIcon,
					condition: (organisatie) => organisatie['@self']?.published,
					handler: (organisatie) => {
						this.depublishOrganisatie(organisatie)
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
						this.createOrganisation()
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
					onChange: (value) => this.onFilterChange('status', value),
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
					onChange: (value) => this.onFilterChange('type', value),
				},
			],
			searchQuery: '',
			searchDebounceTimeout: null,
			addOrganisatieAction: {
				id: 'add',
				label: 'Add Organisatie',
				icon: Plus,
				handler: () => {
					this.createOrganisation()
				},
			},
		}
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

	/**
	 * Component mounted - read URL parameters for deep linking
	 * @return {void}
	 */
	mounted() {
		// Read URL hash parameters for deep linking
		this.initializeFromUrl()
	},

	methods: {
		/**
		 * Initialize component state from URL hash parameters
		 * @return {void}
		 */
		initializeFromUrl() {
			try {
				const hash = window.location.hash.substring(1) // Remove the # character
				if (!hash) return

				const params = new URLSearchParams(hash)

				// Restore search query
				if (params.has('search')) {
					this.searchQuery = params.get('search')
				}

				// Restore filters
				if (params.has('status')) {
					this.currentFilters.status = params.get('status')
				}
				if (params.has('type')) {
					this.currentFilters.type = params.get('type')
				}

				// Note: page is handled by the store's pagination state
				console.info('Initialized from URL:', {
					search: this.searchQuery,
					filters: this.currentFilters,
				})
			} catch (error) {
				console.error('Error parsing URL parameters:', error)
			}
		},

		/**
		 * Update URL hash with current state
		 * @return {void}
		 */
		updateUrl() {
			const params = new URLSearchParams()

			// Add search query
			if (this.searchQuery && this.searchQuery.trim()) {
				params.set('search', this.searchQuery.trim())
			}

			// Add filters if not 'all'
			if (this.currentFilters.status !== 'all') {
				params.set('status', this.currentFilters.status)
			}
			if (this.currentFilters.type !== 'all') {
				params.set('type', this.currentFilters.type)
			}

			// Add current page from pagination
			const pagination = objectStore.getPagination('organisatie')
			if (pagination && pagination.page > 1) {
				params.set('page', pagination.page.toString())
			}

			// Update URL hash
			const hash = params.toString()
			if (hash) {
				window.location.hash = hash
			} else {
				// Clear hash if no parameters
				history.replaceState(null, '', window.location.pathname + window.location.search)
			}

			console.info('URL updated:', window.location.hash)
		},

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

				// Check if we should load from URL parameters
				const hash = window.location.hash.substring(1)
				const params = new URLSearchParams(hash)
				const page = params.has('page') ? parseInt(params.get('page'), 10) : 1
				const limit = 20

				// Fetch organisaties collection with contactpersonen extended and URL parameters
				console.info('Fetching organisaties with page:', page)
				await this.fetchOrganisatiesWithFilters(page, limit)
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

				// Use the unified filter method that includes current filters
				await this.fetchOrganisatiesWithFilters()

				// Update URL to reflect search state
				this.updateUrl()
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

			// Fetch all organisaties without search filter but with contactpersonen extended
			await this.fetchOrganisatiesWithFilters()

			// Update URL to reflect cleared search
			this.updateUrl()
		},

		/**
		 * Handle filter changes and refetch data
		 * @param {string} filterKey - The filter key (status or type)
		 * @param {string} filterValue - The new filter value
		 * @return {Promise<void>}
		 */
		async onFilterChange(filterKey, filterValue) {
			console.info('Filter changed:', { filterKey, filterValue })

			// Update the current filter
			this.currentFilters[filterKey] = filterValue

			// Reset to first page when filters change
			await this.fetchOrganisatiesWithFilters()

			// Update URL to reflect filter change
			this.updateUrl()
		},

		/**
		 * Fetch organisaties with current filters and search
		 * @param {number} page - The page number to fetch (defaults to 1)
		 * @param {number} limit - The page size (defaults to 20)
		 * @return {Promise<void>}
		 */
		async fetchOrganisatiesWithFilters(page = 1, limit = 20) {
			try {
				console.info('Fetching organisaties with filters:', this.currentFilters, { page, limit })

				const searchParams = {
					_extend: '@self.schema,contactpersonen',
					_page: page,
					_limit: limit,
				}

				// Add search query if present
				if (this.searchQuery.trim()) {
					searchParams._search = this.searchQuery.trim()
				}

				// Add status filter if not 'all'
				if (this.currentFilters.status !== 'all') {
					searchParams.status = this.currentFilters.status
				}

				// Add type filter if not 'all'
				if (this.currentFilters.type !== 'all') {
					searchParams.type = this.currentFilters.type
				}

				console.info('Final search params:', searchParams)

				// Fetch organisaties with all parameters
				await objectStore.fetchCollection('organisatie', searchParams)
			} catch (error) {
				console.error('Error fetching organisaties with filters:', error)
			}
		},

		/**
		 * Handle pagination changes - preserves search and filters
		 * @param {number} page - The page number to fetch
		 * @param {number} limit - The page size
		 * @return {Promise<void>}
		 */
		async handlePagination(page, limit) {
			console.info('Pagination changed:', { page, limit })
			await this.fetchOrganisatiesWithFilters(page, limit)

			// Update URL to reflect page change
			this.updateUrl()
		},

		/**
		 * Add contactpersoon to organisation
		 * @param {object} organisatie - The organisation object
		 * @return {void}
		 */
		addContactpersoon(organisatie) {
			this.selectedOrganisationForContact = organisatie
			this.showAddContactpersoonModal = true
		},

		/**
		 * Close add contactpersoon modal
		 * @return {void}
		 */
		closeAddContactpersoonModal() {
			this.showAddContactpersoonModal = false
			this.selectedOrganisationForContact = null
		},

		/**
		 * Handle contactpersoon added event
		 * @param {object} contactpersoon - The added contactpersoon
		 * @return {void}
		 */
		onContactpersoonAdded(contactpersoon) {
			console.info('Contactpersoon added:', contactpersoon)
			// The modal already refreshes the data, so we don't need to do anything here
		},

		/**
		 * Navigate to organisation website
		 * @param {object} organisatie - The organisation object
		 * @return {void}
		 */
		goToOrganisation(organisatie) {
			if (!organisatie.website) {
				console.warn('Organisation has no website')
				return
			}

			let websiteUrl = organisatie.website.trim()

			// Add protocol if missing
			if (!websiteUrl.startsWith('http://') && !websiteUrl.startsWith('https://')) {
				websiteUrl = 'https://' + websiteUrl
			}

			// Open website in new tab
			window.open(websiteUrl, '_blank')
		},

		// Organisation Modal Methods
		createOrganisation() {
			this.selectedOrganisation = null
			this.organisationModalMode = 'create'
			this.showOrganisationModal = true
		},
		editOrganisation(organisation) {
			this.selectedOrganisation = organisation
			this.organisationModalMode = 'edit'
			this.showOrganisationModal = true
		},
		copyOrganisation(organisation) {
			this.selectedOrganisation = organisation
			this.organisationModalMode = 'copy'
			this.showOrganisationModal = true
		},
		closeOrganisationModal() {
			this.showOrganisationModal = false
			this.selectedOrganisation = null
			this.organisationModalMode = 'create'
		},

		/**
		 * Publish an organisation
		 * @param {object} organisatie - The organisation to publish
		 * @return {Promise<void>}
		 */
		async publishOrganisatie(organisatie) {
			try {
				console.info('Publishing organisatie:', organisatie)
				console.info('Organisatie @self:', organisatie['@self'])
				console.info('Organisatie id:', organisatie.id)
				console.info('Organisatie register:', organisatie['@self']?.register)
				console.info('Organisatie schema:', organisatie['@self']?.schema)

				await objectStore.publishObject(organisatie)
				// Refresh the organisation list to show updated status
				await objectStore.fetchCollection('organisatie', {
					_extend: '@self.schema,@self.register,contactpersonen',
					_limit: 20,
					_page: 1,
				})
			} catch (error) {
				console.error('Failed to publish organisation:', error)
			}
		},

		/**
		 * Depublish an organisation
		 * @param {object} organisatie - The organisation to depublish
		 * @return {Promise<void>}
		 */
		async depublishOrganisatie(organisatie) {
			try {
				await objectStore.depublishObject(organisatie)
				// Refresh the organisation list to show updated status
				await objectStore.fetchCollection('organisatie', {
					_extend: '@self.schema,@self.register,contactpersonen',
					_limit: 20,
					_page: 1,
				})
			} catch (error) {
				console.error('Failed to depublish organisation:', error)
			}
		},
	},
}
</script>

<style scoped>
.organisatieIndex {
	padding: 16px;
}
</style>
