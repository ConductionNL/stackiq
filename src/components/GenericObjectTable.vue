/**
 * GenericObjectTable.vue
 * Generic component for displaying objects with cards and table view
 * @category Components
 * @package opencatalogi
 * @author Ruben Linde
 * @copyright 2024
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @link https://github.com/opencatalogi/opencatalogi
 */

<script setup>
import { objectStore, navigationStore } from '../store/store.js'
</script>

<template>
	<NcAppContent>
		<div class="viewContainer">
			<!-- Header -->
			<div class="viewHeader">
				<h1 class="viewHeaderTitleIndented">
					{{ title }}
				</h1>
				<p>{{ description }}</p>
			</div>

			<!-- Actions Bar -->
			<div class="viewActionsBar">
				<div class="viewInfo">
					<span v-if="filteredObjects.length" class="viewTotalCount">
						{{ t('opencatalogi', 'Showing {showing} of {total} {type}', { showing: filteredObjects.length, total: currentPagination.total || filteredObjects.length, type: objectTypePlural }) }}
					</span>
					<span v-if="selectedObjects.length > 0" class="viewIndicator">
						({{ t('opencatalogi', '{count} selected', { count: selectedObjects.length }) }})
					</span>
				</div>
				<div class="viewActions">
					<!-- Search Field -->
					<div v-if="searchQuery !== undefined" class="viewSearch">
						<NcTextField
							:value="searchQuery"
							:placeholder="t('opencatalogi', 'Search...')"
							trailing-button-icon="close"
							:show-trailing-button="searchQuery && searchQuery.length > 0"
							@trailing-button-click="handleClearSearch"
							@update:value="handleSearchInput">
							<template #icon>
								<Magnify :size="16" />
							</template>
						</NcTextField>
					</div>

					<!-- Mass Actions Dropdown -->
					<NcActions
						v-if="massActions && massActions.length > 0"
						:force-name="true"
						:disabled="selectedObjects.length === 0"
						:title="selectedObjects.length === 0 ? `Select one or more ${objectTypePlural} to use mass actions` : `Mass actions (${selectedObjects.length} selected)`"
						:menu-name="`Mass Actions (${selectedObjects.length})`">
						<template #icon>
							<FormatListChecks :size="20" />
						</template>
						<NcActionButton
							v-for="action in massActions"
							:key="action.id"
							:disabled="selectedObjects.length === 0"
							close-after-click
							@click="executeMassAction(action)">
							<template #icon>
								<component :is="action.icon" :size="20" />
							</template>
							{{ action.label }}
						</NcActionButton>
					</NcActions>

					<!-- Filters -->
					<div v-if="filters.length > 0" class="viewFilters">
						<div v-for="filter in filters" :key="filter.key" class="filterItem">
							<label :for="`filter-${filter.key}`" class="filterLabel">{{ filter.label }}:</label>
							<NcSelect
								:id="`filter-${filter.key}`"
								class="filterSelect"
								:value="getActiveFilterOption(filter)"
								:options="filter.options"
								:clearable="false"
								@option:selected="setFilter(filter.key, $event)" />
						</div>
					</div>

					<!-- View Mode Switch -->
					<div class="viewModeSwitchContainer">
						<NcCheckboxRadioSwitch
							v-tooltip="`See ${objectTypePlural} as cards`"
							:checked="viewMode === 'cards'"
							:button-variant="true"
							value="cards"
							:name="`${objectType}_view_mode`"
							type="radio"
							button-variant-grouped="horizontal"
							@update:checked="() => setViewMode('cards')">
							Cards
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							v-tooltip="`See ${objectTypePlural} as a table`"
							:checked="viewMode === 'table'"
							:button-variant="true"
							value="table"
							:name="`${objectType}_view_mode`"
							type="radio"
							button-variant-grouped="horizontal"
							@update:checked="() => setViewMode('table')">
							Table
						</NcCheckboxRadioSwitch>
					</div>

					<!-- Regular Actions -->
					<NcActions
						:force-name="true"
						:inline="actions && actions.length > 2 ? 3 : actions?.length || 2"
						menu-name="Actions">
						<NcActionButton
							v-for="action in actions"
							:key="action.id"
							:primary="action.primary || false"
							close-after-click
							:disabled="getActionDisabled(action)"
							@click="executeAction(action)">
							<template #icon>
								<component :is="action.icon" :size="20" />
							</template>
							{{ action.label }}
						</NcActionButton>
					</NcActions>

					<!-- Columns Actions for table view -->
					<NcActions
						v-if="viewMode === 'table' && showColumnSelector"
						:force-name="true"
						:inline="1"
						menu-name="Columns">
						<template #icon>
							<FormatColumns :size="20" />
						</template>

						<!-- Metadata Section -->
						<NcActionCaption name="Metadata" />
						<NcActionCheckbox
							v-for="meta in metadataColumns"
							:key="`meta_${meta.id}`"
							:checked="objectStore.columnFilters[`meta_${meta.id}`]"
							@update:checked="(status) => objectStore.updateColumnFilter(`meta_${meta.id}`, status)">
							{{ meta.label }}
						</NcActionCheckbox>

						<!-- Properties Section -->
						<NcActionCaption v-if="propertyColumns && propertyColumns.length > 0" name="Properties" />
						<NcActionCheckbox
							v-for="prop in propertyColumns"
							:key="`prop_${prop.id}`"
							:checked="objectStore.columnFilters[`prop_${prop.id}`]"
							@update:checked="(status) => objectStore.updateColumnFilter(`prop_${prop.id}`, status)">
							{{ prop.label }}
						</NcActionCheckbox>
					</NcActions>
				</div>
			</div>

			<!-- Loading, Error, and Empty States -->
			<NcEmptyContent v-if="objectStore.isLoading(objectType) || !filteredObjects.length"
				:name="emptyContentName"
				:description="emptyContentDescription">
				<template #icon>
					<NcLoadingIcon v-if="objectStore.isLoading(objectType)" :size="64" />
					<component :is="emptyIcon" v-else :size="64" />
				</template>
				<template v-if="!objectStore.isLoading(objectType) && !filteredObjects.length && addAction" #action>
					<NcButton type="primary" @click="executeAction(addAction)">
						{{ addAction.label }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<!-- Content -->
			<div v-else>
				<template v-if="viewMode === 'cards'">
					<div class="cardGrid">
						<!-- Custom Card Component -->
						<template v-if="customCardComponent">
							<component :is="customCardComponent"
								v-for="item in paginatedObjects"
								:key="getObjectId(item)"
								:item="item"
								:object-actions="objectActions"
								:card-icon="cardIcon" />
						</template>

						<!-- Default Generic Cards -->
						<template v-else>
							<div v-for="item in paginatedObjects"
								:key="getObjectId(item)"
								class="card">
								<div class="cardHeader">
									<h2 v-tooltip.bottom="getObjectSummary(item)">
										<component :is="cardIcon" :size="20" />
										{{ getObjectTitle(item) }}
									</h2>
									<NcActions :primary="true" menu-name="Actions">
										<template #icon>
											<DotsHorizontal :size="20" />
										</template>
										<NcActionButton
											v-for="action in objectActions"
											v-if="!action.condition || action.condition(item)"
											:key="action.id"
											close-after-click
											@click="executeObjectAction(action, item)">
											<template #icon>
												<component :is="action.icon" :size="20" />
											</template>
											{{ action.label }}
										</NcActionButton>
									</NcActions>
								</div>
								<!-- Card Content -->
								<div v-if="cardDisplayMode === 'description'" class="cardDescription">
									<p v-if="getObjectSummary(item)" class="summaryText">
										{{ getObjectSummary(item) }}
									</p>
									<p v-else class="noSummaryText">
										{{ t('opencatalogi', 'No description available') }}
									</p>

									<!-- Show key properties in a compact format -->
									<div v-if="getKeyProperties(item).length > 0" class="keyProperties">
										<span v-for="property in getKeyProperties(item)"
											:key="property.key"
											class="keyProperty">
											<strong>{{ property.label }}:</strong> {{ property.value }}
										</span>
									</div>
								</div>

								<div v-else-if="cardDisplayMode === 'properties'" class="cardProperties">
									<!-- Card Statistics Table -->
									<table class="statisticsTable">
										<thead>
											<tr>
												<th>{{ t('opencatalogi', 'Property') }}</th>
												<th>{{ t('opencatalogi', 'Value') }}</th>
												<th>{{ t('opencatalogi', 'Status') }}</th>
											</tr>
										</thead>
										<tbody>
											<tr v-for="property in getCardProperties(item)" :key="property.key">
												<td>{{ property.label }}</td>
												<td class="truncatedText">
													{{ property.value }}
												</td>
												<td>{{ property.status }}</td>
											</tr>
										</tbody>
									</table>
								</div>

								<div v-else-if="cardDisplayMode === 'mixed'" class="cardMixed">
									<!-- Description first -->
									<div class="cardDescription">
										<p v-if="getObjectSummary(item)" class="summaryText">
											{{ getObjectSummary(item) }}
										</p>
										<p v-else class="noSummaryText">
											{{ t('opencatalogi', 'No description available') }}
										</p>
									</div>

									<!-- Compact properties table -->
									<table v-if="getCardProperties(item).length > 0" class="statisticsTable compact">
										<tbody>
											<tr v-for="property in getCardProperties(item).slice(0, 3)" :key="property.key">
												<td><strong>{{ property.label }}</strong></td>
												<td class="truncatedText">
													{{ property.value }}
												</td>
											</tr>
										</tbody>
									</table>
								</div>
							</div>
						</template>
					</div>
				</template>
				<template v-else>
					<div class="viewTableContainer">
						<VueDraggable v-if="enableColumnReorder"
							v-model="orderedEnabledColumns"
							target=".sort-target"
							animation="150"
							draggable="> *:not(.staticColumn)">
							<table class="viewTable">
								<thead>
									<tr class="viewTableRow sort-target">
										<th class="tableColumnCheckbox">
											<NcCheckboxRadioSwitch
												:checked="allSelected"
												:indeterminate="someSelected"
												@update:checked="toggleSelectAll" />
										</th>
										<th v-for="(column, index) in orderedEnabledColumns"
											:key="`header-${column.id || column.key || `col-${index}`}`"
											:class="`tableColumn${column.id ? column.id.charAt(0).toUpperCase() + column.id.slice(1).replace('_', '') : ''}`">
											<span class="stickyHeader columnTitle" :title="column.description">
												{{ column.label }}
											</span>
										</th>
										<th class="tableColumnActions">
											<!-- Empty header for actions column -->
										</th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="item in paginatedObjects"
										:key="getObjectId(item)"
										class="viewTableRow table-row-selectable"
										:class="{ 'table-row-selected': selectedObjects.includes(getObjectId(item)) }"
										@click="handleRowClick(getObjectId(item), $event)">
										<td class="tableColumnCheckbox">
											<NcCheckboxRadioSwitch
												:checked="selectedObjects.includes(getObjectId(item))"
												@update:checked="handleSelectObject(getObjectId(item))" />
										</td>
										<td v-for="(column, index) in orderedEnabledColumns"
											:key="`cell-${getObjectId(item)}-${column.id || column.key || `col-${index}`}`"
											:class="`tableColumn${column.id ? column.id.charAt(0).toUpperCase() + column.id.slice(1).replace('_', '') : ''}`">
											<span v-if="column.renderer">
												<component :is="column.renderer" :object="item" :column="column" />
											</span>
											<span v-else>
												{{ getColumnValue(item, column) }}
											</span>
										</td>
										<td class="tableColumnActions">
											<NcActions class="actionsButton">
												<NcActionButton
													v-for="action in objectActions"
													v-if="!action.condition || action.condition(item)"
													:key="action.id"
													close-after-click
													@click="executeObjectAction(action, item)">
													<template #icon>
														<component :is="action.icon" :size="20" />
													</template>
													{{ action.label }}
												</NcActionButton>
											</NcActions>
										</td>
									</tr>
								</tbody>
							</table>
						</VueDraggable>
						<table v-else class="viewTable">
							<thead>
								<tr class="viewTableRow">
									<th class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="allSelected"
											:indeterminate="someSelected"
											@update:checked="toggleSelectAll" />
									</th>
									<th v-for="(column, index) in orderedEnabledColumns"
										:key="`header-${column.id || column.key || `col-${index}`}`"
										:class="`tableColumn${column.id ? column.id.charAt(0).toUpperCase() + column.id.slice(1).replace('_', '') : ''}`">
										<span class="columnTitle" :title="column.description">
											{{ column.label }}
										</span>
									</th>
									<th class="tableColumnActions">
										{{ t('opencatalogi', 'Actions') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="item in paginatedObjects"
									:key="getObjectId(item)"
									class="viewTableRow table-row-selectable"
									:class="{ 'table-row-selected': selectedObjects.includes(getObjectId(item)) }"
									@click="handleRowClick(getObjectId(item), $event)">
									<td class="tableColumnCheckbox">
										<NcCheckboxRadioSwitch
											:checked="selectedObjects.includes(getObjectId(item))"
											@update:checked="handleSelectObject(getObjectId(item))" />
									</td>
									<td v-for="(column, index) in orderedEnabledColumns"
										:key="`cell-${getObjectId(item)}-${column.id || column.key || `col-${index}`}`"
										:class="`tableColumn${column.id ? column.id.charAt(0).toUpperCase() + column.id.slice(1).replace('_', '') : ''}`">
										<span v-if="column.renderer">
											<component :is="column.renderer" :object="item" :column="column" />
										</span>
										<span v-else>
											{{ getColumnValue(item, column) }}
										</span>
									</td>
									<td class="tableColumnActions">
										<NcActions class="actionsButton">
											<NcActionButton
												v-for="action in objectActions"
												v-if="!action.condition || action.condition(item)"
												:key="action.id"
												close-after-click
												@click="executeObjectAction(action, item)">
												<template #icon>
													<component :is="action.icon" :size="20" />
												</template>
												{{ action.label }}
											</NcActionButton>
										</NcActions>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</template>
			</div>

			<!-- Pagination -->
			<PaginationComponent
				:current-page="currentPagination.page || 1"
				:total-pages="currentPagination.pages || Math.ceil(filteredObjects.length / (currentPagination.limit || 20))"
				:total-items="currentPagination.total || filteredObjects.length"
				:current-page-size="currentPagination.limit || 20"
				:min-items-to-show="0"
				@page-changed="onPageChanged"
				@page-size-changed="onPageSizeChanged" />
		</div>
	</NcAppContent>
</template>

<script>
import {
	NcAppContent,
	NcEmptyContent,
	NcLoadingIcon,
	NcActions,
	NcActionButton,
	NcActionCheckbox,
	NcActionCaption,
	NcCheckboxRadioSwitch,
	NcButton,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { VueDraggable } from 'vue-draggable-plus'

import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import FormatListChecks from 'vue-material-design-icons/FormatListChecks.vue'
import FormatColumns from 'vue-material-design-icons/FormatColumns.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'

import PaginationComponent from './PaginationComponent.vue'

export default {
	name: 'GenericObjectTable',
	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcActions,
		NcActionButton,
		NcActionCheckbox,
		NcActionCaption,
		NcCheckboxRadioSwitch,
		NcButton,
		NcSelect,
		NcTextField,
		VueDraggable,
		DotsHorizontal,
		FormatListChecks,
		FormatColumns,
		Magnify,
		PaginationComponent,
	},

	props: {
		/**
		 * Object type identifier
		 */
		objectType: {
			type: String,
			required: true,
		},
		/**
		 * Plural form of object type for display
		 */
		objectTypePlural: {
			type: String,
			required: true,
		},
		/**
		 * Title for the view
		 */
		title: {
			type: String,
			required: true,
		},
		/**
		 * Description for the view
		 */
		description: {
			type: String,
			required: true,
		},
		/**
		 * Icon for empty state
		 */
		emptyIcon: {
			type: [String, Object],
			required: true,
		},
		/**
		 * Icon for cards
		 */
		cardIcon: {
			type: [String, Object],
			required: true,
		},
		/**
		 * Properties to display in table/cards
		 */
		properties: {
			type: Array,
			default: () => [],
		},
		/**
		 * Available actions for individual objects
		 */
		objectActions: {
			type: Array,
			default: () => [],
		},
		/**
		 * Available mass actions
		 */
		massActions: {
			type: Array,
			default: () => [],
		},
		/**
		 * Available header actions
		 */
		actions: {
			type: Array,
			default: () => [],
		},
		/**
		 * Add action (for empty state)
		 */
		addAction: {
			type: Object,
			default: null,
		},
		/**
		 * Custom modal configurations
		 */
		modalConfig: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * Custom dialog configurations
		 */
		dialogConfig: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * Whether to show column selector
		 */
		showColumnSelector: {
			type: Boolean,
			default: true,
		},
		/**
		 * Whether to enable column reordering
		 */
		enableColumnReorder: {
			type: Boolean,
			default: false,
		},
		/**
		 * Custom refresh function
		 */
		refreshFunction: {
			type: Function,
			default: null,
		},
		/**
		 * Custom pagination function
		 */
		paginationFunction: {
			type: Function,
			default: null,
		},
		/**
		 * Help URL for documentation
		 */
		helpUrl: {
			type: String,
			default: null,
		},
		/**
		 * Display mode for cards: 'properties' shows property table, 'description' shows description, 'mixed' shows both
		 */
		cardDisplayMode: {
			type: String,
			default: 'properties',
			validator: value => ['properties', 'description', 'mixed'].includes(value),
		},
		/**
		 * Custom card component to use instead of the default card
		 */
		customCardComponent: {
			type: [String, Object],
			default: null,
		},
		/**
		 * Available filters for this object type
		 */
		filters: {
			type: Array,
			default: () => [],
		},
		/**
		 * Search query for filtering objects
		 */
		searchQuery: {
			type: String,
			default: '',
		},
		/**
		 * Search query change handler
		 */
		onSearchInput: {
			type: Function,
			default: null,
		},
		/**
		 * Clear search handler
		 */
		clearSearch: {
			type: Function,
			default: null,
		},
	},

	data() {
		return {
			viewMode: 'cards',
			localSelectedObjects: [],
			activeFilters: {},
		}
	},

	computed: {
		filteredObjects() {
			// Trust the API response - no frontend filtering needed
			// The API handles all filtering based on query parameters
			let objects = objectStore.getCollection(this.objectType)?.results || []

			// Only apply search query if it's not handled by the API
			// (This is kept for backward compatibility with components that don't use API search)
			if (this.searchQuery && this.searchQuery.trim() && !this.onSearchInput) {
				const searchTerm = this.searchQuery.toLowerCase().trim()
				objects = objects.filter(obj => {
					const objTitle = this.getObjectTitle(obj).toLowerCase()
					const objSummary = this.getObjectSummary(obj).toLowerCase()
					return objTitle.includes(searchTerm) || objSummary.includes(searchTerm)
				})
			}

			return objects
		},
		currentPagination() {
			const pagination = objectStore.getPagination(this.objectType)
			console.info(`GenericObjectTable: Pagination for ${this.objectType}:`, {
				pagination,
				filteredObjectsLength: this.filteredObjects.length,
			})
			return pagination
		},
		paginatedObjects() {
			// Check if we should use server-side pagination
			// Server-side pagination is when we have proper pagination metadata AND
			// the total from server matches the actual results length (indicating server handled pagination)
			const hasServerPagination = this.currentPagination?.page && 
				this.currentPagination?.limit && 
				this.currentPagination?.total &&
				this.filteredObjects.length <= this.currentPagination.limit

			if (hasServerPagination) {
				// Server has already paginated the results
				return this.filteredObjects
			}
			
			// Client-side pagination - split the full result set into pages
			const pageSize = this.currentPagination?.limit || 20
			const currentPage = this.currentPagination?.page || 1
			const startIndex = (currentPage - 1) * pageSize
			const endIndex = startIndex + pageSize
			
			return this.filteredObjects.slice(startIndex, endIndex)
		},
		selectedObjects() {
			// Use store-managed selected objects if available, otherwise use local state
			return (objectStore.selectedObjects || []).map(obj =>
				this.getObjectId(obj),
			).filter(Boolean)
		},
		allSelected() {
			return this.filteredObjects.length > 0 && this.filteredObjects.every(obj =>
				this.selectedObjects.includes(this.getObjectId(obj)),
			)
		},
		someSelected() {
			return this.selectedObjects.length > 0 && !this.allSelected
		},
		emptyContentName() {
			if (objectStore.isLoading(this.objectType)) {
				return t('opencatalogi', `Loading ${this.objectTypePlural}...`)
			} else if (!this.filteredObjects.length) {
				return t('opencatalogi', `No ${this.objectTypePlural} found`)
			}
			return ''
		},
		emptyContentDescription() {
			if (objectStore.isLoading(this.objectType)) {
				return t('opencatalogi', `Please wait while we fetch your ${this.objectTypePlural}.`)
			} else if (!this.filteredObjects.length) {
				return t('opencatalogi', `No ${this.objectTypePlural} are available.`)
			}
			return ''
		},
		metadataColumns() {
			// Get all available metadata columns from objectStore
			return Object.entries(objectStore.metadata).map(([key, meta]) => ({
				id: key,
				...meta,
			}))
		},
		propertyColumns() {
			// Get all available property columns from objectStore
			return Object.entries(objectStore.properties || {}).map(([key, prop]) => ({
				id: key,
				...prop,
			}))
		},
		orderedEnabledColumns() {
			// Get enabled columns from the store or use provided properties
			const enabledColumns = objectStore.enabledColumns.length > 0
				? objectStore.enabledColumns
				: this.properties

			// Apply custom ordering if provided
			if (this.properties && this.properties.length > 0) {
				const desiredOrder = this.properties.map(p => p.id)
				return enabledColumns.sort((a, b) => {
					const aIndex = desiredOrder.indexOf(a.id)
					const bIndex = desiredOrder.indexOf(b.id)

					if (aIndex === -1 && bIndex === -1) return 0
					if (aIndex === -1) return 1
					if (bIndex === -1) return -1

					return aIndex - bIndex
				})
			}

			return enabledColumns
		},
	},

	mounted() {
		console.info(`GenericObjectTable mounted for ${this.objectType}, fetching objects...`)
		
		// Initialize active filters with default values
		if (this.filters && this.filters.length > 0) {
			this.filters.forEach(filter => {
				this.$set(this.activeFilters, filter.key, 'all')
			})
		}
		
		this.refreshObjects()
		// Initialize column filters
		objectStore.initializeColumnFilters()
	},

	methods: {
		setViewMode(mode) {
			console.info('Setting view mode to:', mode)
			this.viewMode = mode
		},

		toggleSelectAll(checked) {
			if (checked) {
				// Select all - update store with full objects
				const selectedObjects = this.filteredObjects.map(obj => ({
					...obj,
					id: this.getObjectId(obj),
				}))
				objectStore.setSelectedObjects(selectedObjects)
			} else {
				// Deselect all
				objectStore.setSelectedObjects([])
			}
		},

		handleSelectObject(objectId) {
			const currentSelected = [...(objectStore.selectedObjects || [])]
			const existingIndex = currentSelected.findIndex(obj =>
				this.getObjectId(obj) === objectId,
			)

			if (existingIndex > -1) {
				// Remove from selection
				currentSelected.splice(existingIndex, 1)
			} else {
				// Add to selection - find the full object
				const objectToAdd = this.filteredObjects.find(obj =>
					this.getObjectId(obj) === objectId,
				)
				if (objectToAdd) {
					currentSelected.push({
						...objectToAdd,
						id: this.getObjectId(objectToAdd),
					})
				}
			}

			objectStore.setSelectedObjects(currentSelected)
		},

		handleRowClick(id, event) {
			// Don't select if clicking on the checkbox, actions button, or inside actions menu
			if (event.target.closest('.tableColumnCheckbox')
				|| event.target.closest('.tableColumnActions')
				|| event.target.closest('.actionsButton')) {
				return
			}

			// Toggle selection on row click
			this.handleSelectObject(id)
		},

		getObjectId(item) {
			return item?.id || item?.['@self']?.id || item?.uuid
		},

		getObjectTitle(item) {
			// For organizations, prioritize naam field which is the proper Dutch name field
			if (this.objectType === 'organisatie' && item?.naam) {
				return item.naam
			}

			// For other objects or fallback, use the @self.name (which we fixed) or other fallbacks
			return item?.title || item?.name || item?.naam || item?.['@self']?.name || this.getObjectId(item) || 'Unknown'
		},

		getObjectSummary(item) {
			// For organizations, create a meaningful description from available fields
			if (this.objectType === 'organisatie') {
				if (item?.beschrijvingKort) return item.beschrijvingKort
				if (item?.beschrijvingLang) return item.beschrijvingLang
				if (item?.type && item?.naam) return `${item.type} organisatie`
				if (item?.type) return item.type
			}

			// For other object types, use standard fields
			return item?.summary || item?.description || item?.beschrijvingKort || item?.beschrijvingLang || ''
		},

		getColumnValue(item, column) {
			if (column.key) {
				// Handle nested properties
				const keys = column.key.split('.')
				let value = item
				for (const key of keys) {
					value = value?.[key]
					if (value === undefined || value === null) break
				}
				return value || 'N/A'
			}
			return 'N/A'
		},

		getCardProperties(item) {
			// Convert properties to card display format
			return this.orderedEnabledColumns.map(column => ({
				key: column.key || column.id,
				label: column.label,
				value: this.getColumnValue(item, column),
				status: 'Available', // Default status, can be customized
			})).filter(prop => prop.value !== 'N/A')
		},

		getKeyProperties(item) {
			// Show only the first few most important properties in a compact format
			return this.orderedEnabledColumns.slice(0, 3).map(column => ({
				key: column.key || column.id,
				label: column.label,
				value: this.getColumnValue(item, column),
			})).filter(prop => prop.value !== 'N/A' && prop.value !== null && prop.value !== undefined)
		},

		getActionDisabled(action) {
			if (typeof action.disabled === 'function') {
				return action.disabled()
			}
			return action.disabled || false
		},

		executeAction(action) {
			if (action.handler) {
				action.handler()
			} else if (action.modal) {
				// Set active object if needed
				if (action.clearActiveObject) {
					objectStore.clearActiveObject(this.objectType)
				}
				navigationStore.setModal(this.modalConfig[action.modal] || action.modal)
			} else if (action.dialog) {
				navigationStore.setDialog(this.dialogConfig[action.dialog] || action.dialog)
			} else if (action.method) {
				this[action.method]()
			}
		},

		executeObjectAction(action, item) {
			if (action.handler) {
				action.handler(item)
			} else if (action.modal) {
				// Set the object as active
				objectStore.setActiveObject(this.objectType, item)
				navigationStore.setModal(this.modalConfig[action.modal] || action.modal)
			} else if (action.dialog) {
				// Set the object as active
				objectStore.setActiveObject(this.objectType, item)
				navigationStore.setDialog(this.dialogConfig[action.dialog] || action.dialog, {
					objectType: this.objectType,
					dialogTitle: this.objectType.charAt(0).toUpperCase() + this.objectType.slice(1),
				})
			} else if (action.method) {
				this[action.method](item)
			}
		},

		executeMassAction(action) {
			if (this.selectedObjects.length === 0) return

			if (action.handler) {
				action.handler()
			} else if (action.dialog) {
				navigationStore.setDialog(action.dialog)
			} else if (action.method) {
				this[action.method]()
			}
		},

		onPageChanged(page) {
			console.info('Page changed to:', page)
			if (this.paginationFunction) {
				this.paginationFunction(page, this.currentPagination.limit || 20)
			} else {
				const params = { _page: page, _limit: this.currentPagination.limit || 20 }
				// For organisatie, always include contactpersonen extend
				if (this.objectType === 'organisatie') {
					params._extend = '@self.schema,contactpersonen'
				}
				objectStore.fetchCollection(this.objectType, params)
			}
		},

		onPageSizeChanged(pageSize) {
			console.info('Page size changed to:', pageSize)
			if (this.paginationFunction) {
				this.paginationFunction(1, pageSize)
			} else {
				const params = { _page: 1, _limit: pageSize }
				// For organisatie, always include contactpersonen extend
				if (this.objectType === 'organisatie') {
					params._extend = '@self.schema,contactpersonen'
				}
				objectStore.fetchCollection(this.objectType, params)
			}
		},

		/**
		 * Get the active filter option for a given filter
		 * @param {object} filter - The filter configuration
		 * @return {object} The currently active filter option
		 */
		getActiveFilterOption(filter) {
			const activeValue = this.activeFilters[filter.key] || 'all'
			return filter.options.find(option => option.value === activeValue) || filter.options[0]
		},

		/**
		 * Set a filter value
		 * @param {string} filterKey - The filter key
		 * @param {object} option - The selected filter option
		 */
		setFilter(filterKey, option) {
			this.$set(this.activeFilters, filterKey, option.value)
			
			// Call the onChange handler if it exists for this filter
			const filter = this.filters.find(f => f.key === filterKey)
			if (filter && filter.onChange) {
				filter.onChange(option.value)
			}
		},

		refreshObjects() {
			if (this.refreshFunction) {
				this.refreshFunction()
			} else {
				// For organisatie, always include contactpersonen extend
				const extendParams = this.objectType === 'organisatie' 
					? { _extend: '@self.schema,contactpersonen' }
					: {}
				objectStore.fetchCollection(this.objectType, extendParams)
			}
			// Clear selection after refresh
			objectStore.setSelectedObjects([])
			
			// Reset filters to default values
			if (this.filters && this.filters.length > 0) {
				this.filters.forEach(filter => {
					this.$set(this.activeFilters, filter.key, 'all')
				})
			}
		},

		openLink(url, type = '') {
			window.open(url, type)
		},

		/**
		 * Handle search input
		 * @param {string} value - The search input value
		 */
		handleSearchInput(value) {
			if (this.onSearchInput) {
				this.onSearchInput(value)
			}
		},

		/**
		 * Handle clear search
		 */
		handleClearSearch() {
			if (this.clearSearch) {
				this.clearSearch()
			}
		},
	},
}
</script>

<style>
.actionsButton > div > button {
    margin-top: 0px !important;
    margin-right: 0px !important;
    padding-right: 0px !important;
}
</style>

<style scoped>
.truncatedText {
	max-width: 200px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	display: inline-block;
}

.viewContainer {
	padding: 20px;
}

.viewHeader {
	margin-bottom: 24px;
}

.viewHeaderTitleIndented {
	margin: 0 0 8px 0;
	font-size: 24px;
	font-weight: 600;
}

.viewActionsBar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
	gap: 16px;
	flex-wrap: wrap;
}

.viewInfo {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-lighter);
}

.viewActions {
	display: flex;
	align-items: center;
	gap: 12px;
}

.viewModeSwitchContainer {
	display: flex;
}

.cardGrid {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 20px;
	margin-bottom: 20px;
}

.card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	background: var(--color-main-background);
}

