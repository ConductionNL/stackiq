<template>
	<div class="vulnView">
		<div class="vv-header">
			<h2 class="vv-title">
				{{ t('stackiq', 'Vulnerabilities') }}
			</h2>
			<p class="vv-intro">
				{{
					t(
						'stackiq',
						'Track vulnerabilities (CVE / CVSS) against the applications in your catalogue and see which in-production usages are exposed.',
					)
				}}
			</p>
			<div class="vv-actions">
				<NcButton
					variant="primary"
					:aria-label="t('stackiq', 'Report a vulnerability')"
					data-testid="vuln-report"
					@click="reportVulnerability">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('stackiq', 'Report vulnerability') }}
				</NcButton>
				<NcButton
					variant="tertiary"
					:aria-label="t('stackiq', 'Refresh data')"
					@click="loadData">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<Refresh v-else :size="20" />
					</template>
					{{ t('stackiq', 'Refresh') }}
				</NcButton>
			</div>
		</div>

		<div class="vv-tabs" role="tablist">
			<button
				v-for="tab in severityTabs"
				:key="tab.band"
				type="button"
				role="tab"
				class="vv-tab"
				:class="{ 'vv-tab--active': selectedBand === tab.band }"
				:aria-selected="selectedBand === tab.band"
				:data-testid="'vuln-tab-' + tab.band"
				@click="selectedBand = tab.band">
				{{ tab.label }} <span class="vv-tabCount">({{ tab.count }})</span>
			</button>
		</div>

		<NcLoadingIcon v-if="loading" :size="40" class="vv-loading" />

		<NcEmptyContent
			v-else-if="filteredRows.length === 0"
			:name="t('stackiq', 'No vulnerabilities')"
			:description="
				t(
					'stackiq',
					'No vulnerabilities match the selected severity. Report one to start tracking exposure.',
				)
			">
			<template #icon>
				<ShieldAlert :size="40" />
			</template>
		</NcEmptyContent>

		<table v-else class="vv-table" data-testid="vuln-table">
			<thead>
				<tr>
					<th scope="col">{{ t('stackiq', 'Name') }}</th>
					<th scope="col">{{ t('stackiq', 'CVE') }}</th>
					<th scope="col">{{ t('stackiq', 'CVSS') }}</th>
					<th scope="col">{{ t('stackiq', 'Severity') }}</th>
					<th scope="col">
						{{ t('stackiq', 'Affected applications') }}
					</th>
					<th scope="col">{{ t('stackiq', 'Exposed usages') }}</th>
					<th scope="col" class="vv-actionsCol">
						<span class="hidden-visually">{{
							t('stackiq', 'Actions')
						}}</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="row in filteredRows"
					:key="row.uuid"
					class="vv-row"
					data-testid="vuln-row"
					@click="openDetail(row)">
					<td class="vv-name">
						{{ row.name }}
					</td>
					<td>{{ row.cveCode || '—' }}</td>
					<td>
						{{ row.cvssScore === null ? '—' : row.cvssScore.toFixed(1) }}
					</td>
					<td>
						<span
							class="vv-badge"
							:class="'vv-badge--' + row.band.toLowerCase()"
							:data-testid="'vuln-severity-' + row.band">
							{{ severityLabel(row.band) }}
						</span>
					</td>
					<td>{{ row.affectedCount }}</td>
					<td>{{ row.exposureCount }}</td>
					<td class="vv-actionsCol" @click.stop>
						<NcButton
							variant="tertiary"
							:aria-label="t('stackiq', 'Edit')"
							@click="editVulnerability(row)">
							<template #icon>
								<Pencil :size="18" />
							</template>
						</NcButton>
						<NcButton
							variant="tertiary"
							:aria-label="t('stackiq', 'Delete')"
							@click="deleteVulnerability(row)">
							<template #icon>
								<Delete :size="18" />
							</template>
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import ShieldAlert from 'vue-material-design-icons/ShieldAlert.vue'
import { useLiveCollections } from '../composables/useLiveCollections.js'
import { navigationStore, objectStore } from '../store/store.js'
import { resolveUuid } from '../utils/lifecyclePhase.js'
import { exposureCount } from '../utils/vulnerabilityExposure.js'
import {
	deriveSeverity,
	matchesSeverity,
	parseCvss,
	SEVERITY,
	severityOrder,
} from '../utils/vulnerabilitySeverity.js'

/**
 * @class KwetsbaarhedenView
 * @module Views
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * Vulnerability index: lists kwetsbaarheid records with a CVSS-derived severity
 * band, affected-application count and exposed-in-production-usage count, with
 * severity quick-filter tabs (All / Critical / High / Medium / Low) applied over
 * the OR list fetch. Create/edit/delete reuse the app's generic ObjectModal
 * (kwetsbaarheid is already a generic modal type) — reporting a vulnerability
 * here is what makes the shipped `vulnerability-reported` notification reachable.
 * Severity is derived, never stored.
 *
 * @spec openspec/specs/module-vulnerability-tracking/spec.md
 */
