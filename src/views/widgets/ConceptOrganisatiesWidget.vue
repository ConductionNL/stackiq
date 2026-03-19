<script setup>
import { translate as t } from '@nextcloud/l10n'
import { objectStore } from '../../store/store.js'
</script>

<template>
	<div class="concept-organisaties-widget">
		<div class="widget-header">
			<NcButton type="tertiary"
				:aria-label="t('softwarecatalog', 'Refresh')"
				@click="fetchData">
				<template #icon>
					<RefreshIcon :size="20" />
				</template>
			</NcButton>
		</div>
		<NcDashboardWidget :items="items"
			:loading="loading">
			<template #default="{ item }">
				<NcDashboardWidgetItem :id="item.id"
					:main-text="item.mainText"
					:sub-text="item.subText"
					:avatar-url="item.avatarUrl"
					:avatar-is-no-user="true">
					<template #actions>
						<NcLoadingIcon v-if="processingIds.includes(item.id)"
							:size="20" />
						<NcActionButton v-else
							icon="icon-checkmark"
							:close-after-click="true"
							@click="onAccept(item)">
							{{ t('softwarecatalog', 'Accept') }}
						</NcActionButton>
					</template>
				</NcDashboardWidgetItem>
			</template>
			<template #empty-content>
				<NcEmptyContent :title="t('softwarecatalog', 'No concept organisations found')">
					<template #icon>
						<DomainIcon />
					</template>
				</NcEmptyContent>
			</template>
		</NcDashboardWidget>
	</div>
</template>

<script>
// Components
import { NcDashboardWidget, NcDashboardWidgetItem, NcEmptyContent, NcButton, NcActionButton, NcLoadingIcon } from '@nextcloud/vue'

// Icons
import DomainIcon from 'vue-material-design-icons/Domain.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'

import { getTheme } from '../../services/getTheme.js'

export default {
	name: 'ConceptOrganisatiesWidget',
	components: {
		NcDashboardWidget,
		NcDashboardWidgetItem,
		NcEmptyContent,
		NcButton,
		NcActionButton,
		NcLoadingIcon,
		DomainIcon,
		RefreshIcon,
	},
	props: {
		title: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			loading: false,
			processingIds: [],
		}
	},
	computed: {
		items() {
			return objectStore.getCollection('organisatie').results
				.filter((item) => item.status?.toLowerCase() === 'concept')
				.map((item) => ({
					id: item.id,
					mainText: item.naam || item.name || item.title || t('softwarecatalog', 'Unknown organisation'),
					subText: item.website || item.type || '',
					avatarUrl: getTheme() === 'light' ? '/apps-extra/softwarecatalog/img/app-dark.svg' : '/apps-extra/softwarecatalog/img/app.svg',
				}))
		},
	},
	mounted() {
		this.fetchData()
	},
	methods: {
		/**
		 * Handle accepting an organisatie (change status to actief)
		 * @param {object} item - The organisatie item to accept
		 * @return {void}
		 */
		async onAccept(item) {
			this.processingIds.push(item.id)
			try {
				await objectStore.patchObject('organisatie', item.id, { status: 'actief' })
				await this.fetchData()
			} catch (error) {
				console.error('Error accepting organisatie:', error)
			} finally {
				this.processingIds = this.processingIds.filter((id) => id !== item.id)
			}
		},
		/**
		 * Fetch the organisatie data
		 * @return {Promise<void>}
		 */
		async fetchData() {
			this.loading = true
			await objectStore.fetchCollection('organisatie')
			this.loading = false
		},
	},
}
</script>

<style scoped>
.widget-header {
	display: flex;
	justify-content: flex-end;
	padding: 0 16px;
}
</style>
