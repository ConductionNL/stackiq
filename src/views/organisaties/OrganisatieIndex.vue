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
			ref="organisatieTable"
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
			// Store subscription cleanup function
			storeUnsubscribe: null,
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
	 * Component cleanup - clear timeouts and subscriptions.
	 * @return {void}
	 */
	beforeDestroy() {
		if (this.searchDebounceTimeout) {
			clearTimeout(this.searchDebounceTimeout)
		}
		// Unsubscribe from store if subscription exists.
		if (this.storeUnsubscribe) {
			this.storeUnsubscribe()
		}
	},

	/**
	 * Component mounted - read URL parameters for deep linking and subscribe to store changes.
	 * @return {void}
	 */
	mounted() {
		// Read URL hash parameters for deep linking.
		// This must happen BEFORE GenericObjectTable mounts and fetches data.
		this.initializeFromUrl()

		// Subscribe to navigation store changes to detect organisation activation.
		this.storeUnsubscribe = navigationStore.$subscribe((mutation, state) => {
			// Only process if transferData is not null and has the activation action.
			if (!state.transferData) {
				return
			}

			// Check if transferData was set with an organisation activation.
			if (state.transferData.action === 'organisationActivated') {
				const organisationName = state.transferData.organisationName

				// Clear the transfer data FIRST to prevent retriggering.
				navigationStore.setTransferData(null)

				// Set search query to the organisation name.
				this.searchQuery = organisationName || ''

				// Set status filter in currentFilters (for the fetch logic).
				// This must be done BEFORE calling setFilter so onFilterChange has the correct value.
				this.currentFilters.status = 'Actief'

				// Update the GenericObjectTable filter UI by calling setFilter directly.
				// This will trigger the onChange callback which calls fetchOrganisatiesWithFilters().
				// So we don't need to call it manually here.
				if (this.$refs.organisatieTable) {
					this.$refs.organisatieTable.setFilter('status', { value: 'Actief', label: 'Actief' })
				} else {
					// If ref not available yet, fetch manually.
					this.fetchOrganisatiesWithFilters()
				}

				// Update URL to reflect the new state.
				this.updateUrl()
			}

			// Handle organisation update - refresh with current filters preserved.
			if (state.transferData.action === 'organisationUpdated' || state.transferData.action === 'organisationCreated') {
				// Clear the transfer data.
				navigationStore.setTransferData(null)

				// Refresh with current search and filters to show the updated/new organisation.
				this.fetchOrganisatiesWithFilters()
			}

			// Handle contactpersoon added - refresh with current filters preserved.
			if (state.transferData.action === 'contactpersoonAdded') {
				// Clear the transfer data.
				navigationStore.setTransferData(null)

				// Refresh with current search and filters to show the updated organisation.
				this.fetchOrganisatiesWithFilters()
			}
		})
	},

	methods: {
		/**
		 * Initialize component state from URL hash parameters.
		 * Also updates the GenericObjectTable filter UI to match URL state.
		 * @return {void}
		 */
		initializeFromUrl() {
			try {
				const hash = window.location.hash.substring(1) // Remove the # character.
				if (!hash) return

				const params = new URLSearchParams(hash)

				// Restore search query.
				if (params.has('search')) {
					this.searchQuery = params.get('search')
					console.info('Search query restored from URL:', this.searchQuery)
				}

				// Restore filters.
				if (params.has('status')) {
					const statusValue = params.get('status')
					this.currentFilters.status = statusValue

					// Also update the GenericObjectTable filter UI if ref is available.
					this.$nextTick(() => {
						if (this.$refs.organisatieTable && statusValue !== 'all') {
							this.$refs.organisatieTable.setFilter('status', { value: statusValue, label: statusValue })
						}
					})
				}
				if (params.has('type')) {
					const typeValue = params.get('type')
					this.currentFilters.type = typeValue

					// Also update the GenericObjectTable filter UI if ref is available.
					this.$nextTick(() => {
						if (this.$refs.organisatieTable && typeValue !== 'all') {
							this.$refs.organisatieTable.setFilter('type', { value: typeValue, label: typeValue })
						}
					})
				}

				// Note: page is handled by the store's pagination state.
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

				// IMPORTANT: Initialize URL parameters FIRST before fetching.
				// This ensures search queries and filters from deep links are applied.
				this.initializeFromUrl()

				// Fetch organisaties collection with contactpersonen extended and URL parameters.
				// At this point, this.searchQuery should already be set from initializeFromUrl().
				console.info('Fetching organisaties with page:', page, 'search:', this.searchQuery)
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
		 * Handle filter changes and refetch data.
		 * @param {string} filterKey - The filter key (status or type).
		 * @param {string} filterValue - The new filter value.
		 * @return {Promise<void>}
		 */
		async onFilterChange(filterKey, filterValue) {
		// Update the current filter.
			this.currentFilters[filterKey] = filterValue

			// Reset to first page when filters change.
			await this.fetchOrganisatiesWithFilters()

			// Update URL to reflect filter change.
			this.updateUrl()
		},

		/**
		 * Fetch organisaties with current filters and search.
		 * @param {number} page - The page number to fetch (defaults to 1).
		 * @param {number} limit - The page size (defaults to 20).
		 * @return {Promise<void>}
		 */
		async fetchOrganisatiesWithFilters(page = 1, limit = 20) {
			try {
				const searchParams = {
					_extend: '@self.schema,contactpersonen',
					_page: page,
					_limit: limit,
				}

				// Add search query if present.
				if (this.searchQuery.trim()) {
					searchParams._search = this.searchQuery.trim()
				}

				// Add status filter if not 'all'.
				if (this.currentFilters.status !== 'all') {
					searchParams.status = this.currentFilters.status
				}

				// Add type filter if not 'all'.
				if (this.currentFilters.type !== 'all') {
					searchParams.type = this.currentFilters.type
				}

				// Fetch organisaties with all parameters.
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
	},
}
</script>

<style scoped>
.organisatieIndex {
	padding: 16px;
}
</style>
