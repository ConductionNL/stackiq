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
						<div v-for="item in paginatedObjects" :key="getObjectId(item)" class="card">
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
										:key="action.id"
										close-after-click
										:disabled="action.condition && !action.condition(item)"
										@click="executeObjectAction(action, item)">
										<template #icon>
											<component :is="action.icon" :size="20" />
										</template>
										{{ action.label }}
									</NcActionButton>
								</NcActions>
							</div>
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
													:key="action.id"
													close-after-click
													:disabled="action.condition && !action.condition(item)"
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
												:key="action.id"
												close-after-click
												:disabled="action.condition && !action.condition(item)"
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
} from '@nextcloud/vue'
import { VueDraggable } from 'vue-draggable-plus'

import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import FormatListChecks from 'vue-material-design-icons/FormatListChecks.vue'
import FormatColumns from 'vue-material-design-icons/FormatColumns.vue'

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
		VueDraggable,
		DotsHorizontal,
		FormatListChecks,
		FormatColumns,
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
	},

	data() {
		return {
			viewMode: 'cards',
			localSelectedObjects: [],
		}
	},

	computed: {
		filteredObjects() {
			return objectStore.getCollection(this.objectType)?.results || []
		},
		currentPagination() {
			const pagination = objectStore.getPagination(this.objectType)
			return pagination
		},
		paginatedObjects() {
			return this.filteredObjects
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
			return item?.title || item?.name || item?.['@self']?.name || this.getObjectId(item) || 'Unknown'
		},

		getObjectSummary(item) {
			return item?.summary || item?.description || ''
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
				objectStore.fetchCollection(this.objectType, { _page: page, _limit: this.currentPagination.limit || 20 })
			}
		},

		onPageSizeChanged(pageSize) {
			console.info('Page size changed to:', pageSize)
			if (this.paginationFunction) {
				this.paginationFunction(1, pageSize)
			} else {
				objectStore.fetchCollection(this.objectType, { _page: 1, _limit: pageSize })
			}
		},

		refreshObjects() {
			if (this.refreshFunction) {
				this.refreshFunction()
			} else {
				objectStore.fetchCollection(this.objectType)
			}
			// Clear selection after refresh
			objectStore.setSelectedObjects([])
		},

		openLink(url, type = '') {
			window.open(url, type)
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
