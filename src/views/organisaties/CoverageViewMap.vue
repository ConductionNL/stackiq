<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 Coverage map: one imported GEMMA view, drawn read-only on the shared graph
 canvas, with every node of a reference component carrying its coverage
 state as a border colour and, so colour is never the only signal, as a text
 badge with the number and names of its applications.

 With "Show products" on, the view is fetched with `include_products=true`
 and each node also lists the products (diensten) linked to its element.

 @spec openspec/specs/reference-component-coverage/spec.md#requirement-req-rcc-003-the-coverage-shall-be-drawn-on-a-gemma-view-as-a-map
 @spec openspec/changes/archive/2026-11-24-view-products-enrichment/specs/view-enrichment-api/spec.md#requirement-product-filter-enrichment-must-return-real-product-data
-->
<template>
	<section class="coverage-view-map" data-testid="coverage-view-map">
		<h3 class="coverage-view-map__title">
			{{ t('stackiq', 'Coverage on a GEMMA view') }}
		</h3>

		<NcSelect
			v-model="selectedView"
			class="coverage-view-map__picker"
			:options="options"
			:inputLabel="t('stackiq', 'GEMMA view')"
			:placeholder="t('stackiq', 'Select a view')"
			trackBy="id"
			label="label"
			data-testid="coverage-view-map-picker"
			@update:modelValue="loadView" />

		<NcCheckboxRadioSwitch
			v-model="showProducts"
			type="switch"
			:disabled="!selectedView"
			data-testid="coverage-view-map-products"
			@update:modelValue="loadView">
			{{ t('stackiq', 'Show products') }}
		</NcCheckboxRadioSwitch>

		<NcLoadingIcon v-if="loading" :size="44" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcEmptyContent
			v-else-if="!graph"
			:name="t('stackiq', 'Select a view')"
			:description="t('stackiq', 'Pick a GEMMA view to see the coverage drawn on it.')" />

		<NcEmptyContent
			v-else-if="!graph.nodes.length"
			:name="t('stackiq', 'Empty view')"
			:description="t('stackiq', 'This view holds no elements.')" />

		<CnGraphCanvas
			v-else
			class="coverage-view-map__canvas"
			:nodes="graph.nodes"
			:edges="graph.edges"
			:readOnly="true"
			:showMiniMap="false">
			<template #node="{ node }">
				<div
					class="coverage-view-map__node"
					:class="nodeClass(node.data.component)"
					:data-state="node.data.component ? node.data.component.state : null">
					<strong>{{ node.data.name }}</strong>
					<template v-if="node.data.component">
						<CnStatusBadge
							:label="stateLabel(node.data.component)"
							:variant="stateVariant(node.data.component.state)"
							size="small" />
						<span class="coverage-view-map__apps">{{ applicationsText(node.data.component) }}</span>
					</template>
					<span
						v-if="showProducts && node.data.products"
						class="coverage-view-map__products"
						data-testid="coverage-view-map-node-products">
						{{ productsText(node.data.products) }}
					</span>
				</div>
			</template>
		</CnGraphCanvas>
	</section>
</template>

<script>
import { CnGraphCanvas, CnStatusBadge } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcCheckboxRadioSwitch, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { coverageMapGraph, viewOptions } from '../../utils/referenceComponentCoverage.js'

/**
 * A GEMMA view with the coverage of one organisation drawn on it (REQ-RCC-003).
 *
 * @class CoverageViewMap
 * @module Views
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * @spec openspec/specs/reference-component-coverage/spec.md#requirement-req-rcc-003-the-coverage-shall-be-drawn-on-a-gemma-view-as-a-map
 */
