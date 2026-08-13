<script setup>
import { reactive } from 'vue'
</script>

<template>
	<NcAppSidebar
		name="Zoek opdracht"
		subtitle="baldie"
		subname="Binnen het federatieve netwerk">
		<NcAppSidebarTab id="search-tab" name="Zoeken" :order="1">
			<template #icon>
				<Magnify :size="20" />
			</template>
			Zoek snel in het voor uw beschikbare federatieve netwerk<br />
			<NcTextField
				v-model="searchStore.search"
				class="searchField"
				label="Zoeken" />
			<NcNoteCard v-if="searchStore.searchError" type="error">
				<p>{{ searchStore.searchError }}</p>
			</NcNoteCard>
		</NcAppSidebarTab>
		<NcAppSidebarTab id="settings-tab" name="Catalogi" :order="2">
			<template #icon>
				<DatabaseOutline :size="20" />
			</template>
			<NcCheckboxRadioSwitch
				v-for="(catalogiItem, i) in catalogiStore.catalogiList"
				:key="`${catalogiItem}${i}`"
				v-model="searchStore.catalogi[catalogiItem.id]"
				type="switch">
				{{ catalogiItem.title || 'Geen titel' }}
			</NcCheckboxRadioSwitch>
		</NcAppSidebarTab>
		<NcAppSidebarTab id="share-tab" name="Publicatie typen" :order="3">
			<template #icon>
				<FileTreeOutline :size="20" />
			</template>
			<NcCheckboxRadioSwitch
				v-for="(metaData, i) in metadataStore.metaDataList"
				:key="`${metaData}${i}`"
				v-model="searchStore.metadata[metaData.id]"
				type="switch">
				{{ metaData.title || 'Geen titel' }}
			</NcCheckboxRadioSwitch>
		</NcAppSidebarTab>
	</NcAppSidebar>
</template>
<script>
import {
	NcAppSidebar,
	NcAppSidebarTab,
	NcTextField,
	NcNoteCard,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import debounce from 'lodash/debounce'

// Temporary placeholder stores until they are properly implemented
const searchStore = reactive({
	// Add placeholder properties as needed
})

const metadataStore = reactive({
	// Add placeholder properties as needed
})

const catalogiStore = reactive({
	// Add placeholder properties as needed
})

export default {
	name: 'SearchSideBar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcTextField,
		NcCheckboxRadioSwitch,
		// Icons
		Magnify,
		DatabaseOutline,
		FileTreeOutline,
	},
	props: {
		search: {
			type: String,
			required: true,
		},
		metadata: {
			type: Object,
			required: true,
		},
		catalogi: {
			type: Object,
			required: true,
		},
	},
	data() {
		return {
			starred: false,
		}
	},
	watch: {
		search: 'debouncedSearch',
		metadata: {
			/**
			 * @spec openspec/specs/fe-shell-navigation/spec.md
			 */
			handler() {
				this.debouncedSearch()
			},
			deep: true,
		},
		catalogi: {
			/**
			 * @spec openspec/specs/fe-shell-navigation/spec.md
			 */
			handler() {
				this.debouncedSearch()
			},
			deep: true,
		},
	},
	/**
	 * @spec openspec/specs/fe-shell-navigation/spec.md
	 */
	mounted() {
		metadataStore.refreshMetaDataList()
		catalogiStore.refreshCatalogiList()
	},
	methods: {
		debouncedSearch: debounce(function () {
			searchStore.getSearchResults()
		}, 500),
	},
}
</script>