.cardHeader {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 12px;
}

.cardHeader h2 {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0;
	font-size: 16px;
	font-weight: 600;
	flex: 1;
	min-width: 0;
}

.cardDescription {
	margin-top: 12px;
}

.summaryText {
	font-size: 14px;
	line-height: 1.4;
	color: var(--color-main-text);
	margin: 0 0 12px 0;
}

.noSummaryText {
	font-size: 14px;
	color: var(--color-text-lighter);
	font-style: italic;
	margin: 0 0 12px 0;
}

.keyProperties {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border-dark);
}

.keyProperty {
	font-size: 12px;
	color: var(--color-main-text);
}

.keyProperty strong {
	color: var(--color-text-lighter);
	font-weight: 600;
}

.cardProperties {
	margin-top: 12px;
}

.cardMixed {
	margin-top: 12px;
}

.cardMixed .cardDescription {
	margin-bottom: 12px;
}

.statisticsTable {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
}

.statisticsTable th,
.statisticsTable td {
	padding: 6px 8px;
	text-align: left;
	border-bottom: 1px solid var(--color-border-dark);
}

.statisticsTable th {
	background: var(--color-background-dark);
	font-weight: 600;
	font-size: 12px;
}

.statisticsTable.compact {
	font-size: 12px;
}

.statisticsTable.compact td {
	padding: 4px 8px;
}

