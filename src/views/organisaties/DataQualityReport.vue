<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 Data quality of one organisation's applications in use (board SkDatakwaliteit).

 A thin renderer over the bounded, organisation-scoped `GET /api/data-quality`:
 the register score, average completeness and entries not confirmed, the
 declared rules with how many entries meet each, and the least complete
 entries first with what is missing.

 @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
-->
<template>
	<div class="data-quality">
		<header class="data-quality__header">
			<h2 class="data-quality__title">
				{{ t('stackiq', 'Data quality') }}
			</h2>
			<p class="data-quality__intro">
				{{ t('stackiq', 'How correct, complete and current the register of an organisation is.') }}
			</p>
		</header>

		<NcSelect
			v-model="selectedOrg"
			class="data-quality__org"
			:options="organisationOptions"
			:inputLabel="t('stackiq', 'Organisation')"
			:placeholder="t('stackiq', 'Select an organisation')"
			trackBy="uuid"
			label="label"
			@update:modelValue="loadReport" />

		<NcLoadingIcon v-if="loading" :size="44" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcEmptyContent
			v-else-if="!report"
			:name="t('stackiq', 'Select an organisation')"
			:description="t('stackiq', 'Pick an organisation above to see the quality of its register.')" />

		<template v-else>
			<NcNoteCard v-if="report.truncated" type="warning">
				{{ t('stackiq', 'The figures cover the first {count} of {total} applications in use.', { count: report.summary.entries, total: report.total }) }}
			</NcNoteCard>

			<CnKpiGrid :columns="3" data-testid="data-quality-kpis">
				<CnStatsBlock
					:title="t('stackiq', 'Register score')"
					:count="report.summary.score ?? 0"
					:countLabel="t('stackiq', 'of 100, against {count} rules', { count: report.summary.ruleCount })"
					showZeroCount />
				<CnStatsBlock
					:title="t('stackiq', 'Average completeness')"
					:count="report.summary.completeness ?? 0"
					:countLabel="t('stackiq', '% of {count} applications in use', { count: report.summary.entries })"
					showZeroCount />
				<CnStatsBlock
					:title="t('stackiq', 'Not confirmed')"
					:count="report.summary.notConfirmed"
					:countLabel="t('stackiq', 'entries never or too long ago confirmed')"
					showZeroCount />
			</CnKpiGrid>

			<section class="data-quality__section" data-testid="data-quality-rules">
				<h3>{{ t('stackiq', 'Rule set') }}</h3>
				<p class="data-quality__hint">
					{{ t('stackiq', 'Score per rule') }}
				</p>
				<CnProgressBar :items="ruleItems" showPercentage />
			</section>

			<section class="data-quality__section" data-testid="data-quality-lowest">
				<h3>{{ t('stackiq', 'Completeness per application') }}</h3>
				<p class="data-quality__hint">
					{{ t('stackiq', 'Lowest scores first') }}
				</p>
				<CnDataTable
					:rows="report.lowest"
					:columns="columns"
					rowKey="id"
					:emptyText="t('stackiq', 'No applications in use for this organisation')">
					<template #column-name="{ row }">
						<router-link :to="{ name: 'GebruikDetail', params: { id: row.id } }">
							{{ row.name || t('stackiq', 'Unnamed application') }}
						</router-link>
					</template>
					<template #column-completeness="{ row }">
						{{ Math.round(row.completeness * 100) }}%
					</template>
					<template #column-missing="{ row }">
						{{ row.missing.length ? row.missing.join(', ') : t('stackiq', 'Nothing') }}
					</template>
					<template #column-confirmedAt="{ row }">
						{{ row.confirmedAt ? formatDate(row.confirmedAt) : t('stackiq', 'Never') }}
					</template>
				</CnDataTable>
			</section>
		</template>
	</div>
</template>

<script>
import { CnDataTable, CnKpiGrid, CnProgressBar, CnStatsBlock } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import { useLiveCollections } from '../../composables/useLiveCollections.js'
import { objectStore } from '../../store/store.js'
import { resolveUuid } from '../../utils/lifecyclePhase.js'

/**
 * @class DataQualityReport
 * @module Views
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
 */
export default {
	name: 'DataQualityReport',
	components: {
		CnDataTable,
		CnKpiGrid,
		CnProgressBar,
		CnStatsBlock,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	/**
	 * The organisation picker reads the shared organisation collection; the
	 * figures come from the report endpoint.
	 *
	 * @return {object} Nothing; the subscription is a side effect.
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
		 * Organisation options for the picker.
		 *
		 * @return {Array<{uuid: string, label: string}>} Options.
		 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
		 */
		organisationOptions() {
			return (objectStore.getCollection('organization')?.results || []).map((org) => {
				const data = org.object || org
				const uuid = resolveUuid(org.uuid ?? org.id ?? org['@self']?.id ?? org)
				return { uuid, label: data.name || data.title || uuid }
			})
		},

		/**
		 * The rules as progress bars: how many entries meet each.
		 *
		 * @return {Array<object>} CnProgressBar items.
		 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
		 */
		ruleItems() {
			return (this.report?.rules || []).map((rule) => ({
				key: rule.type + ':' + rule.field,
				label: t('stackiq', '{rule}: {met} of {total} meet it', { rule: this.ruleLabel(rule), met: rule.met, total: rule.total }),
				count: rule.met,
				total: rule.total,
			}))
		},

		/**
		 * Columns of the completeness table.
		 *
		 * @return {Array<object>} Columns.
		 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
		 */
		columns() {
			return [
				{ key: 'name', label: t('stackiq', 'Application') },
				{ key: 'completeness', label: t('stackiq', 'Completeness') },
				{ key: 'missing', label: t('stackiq', 'Missing') },
				{ key: 'confirmedAt', label: t('stackiq', 'Last confirmed') },
			]
		},
	},

	watch: {
		/**
		 * With one organisation to choose from, show it straight away.
		 *
		 * @param {Array<object>} options The organisation options.
		 * @return {void}
		 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
		 */
		organisationOptions: {
			immediate: true,
			handler(options) {
				if (!this.selectedOrg && options.length === 1) {
					this.selectedOrg = options[0]
					this.loadReport()
				}
			},
		},
	},

	methods: {
		t,

		/**
		 * Load the report of the selected organisation.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
		 */
		async loadReport() {
			if (!this.selectedOrg?.uuid) {
				this.report = null
				return
			}
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(generateUrl('/apps/stackiq/api/data-quality'), {
					params: { organisation: this.selectedOrg.uuid },
				})
				this.report = response.data
			} catch (error) {
				this.report = null
				this.error = error?.response?.data?.message
					|| error?.response?.data?.error
					|| t('stackiq', 'The data quality could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * A rule as a sentence.
		 *
		 * @param {object} rule A rule row from the report.
		 * @return {string} The sentence.
		 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
		 */
		ruleLabel(rule) {
			const field = rule.title || rule.field
			if (rule.type === 'freshness') {
				return t('stackiq', '{field} is recent', { field })
			}
			if (rule.type === 'format') {
				return t('stackiq', '{field} has the right format', { field })
			}
			return t('stackiq', '{field} is filled in', { field })
		},

		/**
		 * A date in the user's locale.
		 *
		 * @param {string} value An ISO date.
		 * @return {string} The date.
		 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
		 */
		formatDate(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.data-quality {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 4);
}

.data-quality__title {
	margin: 0;
}

.data-quality__intro,
.data-quality__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.data-quality__org {
	max-width: 400px;
}

.data-quality__section h3 {
	margin: 0;
}
</style>
