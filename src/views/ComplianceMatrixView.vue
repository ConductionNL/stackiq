<template>
	<div class="complianceMatrixView">
		<div class="cmv-header">
			<h2 class="cmv-title">
				{{ t('stackiq', 'Compliance matrix') }}
			</h2>
			<p class="cmv-intro">
				{{
					columnSource === 'bioMeasure'
						? t(
								'stackiq',
								"Which applications support which BIO 2.0 measures, plus each application's BBN level and DPIA status. A verified cell traces to evidence; a claimed cell is a supplier statement without evidence.",
							)
						: t(
								'stackiq',
								'Which applications support which standards. A verified cell traces to evidence; a claimed cell is a supplier statement without evidence.',
							)
				}}
			</p>
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

		<!-- Column-source scope: standards (GEMMA) or BIO 2.0 measures. -->
		<div
			class="cmv-scope"
			role="radiogroup"
			:aria-label="t('stackiq', 'Compliance matrix scope')">
			<NcCheckboxRadioSwitch
				v-model="columnSource"
				value="standardVersion"
				name="cmv-columnSource"
				type="radio">
				{{ t('stackiq', 'Standards') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-model="columnSource"
				value="bioMeasure"
				name="cmv-columnSource"
				type="radio">
				{{ t('stackiq', 'BIO measures') }}
			</NcCheckboxRadioSwitch>
		</div>

		<!-- Filter-first: pick columns (and optionally an organisation scope) before cells render. -->
		<div class="cmv-filters">
			<NcSelect
				v-model="selectedColumns"
				class="cmv-standardSelect"
				:options="columnOptions"
				:multiple="true"
				:closeOnSelect="false"
				:inputLabel="
					columnSource === 'bioMeasure'
						? t('stackiq', 'BIO measures')
						: t('stackiq', 'Standards')
				"
				:placeholder="
					columnSource === 'bioMeasure'
						? t('stackiq', 'Select one or more BIO measures')
						: t('stackiq', 'Select one or more standards')
				"
				trackBy="uuid"
				label="label"
				@update:modelValue="onSelectionChange" />
			<NcSelect
				v-model="selectedOrganisation"
				class="cmv-orgSelect"
				:options="organisationOptions"
				:multiple="false"
				:clearable="true"
				:inputLabel="
					t(
						'stackiq',
						'Organisation (scope to in-use applications)',
					)
				"
				:placeholder="t('stackiq', 'All applications')"
				trackBy="uuid"
				label="label"
				@update:modelValue="onSelectionChange" />
		</div>

		<NcEmptyContent
			v-if="!loading && noColumnsImported"
			:name="
				columnSource === 'bioMeasure'
					? t('stackiq', 'No BIO measures seeded')
					: t('stackiq', 'No standards imported')
			"
			:description="
				columnSource === 'bioMeasure'
					? t(
							'stackiq',
							'The BIO measures catalog is seeded on install/upgrade. Refresh, or check the BIO measures catalog.',
						)
					: t(
							'stackiq',
							'Import GEMMA standards via the ArchiMate import before building a compliance matrix.',
						)
			">
			<template #icon>
				<CheckboxMarkedCircleOutline :size="40" />
			</template>
		</NcEmptyContent>

		<NcEmptyContent
			v-else-if="!loading && selectedColumns.length === 0"
			:name="
				columnSource === 'bioMeasure'
					? t('stackiq', 'Select BIO measures to compare')
					: t('stackiq', 'Select standards to compare')
			"
			:description="
				t(
					'stackiq',
					'Pick one or more columns above to render the compliance matrix.',
				)
			">
			<template #icon>
				<TableLarge :size="40" />
			</template>
		</NcEmptyContent>

		<div v-else-if="!loading" class="cmv-tableWrap">
			<!-- Legend — states must not rely on colour alone (WCAG AA). -->
			<div class="cmv-legend" aria-hidden="false">
				<span class="cmv-legendItem"
					><CheckCircle :size="16" class="cmv-iconVerified" />
					{{ t('stackiq', 'Verified (with evidence)') }}</span
				>
				<span class="cmv-legendItem"
					><HelpCircle :size="16" class="cmv-iconClaimed" />
					{{ t('stackiq', 'Claimed (no evidence)') }}</span
				>
				<span class="cmv-legendItem"
					><MinusCircle :size="16" class="cmv-iconNone" />
					{{ t('stackiq', 'None') }}</span
				>
			</div>

			<table class="cmv-table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('stackiq', 'Module') }}
						</th>
						<th v-if="columnSource === 'bioMeasure'" scope="col">
							{{ t('stackiq', 'BBN level') }}
						</th>
						<th v-if="columnSource === 'bioMeasure'" scope="col">
							{{ t('stackiq', 'DPIA status') }}
						</th>
						<th
							v-for="column in matrix.columns"
							:key="column.uuid"
							scope="col">
							{{ column.label }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in matrix.rows" :key="row.moduleUuid">
						<th scope="row" class="cmv-rowHeader">
							{{ moduleLabel(row.module) }}
						</th>
						<td
							v-if="columnSource === 'bioMeasure'"
							class="cmv-cell cmv-cell--meta">
							{{ bbnLevelLabel(row.module) }}
						</td>
						<td
							v-if="columnSource === 'bioMeasure'"
							class="cmv-cell cmv-cell--meta">
							{{ dpiaStatusLabel(row.module) }}
						</td>
						<td
							v-for="column in matrix.columns"
							:key="column.uuid"
							class="cmv-cell"
							:class="['cmv-cell--' + row.cells[column.uuid].state]">
							<button
								v-if="row.cells[column.uuid].record"
								type="button"
								class="cmv-cellButton"
								:aria-label="cellAriaLabel(row, column)"
								@click="openRecord(row.cells[column.uuid].record)">
								<CheckCircle
									v-if="
										row.cells[column.uuid].state === 'verified'
									"
									:size="18"
									class="cmv-iconVerified" />
								<HelpCircle
									v-else
									:size="18"
									class="cmv-iconClaimed" />
								<span class="cmv-cellText">{{
									stateLabel(row.cells[column.uuid].state)
								}}</span>
							</button>
							<span
								v-else
								class="cmv-cellNone"
								:aria-label="cellAriaLabel(row, column)">
								<MinusCircle :size="18" class="cmv-iconNone" />
								<span class="cmv-cellText">{{
									stateLabel('none')
								}}</span>
							</span>
						</td>
					</tr>
				</tbody>
			</table>

			<div v-if="matrix.unresolved.length > 0" class="cmv-unresolved">
				<NcNoteCard type="warning">
					{{
						t(
							'stackiq',
							'Some compliancy records only reference a standard by name and could not be matched to a standard version. They are excluded from the matrix.',
						)
						+ ' ('
						+ matrix.unresolved.length
						+ ')'
					}}
				</NcNoteCard>
			</div>

			<div v-if="matrix.conflicted.length > 0" class="cmv-unresolved">
				<NcNoteCard type="warning">
					{{
						t(
							'stackiq',
							'Some compliancy records reference both a standard and a BIO measure — a data-quality issue. They are excluded from both matrices until corrected.',
						)
						+ ' ('
						+ matrix.conflicted.length
						+ ')'
					}}
				</NcNoteCard>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" :size="40" class="cmv-loading" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import CheckboxMarkedCircleOutline from 'vue-material-design-icons/CheckboxMarkedCircleOutline.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import HelpCircle from 'vue-material-design-icons/HelpCircle.vue'
import MinusCircle from 'vue-material-design-icons/MinusCircle.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import TableLarge from 'vue-material-design-icons/TableLarge.vue'
import { useLiveCollections } from '../composables/useLiveCollections.js'
import { navigationStore, objectStore } from '../store/store.js'
import {
	buildComplianceMatrix,
	COLUMN_SOURCE,
	columnLabel,
	dataOf,
	resolveUuid,
} from '../utils/complianceMatrix.js'

/**
 * @class ComplianceMatrixView
 * @module Views
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * Filter-first compliance matrix: modules × a selected column source
 * (GEMMA standard versions, or — since bio-compliance-assessment — BIO 2.0
 * measures), with verified / claimed / none cell states. The buyer-facing
 * answer to "does this application support standard/measure X, and is that
 * a claim or a fact?". In the BIO scope, optionally narrowed to a single
 * organisation's in-use applications, with each row also showing the
 * application's BBN level and DPIA status (the BIO coverage report).
 *
 * @spec openspec/specs/module-compliance-assessment/spec.md
 * @spec openspec/specs/bio-compliance-assessment/spec.md
 */
export default {
	name: 'ComplianceMatrixView',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcEmptyContent,
		NcNoteCard,
		NcCheckboxRadioSwitch,
		Refresh,
		CheckCircle,
		HelpCircle,
		MinusCircle,
		TableLarge,
		CheckboxMarkedCircleOutline,
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
			'module',
			'compliancy',
			'element',
			'bioMeasure',
			'usage',
			'organization',
		])
		return {}
	},

	data() {
		return {
			loading: true,
			columnSource: COLUMN_SOURCE.STANDAARDVERSIE,
			selectedStandards: [],
			selectedBioMeasures: [],
			selectedOrganisation: null,
		}
	},

	computed: {
		/**
		 * All compliancy records currently in the store.
		 *
		 * @return {Array} Compliancy records.
		 * @spec openspec/specs/module-compliance-assessment/spec.md
		 */
		compliancy() {
			return objectStore.getCollection('compliancy')?.results || []
		},

		/**
		 * All module records currently in the store.
		 *
		 * @return {Array} Module records.
		 * @spec openspec/specs/module-compliance-assessment/spec.md
		 */
		modules() {
			return objectStore.getCollection('module')?.results || []
		},

		/**
		 * All standaardversie elements (GEMMA elements with gemmaType=standaardversie).
		 *
		 * @return {Array} Standard version records.
		 * @spec openspec/specs/module-compliance-assessment/spec.md
		 */
		standardVersions() {
			const elements = objectStore.getCollection('element')?.results || []
			return elements.filter(
				(el) =>
					(el.gemmaType || el.object?.gemmaType) === 'standard_version',
			)
		},

		/**
		 * All BIO 2.0 measures currently in the store.
		 *
		 * @return {Array} bioMaatregel records.
		 * @spec openspec/specs/bio-compliance-assessment/spec.md#requirement-bio-measures-form-a-seedable-reference-catalog
		 */
		bioMaatregelen() {
			return objectStore.getCollection('bioMeasure')?.results || []
		},

		/**
		 * All gebruik (in-use) records currently in the store — used to scope
		 * the matrix to a single organisation's in-use applications.
		 *
		 * @return {Array} gebruik records.
		 * @spec openspec/specs/bio-compliance-assessment/spec.md#requirement-organisation-bio-coverage-is-reportable
		 */
		usages() {
			return objectStore.getCollection('usage')?.results || []
		},

		/**
		 * All organisatie records currently in the store — the coverage-report
		 * organisation picker's source.
		 *
		 * @return {Array} organisatie records.
		 * @spec openspec/specs/bio-compliance-assessment/spec.md#requirement-organisation-bio-coverage-is-reportable
		 */
		organisaties() {
			return objectStore.getCollection('organization')?.results || []
		},

		/**
		 * Whether no columns are available to pick for the active column source.
		 *
		 * @return {boolean} True when the active source has no entries.
		 * @spec openspec/specs/module-compliance-assessment/spec.md
		 * @spec openspec/specs/bio-compliance-assessment/spec.md
		 */
		noColumnsImported() {
			return this.columnSource === COLUMN_SOURCE.BIO_MAATREGEL
				? this.bioMaatregelen.length === 0
				: this.standardVersions.length === 0
		},

		/**
		 * NcSelect options for the standards picker.
		 *
		 * @return {Array<{uuid: string, label: string}>} Options.
		 * @spec openspec/specs/module-compliance-assessment/spec.md
		 */
		standardOptions() {
			return this.standardVersions.map((standard) => ({
				uuid: resolveUuid(
					standard.uuid
						?? standard.id
						?? standard['@self']?.id
						?? standard,
				),
				label: columnLabel(standard),
				raw: standard,
			}))
		},

		/**
		 * NcSelect options for the BIO measures picker.
		 *
		 * @return {Array<{uuid: string, label: string}>} Options.
		 * @spec openspec/specs/bio-compliance-assessment/spec.md#requirement-bio-measures-form-a-seedable-reference-catalog
		 */
		bioMaatregelOptions() {
			return this.bioMaatregelen.map((measure) => ({
				uuid: resolveUuid(
					measure.uuid ?? measure.id ?? measure['@self']?.id ?? measure,
				),
				label: columnLabel(measure),
				raw: measure,
			}))
		},

		/**
		 * The options for the active column source.
		 *
		 * @return {Array<{uuid: string, label: string}>} Options.
		 * @spec openspec/specs/bio-compliance-assessment/spec.md
		 */
		columnOptions() {
			return this.columnSource === COLUMN_SOURCE.BIO_MAATREGEL
				? this.bioMaatregelOptions
				: this.standardOptions
		},

		/**
		 * The current column selection, proxied to whichever of
		 * selectedStandards/selectedBioMeasures matches the active column
		 * source — so switching scope back and forth does not lose a
		 * previously-made selection in the other scope.
		 *
		 * @return {Array<object>} Selected NcSelect options.
		 * @spec openspec/specs/bio-compliance-assessment/spec.md
		 */
		selectedColumns: {
			/**
			 * @return {Array<object>} Selected NcSelect options for the active scope.
			 * @spec openspec/specs/bio-compliance-assessment/spec.md
			 */
			get() {
				return this.columnSource === COLUMN_SOURCE.BIO_MAATREGEL
					? this.selectedBioMeasures
					: this.selectedStandards
			},

			/**
			 * @param {Array<object>} value The new selection for the active scope.
			 * @return {void}
			 * @spec openspec/specs/bio-compliance-assessment/spec.md
			 */
			set(value) {
				if (this.columnSource === COLUMN_SOURCE.BIO_MAATREGEL) {
					this.selectedBioMeasures = value
				} else {
					this.selectedStandards = value
				}
			},
		},

		/**
		 * NcSelect options for the organisation scope picker.
		 *
		 * @return {Array<{uuid: string, label: string}>} Options.
		 * @spec openspec/specs/bio-compliance-assessment/spec.md#requirement-organisation-bio-coverage-is-reportable
		 */
		organisationOptions() {
			return this.organisaties.map((org) => {
				const data = dataOf(org)
				return {
					uuid: resolveUuid(org.uuid ?? org.id ?? org['@self']?.id ?? org),
					label:
						data.name
						|| data.title
						|| resolveUuid(org.uuid ?? org.id ?? ''),
					raw: org,
				}
			})
		},

		/**
		 * Modules in scope for the matrix: every module, unless an
		 * organisation is selected — then only that organisation's in-use
		 * applications (gebruik.consumer === org → gebruik.module), per the
		 * BIO coverage report requirement. Applications with no compliance
		 * data are still included (rendered as "none"/"not set"), never
		 * omitted.
		 *
		 * @return {Array<object>} Modules to render as matrix rows.
		 * @spec openspec/specs/bio-compliance-assessment/spec.md#requirement-organisation-bio-coverage-is-reportable
		 */
		scopedModules() {
			if (!this.selectedOrganisation) {
				return this.modules
			}
			const orgUuid = this.selectedOrganisation.uuid
			const inUseModuleUuids = new Set(
				this.usages
					.map((g) => dataOf(g))
					.filter((data) => resolveUuid(data.consumer) === orgUuid)
					.map((data) => resolveUuid(data.module))
					.filter(Boolean),
			)
			return this.modules.filter((module) =>
				inUseModuleUuids.has(
					resolveUuid(module.uuid ?? module.id ?? module),
				),
			)
		},

		/**
		 * The computed matrix for the current selection.
		 *
		 * @return {object} { rows, columns, unresolved, conflicted }.
		 * @spec openspec/specs/module-compliance-assessment/spec.md
		 * @spec openspec/specs/bio-compliance-assessment/spec.md
		 */
		matrix() {
			const selected = this.selectedColumns.map(
				(option) => option.raw || option,
			)
			return buildComplianceMatrix({
				modules: this.scopedModules,
				columns: selected,
				compliancy: this.compliancy,
				columnSource: this.columnSource,
			})
		},
	},

	watch: {
		/**
		 * Re-encode the URL whenever the column-source scope changes (the
		 * `v-model`-bound radio switches update `columnSource` directly, so
		 * this is the only hook point for that transition).
		 *
		 * @return {void}
		 * @spec openspec/specs/bio-compliance-assessment/spec.md
		 */
		columnSource() {
			this.onSelectionChange()
		},
	},

	/**
	 * Load data and restore any shared selection on mount.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/module-compliance-assessment/spec.md
	 */
	async mounted() {
		await this.loadData()
		this.restoreSelectionFromUrl()
	},

	methods: {
		t,

		/**
		 * Fetch the collections the matrix depends on.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/module-compliance-assessment/spec.md
		 * @spec openspec/specs/bio-compliance-assessment/spec.md
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
					this.fetchType('module'),
					this.fetchType('compliancy'),
					this.fetchType('element'),
					this.fetchType('bioMeasure'),
					this.fetchType('usage'),
					this.fetchType('organization'),
				])
			} catch (error) {
				console.error('ComplianceMatrixView: failed to load data', error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch a single object type collection if the store exposes the action.
		 *
		 * @param {string} type Object type slug.
		 * @return {Promise<void>}
		 * @spec openspec/specs/module-compliance-assessment/spec.md
		 */
		async fetchType(type) {
			// Register the object type from the resolved config before fetching —
			// `initializeVoorzieningenObjectTypes` only auto-registers when the
			// settings `availableRegisters` carries a register slugged exactly
			// 'voorzieningen', which is not guaranteed on every instance. Without
			// this the nc-vue object store throws "Object type <type> is not
			// registered". Mirrors the OrganisatieIndex registration fallback.
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
				// A single type whose register/schema is unavailable (e.g. the
				// optional ArchiMate `element` register is not provisioned on this
				// instance) must not abort the whole matrix load — the surface
				// renders its filter-first empty state from whatever did resolve.
				try {
					await objectStore.fetchCollection(type, { _limit: 1000 })
				} catch (error) {
					console.warn(
						`ComplianceMatrixView: ${type} collection unavailable`,
						error,
					)
				}
			}
		},

		/**
		 * Encode the current selection (column source, standards, BIO
		 * measures, organisation scope) in the URL so the comparison is
		 * shareable — restoring it renders the same selection without
		 * re-picking filters.
		 *
		 * @return {void}
		 * @spec openspec/specs/module-compliance-assessment/spec.md
		 * @spec openspec/specs/bio-compliance-assessment/spec.md
		 */
		onSelectionChange() {
			const url = new URL(window.location.href)

			url.searchParams.set('columnSource', this.columnSource)

			const standardUuids = this.selectedStandards
				.map((option) => option.uuid)
				.filter(Boolean)
			if (standardUuids.length > 0) {
				url.searchParams.set('standards', standardUuids.join(','))
			} else {
				url.searchParams.delete('standards')
			}

			const bioMeasureUuids = this.selectedBioMeasures
				.map((option) => option.uuid)
				.filter(Boolean)
			if (bioMeasureUuids.length > 0) {
				url.searchParams.set('bioMeasures', bioMeasureUuids.join(','))
			} else {
				url.searchParams.delete('bioMeasures')
			}

			if (this.selectedOrganisation?.uuid) {
				url.searchParams.set('org', this.selectedOrganisation.uuid)
			} else {
				url.searchParams.delete('org')
			}

			window.history.replaceState({}, '', url.toString())
		},

		/**
		 * Restore the selection from the URL query parameters (`columnSource`,
		 * `standards`, `bioMeasures`, `org`).
		 *
		 * @return {void}
		 * @spec openspec/specs/module-compliance-assessment/spec.md
		 * @spec openspec/specs/bio-compliance-assessment/spec.md
		 */
		restoreSelectionFromUrl() {
			const url = new URL(window.location.href)

			const columnSource = url.searchParams.get('columnSource')
			if (
				columnSource === COLUMN_SOURCE.BIO_MAATREGEL
				|| columnSource === COLUMN_SOURCE.STANDAARDVERSIE
			) {
				this.columnSource = columnSource
			}

			const rawStandards = url.searchParams.get('standards')
			if (rawStandards) {
				const wanted = new Set(
					rawStandards
						.split(',')
						.map((s) => s.trim())
						.filter(Boolean),
				)
				this.selectedStandards = this.standardOptions.filter((option) =>
					wanted.has(option.uuid),
				)
			}

			const rawBioMeasures = url.searchParams.get('bioMeasures')
			if (rawBioMeasures) {
				const wanted = new Set(
					rawBioMeasures
						.split(',')
						.map((s) => s.trim())
						.filter(Boolean),
				)
				this.selectedBioMeasures = this.bioMaatregelOptions.filter(
					(option) => wanted.has(option.uuid),
				)
			}

			const rawOrg = url.searchParams.get('org')
			if (rawOrg) {
				this.selectedOrganisation =
					this.organisationOptions.find((option) => option.uuid === rawOrg)
					|| null
			}
		},

		/**
		 * Open the compliancy record behind a cell (detail navigation).
		 *
		 * @param {object} record The compliancy OR object.
		 * @return {void}
		 * @spec openspec/specs/module-compliance-assessment/spec.md
		 */
		openRecord(record) {
			const id = record?.['@self']?.id || record?.id || resolveUuid(record)
			if (id && typeof navigationStore.setSelected === 'function') {
				navigationStore.setSelected('komplianties')
			}
		},

		/**
		 * Human label for a module row header.
		 *
		 * @param {object} module Module object.
		 * @return {string} Display label.
		 * @spec openspec/specs/module-compliance-assessment/spec.md
		 */
		moduleLabel(module) {
			return (
				module?.name
				|| module?.object?.name
				|| resolveUuid(module?.uuid ?? module?.id ?? module)
			)
		},

		/**
		 * Translated label for a cell state.
		 *
		 * @param {string} state Cell state.
		 * @return {string} Label.
		 * @spec openspec/specs/module-compliance-assessment/spec.md
		 */
		stateLabel(state) {
			if (state === 'verified') {
				return t('stackiq', 'Verified')
			}
			if (state === 'claimed') {
				return t('stackiq', 'Claimed')
			}
			return t('stackiq', 'None')
		},

		/**
		 * Accessible label describing a cell for screen readers.
		 *
		 * @param {object} row    Matrix row.
		 * @param {object} column Matrix column.
		 * @return {string} Aria label.
		 * @spec openspec/specs/module-compliance-assessment/spec.md
		 */
		cellAriaLabel(row, column) {
			return (
				this.moduleLabel(row.module)
				+ ' — '
				+ column.label
				+ ': '
				+ this.stateLabel(row.cells[column.uuid].state)
			)
		},

		/**
		 * BBN level for a module row in the BIO coverage report. Applications
		 * with no BBN level are shown as "Not set" — never omitted.
		 *
		 * @param {object} module Module object.
		 * @return {string} Display label.
		 * @spec openspec/specs/bio-compliance-assessment/spec.md#requirement-organisation-bio-coverage-is-reportable
		 */
		bbnLevelLabel(module) {
			const data = dataOf(module)
			return data.bbnLevel || t('stackiq', 'Not set')
		},

		/**
		 * DPIA status for a module row in the BIO coverage report, translated.
		 * Applications with no DPIA status are shown as "Not set" — never
		 * omitted.
		 *
		 * @param {object} module Module object.
		 * @return {string} Display label.
		 * @spec openspec/specs/bio-compliance-assessment/spec.md#requirement-organisation-bio-coverage-is-reportable
		 */
		dpiaStatusLabel(module) {
			const data = dataOf(module)
			if (data.dpiaStatus === 'executed') {
				return t('stackiq', 'Executed')
			}
			if (data.dpiaStatus === 'required') {
				return t('stackiq', 'Required')
			}
			if (data.dpiaStatus === 'not required') {
				return t('stackiq', 'Not required')
			}
			return t('stackiq', 'Not set')
		},
	},
}
</script>

