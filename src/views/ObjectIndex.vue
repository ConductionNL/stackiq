/**
 * ObjectIndex.vue
 * Dynamic object index page using CnIndexPage for any registered object type.
 * Replaces GenericObjectTable with the shared component library.
 *
 * @category Views
 * @package  softwarecatalog
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2
 * @version  2.0.0
 * @link     https://github.com/ConductionNL/softwarecatalog
 */

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { navigationStore, objectStore } from '../store/store.js'
</script>

<template>
	<CnIndexPage
		ref="indexPage"
		:title="objectTitle"
		:description="objectDescription"
		:schema="objectSchema"
		:objects="currentObjects"
		:pagination="currentPagination"
		:loading="currentLoading"
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
		@refresh="onRefresh"
		@add="onAdd" />
</template>

<script>
import { CnIndexPage } from '@conduction/nextcloud-vue'

export default {
	name: 'ObjectIndex',
	components: {
		CnIndexPage,
	},

	inject: {
		sidebarState: { default: null },
	},

	props: {
		objectType: {
			type: String,
			required: true,
		},
		customCardComponent: {
			type: [String, Object],
			default: null,
		},
		cardDisplayMode: {
			type: String,
			default: 'mixed',
		},
	},

	data() {
		return {
			objectSchema: null,
			sortKey: null,
			sortOrder: 'asc',
			viewMode: 'table',
			visibleColumns: null,
			selectedIds: [],
			searchQuery: '',
			searchDebounceTimeout: null,
			currentFilters: {},
			storeUnsubscribe: null,
			rowActionsDef: [
				{
					id: 'view',
					label: t('softwarecatalog', 'View'),
					icon: 'eye',
				},
				{
					id: 'edit',
					label: t('softwarecatalog', 'Edit'),
					icon: 'pencil',
				},
				{
					id: 'copy',
					label: t('softwarecatalog', 'Copy'),
					icon: 'content-copy',
				},
				{
					id: 'delete',
					label: t('softwarecatalog', 'Delete'),
					icon: 'delete',
					destructive: true,
				},
			],
		}
	},

	computed: {
		schemaConfig() {
			if (!objectStore.settings?.schemaConfigurations) {
				return null
			}
			const schemas = objectStore.settings.schemaConfigurations
			for (const register of Object.keys(schemas)) {
				const schemaData = schemas[register]?.schemas?.[this.objectType]
				if (schemaData) {
					return schemaData
				}
			}
			return null
		},

		objectTitle() {
			return this.schemaConfig?.title || this.objectType
		},

		objectDescription() {
			const plural = this.objectTypePlural
			return this.schemaConfig?.description
				|| t('softwarecatalog', 'Manage your {type}', { type: plural.toLowerCase() })
		},

		objectTypePlural() {
			if (this.schemaConfig?.title) {
				const title = this.schemaConfig.title.toLowerCase()
				if (title.endsWith('e')) {
					return this.schemaConfig.title + 'n'
				}
				return this.schemaConfig.title + 's'
			}
			return this.objectType + 's'
		},

		currentObjects() {
			const collection = objectStore.getCollection(this.objectType)
			if (Array.isArray(collection)) return collection
			return collection?.results || []
		},

		currentPagination() {
			return objectStore.getPagination(this.objectType)
				|| { total: 0, page: 1, pages: 1, limit: 20 }
		},

		currentLoading() {
			return objectStore.isLoading(this.objectType)
		},
	},

	watch: {
		objectType(newType, oldType) {
			if (newType !== oldType) {
				this.onObjectTypeChange()
			}
		},
	},

	async mounted() {
		if (!objectStore.settings) {
			await objectStore.fetchSettings()
		}
		await this.fetchObjectSchema()
		this.setupSidebar()
		await this.fetchWithFilters()
	},

	beforeDestroy() {
		if (this.searchDebounceTimeout) {
			clearTimeout(this.searchDebounceTimeout)
		}
		if (this.storeUnsubscribe) {
			this.storeUnsubscribe()
		}
		if (this.sidebarState) {
			this.sidebarState.active = false
			this.sidebarState.schema = null
			this.sidebarState.onSearch = null
			this.sidebarState.onFilterChange = null
			this.sidebarState.onColumnsChange = null
		}
	},

	methods: {
		async onObjectTypeChange() {
			this.sortKey = null
			this.sortOrder = 'asc'
			this.selectedIds = []
			this.searchQuery = ''
			this.currentFilters = {}
			this.objectSchema = null
			this.visibleColumns = null

			await this.fetchObjectSchema()
			this.setupSidebar()
			await this.fetchWithFilters()
		},

		async fetchObjectSchema() {
			try {
				const config = objectStore.getSchemaConfig(this.objectType)
				if (!config?.schema) return
				const schemaId = typeof config.schema === 'object'
					? config.schema?.id || config.schema?.uuid
					: config.schema
				const response = await fetch(
					`/index.php/apps/openregister/api/schemas/${schemaId}`,
					{ headers: { 'OCS-APIRequest': 'true' } },
				)
				if (response.ok) {
					this.objectSchema = await response.json()
				}
			} catch (error) {
				console.warn('Failed to fetch schema for ' + this.objectType + ':', error)
			}
		},

		setupSidebar() {
			if (!this.sidebarState) return

			this.sidebarState.active = true
			this.sidebarState.schema = this.objectSchema
			this.sidebarState.searchValue = this.searchQuery
			this.sidebarState.activeFilters = { ...this.currentFilters }

			this.sidebarState.onSearch = (value) => {
				this.searchQuery = value
				if (this.searchDebounceTimeout) {
					clearTimeout(this.searchDebounceTimeout)
				}
				this.searchDebounceTimeout = setTimeout(() => {
					this.fetchWithFilters()
				}, 500)
			}

			this.sidebarState.onFilterChange = ({ key, values }) => {
				if (!values || values.length === 0) {
					const updated = { ...this.currentFilters }
					delete updated[key]
					this.currentFilters = updated
				} else {
					this.currentFilters = {
						...this.currentFilters,
						[key]: values,
					}
				}
				if (this.sidebarState) {
					this.sidebarState.activeFilters = {
						...this.currentFilters,
					}
				}
				this.fetchWithFilters()
			}

			this.sidebarState.onColumnsChange = (cols) => {
				this.visibleColumns = cols
			}
		},

		async fetchWithFilters(page = 1, limit = 20) {
			try {
				const params = {
					_page: page,
					_limit: limit,
				}

				if (this.searchQuery.trim()) {
					params._search = this.searchQuery.trim()
				}

				if (this.sortKey) {
					params._order = {
						[this.sortKey]: this.sortOrder,
					}
				}

				for (const [key, values] of Object.entries(this.currentFilters)) {
					if (values && values.length > 0) {
						params[key] = values.length === 1
							? values[0]
							: values
					}
				}

				await objectStore.fetchCollection(this.objectType, params)
			} catch (error) {
				console.error('Error fetching ' + this.objectType + ':', error)
			}
		},

		onSort({ key, order }) {
			this.sortKey = key
			this.sortOrder = order || 'asc'
			this.fetchWithFilters()
		},

		onPageChange(page) {
			this.fetchWithFilters(page)
		},

		onPageSizeChange(size) {
			this.fetchWithFilters(1, size)
		},

		onRowClick(row) {
			objectStore.setActiveObject(this.objectType, row)
			navigationStore.setModal(this.objectType)
		},

		onSelect(ids) {
			this.selectedIds = ids
			objectStore.setSelectedObjects(ids)
		},

		onRefresh() {
			this.fetchWithFilters()
		},

		onAdd() {
			objectStore.clearActiveObject(this.objectType)
			navigationStore.setModal(this.objectType)
		},
	},
}
</script>