export default {
	name: 'KwetsbaarhedenView',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		Refresh,
		Plus,
		Pencil,
		Delete,
		ShieldAlert,
	},

	/**
	 * Live updates (nc-vue liveUpdatesPlugin, default-on since beta.212):
	 * subscribe to the collection scope of every type this view renders.
	 * Events are refetch hints — the plugin re-runs fetchCollection into
	 * the same getCollection() state the computeds read, so the view
	 * re-renders without extra bridging. Subscriptions are gated on the
	 * lazy type registration in loadData() and released on unmount.
	 *
	 * @return {object} Empty — the subscriptions are side-effect only
	 * @spec openspec/specs/realtime-updates-ui/spec.md
	 */
	setup() {
		useLiveCollections(objectStore, ['vulnerability', 'usage'])
		return {}
	},

	data() {
		return {
			loading: true,
			selectedBand: 'All',
		}
	},

	computed: {
		/**
		 * All kwetsbaarheid records in the store.
		 *
		 * @return {Array} Vulnerability records.
		 * @spec openspec/specs/module-vulnerability-tracking/spec.md
		 */
		vulnerabilities() {
			return objectStore.getCollection('vulnerability')?.results || []
		},

		/**
		 * All gebruik records (usages) — the exposure join input.
		 *
		 * @return {Array} Gebruik records.
		 * @spec openspec/specs/module-vulnerability-tracking/spec.md
		 */
		usages() {
			return objectStore.getCollection('usage')?.results || []
		},

		/**
		 * Row view-models: raw fields + derived severity + affected/exposure counts.
		 *
		 * @return {Array} Row view-models.
		 * @spec openspec/specs/module-vulnerability-tracking/spec.md
		 */
		rows() {
			return this.vulnerabilities
				.map((vuln) => {
					const data = vuln.object || vuln
					return {
						uuid: resolveUuid(
							vuln.uuid ?? vuln.id ?? vuln['@self']?.id ?? vuln,
						),
						raw: vuln,
						name: data.name || t('stackiq', 'Unnamed vulnerability'),
						cveCode: data.cveCode || '',
						cvssScore: parseCvss(data.cvssScore),
						band: deriveSeverity(vuln),
						affectedCount: Array.isArray(data.modules)
							? data.modules.length
							: 0,
						exposureCount: exposureCount(vuln, this.usages),
					}
				})
				.sort((a, b) => severityOrder(a.band) - severityOrder(b.band))
		},

		/**
		 * Rows filtered to the selected severity band.
		 *
		 * @return {Array} Filtered rows.
		 * @spec openspec/specs/module-vulnerability-tracking/spec.md
		 */
		filteredRows() {
			return this.rows.filter((row) =>
				matchesSeverity(row.raw, this.selectedBand),
			)
		},

		/**
		 * Severity tabs with per-band counts (All first).
		 *
		 * @return {Array<{band: string, label: string, count: number}>} Tabs.
		 * @spec openspec/specs/module-vulnerability-tracking/spec.md
		 */
		severityTabs() {
			const bands = [
				'All',
				SEVERITY.CRITICAL,
				SEVERITY.HIGH,
				SEVERITY.MEDIUM,
				SEVERITY.LOW,
			]
			return bands.map((band) => ({
				band,
				label: this.severityLabel(band),
				count: this.rows.filter((row) => matchesSeverity(row.raw, band))
					.length,
			}))
		},
	},

	async mounted() {
		await this.loadData()
	},

	methods: {
		t,

		/**
		 * Load vulnerabilities and usages.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/module-vulnerability-tracking/spec.md
		 */
		async loadData() {
			this.loading = true
			try {
				if (
					!objectStore.settings
					&& typeof objectStore.fetchSettings === 'function'
				) {
					await objectStore.fetchSettings()
				}
				await Promise.all([
					this.fetchType('vulnerability'),
					this.fetchType('usage'),
				])
			} catch (error) {
				console.error('KwetsbaarhedenView: failed to load data', error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch one object type collection, registering the type if needed.
		 *
		 * @param {string} type Object type slug.
		 * @return {Promise<void>}
		 * @spec openspec/specs/module-vulnerability-tracking/spec.md
		 */
		async fetchType(type) {
			if (
				typeof objectStore.registerObjectType === 'function'
				&& !objectStore.objectTypeRegistry?.[type]
			) {
				let cfg = null
				try {
					cfg = objectStore.getSchemaConfig?.(type)
				} catch (cfgError) {
					// getSchemaConfig throws when no schema/register resolves; fall through.
				}
				if (cfg?.register && cfg?.schema) {
					objectStore.registerObjectType(type, cfg.schema, cfg.register)
				}
			}
			if (typeof objectStore.fetchCollection === 'function') {
				await objectStore.fetchCollection(type, { _limit: 1000 })
			}
		},

		/**
		 * Human label for a severity band (All included).
		 *
		 * @param {string} band A SEVERITY.* value or 'All'.
		 * @return {string} Display label.
		 * @spec openspec/specs/module-vulnerability-tracking/spec.md
		 */
		severityLabel(band) {
			const map = {
				All: t('stackiq', 'All'),
				[SEVERITY.CRITICAL]: t('stackiq', 'Critical'),
				[SEVERITY.HIGH]: t('stackiq', 'High'),
				[SEVERITY.MEDIUM]: t('stackiq', 'Medium'),
				[SEVERITY.LOW]: t('stackiq', 'Low'),
				[SEVERITY.UNKNOWN]: t('stackiq', 'Unknown'),
			}
			return map[band] || band
		},

		/**
		 * Open the generic create modal for a new vulnerability (activates the
		 * shipped vulnerability-reported notification on save).
		 *
		 * @return {void}
		 * @spec openspec/specs/module-vulnerability-tracking/spec.md
		 */
		reportVulnerability() {
			navigationStore.setTransferData('create')
			navigationStore.setModal('vulnerability')
		},

		/**
		 * Open the generic edit modal for an existing vulnerability.
		 *
		 * @param {object} row A row view-model.
		 * @return {void}
		 * @spec openspec/specs/module-vulnerability-tracking/spec.md
		 */
		editVulnerability(row) {
			navigationStore.setTransferData(row.raw)
			navigationStore.setModal('vulnerability')
		},

		/**
		 * Open the delete confirmation dialog for a vulnerability.
		 *
		 * @param {object} row A row view-model.
		 * @return {void}
		 * @spec openspec/specs/module-vulnerability-tracking/spec.md
		 */
		deleteVulnerability(row) {
			navigationStore.setTransferData(row.raw)
			navigationStore.setDialog('deleteObject', {
				objectType: 'vulnerability',
			})
		},

		/**
		 * Open the full vulnerability record (the app's canonical "open record"
		 * gesture) so its fields, affected applications and exposure are readable.
		 *
		 * @param {object} row A row view-model.
		 * @return {void}
		 * @spec openspec/specs/module-vulnerability-tracking/spec.md
		 */
		openDetail(row) {
			if (typeof objectStore.setActiveObject === 'function') {
				objectStore.setActiveObject('vulnerability', row.raw)
			}
			navigationStore.setTransferData(row.raw)
			navigationStore.setModal('vulnerability')
		},
	},
}
</script>

<style scoped>
.vulnView {
	padding: 20px;
}

.vv-header {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 16px;
}

.vv-title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.vv-intro {
	margin: 0;
	color: var(--color-text-maxcontrast);
	max-width: 72ch;
}

.vv-actions {
	display: flex;
	gap: 8px;
	margin-top: 4px;
}

.vv-tabs {
	display: flex;
	gap: 4px;
	flex-wrap: wrap;
	margin-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
}

.vv-tab {
	background: none;
	border: none;
	border-bottom: 2px solid transparent;
	padding: 8px 14px;
	cursor: pointer;
	font: inherit;
	color: var(--color-text-maxcontrast);
}

.vv-tab--active {
	color: var(--color-main-text);
	border-bottom-color: var(--color-primary-element);
	font-weight: 600;
}

.vv-tabCount {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.vv-table {
	width: 100%;
	border-collapse: collapse;
}

.vv-table th,
.vv-table td {
	text-align: left;
	padding: 8px 10px;
	border-bottom: 1px solid var(--color-border);
}

.vv-row {
	cursor: pointer;
}

.vv-row:hover {
	background: var(--color-background-hover);
}

.vv-name {
	font-weight: 600;
}

.vv-actionsCol {
	white-space: nowrap;
	text-align: right;
}

.vv-badge {
	display: inline-block;
	font-size: 12px;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 16px);
	border: 1px solid var(--color-border-dark);
}

.vv-badge--critical {
	color: var(--color-error);
	border-color: var(--color-error);
}

.vv-badge--high {
	color: var(--color-warning);
	border-color: var(--color-warning);
}

.vv-badge--medium {
	color: var(--color-main-text);
}

.vv-badge--low,
.vv-badge--unknown {
	color: var(--color-text-maxcontrast);
}

.vv-loading {
	margin: 40px auto;
	display: block;
}
</style>
