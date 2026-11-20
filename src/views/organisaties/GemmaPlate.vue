<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 GEMMA plate (board SkGemmaPlaat): the GEMMA reference components, grouped as
 the GEMMA model groups them, each with the applications one organisation uses
 for it and its state: covered, gap (fillable when an application already in
 use offers it) or overlap. A thin renderer over the bounded,
 organisation-scoped `GET /api/reference-component-coverage`.

 A picker chooses one imported GEMMA view (plate); the plate then draws that
 view's reference components in the view's own groups, read through
 `GET /api/views/{viewId}` with `include_gebruik`, and keeps the choice in
 the URL as `?view=<id>`. Without a chosen view it groups the whole model.

 The layer "What comparable organisations use" adds, from
 `GET /api/reference-component-coverage/peers`, the applications comparable
 organisations use per component with their counts, as a table and in the
 side panel; the endpoint never names an organisation.

 @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002

 @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-draws-the-gemma-view-the-user-picks-req-gpl-001
 @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-002-a-gemma-plate-shall-show-the-components-with-their-applications-with-filters-for-gaps-and-overlaps
-->
<template>
	<div class="gemma-plate">
		<header class="gemma-plate__header">
			<div>
				<h2 class="gemma-plate__title">
					{{ t('stackiq', 'GEMMA plate') }}
				</h2>
				<p class="gemma-plate__intro">
					{{
						t(
							'stackiq',
							'The GEMMA reference components with the applications an organisation uses for them.',
						)
					}}
				</p>
			</div>
			<NcButton
				v-if="coverage"
				variant="secondary"
				:href="csvUrl"
				data-testid="gemma-plate-download">
				<template #icon>
					<Download :size="20" />
				</template>
				{{ t('stackiq', 'Download') }}
			</NcButton>
		</header>

		<NcSelect
			v-model="selectedOrg"
			class="gemma-plate__org"
			:options="organisationOptions"
			:inputLabel="t('stackiq', 'Organisation')"
			:placeholder="t('stackiq', 'Select an organisation')"
			trackBy="uuid"
			label="label"
			@update:modelValue="loadCoverage" />

		<NcSelect
			v-model="selectedView"
			class="gemma-plate__org"
			:options="viewChoices"
			:inputLabel="t('stackiq', 'GEMMA view')"
			:placeholder="t('stackiq', 'The whole GEMMA model')"
			:loading="viewStore.loading"
			trackBy="id"
			label="label"
			data-testid="gemma-plate-view"
			@update:modelValue="pickView" />

		<NcNoteCard
			v-if="viewsLoaded && !viewChoices.length"
			type="info"
			data-testid="gemma-plate-no-views">
			{{ t('stackiq', 'Import the GEMMA model to pick a GEMMA view.') }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="44" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcEmptyContent
			v-else-if="!coverage"
			:name="t('stackiq', 'Select an organisation')"
			:description="
				t(
					'stackiq',
					'Pick an organisation above to see its applications on the GEMMA plate.',
				)
			" />

		<NcEmptyContent
			v-else-if="!coverage.components.length"
			:name="t('stackiq', 'No GEMMA model')"
			:description="t('stackiq', 'Import the GEMMA model to see coverage.')"
			data-testid="gemma-plate-no-model" />

		<template v-else>
			<NcNoteCard v-if="coverage.truncated" type="warning">
				{{
					t(
						'stackiq',
						'The plate covers the first applications in use of {total}.',
						{ total: coverage.total },
					)
				}}
			</NcNoteCard>

			<CnKpiGrid :columns="4" data-testid="gemma-plate-summary">
				<CnStatsBlock
					:title="t('stackiq', 'Reference components')"
					:count="coverage.summary.components"
					showZeroCount />
				<CnStatsBlock
					:title="t('stackiq', 'Covered')"
					:count="coverage.summary.covered"
					showZeroCount />
				<CnStatsBlock
					:title="t('stackiq', 'Gaps')"
					:count="coverage.summary.gap"
					:countLabel="
						t('stackiq', '{count} fillable with an application in use', {
							count: coverage.summary.fillable,
						})
					"
					showZeroCount />
				<CnStatsBlock
					:title="t('stackiq', 'Overlap')"
					:count="coverage.summary.overlap"
					showZeroCount />
			</CnKpiGrid>

			<p
				v-if="coverage.summary.usagesWithoutComponent"
				class="gemma-plate__hint">
				{{
					t(
						'stackiq',
						'{count} applications in use name no reference component, so they cover nothing on this plate.',
						{ count: coverage.summary.usagesWithoutComponent },
					)
				}}
			</p>

			<div
				class="gemma-plate__filters"
				role="group"
				:aria-label="t('stackiq', 'Show')">
				<NcButton
					v-for="option in filterOptions"
					:key="option.id"
					:variant="filter === option.id ? 'primary' : 'tertiary'"
					:pressed="filter === option.id"
					:data-testid="'gemma-plate-filter-' + option.id"
					@click="filter = option.id">
					{{ option.label }}
				</NcButton>
			</div>

			<fieldset class="gemma-plate__layers" data-testid="gemma-plate-layers">
				<legend>{{ t('stackiq', 'Layers') }}</legend>
				<NcCheckboxRadioSwitch
					v-model="showPeers"
					type="switch"
					data-testid="gemma-plate-layer-peers"
					@update:modelValue="togglePeers">
					{{ t('stackiq', 'What comparable organisations use') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<div class="gemma-plate__body">
				<div class="gemma-plate__plate" data-testid="gemma-plate-groups">
					<section
						v-for="group in groups"
						:key="group.name"
						class="gemma-plate__group">
						<h3 class="gemma-plate__group-name">
							{{ group.name }}
						</h3>
						<ul class="gemma-plate__components">
							<li
								v-for="component in group.components"
								:key="component.id">
								<button
									type="button"
									class="gemma-plate__component"
									:class="
										'gemma-plate__component--' + component.state
									"
									:aria-pressed="
										selected && selected.id === component.id
											? 'true'
											: 'false'
									"
									:data-state="component.state"
									@click="selected = component">
									<span class="gemma-plate__component-name">{{
										component.name
									}}</span>
									<span class="gemma-plate__component-apps">{{
										applicationNames(component)
									}}</span>
									<CnStatusBadge
										:label="stateLabel(component)"
										:variant="stateVariant(component.state)"
										size="small" />
								</button>
							</li>
						</ul>
					</section>
				</div>

				<aside
					v-if="selected"
					class="gemma-plate__detail"
					data-testid="gemma-plate-detail">
					<h3>{{ selected.name }}</h3>
					<p v-if="selected.documentation" class="gemma-plate__hint">
						{{ selected.documentation }}
					</p>
					<CnStatusBadge
						:label="stateLabel(selected)"
						:variant="stateVariant(selected.state)" />
					<p>{{ applicationNames(selected) }}</p>
					<p v-if="selected.fillable">
						{{
							t(
								'stackiq',
								'Applications in use that offer it: {names}',
								{ names: selected.fillableBy.join(', ') },
							)
						}}
					</p>
					<template v-if="showPeers && peers">
						<h4 class="gemma-plate__detail-heading">
							{{ t('stackiq', 'Comparable organisations use') }}
						</h4>
						<ul
							v-if="selectedPeers.length"
							class="gemma-plate__peers"
							data-testid="gemma-plate-detail-peers">
							<li
								v-for="application in selectedPeers"
								:key="application.moduleId">
								{{ peerLabel(application) }}
							</li>
						</ul>
						<p v-else class="gemma-plate__hint">
							{{ t('stackiq', 'None') }}
						</p>
					</template>
				</aside>
			</div>

			<NcLoadingIcon v-if="showPeers && peersLoading" :size="32" />
			<NcNoteCard v-else-if="showPeers && peersError" type="error">
				{{ peersError }}
			</NcNoteCard>
			<PeerUsageTable
				v-else-if="showPeers && peers"
				:components="plateComponents"
				:peers="peers" />
			<CoverageViewMap :components="coverage.components" />
		</template>
	</div>
</template>

<script>
import { CnKpiGrid, CnStatsBlock, CnStatusBadge } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import Download from 'vue-material-design-icons/Download.vue'
import PeerUsageTable from '../../components/gemma/PeerUsageTable.vue'
import CoverageViewMap from './CoverageViewMap.vue'
import { peerLabel } from '../../components/gemma/peerLabel.js'
import { useLiveCollections } from '../../composables/useLiveCollections.js'
import pinia from '../../pinia.js'
import { useViewStore } from '../../store/modules/view.js'
import { objectStore } from '../../store/store.js'
import { resolveUuid } from '../../utils/lifecyclePhase.js'
import { loadObjectTypeCollection } from '../../utils/objectTypes.js'
import { peerApplicationsOf, peerIndex } from '../../utils/peerUsage.js'
import {
	filterCoverage,
	filterGroups,
	groupCoverage,
	viewGroups,
	viewId,
	viewOptions,
} from '../../utils/referenceComponentCoverage.js'

/**
 * @class GemmaPlate
 * @module Views
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-002-a-gemma-plate-shall-show-the-components-with-their-applications-with-filters-for-gaps-and-overlaps
 */
export default {
	name: 'GemmaPlate',
	components: {
		CnKpiGrid,
		CnStatsBlock,
		CnStatusBadge,
		CoverageViewMap,
		Download,
		NcButton,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		PeerUsageTable,
	},

	/**
	 * The organisation picker reads the shared organisation collection; the
	 * coverage comes from the endpoint.
	 *
	 * @return {object} Nothing; the subscription is a side effect.
	 * @spec openspec/specs/realtime-updates-ui/spec.md
	 */
	setup() {
		useLiveCollections(objectStore, ['organization'])
		return { viewStore: useViewStore(pinia) }
	},

	data() {
		return {
			loading: false,
			error: null,
			selectedOrg: null,
			coverage: null,
			filter: 'all',
			selected: null,
			selectedView: null,
			viewsLoaded: false,
			showPeers: false,
			peers: null,
			peersLoading: false,
			peersError: null,
		}
	},

	computed: {
		/**
		 * Organisation options for the picker.
		 *
		 * @return {Array<{uuid: string, label: string}>} Options.
		 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-002-a-gemma-plate-shall-show-the-components-with-their-applications-with-filters-for-gaps-and-overlaps
		 */
		organisationOptions() {
			return (objectStore.getCollection('organization')?.results || []).map(
				(org) => {
					const data = org.object || org
					const uuid = resolveUuid(
						org.uuid ?? org.id ?? org['@self']?.id ?? org,
					)
					return { uuid, label: data.name || data.title || uuid }
				},
			)
		},

		/**
		 * The filter choices.
		 *
		 * @return {Array<{id: string, label: string}>} Options.
		 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-002-a-gemma-plate-shall-show-the-components-with-their-applications-with-filters-for-gaps-and-overlaps
		 */
		filterOptions() {
			return [
				{ id: 'all', label: t('stackiq', 'All') },
				{ id: 'gap', label: t('stackiq', 'Gaps') },
				{ id: 'fillable', label: t('stackiq', 'Fillable gaps') },
				{ id: 'overlap', label: t('stackiq', 'Overlap') },
			]
		},

		/**
		 * The filtered components in their GEMMA groups.
		 *
		 * @return {Array<{name: string, components: Array<object>}>} Groups.
		 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-002-a-gemma-plate-shall-show-the-components-with-their-applications-with-filters-for-gaps-and-overlaps
		 */
		groups() {
			if (this.shownView) {
				return filterGroups(
					viewGroups(
						this.shownView,
						this.coverage?.components || [],
						t('stackiq', 'Other'),
					),
					this.filter,
				)
			}
			return groupCoverage(
				filterCoverage(this.coverage?.components || [], this.filter),
				t('stackiq', 'Other'),
			)
		},

		/**
		 * The components on the plate, in plate order.
		 *
		 * @return {Array<object>} Coverage rows.
		 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
		 */
		plateComponents() {
			return this.groups.flatMap((group) => group.components)
		},

		/**
		 * What comparable organisations use for the selected component.
		 *
		 * @return {Array<object>} Peer applications.
		 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
		 */
		selectedPeers() {
			return this.selected
				? peerApplicationsOf(peerIndex(this.peers), this.selected)
				: []
		},

		/**
		 * The imported GEMMA views to pick from, each with its release.
		 *
		 * @return {Array<{id: string, label: string}>} Options.
		 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-draws-the-gemma-view-the-user-picks-req-gpl-001
		 */
		viewChoices() {
			return viewOptions(this.viewStore?.views)
		},

		/**
		 * The fetched view the plate draws, once it is the chosen one.
		 *
		 * @return {object|null} The view.
		 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-draws-the-gemma-view-the-user-picks-req-gpl-001
		 */
		shownView() {
			const active = this.viewStore?.activeView
			if (!this.selectedView || !active) {
				return null
			}
			return viewId(active) === this.selectedView.id ? active : null
		},

		/**
		 * The same coverage as CSV.
		 *
		 * @return {string} The download URL.
		 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-001-stackiq-shall-give-each-reference-component-a-coverage-state-for-one-organisation
		 */
		csvUrl() {
			const organisation = encodeURIComponent(this.selectedOrg?.uuid || '')
			return (
				generateUrl('/apps/stackiq/api/reference-component-coverage')
				+ `?organisation=${organisation}&format=csv`
			)
		},
	},

	watch: {
		/**
		 * With one organisation to choose from, show it straight away.
		 *
		 * @param {Array<object>} options The organisation options.
		 * @return {void}
		 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-002-a-gemma-plate-shall-show-the-components-with-their-applications-with-filters-for-gaps-and-overlaps
		 */
		organisationOptions: {
			immediate: true,
			handler(options) {
				if (!this.selectedOrg && options.length === 1) {
					this.selectedOrg = options[0]
					this.loadCoverage()
				}
			},
		},
	},

	/**
	 * Load the views to pick from and open the view the URL names.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-draws-the-gemma-view-the-user-picks-req-gpl-001
	 */
	async created() {
		this.viewStore.setIncludeGebruik(true)
		await this.viewStore.fetchViews()
		this.viewsLoaded = true
		const wanted = this.routeView()
		const option = this.viewChoices.find((choice) => choice.id === wanted)
		if (option) {
			this.selectedView = option
			await this.viewStore.fetchView(option.id)
		}
	},

	/**
	 * Load the organisations the picker offers.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-002-a-gemma-plate-shall-show-the-components-with-their-applications-with-filters-for-gaps-and-overlaps
	 */
	async mounted() {
		await loadObjectTypeCollection('organization')
	},

	methods: {
		t,
		peerLabel,

		/**
		 * Load what comparable organisations use when the layer is switched on.
		 *
		 * @param {boolean} on Whether the layer is on.
		 * @return {Promise<void>}
		 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
		 */
		async togglePeers(on) {
			if (on && !this.peers) {
				await this.loadPeers()
			}
		},

		/**
		 * Load what comparable organisations use, for the selected organisation.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
		 */
		async loadPeers() {
			this.peers = null
			this.peersError = null
			if (!this.selectedOrg?.uuid) {
				return
			}
			this.peersLoading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/stackiq/api/reference-component-coverage/peers'),
					{ params: { organisation: this.selectedOrg.uuid } },
				)
				this.peers = response.data
			} catch (error) {
				this.peersError =
					error?.response?.data?.message
					|| error?.response?.data?.error
					|| t('stackiq', 'What comparable organisations use could not be loaded.')
			} finally {
				this.peersLoading = false
			}
		},

		/**
		 * The view id in the URL, or ''.
		 *
		 * @return {string} The id.
		 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-draws-the-gemma-view-the-user-picks-req-gpl-001
		 */
		routeView() {
			const fromRoute = this.$route?.query?.view
			if (typeof fromRoute === 'string') {
				return fromRoute
			}
			return new URLSearchParams(window.location.search).get('view') || ''
		},

		/**
		 * Draw the picked view and name it in the URL; no pick draws the whole model.
		 *
		 * @param {{id: string}|null} option The picked view.
		 * @return {Promise<void>}
		 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-draws-the-gemma-view-the-user-picks-req-gpl-001
		 */
		async pickView(option) {
			this.selected = null
			this.writeRouteView(option?.id || '')
			if (option?.id) {
				await this.viewStore.fetchView(option.id)
			}
		},

		/**
		 * Keep the chosen view in the URL.
		 *
		 * @param {string} id The view id, '' to drop it.
		 * @return {void}
		 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-draws-the-gemma-view-the-user-picks-req-gpl-001
		 */
		writeRouteView(id) {
			if (this.$router && this.$route) {
				const query = { ...this.$route.query }
				if (id) {
					query.view = id
				} else {
					delete query.view
				}
				this.$router.replace({ query }).catch(() => {
					// NavigationDuplicated: the same query, nothing to do.
				})
				return
			}
			const url = new URL(window.location.href)
			if (id) {
				url.searchParams.set('view', id)
			} else {
				url.searchParams.delete('view')
			}
			window.history.replaceState(window.history.state, '', url.toString())
		},

		/**
		 * Load the coverage of the selected organisation.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-002-a-gemma-plate-shall-show-the-components-with-their-applications-with-filters-for-gaps-and-overlaps
		 */
		async loadCoverage() {
			this.selected = null
			this.peers = null
			this.peersError = null
			if (this.showPeers) {
				this.loadPeers()
			}
			if (!this.selectedOrg?.uuid) {
				this.coverage = null
				return
			}
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					generateUrl('/apps/stackiq/api/reference-component-coverage'),
					{
						params: { organisation: this.selectedOrg.uuid },
					},
				)
				this.coverage = response.data
			} catch (error) {
				this.coverage = null
				this.error =
					error?.response?.data?.message
					|| error?.response?.data?.error
					|| t('stackiq', 'The coverage could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The applications covering a component, or that it has none.
		 *
		 * @param {object} component A coverage row.
		 * @return {string} The names.
		 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-002-a-gemma-plate-shall-show-the-components-with-their-applications-with-filters-for-gaps-and-overlaps
		 */
		applicationNames(component) {
			const names = component.applications
				.map((app) => app.moduleName)
				.filter(Boolean)
			return names.length ? names.join(', ') : t('stackiq', 'No application')
		},

		/**
		 * The state in words, so colour is never the only signal.
		 *
		 * @param {object} component A coverage row.
		 * @return {string} The label.
		 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-002-a-gemma-plate-shall-show-the-components-with-their-applications-with-filters-for-gaps-and-overlaps
		 */
		stateLabel(component) {
			if (component.state === 'overlap') {
				return t('stackiq', 'Overlap')
			}
			if (component.state === 'covered') {
				return t('stackiq', 'Covered')
			}
			return component.fillable
				? t('stackiq', 'Gap, fillable')
				: t('stackiq', 'Gap')
		},

		/**
		 * The badge colour of a state.
		 *
		 * @param {string} state gap, covered or overlap.
		 * @return {string} A CnStatusBadge variant.
		 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-002-a-gemma-plate-shall-show-the-components-with-their-applications-with-filters-for-gaps-and-overlaps
		 */
		stateVariant(state) {
			return (
				{ gap: 'error', covered: 'success', overlap: 'warning' }[state]
				|| 'default'
			)
		},
	},
}
</script>

<style scoped>
.gemma-plate {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 4);
}

.gemma-plate__header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline, 4px) * 4);
}