.truncatedText {
	max-width: 200px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

/* Filter Styles */
.viewFilters {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
}

.viewSearch {
	min-width: 200px;
}

.filterItem {
	display: flex;
	align-items: center;
	gap: 8px;
}

.filterLabel {
	font-size: 14px;
	font-weight: 500;
	color: var(--color-text-lighter);
	white-space: nowrap;
}

.filterSelect {
	min-width: 120px;
}

/* Pagination Styles */
.viewPagination {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-top: 24px;
	padding: 16px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	gap: 16px;
	flex-wrap: wrap;
}

.viewPaginationInfo {
	display: flex;
	align-items: center;
	color: var(--color-text-lighter);
	font-size: 14px;
}

.viewPaginationNav {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.viewPaginationNumbers {
	display: flex;
	align-items: center;
	gap: 4px;
}

.viewPaginationEllipsis {
	padding: 6px 8px;
	color: var(--color-text-lighter);
}

.viewPaginationPageSize {
	display: flex;
	align-items: center;
	gap: 8px;
}

.viewPaginationPageSize label {
	font-size: 14px;
	color: var(--color-text-lighter);
	white-space: nowrap;
}

.pagination-page-size-select {
	min-width: 80px;
}

.viewTableContainer {
	overflow-x: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.viewTable {
	width: 100%;
	border-collapse: collapse;
	table-layout: auto;
	min-width: 600px;
}

.viewTable th,
.viewTable td {
	padding: 12px 8px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
	width: auto;
	min-width: 120px;
}

.viewTable th {
	background: var(--color-background-dark);
	font-weight: 600;
	position: sticky;
	top: 0;
	z-index: 1;
}

.viewTableRow:hover {
	background: var(--color-background-hover);
}

.tableColumnCheckbox {
	width: 40px !important;
	min-width: 40px !important;
	max-width: 40px !important;
	text-align: center;
	padding: 8px !important;
}

.tableColumnCheckbox :deep(.checkbox-radio-switch) {
	margin: 0;
	display: flex;
	align-items: center;
	justify-content: center;
}

.tableColumnCheckbox :deep(.checkbox-radio-switch__content) {
	margin: 0;
}

.tableColumnActions {
	width: 60px !important;
	min-width: 60px !important;
	max-width: 60px !important;
	text-align: center;
}

.columnTitle {
	font-weight: bold;
}

.stickyHeader {
	position: sticky;
	left: 0;
}

/* Row selection styling */
.table-row-selectable {
	cursor: pointer;
}

.table-row-selectable:hover {
	background-color: var(--color-background-hover);
}

.table-row-selected {
	background-color: var(--color-primary-light) !important;
}

.viewTotalCount {
	font-weight: 500;
}

.viewIndicator {
	color: var(--color-primary);
	font-weight: 500;
}
</style>