<style scoped>
.complianceMatrixView {
	padding: 20px;
}

.cmv-header {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 16px;
}

.cmv-title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.cmv-intro {
	margin: 0;
	color: var(--color-text-maxcontrast);
	max-width: 70ch;
}

.cmv-scope {
	display: flex;
	gap: 16px;
	margin-bottom: 12px;
}

.cmv-filters {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	margin-bottom: 16px;
}

.cmv-standardSelect,
.cmv-orgSelect {
	max-width: 480px;
	flex: 1 1 320px;
}

.cmv-cell--meta {
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.cmv-legend {
	display: flex;
	gap: 20px;
	flex-wrap: wrap;
	margin-bottom: 12px;
	font-size: 13px;
}

.cmv-legendItem {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}

.cmv-tableWrap {
	overflow-x: auto;
}

.cmv-table {
	width: 100%;
	border-collapse: collapse;
}

.cmv-table th,
.cmv-table td {
	border: 1px solid var(--color-border);
	padding: 8px 12px;
	text-align: left;
}

.cmv-rowHeader {
	font-weight: 600;
	background: var(--color-background-hover);
}

.cmv-cell {
	text-align: center;
}

.cmv-cell--verified {
	background: var(--color-success, #2d7d46);
	background: color-mix(in srgb, var(--color-success) 12%, transparent);
}

.cmv-cell--claimed {
	background: color-mix(in srgb, var(--color-warning) 12%, transparent);
}

.cmv-cellButton {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-main-text);
	font: inherit;
}

.cmv-cellNone {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	color: var(--color-text-maxcontrast);
}

.cmv-iconVerified {
	color: var(--color-success);
}

.cmv-iconClaimed {
	color: var(--color-warning);
}

.cmv-iconNone {
	color: var(--color-text-maxcontrast);
}

.cmv-unresolved {
	margin-top: 16px;
}

.cmv-loading {
	margin: 40px auto;
	display: block;
}
</style>
