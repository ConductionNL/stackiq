<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Custom organisation index view — type: "custom" survivor in the manifest.

 Renders a card grid of organisations using the bespoke OrganisatieCard
 (with inline contactpersoon toggle and multi-view orchestration). Cannot
 be replaced by type: "index" until CnIndexPage exposes a `cardComponent`
 config field. Tracked as Open Question 2 in the manifest v1 design doc.

 Modals (OrganisationModal create/edit, AddContactpersoonModal) are mounted
 globally in App.vue's <Modals /> and triggered via navigationStore.setModal().

 @spec openspec/changes/softwarecatalog-manifest-v1/tasks.md#task-4.5
-->
<template>
	<div class="organisatieIndexView">
		<!-- Loading state -->
		<NcLoadingIcon v-if="loading && !organisations.length"
			:size="64"
			class="organisatieIndexView__loading" />

		<!-- Empty state -->
		<NcEmptyContent
			v-else-if="!loading && !organisations.length"
			:name="t('softwarecatalog', 'No organisations')"
			:description="t('softwarecatalog', 'No organisations found. Create one to get started.')">
			<template #icon>
				<OfficeBuildingOutline :size="64" />
			</template>
			<template #action>
				<NcButton type="primary" @click="createOrganisatie">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('softwarecatalog', 'Add organisation') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<!-- Card grid -->
		<template v-else>
			<div class="organisatieIndexView__toolbar">
				<NcButton type="primary" @click="createOrganisatie">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('softwarecatalog', 'Add organisation') }}
				</NcButton>
			</div>

			<div class="organisatieIndexView__grid">
				<OrganisatieCard
					v-for="item in organisations"
					:key="item.id || item.uuid"
					:item="item"
					:card-icon="organisatieIcon"
					:object-actions="getActions(item)" />
			</div>

			<div v-if="loading" class="organisatieIndexView__loadingMore">
				<NcLoadingIcon :size="20" />
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import OrganisatieCard from '../../components/cards/OrganisatieCard.vue'
import { navigationStore, objectStore } from '../../store/store.js'

export default {
	name: 'OrganisatieIndex',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		OfficeBuildingOutline,
		Plus,
		OrganisatieCard,
	},

	data() {
		return {
			loading: false,
		}
	},

	computed: {
		/**
		 * Get the list of organisations from the object store cache.
		 * @return {Array} Array of organisation objects.
		 */
		organisations() {
			const collection = objectStore.getCollection ? objectStore.getCollection('organisatie') : null
			return collection?.results || []
		},

		/**
		 * Icon component for organisation cards.
		 * Defined as computed (not data) to avoid Vue 2 deep-reactivity on the component definition.
		 * @return {object} Vue component options object.
		 */
		organisatieIcon() {
			return OfficeBuildingOutline
		},
	},

	async mounted() {
		await this.loadOrganisaties()
	},

	methods: {
		/**
		 * Fetch organisations from the backend via the object store.
		 * @return {Promise<void>}
		 */
		async loadOrganisaties() {
			this.loading = true
			try {
				if (!objectStore.settings) {
					await objectStore.fetchSettings?.()
				}
				await objectStore.fetchCollection?.('organisatie', { _limit: 100, _page: 1 })
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('[OrganisatieIndex] Failed to load organisations:', error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Trigger the create-organisation modal.
		 * @return {void}
		 */
		createOrganisatie() {
			navigationStore.setTransferData(null)
			navigationStore.setModal('organisatie')
		},

		/**
		 * Build the action list for a single organisation card.
		 * @param {object} item Organisation object.
		 * @return {Array} Action descriptors for OrganisatieCard.
		 */
		getActions(item) {
			return [
				{
					id: 'edit',
					label: this.t('softwarecatalog', 'Edit'),
					icon: Pencil,
					handler: (org) => {
						navigationStore.setTransferData(org)
						navigationStore.setModal('organisatie')
					},
				},
				{
					id: 'delete',
					label: this.t('softwarecatalog', 'Delete'),
					icon: Delete,
					handler: (org) => {
						navigationStore.setTransferData(org)
						navigationStore.setDialog('deleteObject', { objectType: 'organisatie' })
					},
				},
			]
		},
	},
}
</script>

<style scoped>
.organisatieIndexView {
	padding: 16px;
	min-height: 200px;
}

.organisatieIndexView__loading {
	display: flex;
	justify-content: center;
	padding: 60px 0;
}

.organisatieIndexView__toolbar {
	display: flex;
	justify-content: flex-end;
	margin-bottom: 16px;
}

.organisatieIndexView__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
	gap: 16px;
}

.organisatieIndexView__loadingMore {
	display: flex;
	justify-content: center;
	padding: 16px 0;
}
</style>
