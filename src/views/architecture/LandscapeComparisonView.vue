<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Today's landscape next to the plan or a scenario on a date
  - (architecture-future-state-scenarios, design D3 and D4).
  -
  - On the LandscapeComparison page it offers an organisation and a date and
  - compares the plan alone; the Portfolio roadmap opens it with
  - `?organisation=`. On a scenario page (body widget, `objectId`) it compares
  - the scenario on its target date and, for an adopted scenario that was not
  - applied, offers Apply to landscape.
  -
  - @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
-->
<template>
	<div class="landscape-comparison" data-testid="landscape-comparison">
		<header class="landscape-comparison__header">
			<h2 class="landscape-comparison__title">
				{{ scenarioId ? t('stackiq', 'Today and this scenario') : t('stackiq', 'Compare with the plan') }}
			</h2>
			<p class="landscape-comparison__intro">
				{{
					scenarioId
						? t('stackiq', 'The landscape of today next to the plan on the target date with this scenario applied.')
						: t('stackiq', 'The landscape of today next to the plan on a date, read from the lifecycle dates of the applications in use.')
				}}
			</p>
		</header>

		<div v-if="!scenarioId" class="landscape-comparison__filters">
			<NcSelect
				v-model="selectedOrg"
				class="landscape-comparison__org"
				:options="organisationOptions"
				:inputLabel="t('stackiq', 'Organisation')"
				:placeholder="t('stackiq', 'Select an organisation')"
				trackBy="uuid"
				label="label"
				@update:modelValue="loadComparison" />
			<NcTextField
				v-model="date"
				class="landscape-comparison__date"
				type="date"
				:label="t('stackiq', 'Date')"
				@update:modelValue="loadComparison" />
		</div>

		<div v-if="canApply" class="landscape-comparison__apply">
			<NcButton
				variant="primary"
				:disabled="applying"
				data-testid="apply-scenario"
				@click="apply">
				<template #icon>
					<NcLoadingIcon v-if="applying" :size="20" />
				</template>
				{{ t('stackiq', 'Apply to landscape') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="applyNotice" :type="applyNotice.type">
			{{ applyNotice.text }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="44" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcEmptyContent
			v-else-if="!report"
			:name="t('stackiq', 'Select an organisation')"
			:description="t('stackiq', 'Pick an organisation and a date above to compare its landscape.')" />

		<template v-else>
			<div class="landscape-comparison__columns">
				<section class="landscape-comparison__column" data-testid="landscape-today">
					<h3>{{ t('stackiq', 'Today ({date})', { date: report.today }) }}</h3>
					<ul v-if="todayNames.length > 0">
						<li v-for="(name, index) in todayNames" :key="'t' + index">
							{{ name }}
						</li>
					</ul>
					<p v-else class="landscape-comparison__hint">
						{{ t('stackiq', 'No applications in use') }}
					</p>
				</section>
				<section class="landscape-comparison__column" data-testid="landscape-future">
					<h3>{{ t('stackiq', 'On {date}', { date: report.date }) }}</h3>
					<ul v-if="futureNames.length > 0">
						<li v-for="(name, index) in futureNames" :key="'f' + index">
							{{ name }}
						</li>
					</ul>
					<p v-else class="landscape-comparison__hint">
						{{ t('stackiq', 'No applications in use') }}
					</p>
				</section>
			</div>

			<section class="landscape-comparison__section" data-testid="landscape-applications">
				<h3>{{ t('stackiq', 'Applications that change') }}</h3>
				<CnDataTable
					:rows="applications"
					:columns="applicationColumns"
					rowKey="id"
					:emptyText="t('stackiq', 'No application changes by this date')" />
			</section>

			<section class="landscape-comparison__section" data-testid="landscape-coverage">
				<h3>{{ t('stackiq', 'Reference components whose coverage changes') }}</h3>
				<CnDataTable
					:rows="coverage"
					:columns="coverageColumns"
					rowKey="id"
					:emptyText="t('stackiq', 'No coverage changes by this date')" />
			</section>

			<p class="landscape-comparison__hint" data-testid="landscape-not-dated">
				{{
					n('stackiq', '%n application in use has no lifecycle date or status and is in neither landscape.', '%n applications in use have no lifecycle date or status and are in neither landscape.', report.notDated || 0)
				}}
			</p>
		</template>
	</div>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { useLiveCollections } from '../../composables/useLiveCollections.js'
import { applyScenario, canApply, relationId, storeObjects } from '../../store/modules/scenarioApply.js'
import { objectStore } from '../../store/store.js'
import {
	applicationRows,
	applyFailureNotice,
	COMPARISON_PATH,
	comparisonParams,
	coverageRows,
	landscapeNames,
} from '../../utils/landscapeComparison.js'
import { resolveUuid } from '../../utils/lifecyclePhase.js'
import { ensureObjectTypeOrSlug, loadObjectTypeCollection } from '../../utils/objectTypes.js'

/** The types the comparison reads and an apply writes. */
const SCENARIO_TYPES = ['scenario', 'scenarioChange', 'usage']

/**
 * @class LandscapeComparisonView
 * @module Views
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
 */
export default {
	name: 'LandscapeComparisonView',
	components: {
		CnDataTable,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The scenario, on a scenario page (`@objectId`); empty on the comparison page. */
		objectId: { type: String, default: '' },
	},

	/**
	 * The organisation picker reads the shared organisation collection.
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
			report: null,
			selectedOrg: null,
			date: '',
			scenario: null,
			applying: false,
			applyNotice: null,
		}
	},

	computed: {
		/**
		 * The scenario compared, when on a scenario page.
		 *
		 * @return {string} The scenario uuid, or empty.
		 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-003-an-information-manager-shall-write-a-scenario-of-additions-phase-outs-and-replacements
		 */
		scenarioId() {
			return this.objectId || ''
		},

		/**
		 * Organisation options for the picker.
		 *
		 * @return {Array<{uuid: string, label: string}>} Options.
		 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
		 */
		organisationOptions() {
			return (objectStore.getCollection('organization')?.results || []).map((org) => {
				const data = org.object || org
				const uuid = resolveUuid(org.uuid ?? org.id ?? org['@self']?.id ?? org)
				return { uuid, label: data.name || data.title || uuid }
			})
		},

		/**
		 * Whether Apply to landscape is offered.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-004-a-scenario-shall-move-through-a-declared-lifecycle-and-an-adopted-scenario-shall-be-applied-to-the-landscape
		 */
		canApply() {
			return Boolean(this.scenarioId) && canApply(this.scenario)
		},

		/**
		 * @return {Array<object>} Application difference rows.
		 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
		 */
		applications() {
			return applicationRows(this.report)
		},

		/**
		 * @return {Array<object>} Coverage difference rows.
		 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
		 */
		coverage() {
			return coverageRows(this.report)
		},

		/**
		 * @return {Array<string>} Today's applications.
		 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
		 */
		todayNames() {
			return landscapeNames(this.report, 'today')
		},

		/**
		 * @return {Array<string>} The applications on the date.
		 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
		 */
		futureNames() {
			return landscapeNames(this.report, 'future')
		},

		/**
		 * @return {Array<object>} Columns of the application table.
		 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
		 */
		applicationColumns() {
			return [
				{ key: 'application', label: t('stackiq', 'Application') },
				{ key: 'change', label: t('stackiq', 'Change') },
				{ key: 'source', label: t('stackiq', 'Source') },
				{ key: 'date', label: t('stackiq', 'Date') },
			]
		},

		/**
		 * @return {Array<object>} Columns of the coverage table.
		 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
		 */
		coverageColumns() {
			return [
				{ key: 'component', label: t('stackiq', 'Reference component') },
				{ key: 'today', label: t('stackiq', 'Today') },
				{ key: 'future', label: t('stackiq', 'On the date') },
				{ key: 'change', label: t('stackiq', 'Change') },
			]
		},
	},

	watch: {
		/**
		 * Preselect the organisation the roadmap passed, or the only one there is.
		 *
		 * @param {Array<object>} options The organisation options.
		 * @return {void}
		 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
		 */
		organisationOptions: {
			immediate: true,
			handler(options) {
				if (this.scenarioId || this.selectedOrg) {
					return
				}
				const wanted = this.$route?.query?.organisation
				const match = (wanted && options.find((option) => option.uuid === wanted))
					|| (options.length === 1 ? options[0] : null)
				if (match) {
					this.selectedOrg = match
					this.loadComparison()
				}
			},
		},
	},

	/**
	 * Load the scenario on a scenario page, else the organisations to pick from.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
	 */
	async mounted() {
		if (this.scenarioId) {
			SCENARIO_TYPES.forEach(ensureObjectTypeOrSlug)
			await this.loadScenario()
			return
		}
		await loadObjectTypeCollection('organization')
	},

	methods: {
		t,
		n,

		/**
		 * Read the scenario and compare it.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-003-an-information-manager-shall-write-a-scenario-of-additions-phase-outs-and-replacements
		 */
		async loadScenario() {
			this.scenario = await objectStore.fetchObject('scenario', this.scenarioId)
			await this.loadComparison()
		},

		/**
		 * Load the comparison for the chosen organisation and date, or the scenario.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
		 */
		async loadComparison() {
			const organisation = this.scenarioId
				? relationId(this.scenario?.organisation)
				: this.selectedOrg?.uuid
			if (!organisation) {
				this.report = null
				return
			}
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(generateUrl(COMPARISON_PATH), {
					params: comparisonParams({
						organisation,
						date: this.scenarioId ? '' : this.date,
						scenario: this.scenarioId || null,
					}),
				})
				this.report = response.data
			} catch (error) {
				this.report = null
				this.error = error?.response?.data?.message
					|| error?.response?.data?.error
					|| t('stackiq', 'The comparison could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Apply the adopted scenario to the landscape, then compare again.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-004-a-scenario-shall-move-through-a-declared-lifecycle-and-an-adopted-scenario-shall-be-applied-to-the-landscape
		 */
		async apply() {
			this.applying = true
			this.applyNotice = null
			try {
				const changes = await objectStore.fetchCollectionForOptions('scenarioChange', {
					scenario: this.scenarioId,
					_limit: 500,
				})
				const result = await applyScenario({
					scenario: this.scenario,
					changes: changes?.results ?? changes ?? [],
					objects: storeObjects(objectStore),
				})
				this.applyNotice = result.failed
					? { type: 'error', text: applyFailureNotice(result.failed) }
					: {
						type: 'success',
						text: n('stackiq', 'Applied %n change to the landscape.', 'Applied %n changes to the landscape.', result.applied.length),
					}
				await this.loadScenario()
			} catch (error) {
				this.applyNotice = { type: 'error', text: error?.message || t('stackiq', 'The scenario could not be applied.') }
			} finally {
				this.applying = false
			}
		},
	},
}
</script>

<style scoped>
.landscape-comparison {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 4);
}

.landscape-comparison__title,
.landscape-comparison__section h3,
.landscape-comparison__column h3 {
	margin: 0;
}

.landscape-comparison__intro,
.landscape-comparison__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.landscape-comparison__filters {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	align-items: flex-end;
}

.landscape-comparison__org {
	min-width: 280px;
	max-width: 400px;
}

.landscape-comparison__date {
	max-width: 200px;
}

.landscape-comparison__columns {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
	gap: calc(var(--default-grid-baseline, 4px) * 4);
}

.landscape-comparison__column {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline, 4px) * 3);
}

.landscape-comparison__column ul {
	margin: calc(var(--default-grid-baseline, 4px) * 2) 0 0;
	padding-inline-start: calc(var(--default-grid-baseline, 4px) * 4);
	list-style: disc;
}
</style>
