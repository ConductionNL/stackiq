<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 Portfolio rationalization report — Gartner TIME quadrant view.

 Unlike LifecycleRoadmapView / LicensePostureView (which derive their figures
 client-side over full collections fetched into the browser), this page reads
 a single composed, bounded, organisation-scoped backend endpoint
 (`GET /api/portfolio-report`) — the report aggregation itself, including
 RBAC scoping, lives server-side in PortfolioReportService per design.md
 Decision 3/4. The page is a thin renderer over that response: a TIME
 quadrant chart (apexcharts via CnChartWidget), a per-quadrant summary table
 (EOL exposure / cloud-transition share / annualised cost), a gebruik-level
 detail table grouped by quadrant (Unclassified always visible, never
 omitted), a truncation banner, and a CSV export button that hits the same
 endpoint's `?format=csv` variant.

 @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-portfolio-rationalization-report-aggregates-per-organisation
-->
<template>
	<div class="portfolioReportView">
		<div class="pr-header">
			<h2 class="pr-title">
				{{ t('stackiq', 'Portfolio rationalization') }}
			</h2>
			<p class="pr-intro">
				{{
					t(
						'stackiq',
						"TIME classification (Tolerate / Invest / Migrate / Eliminate) of an organisation's applications in use, combined with end-of-support exposure, cloud-transition share, and annualised contract cost.",
					)
				}}
			</p>
			<div class="pr-actions">
				<NcButton
					variant="tertiary"
					:disabled="!selectedOrg || loading"
					:aria-label="t('stackiq', 'Refresh report')"
					@click="loadReport">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<Refresh v-else :size="20" />
					</template>
					{{ t('stackiq', 'Refresh') }}
				</NcButton>
				<NcButton
					variant="secondary"
					:disabled="!selectedOrg || loading"
					:aria-label="t('stackiq', 'Export CSV')"
					@click="exportCsv">
					<template #icon>
						<Download :size="20" />
					</template>
					{{ t('stackiq', 'Export CSV') }}
				</NcButton>
			</div>
		</div>

		<div class="pr-filters">
			<NcSelect
				v-model="selectedOrg"
				class="pr-orgSelect"
				:options="organisationOptions"
				:inputLabel="t('stackiq', 'Organisation')"
				:placeholder="t('stackiq', 'Select an organisation')"
				trackBy="uuid"
				label="label"
				@update:modelValue="loadReport" />
		</div>

		<NcEmptyContent
			v-if="!loading && !selectedOrg"
			:name="t('stackiq', 'Select an organisation')"
			:description="
				t(
					'stackiq',
					'Pick an organisation above to render its portfolio rationalization report.',
				)
			">
			<template #icon>
				<ChartBoxOutline :size="40" />
			</template>
		</NcEmptyContent>

		<NcNoteCard v-if="error" type="error" class="pr-error">
			{{ error }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="40" class="pr-loading" />

		<template v-else-if="selectedOrg && report">
			<NcNoteCard
				v-if="report.truncated"
				type="warning"
				class="pr-truncated"
				data-testid="pr-truncated">
				{{
					t(
						'stackiq',
						'Showing the first {shown} of {total} applications in use for this organisation — the report is bounded to protect performance.',
						{
							shown: report.includedGebruiken,
							total: report.totalGebruiken,
						},
					)
				}}
			</NcNoteCard>

			<!-- TIME quadrant chart -->
			<section class="pr-section" data-testid="pr-chart">
				<h3 class="pr-sectionTitle">
					{{ t('stackiq', 'TIME quadrant counts') }}
				</h3>
				<CnChartWidget
					type="bar"
					:series="chartSeries"
					:categories="chartCategories"
					:colorMap="chartColorMap"
					:legend="false"
					:height="260" />
			</section>

			<!-- Per-quadrant omschrijving: EOL exposure, cloud-transition share, cost overlay -->
			<section class="pr-section" data-testid="pr-summary">
				<h3 class="pr-sectionTitle">
					{{ t('stackiq', 'Quadrant summary') }}
				</h3>
				<table class="pr-table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('stackiq', 'Quadrant') }}
							</th>
							<th scope="col">{{ t('stackiq', 'Count') }}</th>
							<th scope="col">
								{{ t('stackiq', 'EOL exposed') }}
							</th>
							<th scope="col">
								{{ t('stackiq', 'Cloud-transition share') }}
							</th>
							<th scope="col">
								{{ t('stackiq', 'Annualised cost') }}
							</th>
							<th scope="col">
								{{ t('stackiq', 'One-off cost') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="row in quadrantSummary"
							:key="row.key"
							data-testid="pr-summary-row">
							<td>
								<span
									class="pr-badge"
									:style="{
										backgroundColor: quadrantColor(row.key),
									}">
									{{ quadrantLabel(row.key) }}
								</span>
							</td>
							<td>{{ row.count }}</td>
							<td>{{ row.eolExposedCount }}</td>
							<td>{{ row.cloudTransitionLabel }}</td>
							<td>{{ formatCurrency(row.annualisedCost) }}</td>
							<td>{{ formatCurrency(row.oneOffCost) }}</td>
						</tr>
					</tbody>
				</table>
			</section>

			<!-- Gebruik-level rows, grouped by quadrant (Unclassified visible, not hidden) -->
			<section class="pr-section" data-testid="pr-rows">
				<h3 class="pr-sectionTitle">
					{{ t('stackiq', 'Applications in use') }}
				</h3>
				<div v-for="group in groupedRows" :key="group.key" class="pr-group">
					<h4 class="pr-groupTitle">
						<span
							class="pr-badge"
							:style="{ backgroundColor: quadrantColor(group.key) }">
							{{ quadrantLabel(group.key) }}
						</span>
						<span class="pr-count">({{ group.rows.length }})</span>
					</h4>
					<NcEmptyContent
						v-if="group.rows.length === 0"
						:name="t('stackiq', 'No applications in this quadrant')" />
					<table v-else class="pr-table">
						<thead>
							<tr>
								<th scope="col">
									{{ t('stackiq', 'Application') }}
								</th>
								<th scope="col">
									{{ t('stackiq', 'Rationale') }}
								</th>
								<th scope="col">
									{{ t('stackiq', 'Review date') }}
								</th>
								<th scope="col">
									{{ t('stackiq', 'Lifecycle phase') }}
								</th>
								<th scope="col">
									{{ t('stackiq', 'EOL status') }}
								</th>
								<th scope="col">
									{{ t('stackiq', 'Hosting model') }}
								</th>
								<th scope="col">
									{{ t('stackiq', 'Annualised cost') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr
								v-for="row in group.rows"
								:key="row.uuid"
								data-testid="pr-row">
								<td>{{ row.moduleName }}</td>
								<td>{{ row.timeRationale || '—' }}</td>
								<td>{{ row.timeReviewDate || '—' }}</td>
								<td>{{ row.lifecyclePhase }}</td>
								<td>
									<span
										v-if="row.eol.passed"
										class="pr-eol pr-eol--passed"
										>{{ t('stackiq', 'Passed') }}</span
									>
									<span
										v-else-if="row.eolApproaching"
										class="pr-eol pr-eol--approaching"
										>{{ t('stackiq', 'Approaching') }}</span
									>
									<span v-else class="pr-eol pr-eol--ok">{{
										t('stackiq', 'OK')
									}}</span>
								</td>
								<td>
									{{
										row.hostingModel.length
											? row.hostingModel.join(', ')
											: '—'
									}}
								</td>
								<td>{{ formatCurrency(row.annualisedCost) }}</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>
		</template>
	</div>
</template>

<script>
import { CnChartWidget } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import ChartBoxOutline from 'vue-material-design-icons/ChartBoxOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import { useLiveCollections } from '../../composables/useLiveCollections.js'
import { objectStore } from '../../store/store.js'
import { resolveUuid } from '../../utils/lifecyclePhase.js'
import {
	buildCsvExportUrl,
	cloudTransitionLabel,
	formatCurrency,
	groupRowsByQuadrant,
	QUADRANT_ORDER,
	quadrantColor,
} from '../../utils/portfolioReport.js'

/**
 * @class PortfolioReport
 * @module Views
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * Gartner TIME portfolio rationalization report: renders the bounded,
 * organisation-scoped `PortfolioReportService` aggregate (TIME quadrant
 * counts, EOL exposure, cloud-transition share, annualised cost) as a
 * quadrant chart + summary table + per-quadrant application rows, with a
 * CSV export of the same scope.
 *
 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md
 */
export default {
	name: 'PortfolioReport',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcEmptyContent,
		NcNoteCard,
		CnChartWidget,
		Refresh,
		Download,
		ChartBoxOutline,
	},

	/**
	 * Only the organisation picker reads the shared object-store collection
	 * (an org list the user is already authorised to see); the report figures
	 * themselves come from the bounded, RBAC-scoped backend endpoint, not the
	 * store, so no live-collection subscription is needed for report data.
	 *
	 * @return {object} Empty — the subscription is side-effect only
	 * @spec openspec/specs/realtime-updates-ui/spec.md
	 */
	setup() {
		useLiveCollections(objectStore, ['organization'])
		return {}
	},

	data() {
		return {
			loading: false,
			error: null,
			selectedOrg: null,
			report: null,
		}
	},

	computed: {
		/**
		 * Organisation options for the selector.
		 *
		 * @return {Array<{uuid: string, label: string}>} Options.
		 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-portfolio-rationalization-report-aggregates-per-organisation
		 */
		organisationOptions() {
			return (objectStore.getCollection('organization')?.results || []).map(
				(org) => {
					const data = org.object || org
					return {
						uuid: resolveUuid(
							org.uuid ?? org.id ?? org['@self']?.id ?? org,
						),
						label:
							data.name
							|| data.title
							|| resolveUuid(org.uuid ?? org.id ?? ''),
					}
				},
			)
		},

		/**
		 * Per-quadrant summary rows, in canonical TIME order.
		 *
		 * @return {Array<object>} Summary rows.
		 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-portfolio-rationalization-report-aggregates-per-organisation
		 */
		quadrantSummary() {
			if (!this.report) {
				return []
			}
			return QUADRANT_ORDER.map((key) => {
				const q = this.report.quadrants[key] || {
					count: 0,
					eolExposedCount: 0,
					cloudTransition: {},
					annualisedCost: 0,
					oneOffCost: 0,
				}
				return {
					key,
					count: q.count,
					eolExposedCount: q.eolExposedCount,
					cloudTransitionLabel: cloudTransitionLabel(q.cloudTransition),
					annualisedCost: q.annualisedCost,
					oneOffCost: q.oneOffCost,
				}
			})
		},

		/**
		 * Quadrant counts as ApexCharts bar series (one series, one value per category).
		 *
		 * @return {Array<{name: string, data: number[]}>} Chart series.
		 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-portfolio-rationalization-report-aggregates-per-organisation
		 */
		chartSeries() {
			return [
				{
					name: t('stackiq', 'Applications'),
					data: this.quadrantSummary.map((row) => row.count),
				},
			]
		},

		/**
		 * Quadrant chart x-axis categories (translated labels).
		 *
		 * @return {Array<string>} Categories.
		 * @spec openspec/specs/portfolio-rationalization-time/spec.md#requirement-portfolio-rationalization-report-aggregates-per-organisation
		 */
		chartCategories() {
			return this.quadrantSummary.map((row) => this.quadrantLabel(row.key))
		},

		/**
		 * Per-category colour map keyed by the translated label (matches
		 * CnChartWidget's `colorMap` contract, which is keyed by the resolved
		 * category label, not the raw quadrant key).
		 *
		 * @return {Record<string, string>} Label → CSS colour.
		 * @spec openspec/specs/portfolio-rationalization-time/spec.md#requirement-portfolio-rationalization-report-aggregates-per-organisation
		 */
		chartColorMap() {
			const map = {}
			for (const key of QUADRANT_ORDER) {
				map[this.quadrantLabel(key)] = quadrantColor(key)
			}
			return map
		},

		/**
		 * Report rows grouped by quadrant, in canonical TIME order —
		 * Unclassified always rendered, even when empty. Delegates to the
		 * pure `groupRowsByQuadrant()` util (vitest-covered).
		 *
		 * @return {Array<{key: string, rows: Array}>} Grouped rows.
		 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-portfolio-rationalization-report-aggregates-per-organisation
		 */
		groupedRows() {
			if (!this.report) {
				return []
			}
			return groupRowsByQuadrant(this.report.rows || [])
		},
	},

	async mounted() {
		await this.loadOrganisations()
	},

	methods: {
		t,

		/**
		 * Load the organisation collection the picker depends on.
		 *
		 * `getSchemaConfig('organization')` cannot be used here: it only
		 * resolves a type from the `voorzieningenConfig` blob when that
		 * blob's `<type>_schema` key holds a NUMERIC schema id, and
		 * `organisatie_schema` is unset on instances where the voorzieningen
		 * auto-configure flow never wrote it (it only ever populates
		 * `module`/`compliancy`/`moduleVersie`/`sbomComponent` — see
		 * `SettingsService::normalizeVoorzieningenConfig()`). That is exactly
		 * why the picker rendered "No results" despite the register holding
		 * real `Gemeente`/`Samenwerking`/supplier organisations: the schema
		 * never got registered, so `fetchCollection()` threw
		 * "Object type ... is not registered" before any request was even
		 * sent.
		 *
		 * The manifest-driven `Organisaties` index page (`src/manifest.json`
		 * `pages[].config` for route `/organisaties`) never hits this gap: the
		 * shared library's self-fetch path
		 * (`node_modules/@conduction/nextcloud-vue/src/components/CnIndexPage/useSelfFetchList.js`)
		 * calls `registerObjectType(type, props.schema, props.register, ...)`
		 * using the SCHEMA SLUG itself (`'organization'`) as the id — which
		 * OpenRegister's `/api/objects/{register}/{schemaSlugOrId}` accepts
		 * interchangeably with a numeric id — rather than resolving a numeric
		 * schema id from a config blob first. This mirrors that proven path:
		 * register the slug directly against the voorzieningen register id
		 * (which IS reliably populated — `voorzieningenConfig.register`),
		 * with no dependency on `organisatie_schema` ever being set.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/portfolio-rationalization-time/spec.md#requirement-report-and-csv-export-are-scoped-to-the-requesters-authorised-organisations
		 */
		async loadOrganisations() {
			try {
				if (
					!objectStore.settings
					&& typeof objectStore.fetchSettings === 'function'
				) {
					await objectStore.fetchSettings()
				}
				const voorzieningenConfig =
					objectStore.settings?.voorzieningen
					|| objectStore.settings?.voorzieningenConfig
					|| {}
				const registerId = voorzieningenConfig.register
				if (
					typeof objectStore.registerObjectType === 'function'
					&& registerId
					&& !objectStore.objectTypeRegistry?.organization
				) {
					objectStore.registerObjectType(
						'organization',
						'organization',
						registerId,
						{
							registerSlug: 'stackiq',
							schemaSlug: 'organization',
						},
					)
				}
				if (typeof objectStore.fetchCollection === 'function') {
					await objectStore.fetchCollection('organization', {
						_limit: 1000,
					})
				}
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('PortfolioReport: failed to load organisations', error)
			}
		},

		/**
		 * Fetch the portfolio report for the selected organisation from the
		 * bounded, RBAC-scoped backend endpoint. A denied (403) or failed
		 * request surfaces its message rather than falling back to any
		 * client-side re-derivation — per design.md Decision 4, this report
		 * has exactly one data path.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-report-and-csv-export-are-scoped-to-the-requesters-authorised-organisations
		 */
		async loadReport() {
			if (!this.selectedOrg) {
				this.report = null
				return
			}
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					generateUrl('/apps/stackiq/api/portfolio-report'),
					{
						params: { organisation: this.selectedOrg.uuid },
					},
				)
				this.report = response.data
			} catch (error) {
				this.report = null
				this.error =
					error?.response?.data?.message
					|| t('stackiq', 'Failed to load the portfolio report.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Trigger the CSV export of the same bounded, organisation-scoped
		 * row set the on-screen report renders. URL-building is delegated to
		 * the pure, vitest-covered `buildCsvExportUrl()` util.
		 *
		 * @return {void}
		 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-csv-export-of-the-portfolio-report
		 */
		exportCsv() {
			const url = buildCsvExportUrl(
				generateUrl('/apps/stackiq/api/portfolio-report'),
				this.selectedOrg?.uuid || '',
			)
			if (url === '') {
				return
			}
			window.open(url, '_blank')
		},

		/**
		 * Translate a quadrant key to its display label. Kept as a component
		 * method (not the `portfolioReport.js` util) because it needs `t()`
		 * — matches `LifecycleRoadmapView.phaseLabel()`'s convention.
		 *
		 * @param {string} key A QUADRANT_ORDER key.
		 * @return {string} Display label.
		 * @spec openspec/specs/portfolio-rationalization-time/spec.md#requirement-portfolio-rationalization-report-aggregates-per-organisation
		 */
		quadrantLabel(key) {
			const map = {
				Tolerate: t('stackiq', 'Tolerate'),
				Invest: t('stackiq', 'Invest'),
				Migrate: t('stackiq', 'Migrate'),
				Eliminate: t('stackiq', 'Eliminate'),
				Unclassified: t('stackiq', 'Unclassified'),
			}
			return map[key] || key
		},

		// Exposed to the template as methods — thin re-exports of the pure
		// `portfolioReport.js` utils (vitest-covered), since Vue 2 Options
		// API templates cannot call a bare imported function directly.
		quadrantColor,
		cloudTransitionLabel,
		formatCurrency,
	},
}
</script>

<style scoped>
.portfolioReportView {
	padding: 20px;
}

.pr-header {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 16px;
}

.pr-title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.pr-intro {
	margin: 0;
	color: var(--color-text-maxcontrast);
	max-width: 72ch;
}

.pr-actions {
	display: flex;
	gap: 8px;
}

.pr-filters {
	margin-bottom: 20px;
	max-width: 420px;
}

.pr-error,
.pr-truncated {
	margin-bottom: 16px;
}

.pr-section {
	margin-bottom: 28px;
}

.pr-sectionTitle {
	margin: 0 0 12px;
	font-size: 16px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 4px;
}

.pr-table {
	width: 100%;
	border-collapse: collapse;
}

.pr-table th,
.pr-table td {
	text-align: left;
	padding: 8px 10px;
	border-bottom: 1px solid var(--color-border);
}

.pr-group {
	margin-bottom: 20px;
}

.pr-groupTitle {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0 0 8px;
	font-size: 14px;
}

.pr-count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.pr-badge {
	display: inline-flex;
	align-items: center;
	padding: 2px 8px;
	border-radius: var(--border-radius, 4px);
	color: var(--color-main-background);
	font-size: 12px;
	font-weight: 600;
}

.pr-eol {
	font-size: 12px;
	padding: 1px 6px;
	border-radius: var(--border-radius);
}

.pr-eol--passed {
	color: var(--color-error);
}

.pr-eol--approaching {
	color: var(--color-warning);
}

.pr-eol--ok {
	color: var(--color-text-maxcontrast);
}

.pr-loading {
	margin: 40px auto;
	display: block;
}
</style>
