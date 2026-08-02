<!--
  GemmaViewIndex.vue

  Displays GEMMA ArchiMate views with configurable gebruik and deelnames enrichment.
  The deelnames toggle is independent from the gebruik toggle and disabled by default.

  @spec openspec/changes/deelnames-gebruik/tasks.md#task-5
-->
<template>
	<div class="gemma-view-index">
		<!-- Filter panel with independent toggles -->
		<div class="gemma-view-index__filters">
			<h3 class="gemma-view-index__filters-title">
				{{ t('softwarecatalog', 'View filters') }}
			</h3>

			<div class="gemma-view-index__toggle-group">
				<NcCheckboxRadioSwitch
					:model-value="viewStore.includeGebruik"
					type="switch"
					@update:model-value="onGebruikToggle">
					{{ t('softwarecatalog', 'Gebruik') }}
				</NcCheckboxRadioSwitch>

				<!-- Deelnames toggle is independent from gebruik toggle per spec -->
				<NcCheckboxRadioSwitch
					:model-value="viewStore.includeDeelnamesGebruik"
					type="switch"
					@update:model-value="onDeelnamesToggle">
					{{ t('softwarecatalog', 'Deelnames') }}
				</NcCheckboxRadioSwitch>

				<!-- Product toggle is independent from the other toggles -->
				<NcCheckboxRadioSwitch
					:model-value="viewStore.includeProducts"
					type="switch"
					@update:model-value="onProductsToggle">
					{{ t('softwarecatalog', 'Products') }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<!-- Loading state -->
		<div v-if="viewStore.loading" class="gemma-view-index__loading">
			<NcLoadingIcon :size="32" />
			<p>{{ t('softwarecatalog', 'Loading views...') }}</p>
		</div>

		<!-- Error state -->
		<NcEmptyContent
			v-else-if="viewStore.error"
			:name="t('softwarecatalog', 'Could not load views')"
			:description="viewStore.error">
			<template #icon>
				<AlertCircleOutline />
			</template>
		</NcEmptyContent>

		<!-- Empty state -->
		<NcEmptyContent
			v-else-if="viewStore.views.length === 0"
			:name="t('softwarecatalog', 'No views found')"
			:description="t('softwarecatalog', 'No GEMMA views are available.')">
			<template #icon>
				<ViewDashboardOutline />
			</template>
		</NcEmptyContent>

		<!-- Views list -->
		<div v-else class="gemma-view-index__views">
			<div
				v-for="view in viewStore.views"
				:key="view.id"
				class="gemma-view-index__view-card">
				<h4 class="gemma-view-index__view-name">{{ view.name }}</h4>
				<p v-if="view.documentation" class="gemma-view-index__view-doc">
					{{ view.documentation }}
				</p>

				<!-- Deelnames summary per node when deelnames data is present -->
				<div
					v-if="viewStore.includeDeelnamesGebruik && hasDeelnamesData(view)"
					class="gemma-view-index__deelnames-notice">
					<span class="gemma-view-index__deelnames-badge">
						{{ t('softwarecatalog', 'Includes deelnames') }}
					</span>
				</div>

				<!-- Products summary per node when product data is present -->
				<div
					v-if="viewStore.includeProducts && hasProductsData(view)"
					class="gemma-view-index__products-notice">
					<span class="gemma-view-index__products-badge">
						{{ t('softwarecatalog', 'Includes products') }}
					</span>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import ViewDashboardOutline from 'vue-material-design-icons/ViewDashboardOutline.vue'
import { useViewStore } from '../../store/modules/view.js'

export default {
	name: 'GemmaViewIndex',

	components: {
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		AlertCircleOutline,
		ViewDashboardOutline,
	},

	setup() {
		const viewStore = useViewStore()
		return { viewStore }
	},

	mounted() {
		this.viewStore.fetchViews()
	},

	methods: {
		/**
		 * Handle gebruik toggle change.
		 *
		 * @param {boolean} value - New toggle state.
		 * @return {void}
		 */
		onGebruikToggle(value) {
			this.viewStore.setIncludeGebruik(value)
			this.viewStore.fetchViews()
		},

		/**
		 * Handle deelnames toggle change.
		 *
		 * Independent from the gebruik toggle — enabling/disabling one does not affect the other.
		 *
		 * @param {boolean} value - New toggle state.
		 * @return {void}
		 */
		onDeelnamesToggle(value) {
			this.viewStore.setIncludeDeelnamesGebruik(value)
			this.viewStore.fetchViews()
		},

		/**
		 * Handle products toggle change.
		 *
		 * Independent from the other toggles — enabling/disabling it does not affect them.
		 *
		 * @param {boolean} value - New toggle state.
		 * @return {void}
		 */
		onProductsToggle(value) {
			this.viewStore.setIncludeProducts(value)
			this.viewStore.fetchViews()
		},

		/**
		 * Check whether any node in the view has deelnames gebruik data.
		 *
		 * @param {object} view - View object from the API.
		 * @return {boolean} True when at least one node has deelnamesGebruik.
		 */
		hasDeelnamesData(view) {
			const nodes = view.viewNodes ?? []
			return nodes.some(node => Array.isArray(node.deelnamesGebruik) && node.deelnamesGebruik.length > 0)
		},

		/**
		 * Check whether any node in the view has linked product data.
		 *
		 * @param {object} view - View object from the API.
		 * @return {boolean} True when at least one node has products.
		 */
		hasProductsData(view) {
			const nodes = view.viewNodes ?? []
			return nodes.some(node => Array.isArray(node.products) && node.products.length > 0)
		},
	},
}
</script>

<style scoped>
.gemma-view-index {
	padding: var(--default-grid-baseline, 8px);
}

.gemma-view-index__filters {
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
	padding: var(--default-grid-baseline, 8px);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.gemma-view-index__filters-title {
	font-size: var(--font-size-normal);
	font-weight: bold;
	margin-bottom: var(--default-grid-baseline, 8px);
}

.gemma-view-index__toggle-group {
	display: flex;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	flex-wrap: wrap;
}

.gemma-view-index__loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: calc(var(--default-grid-baseline, 8px) * 4);
}

.gemma-view-index__views {
	display: grid;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
}

.gemma-view-index__view-card {
	padding: calc(var(--default-grid-baseline, 8px) * 2);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.gemma-view-index__view-name {
	font-size: var(--font-size-normal);
	font-weight: bold;
	margin-bottom: var(--default-grid-baseline, 8px);
}

.gemma-view-index__view-doc {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small);
}

.gemma-view-index__deelnames-notice {
	margin-top: var(--default-grid-baseline, 8px);
}

.gemma-view-index__deelnames-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
	font-size: var(--font-size-small);
}

.gemma-view-index__products-notice {
	margin-top: var(--default-grid-baseline, 8px);
}

.gemma-view-index__products-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
	font-size: var(--font-size-small);
}
</style>
