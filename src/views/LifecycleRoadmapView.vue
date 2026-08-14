<template>
	<div class="roadmapView">
		<div class="rm-header">
			<h2 class="rm-title">
				{{ t('softwarecatalog', 'Portfolio roadmap') }}
			</h2>
			<p class="rm-intro">
				{{
					t(
						'softwarecatalog',
						'Applications in use for an organisation, grouped by lifecycle phase and ordered by nearest urgency (end-of-support, phase-out or planned replacement).',
					)
				}}
			</p>
			<NcButton
				variant="tertiary"
				:aria-label="t('softwarecatalog', 'Refresh data')"
				@click="loadData">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Refresh v-else :size="20" />
				</template>
				{{ t('softwarecatalog', 'Refresh') }}
			</NcButton>
		</div>

		<div class="rm-filters">
			<NcSelect
				v-model="selectedOrg"
				class="rm-orgSelect"
				:options="organisationOptions"
				:input-label="t('softwarecatalog', 'Organisation')"
				:placeholder="t('softwarecatalog', 'Select an organisation')"
				track-by="uuid"
				label="label"
				@update:model-value="onOrgChange" />
		</div>

		<NcEmptyContent
			v-if="!loading && !selectedOrg"
			:name="t('softwarecatalog', 'Select an organisation')"
			:description="
				t(
					'softwarecatalog',
					'Pick an organisation above to render its lifecycle roadmap.',
				)
			">
			<template #icon>
				<MapClock :size="40" />
			</template>
		</NcEmptyContent>

		<div v-else-if="!loading" class="rm-groups">
			<section
				v-for="group in phaseGroups"
				:key="group.phase"
				class="rm-group">
				<h3 class="rm-groupTitle">
					{{ phaseLabel(group.phase) }}
					<span class="rm-count">({{ group.entries.length }})</span>
				</h3>
				<NcEmptyContent
					v-if="group.entries.length === 0"
					:name="t('softwarecatalog', 'No applications in this phase')" />
				<ul v-else class="rm-list">
					<li
						v-for="entry in group.entries"
						:key="entry.uuid"
						class="rm-entry">
						<div class="rm-entryMain">
							<span class="rm-appName">{{ entry.appName }}</span>
							<span
								v-if="entry.eol.passed"
								class="rm-badge rm-badge--eol">
								<AlertCircle :size="14" />
								{{ t('softwarecatalog', 'End of support passed') }}
							</span>
							<span
								v-else-if="entry.eolApproaching"
								class="rm-badge rm-badge--warn">
								<ClockAlert :size="14" />
								{{
									t(
										'softwarecatalog',
										'End of support approaching',
									)
								}}
							</span>
							<span
								v-if="entry.eol.withdrawn"
								class="rm-badge rm-badge--eol">
								<CloseCircle :size="14" />
								{{ t('softwarecatalog', 'Withdrawn') }}
							</span>
						</div>
						<div class="rm-entryDates">
							<span v-if="entry.eol.endDate"
								>{{ t('softwarecatalog', 'End of support') }}:
								{{ entry.eol.endDate }}</span
							>
							<span v-if="entry.phaseOutDate"
								>{{ t('softwarecatalog', 'Phase-out') }}:
								{{ entry.phaseOutDate }}</span
							>
							<span v-if="entry.replacementDate"
								>{{ t('softwarecatalog', 'Planned replacement') }}:
								{{ entry.replacementDate }}</span
							>
						</div>
						<div v-if="entry.replacementName" class="rm-replacement">
							{{ t('softwarecatalog', 'Successor') }}:
							<button
								type="button"
								class="rm-link"
								@click="openModule(entry.replacementUuid)">
								{{ entry.replacementName }}
							</button>
						</div>
					</li>
				</ul>
			</section>
		</div>

		<NcLoadingIcon v-if="loading" :size="40" class="rm-loading" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSelect, NcEmptyContent } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { objectStore, navigationStore } from '../store/store.js'
import { useLiveCollections } from '../composables/useLiveCollections.js'
import {
	PHASE,
	derivePhase,
	endOfSupportState,
	isEolApproaching,
	phaseOrder,
	resolveUuid,
	parseDate,
} from '../utils/lifecyclePhase.js'

import Refresh from 'vue-material-design-icons/Refresh.vue'
import MapClock from 'vue-material-design-icons/MapClock.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import ClockAlert from 'vue-material-design-icons/ClockAlert.vue'
import CloseCircle from 'vue-material-design-icons/CloseCircle.vue'

const EOL_WINDOW_DAYS = 180

/**
 * @class LifecycleRoadmapView
 * @module Views
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * Per-organisation portfolio roadmap: the organisation's gebruiken grouped by
 * derived lifecycle phase (incl. Onbekend, rendered first), ordered within a
 * group by nearest urgency date, with end-of-support badges and planned
 * replacements. The rationalisation overview.
 *
 * @spec openspec/specs/application-lifecycle-tracking/spec.md
 */