export default {
	name: 'CoverageViewMap',
	components: {
		CnGraphCanvas,
		CnStatusBadge,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	props: {
		/** The coverage rows of the organisation, from the coverage endpoint. */
		components: {
			type: Array,
			required: true,
		},
	},

	data() {
		return {
			views: [],
			selectedView: null,
			showProducts: false,
			view: null,
			loading: false,
			error: null,
		}
	},

	computed: {
		/**
		 * The GEMMA views to pick from.
		 *
		 * @return {Array<{id: string, label: string}>} Options.
		 * @spec openspec/specs/reference-component-coverage/spec.md#requirement-req-rcc-003-the-coverage-shall-be-drawn-on-a-gemma-view-as-a-map
		 */
		options() {
			return viewOptions(this.views)
		},

		/**
		 * The picked view as canvas nodes and edges, with the coverage on its nodes.
		 *
		 * @return {{nodes: Array<object>, edges: Array<object>}|null} The graph, or null before a view is loaded.
		 * @spec openspec/specs/reference-component-coverage/spec.md#requirement-req-rcc-003-the-coverage-shall-be-drawn-on-a-gemma-view-as-a-map
		 */
		graph() {
			if (!this.view) {
				return null
			}
			return coverageMapGraph(this.view.viewNodes || [], this.view.viewRelationships || [], this.components)
		},
	},

	mounted() {
		this.loadViews()
	},

	methods: {
		t,

		/**
		 * Load the GEMMA views to pick from.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/reference-component-coverage/spec.md#requirement-req-rcc-003-the-coverage-shall-be-drawn-on-a-gemma-view-as-a-map
		 */
		async loadViews() {
			try {
				const response = await axios.get(generateUrl('/apps/stackiq/api/views'))
				this.views = response.data?.views || []
			} catch {
				this.views = []
				this.error = t('stackiq', 'The GEMMA views could not be loaded.')
			}
		},

		/**
		 * Load the picked view.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/reference-component-coverage/spec.md#requirement-req-rcc-003-the-coverage-shall-be-drawn-on-a-gemma-view-as-a-map
		 */
		async loadView() {
			this.view = null
			this.error = null
			if (!this.selectedView?.id) {
				return
			}
			this.loading = true
			try {
				// Only a switched-on filter adds its enrichment parameter.
				const params = this.showProducts ? { include_products: true } : {}
				const response = await axios.get(
					generateUrl('/apps/stackiq/api/views/{viewId}', { viewId: this.selectedView.id }),
					{ params },
				)
				this.view = response.data?.view || null
				if (!this.view) {
					this.error = t('stackiq', 'The view could not be loaded.')
				}
			} catch (error) {
				this.error = error?.response?.data?.error || t('stackiq', 'The view could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The border class of a node: its state, or none for an element that is no reference component.
		 *
		 * @param {object|null} component The node's coverage row.
		 * @return {string} The class.
		 * @spec openspec/specs/reference-component-coverage/spec.md#requirement-req-rcc-003-the-coverage-shall-be-drawn-on-a-gemma-view-as-a-map
		 */
		nodeClass(component) {
			return component ? `coverage-view-map__node--${component.state}` : ''
		},

		/**
		 * The state in words.
		 *
		 * @param {object} component A coverage row.
		 * @return {string} The label.
		 * @spec openspec/specs/reference-component-coverage/spec.md#requirement-req-rcc-003-the-coverage-shall-be-drawn-on-a-gemma-view-as-a-map
		 */
		stateLabel(component) {
			if (component.state === 'overlap') {
				return t('stackiq', 'Overlap')
			}
			if (component.state === 'covered') {
				return t('stackiq', 'Covered')
			}
			return t('stackiq', 'Gap')
		},

		/**
		 * The badge variant of a state.
		 *
		 * @param {string} state gap, covered or overlap.
		 * @return {string} The variant.
		 * @spec openspec/specs/reference-component-coverage/spec.md#requirement-req-rcc-003-the-coverage-shall-be-drawn-on-a-gemma-view-as-a-map
		 */
		stateVariant(state) {
			if (state === 'overlap') {
				return 'warning'
			}
			if (state === 'covered') {
				return 'success'
			}
			return 'error'
		},

		/**
		 * The number and names of a component's applications.
		 *
		 * @param {object} component A coverage row.
		 * @return {string} The text.
		 * @spec openspec/specs/reference-component-coverage/spec.md#requirement-req-rcc-003-the-coverage-shall-be-drawn-on-a-gemma-view-as-a-map
		 */
		applicationsText(component) {
			const names = (component.applications || []).map((app) => app.moduleName).filter(Boolean)
			if (!names.length) {
				return t('stackiq', 'No application')
			}
			return t('stackiq', 'Applications ({count}): {names}', {
				count: names.length,
				names: names.join(', '),
			})
		},

		/**
		 * The number and names of the products linked to a node's element.
		 *
		 * @param {Array<object>} products The node's products.
		 * @return {string} The text.
		 * @spec openspec/changes/archive/2026-11-24-view-products-enrichment/specs/view-enrichment-api/spec.md#requirement-product-filter-enrichment-must-return-real-product-data
		 */
		productsText(products) {
			const names = products.map((product) => product?.name || product?.title || '').filter(Boolean)
			if (!products.length) {
				return t('stackiq', 'No product')
			}
			return t('stackiq', 'Products ({count}): {names}', {
				count: products.length,
				names: names.join(', '),
			})
		},
	},
}
</script>

<style scoped>
.coverage-view-map {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 24px;
}

.coverage-view-map__title {
	margin: 0;
}

.coverage-view-map__picker {
	max-width: 480px;
}

.coverage-view-map__canvas {
	height: 600px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.coverage-view-map__node {
	display: flex;
	flex-direction: column;
	gap: 4px;
	height: 100%;
	padding: 4px 8px;
	box-sizing: border-box;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	overflow: hidden;
}

.coverage-view-map__node--gap {
	border-color: var(--color-error);
}

.coverage-view-map__node--covered {
	border-color: var(--color-success);
}

.coverage-view-map__node--overlap {
	border-color: var(--color-warning);
}

.coverage-view-map__apps,
.coverage-view-map__products {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}
</style>
