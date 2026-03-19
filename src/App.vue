<template>
	<NcContent app-name="softwarecatalog">
		<!-- OpenRegister not installed: show empty state -->
		<NcAppContent v-if="storesReady && !hasOpenRegisters" class="open-register-missing">
			<NcEmptyContent
				:name="t('softwarecatalog', 'OpenRegister is required')"
				:description="t('softwarecatalog', 'Software Catalogus needs the OpenRegister app to store and manage data. Please install OpenRegister from the app store to get started.')">
				<template #icon>
					<img :src="appIcon" class="open-register-icon">
				</template>
				<template #action>
					<NcButton
						v-if="isAdmin"
						type="primary"
						:href="appStoreUrl">
						{{ t('softwarecatalog', 'Install OpenRegister') }}
					</NcButton>
					<p v-else class="open-register-admin-hint">
						{{ t('softwarecatalog', 'Ask your administrator to install the OpenRegister app.') }}
					</p>
				</template>
			</NcEmptyContent>
		</NcAppContent>

		<!-- App loaded normally -->
		<template v-else-if="storesReady && hasOpenRegisters">
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
		</template>

		<!-- Loading -->
		<NcAppContent v-else>
			<div style="display: flex; justify-content: center; align-items: center; height: 100%;">
				<NcLoadingIcon :size="64" />
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script>

import Vue from 'vue'
import { NcContent, NcAppContent, NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { CnIndexSidebar } from '@conduction/nextcloud-vue'
import { generateUrl, imagePath } from '@nextcloud/router'
import MainMenu from './navigation/MainMenu.vue'
import Modals from './modals/Modals.vue'
import Dialogs from './dialogs/Dialogs.vue'
import Views from './views/Views.vue'
import { settingsStore } from './store/store.js'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
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
			storesReady: false,
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

	computed: {
		hasOpenRegisters() {
			return settingsStore.hasOpenRegisters
		},
		isAdmin() {
			return settingsStore.getIsAdmin
		},
		appIcon() {
			return imagePath('softwarecatalog', 'app-dark.svg')
		},
		appStoreUrl() {
			return generateUrl('/settings/apps/integration/openregister')
		},
	},

	async created() {
		await settingsStore.loadSettings()
		this.storesReady = true
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

<style scoped>
.open-register-icon {
	width: 64px;
	height: 64px;
	filter: var(--background-invert-if-dark);
}

.open-register-admin-hint {
	color: var(--color-text-maxcontrast);
	text-align: center;
}
</style>