export default {
	name: 'LifecycleRoadmapView',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcEmptyContent,
		Refresh,
		MapClock,
		AlertCircle,
		ClockAlert,
		CloseCircle,
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
		useLiveCollections(objectStore, [
			'gebruik',
			'moduleVersie',
			'module',
			'organisatie',
		])
		return {}
	},

	data() {
		return {
			loading: true,
			selectedOrg: null,
		}
	},

	computed: {
		/**
		 * All gebruik records in the store.
		 * @return {Array} Gebruik records.
		 * @spec openspec/specs/application-lifecycle-tracking/spec.md
		 */
		gebruiken() {
			return objectStore.getCollection('gebruik')?.results || []
		},

		/**
		 * All moduleVersie records, indexed by uuid for EOL lookups.
		 * @return {object} UUID → moduleVersie.
		 * @spec openspec/specs/application-lifecycle-tracking/spec.md
		 */
		moduleVersieIndex() {
			const index = {}
			for (const mv of objectStore.getCollection('moduleVersie')?.results
				|| []) {
				const id = resolveUuid(mv.uuid ?? mv.id ?? mv['@self']?.id ?? mv)
				if (id) {
					index[id] = mv
				}
			}
			return index
		},

		/**
		 * Module records indexed by uuid for successor labels.
		 * @return {object} UUID → module.
		 * @spec openspec/specs/application-lifecycle-tracking/spec.md
		 */
		moduleIndex() {
			const index = {}
			for (const m of objectStore.getCollection('module')?.results || []) {
				const id = resolveUuid(m.uuid ?? m.id ?? m['@self']?.id ?? m)
				if (id) {
					index[id] = m
				}
			}
			return index
		},

		/**
		 * Organisation options for the selector.
		 * @return {Array<{uuid: string, label: string}>} Options.
		 * @spec openspec/specs/application-lifecycle-tracking/spec.md
		 */
		organisationOptions() {
			return (objectStore.getCollection('organisatie')?.results || []).map(
				(org) => ({
					uuid: resolveUuid(org.uuid ?? org.id ?? org['@self']?.id ?? org),
					label:
						org.name
						|| org.title
						|| resolveUuid(org.uuid ?? org.id ?? ''),
				}),
			)
		},

		/**
		 * The phase order rendered top-to-bottom (Onbekend first).
		 * @return {Array<string>} Ordered phases.
		 * @spec openspec/specs/application-lifecycle-tracking/spec.md
		 */
		orderedPhases() {
			return [
				PHASE.UNKNOWN,
				PHASE.ACQUISITION,
				PHASE.PLANNED,
				PHASE.PRODUCTION,
				PHASE.PHASING_OUT,
				PHASE.PHASED_OUT,
			]
		},

		/**
		 * The selected organisation's gebruiken, grouped by derived phase and
		 * ordered within group by nearest urgency date.
		 * @return {Array<{phase: string, entries: Array}>} Grouped entries.
		 * @spec openspec/specs/application-lifecycle-tracking/spec.md
		 */
		phaseGroups() {
			if (!this.selectedOrg) {
				return []
			}
			const now = new Date()
			const orgUuid = this.selectedOrg.uuid

			const entries = this.gebruiken
				.filter((g) => {
					const data = g.object || g
					return resolveUuid(data.consumer) === orgUuid
				})
				.map((g) => this.buildEntry(g, now))

			return this.orderedPhases
				.map((phase) => ({
					phase,
					entries: entries
						.filter((e) => e.phase === phase)
						.sort((a, b) => this.urgency(a) - this.urgency(b)),
				}))
				.filter(
					(group) =>
						group.entries.length > 0 || group.phase === PHASE.UNKNOWN,
				)
		},
	},

	async mounted() {
		await this.loadData()
	},

	methods: {
		t,

		/**
		 * Load the collections the roadmap depends on.
		 * @return {Promise<void>}
		 * @spec openspec/specs/application-lifecycle-tracking/spec.md
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
					this.fetchType('gebruik'),
					this.fetchType('moduleVersie'),
					this.fetchType('module'),
					this.fetchType('organisatie'),
				])
			} catch (error) {
				console.error('LifecycleRoadmapView: failed to load data', error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch one object type collection if the store exposes the action.
		 * @param {string} type Object type slug.
		 * @return {Promise<void>}
		 * @spec openspec/specs/application-lifecycle-tracking/spec.md
		 */
		async fetchType(type) {
			// Register the object type from the resolved config before fetching —
			// the slug-based auto-registration only fires when the settings
			// `availableRegisters` carries a register slugged exactly
			// 'voorzieningen', which is not guaranteed on every instance. Without
			// this the nc-vue object store throws "Object type <type> is not
			// registered".
			if (
				typeof objectStore.registerObjectType === 'function'
				&& !objectStore.objectTypeRegistry?.[type]
			) {
				let cfg = null
				try {
					cfg = objectStore.getSchemaConfig?.(type)
				} catch (cfgError) {
					// getSchemaConfig throws when no schema/register resolves;
					// fall through so fetchCollection surfaces its own error.
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
		 * Build a roadmap entry for a gebruik.
		 * @param {object} gebruik A gebruik record.
		 * @param {Date}   now     Reference moment.
		 * @return {object} The roadmap entry view-model.
		 * @spec openspec/specs/application-lifecycle-tracking/spec.md
		 */
		buildEntry(gebruik, now) {
			const data = gebruik.object || gebruik
			const moduleUuid = resolveUuid(data.module)
			const versieUuid = resolveUuid(data.moduleVersie)
			const versie = this.moduleVersieIndex[versieUuid]
			const eol = endOfSupportState(versie || {}, now)
			const replacementUuid = resolveUuid(data.plannedReplacement)
			const replacementModule = this.moduleIndex[replacementUuid]

			return {
				uuid: resolveUuid(
					gebruik.uuid ?? gebruik.id ?? gebruik['@self']?.id ?? gebruik,
				),
				phase: derivePhase(gebruik, now),
				appName:
					this.moduleIndex[moduleUuid]?.name
					|| data.module?.name
					|| moduleUuid
					|| t('softwarecatalog', 'Unknown application'),
				eol,
				eolApproaching: versie
					? isEolApproaching(versie, EOL_WINDOW_DAYS, now)
					: false,
				phaseOutDate: data.startDateOutPhasing || null,
				replacementUuid,
				replacementName: replacementModule?.name || replacementUuid || null,
				replacementDate: data.plannedReplacementDate || null,
			}
		},

		/**
		 * Nearest urgency timestamp for ordering (min of EOL, phase-out, replacement).
		 * @param {object} entry A roadmap entry.
		 * @return {number} A sortable timestamp (Infinity when no dates).
		 * @spec openspec/specs/application-lifecycle-tracking/spec.md
		 */
		urgency(entry) {
			const dates = [
				entry.eol.endDate,
				entry.phaseOutDate,
				entry.replacementDate,
			]
				.map((d) => parseDate(d))
				.filter((d) => d !== null)
				.map((d) => d.getTime())
			return dates.length > 0 ? Math.min(...dates) : Infinity
		},

		/**
		 * Translate a phase to a human label (Dutch domain terms preserved).
		 * @param {string} phase A PHASE.* value.
		 * @return {string} Display label.
		 * @spec openspec/specs/application-lifecycle-tracking/spec.md
		 */
		phaseLabel(phase) {
			const map = {
				[PHASE.UNKNOWN]: t('softwarecatalog', 'Unknown'),
				[PHASE.ACQUISITION]: t('softwarecatalog', 'Acquisition'),
				[PHASE.PLANNED]: t('softwarecatalog', 'Planned'),
				[PHASE.PRODUCTION]: t('softwarecatalog', 'In production'),
				[PHASE.PHASING_OUT]: t('softwarecatalog', 'Phasing out'),
				[PHASE.PHASED_OUT]: t('softwarecatalog', 'Phased out'),
			}
			return map[phase] || phase
		},

		/**
		 * Persist nothing; just record the org selection (kept for symmetry).
		 * @return {void}
		 * @spec openspec/specs/application-lifecycle-tracking/spec.md
		 */
		onOrgChange() {
			// Selection is reactive via v-model; no extra fetch needed.
		},

		/**
		 * Navigate to a successor module's detail.
		 * @param {string} uuid The module uuid.
		 * @return {void}
		 * @spec openspec/specs/application-lifecycle-tracking/spec.md
		 */
		openModule(uuid) {
			if (uuid && typeof navigationStore.setSelected === 'function') {
				navigationStore.setSelected('moduleversies')
			}
		},

		// Re-export for use in computed (phaseOrder kept importable + referenced).
		phaseOrder,
	},
}
</script>

<style scoped>
.roadmapView {
	padding: 20px;
}

.rm-header {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 16px;
}

.rm-title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.rm-intro {
	margin: 0;
	color: var(--color-text-maxcontrast);
	max-width: 72ch;
}

.rm-filters {
	margin-bottom: 20px;
	max-width: 420px;
}

.rm-group {
	margin-bottom: 24px;
}

.rm-groupTitle {
	margin: 0 0 8px;
	font-size: 16px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 4px;
}

.rm-count {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.rm-list {
	list-style: none;
	padding: 0;
	margin: 0;
}

.rm-entry {
	padding: 10px 0;
	border-bottom: 1px solid var(--color-border-dark);
}

.rm-entryMain {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
}

.rm-appName {
	font-weight: 600;
}

.rm-badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 12px;
	padding: 1px 6px;
	border-radius: var(--border-radius);
}

.rm-badge--eol {
	color: var(--color-error);
}

.rm-badge--warn {
	color: var(--color-warning);
}

.rm-entryDates {
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}

.rm-replacement {
	font-size: 13px;
	margin-top: 4px;
}

.rm-link {
	background: none;
	border: none;
	color: var(--color-primary-element);
	cursor: pointer;
	padding: 0;
	font: inherit;
	text-decoration: underline;
}

.rm-loading {
	margin: 40px auto;
	display: block;
}
</style>