.gemma-plate__title {
	margin: 0;
}

.gemma-plate__intro,
.gemma-plate__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.gemma-plate__org {
	max-width: 400px;
}

.gemma-plate__filters {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline, 4px);
}

.gemma-plate__layers {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	margin: 0;
	padding: 0;
	border: none;
}

.gemma-plate__layers legend {
	font-weight: 600;
}

.gemma-plate__detail-heading {
	margin: 0;
	font-size: 0.95rem;
}

.gemma-plate__peers {
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline, 4px) * 5);
}

.gemma-plate__body {
	display: grid;
	grid-template-columns: minmax(0, 1fr) auto;
	gap: calc(var(--default-grid-baseline, 4px) * 4);
}

.gemma-plate__plate {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 4);
}

.gemma-plate__group-name {
	margin: 0 0 calc(var(--default-grid-baseline, 4px) * 2);
	font-size: 1rem;
}

.gemma-plate__components {
	list-style: none;
	margin: 0;
	padding: 0;
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.gemma-plate__component {
	width: 100%;
	height: 100%;
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: var(--default-grid-baseline, 4px);
	padding: calc(var(--default-grid-baseline, 4px) * 3);
	text-align: start;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-inline-start: 4px solid var(--color-border-dark);
	border-radius: var(--border-radius-large);
	cursor: pointer;
}

.gemma-plate__component--covered {
	border-inline-start-color: var(--color-success);
}

.gemma-plate__component--gap {
	border-inline-start-color: var(--color-error);
}

.gemma-plate__component--overlap {
	border-inline-start-color: var(--color-warning);
}

.gemma-plate__component[aria-pressed='true'] {
	outline: 2px solid var(--color-primary-element);
}

.gemma-plate__component-name {
	font-weight: 600;
}

.gemma-plate__component-apps {
	color: var(--color-text-maxcontrast);
}

.gemma-plate__detail {
	width: 320px;
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.gemma-plate__detail h3 {
	margin: 0;
}

@media (max-width: 1024px) {
	.gemma-plate__body {
		grid-template-columns: minmax(0, 1fr);
	}

	.gemma-plate__detail {
		width: auto;
	}
}
</style>
