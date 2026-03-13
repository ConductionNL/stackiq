<template>
	<NcContent app-name="softwarecatalog">
		<MainMenu />
		<Views />
		<CnIndexSidebar
			v-if="sidebarState.active"
			:schema="sidebarState.schema"
			:visible-columns="sidebarState.visibleColumns"
			:search-value="sidebarState.searchValue"
			:active-filters="sidebarState.activeFilters"
			:facet-data="sidebarState.facetData"
			:open="sidebarState.open"
			@update:open="sidebarState.open = $event"
			@search="onSidebarSearch"
			@columns-change="onSidebarColumnsChange"
			@filter-change="onSidebarFilterChange" />
		<Modals />
		<Dialogs />
	</NcContent>
</template>

<script>

import Vue from 'vue'
import { NcContent } from '@nextcloud/vue'
import { CnIndexSidebar } from '@conduction/nextcloud-vue'
import MainMenu from './navigation/MainMenu.vue'
import Modals from './modals/Modals.vue'
import Dialogs from './dialogs/Dialogs.vue'
import Views from './views/Views.vue'

export default {
	name: 'App',
	components: {
		NcContent,
		CnIndexSidebar,
		MainMenu,
		Modals,
		Dialogs,
		Views,
	},

	provide() {
		return {
			sidebarState: this.sidebarState,
		}
	},

	data() {
		return {
			sidebarState: Vue.observable({
				active: false,
				open: true,
				schema: null,
				visibleColumns: null,
				searchValue: '',
				activeFilters: {},
				facetData: {},
				onSearch: null,
				onColumnsChange: null,
				onFilterChange: null,
			}),
		}
	},

	methods: {
		onSidebarSearch(value) {
			this.sidebarState.searchValue = value
			if (typeof this.sidebarState.onSearch === 'function') {
				this.sidebarState.onSearch(value)
			}
		},
		onSidebarColumnsChange(columns) {
			this.sidebarState.visibleColumns = columns
			if (typeof this.sidebarState.onColumnsChange === 'function') {
				this.sidebarState.onColumnsChange(columns)
			}
		},
		onSidebarFilterChange(filter) {
			if (typeof this.sidebarState.onFilterChange === 'function') {
				this.sidebarState.onFilterChange(filter)
			}
		},
	},
}
</script>
