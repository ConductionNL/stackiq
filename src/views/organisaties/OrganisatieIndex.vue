/**
 * OrganisatieIndex.vue
 * Component for displaying and managing organisaties using CnIndexPage + CnIndexSidebar
 * @category Views
 * @package softwarecatalog
 * @author Ruben Linde
 * @copyright 2024
 * @license AGPL-3.0-or-later
 * @version 2.0.0
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
		<CnIndexPage
			ref="indexPage"
			:title="t('softwarecatalog', 'Organisaties')"
			:description="t('softwarecatalog', 'Manage your organisaties and their configurations')"
			:schema="organisatieSchema"
			:objects="organisatieObjects"
			:pagination="organisatiePagination"
			:loading="organisatieLoading"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:selectable="true"
			:selected-ids="selectedIds"
			:include-columns="visibleColumns"
			:view-mode="viewMode"
			:show-view-toggle="true"
			:row-actions="rowActionsDef"
			@sort="onSort"
			@page-changed="onPageChange"
			@page-size-changed="onPageSizeChange"
			@row-click="onRowClick"
			@select="onSelect"
			@refresh="onRefresh">
			<!-- Custom card template for card view -->
			<template #card="{ object }">
				<OrganisatieCard
					:item="object"
					:object-actions="organisatieObjectActions"
					:card-icon="OfficeBuildingOutline" />
			</template>
		</CnIndexPage>

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
import { CnIndexPage } from '@conduction/nextcloud-vue'
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
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
		CnIndexPage,
		// eslint-disable-next-line vue/no-unused-components
		OrganisatieCard,
		// eslint-disable-next-line vue/no-unused-components
		AddContactpersoonModal,
		// eslint-disable-next-line vue/no-unused-components
		OrganisationModal,
	},

	inject: {
		sidebarState: { default: null },
	},

	data() {
		return {
			// Schema for sidebar auto-generation
			organisatieSchema: null,
			// Sort state
			sortKey: null,
			sortOrder: 'asc',
			// View state
			viewMode: 'cards',
			visibleColumns: null,
			selectedIds: [],
			// Search and filter state
			searchQuery: '',
			searchDebounceTimeout: null,
			currentFilters: {},
			// Store subscription cleanup
			storeUnsubscribe: null,
			// Add Contactpersoon Modal
			showAddContactpersoonModal: false,
			selectedOrganisationForContact: null,
			// Organisation Modal
			showOrganisationModal: false,
			selectedOrganisation: null,
			organisationModalMode: 'create',
			// Row actions definition for CnIndexPage
			rowActionsDef: [
				{ id: 'view', label: 'View', icon: 'eye' },
				{ id: 'edit', label: 'Edit', icon: 'pencil' },
				{ id: 'copy', label: 'Copy', icon: 'content-copy' },
				{ id: 'delete', label: 'Delete', icon: 'delete', destructive: true },
			],
			// Object actions for OrganisatieCard
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
		}
	},

	computed: {
		organisatieObjects() {
			const collection = objectStore.getCollection('organisatie')
			if (Array.isArray(collection)) return collection
			return collection?.results || []
		},
		organisatiePagination() {
			return objectStore.getPagination('organisatie') || { total: 0, page: 1, pages: 1, limit: 20 }
		},
		organisatieLoading() {
			return objectStore.isLoading('organisatie')
		},
	},

	beforeDestroy() {
		if (this.searchDebounceTimeout) {
			clearTimeout(this.searchDebounceTimeout)
		}
		if (this.storeUnsubscribe) {
			this.storeUnsubscribe()
		}
		// Deactivate sidebar
		if (this.sidebarState) {
			this.sidebarState.active = false
			this.sidebarState.schema = null
			this.sidebarState.onSearch = null
			this.sidebarState.onFilterChange = null
			this.sidebarState.onColumnsChange = null
		}
	},

	async mounted() {
		// Ensure settings are loaded
		if (!objectStore.settings) {
			await objectStore.fetchSettings()
		}

		// Fetch the organisatie schema for sidebar filter generation
		await this.fetchOrganisatieSchema()

		// Setup sidebar
		this.setupSidebar()

		// Initialize from URL deep links
		this.initializeFromUrl()

		// Subscribe to store changes for cross-component events
		this.storeUnsubscribe = navigationStore.$subscribe((mutation, state) => {
			if (!state.transferData) return

			if (state.transferData.action === 'organisationActivated') {
				const organisationName = state.transferData.organisationName
				navigationStore.setTransferData(null)
				this.searchQuery = organisationName || ''
				this.currentFilters.status = ['Actief']
				if (this.sidebarState) {
					this.sidebarState.searchValue = this.searchQuery
					this.sidebarState.activeFilters = { ...this.currentFilters }
				}
				this.fetchOrganisatiesWithFilters()
				this.updateUrl()
			}

			if (state.transferData.action === 'organisationUpdated' || state.transferData.action === 'organisationCreated') {
				navigationStore.setTransferData(null)
				this.fetchOrganisatiesWithFilters()
			}

			if (state.transferData.action === 'contactpersoonAdded') {
				navigationStore.setTransferData(null)
				this.fetchOrganisatiesWithFilters()
			}
		})

		// Initial fetch
		const hash = window.location.hash.substring(1)
		const params = new URLSearchParams(hash)
		const page = params.has('page') ? parseInt(params.get('page'), 10) : 1
		await this.fetchOrganisatiesWithFilters(page)
	},

	methods: {
		/**
		 * Fetch the organisatie JSON schema from the API for sidebar filter generation
		 */
		async fetchOrganisatieSchema() {
			try {
				const config = objectStore.getSchemaConfig('organisatie')
				if (!config?.schema) {
					console.warn('No schema config found for organisatie')
					return
				}
				const schemaId = typeof config.schema === 'object' ? config.schema?.id || config.schema?.uuid : config.schema
				const response = await fetch(`/index.php/apps/openregister/api/schemas/${schemaId}`, {
					headers: { 'OCS-APIRequest': 'true' },
				})
				if (response.ok) {
					this.organisatieSchema = await response.json()
				}
			} catch (error) {
				console.warn('Failed to fetch organisatie schema:', error)
			}
		},

		/**
		 * Setup sidebar state and wire event handlers
		 */
		setupSidebar() {
			if (!this.sidebarState) return

			this.sidebarState.active = true
			this.sidebarState.schema = this.organisatieSchema
			this.sidebarState.searchValue = this.searchQuery
			this.sidebarState.activeFilters = { ...this.currentFilters }

			this.sidebarState.onSearch = (value) => {
				this.searchQuery = value
				if (this.searchDebounceTimeout) {
					clearTimeout(this.searchDebounceTimeout)
				}
				this.searchDebounceTimeout = setTimeout(() => {
					this.fetchOrganisatiesWithFilters()
					this.updateUrl()
				}, 500)
			}

			this.sidebarState.onFilterChange = ({ key, values }) => {
				if (!values || values.length === 0) {
					const updated = { ...this.currentFilters }
					delete updated[key]
					this.currentFilters = updated
				} else {
					this.currentFilters = { ...this.currentFilters, [key]: values }
				}
				if (this.sidebarState) {
					this.sidebarState.activeFilters = { ...this.currentFilters }
				}
				this.fetchOrganisatiesWithFilters()
				this.updateUrl()
			}

			this.sidebarState.onColumnsChange = (cols) => {
				this.visibleColumns = cols
			}
		},

		/**
		 * Initialize from URL hash parameters for deep linking
		 */
		initializeFromUrl() {
			try {
				const hash = window.location.hash.substring(1)
				if (!hash) return

				const params = new URLSearchParams(hash)

				if (params.has('search')) {
					this.searchQuery = params.get('search')
					if (this.sidebarState) {
						this.sidebarState.searchValue = this.searchQuery
					}
				}

				if (params.has('status')) {
					this.currentFilters.status = [params.get('status')]
				}
				if (params.has('type')) {
					this.currentFilters.type = [params.get('type')]
				}

				if (this.sidebarState) {
					this.sidebarState.activeFilters = { ...this.currentFilters }
				}
			} catch (error) {
				console.error('Error parsing URL parameters:', error)
			}
		},

		/**
		 * Update URL hash with current search/filter state
		 */
		updateUrl() {
			const params = new URLSearchParams()

			if (this.searchQuery && this.searchQuery.trim()) {
				params.set('search', this.searchQuery.trim())
			}

			for (const [key, values] of Object.entries(this.currentFilters)) {
				if (values && values.length > 0) {
					params.set(key, values[0])
				}
			}

			const pagination = objectStore.getPagination('organisatie')
			if (pagination && pagination.page > 1) {
				params.set('page', pagination.page.toString())
			}

			const hash = params.toString()
			if (hash) {
				window.location.hash = hash
			} else {
				history.replaceState(null, '', window.location.pathname + window.location.search)
			}
		},

		/**
		 * Fetch organisaties with current search, filters, and pagination
		 */
		async fetchOrganisatiesWithFilters(page = 1, limit = 20) {
			try {
				const searchParams = {
					_extend: 'contactpersonen',
					_page: page,
					_limit: limit,
				}

				if (this.searchQuery.trim()) {
					searchParams._search = this.searchQuery.trim()
				}

				if (this.sortKey) {
					searchParams._order = { [this.sortKey]: this.sortOrder }
				}

				// Apply sidebar filters
				for (const [key, values] of Object.entries(this.currentFilters)) {
					if (values && values.length > 0) {
						searchParams[key] = values.length === 1 ? values[0] : values
					}
				}

				await objectStore.fetchCollection('organisatie', searchParams)
			} catch (error) {
				console.error('Error fetching organisaties:', error)
			}
		},

		// CnIndexPage event handlers
		onSort({ key, order }) {
			this.sortKey = key
			this.sortOrder = order || 'asc'
			this.fetchOrganisatiesWithFilters()
		},

		onPageChange(page) {
			this.fetchOrganisatiesWithFilters(page)
			this.updateUrl()
		},

		onPageSizeChange(size) {
			this.fetchOrganisatiesWithFilters(1, size)
		},

		onRowClick(row) {
			this.editOrganisation(row)
		},

		onSelect(ids) {
			this.selectedIds = ids
			objectStore.setSelectedObjects(ids)
		},

		onRefresh() {
			this.fetchOrganisatiesWithFilters()
		},

		// Organisation modal methods
		addContactpersoon(organisatie) {
			this.selectedOrganisationForContact = organisatie
			this.showAddContactpersoonModal = true
		},

		closeAddContactpersoonModal() {
			this.showAddContactpersoonModal = false
			this.selectedOrganisationForContact = null
		},

		onContactpersoonAdded() {
			this.fetchOrganisatiesWithFilters()
		},

		goToOrganisation(organisatie) {
			if (!organisatie.website) return
			let websiteUrl = organisatie.website.trim()
			if (!websiteUrl.startsWith('http://') && !websiteUrl.startsWith('https://')) {
				websiteUrl = 'https://' + websiteUrl
			}
			window.open(websiteUrl, '_blank')
		},

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
