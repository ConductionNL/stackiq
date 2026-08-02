<script setup>
import { translate as t } from '@nextcloud/l10n'
import { objectStore } from '../../store/store.js'
</script>

<template>
	<div class="concept-organisaties-widget">
		<div class="widget-header">
			<NcButton variant="tertiary"
				:aria-label="t('softwarecatalog', 'Refresh')"
				@click="fetchData">
				<template #icon>
					<RefreshIcon :size="20" />
				</template>
			</NcButton>
		</div>
		<CnDataTable :rows="items"
			:columns="columns"
			:loading="loading"
			row-icon="Domain"
			hide-header
			borderless
			:empty-text="t('softwarecatalog', 'No concept organisations found')">
			<template #row-actions="{ row }">
				<NcLoadingIcon v-if="processingIds.includes(row.id)"
					:size="20" />
				<NcActions v-else>
					<NcActionButton :close-after-click="true"
						@click="onAccept(row)">
						<template #icon>
							<CheckIcon :size="20" />
						</template>
						{{ t('softwarecatalog', 'Accept') }}
					</NcActionButton>
				</NcActions>
			</template>
		</CnDataTable>
	</div>
</template>

<script>
// Components
import { CnDataTable, registerIcons } from '@conduction/nextcloud-vue'
import { NcButton, NcActions, NcActionButton, NcLoadingIcon } from '@nextcloud/vue'

// Icons
import DomainIcon from 'vue-material-design-icons/Domain.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'

// The widget ships as its own dashboard bundle (conceptOrganisatiesWidget.js),
// so main.js' registerIcons() never runs there. Register the leading row icon
// here — registerIcons() is an idempotent module-registry merge, so this is
// safe when the widget also renders inside the app (ADR-049).
registerIcons({ Domain: DomainIcon })

/**
 * Columns for the universal headerless list look (ADR-049): a bold name and a
 * muted, right-aligned trailing detail. The `cn-cell--*` utilities live in
 * nextcloud-vue's table.css.
 */
const COLUMNS = [
	{ key: 'mainText', cellClass: 'cn-cell--strong' },
	{ key: 'subText', cellClass: 'cn-cell--muted cn-cell--end' },
]

export default {
	name: 'ConceptOrganisatiesWidget',
	components: {
		CnDataTable,
		NcButton,
		NcActions,
		NcActionButton,
		NcLoadingIcon,
		RefreshIcon,
		CheckIcon,
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
			columns: COLUMNS,
		}
	},
	computed: {
		/**
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		items() {
			return objectStore.getCollection('organisatie').results
				.filter((item) => item.status?.toLowerCase() === 'concept')
				.map((item) => ({
					id: item.id,
					mainText: item.naam || item.name || item.title || t('softwarecatalog', 'Unknown organisation'),
					subText: item.website || item.type || '',
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
		 * @spec openspec/specs/fe-organizations/spec.md
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
		 * @spec openspec/specs/fe-organizations/spec.md
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
