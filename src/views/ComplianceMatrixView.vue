<template>
	<div class="complianceMatrixView">
		<div class="cmv-header">
			<h2 class="cmv-title">
				{{ t('softwarecatalog', 'Compliance matrix') }}
			</h2>
			<p class="cmv-intro">
				{{ t('softwarecatalog', 'Which applications support which standards. A verified cell traces to evidence; a claimed cell is a supplier statement without evidence.') }}
			</p>
			<NcButton type="tertiary" :aria-label="t('softwarecatalog', 'Refresh data')" @click="loadData">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Refresh v-else :size="20" />
				</template>
				{{ t('softwarecatalog', 'Refresh') }}
			</NcButton>
		</div>

		<!-- Filter-first: pick standards before cells render. -->
		<div class="cmv-filters">
			<NcSelect
				v-model="selectedStandards"
				class="cmv-standardSelect"
				:options="standardOptions"
				:multiple="true"
				:close-on-select="false"
				:input-label="t('softwarecatalog', 'Standards')"
				:placeholder="t('softwarecatalog', 'Select one or more standards')"
				track-by="uuid"
				label="label"
				@input="onSelectionChange" />
		</div>

		<NcEmptyContent
			v-if="!loading && noStandardsImported"
			:name="t('softwarecatalog', 'No standards imported')"
			:description="t('softwarecatalog', 'Import GEMMA standards via the ArchiMate import before building a compliance matrix.')">
			<template #icon>
				<CheckboxMarkedCircleOutline :size="40" />
			</template>
		</NcEmptyContent>

		<NcEmptyContent
			v-else-if="!loading && selectedStandards.length === 0"
			:name="t('softwarecatalog', 'Select standards to compare')"
			:description="t('softwarecatalog', 'Pick one or more standards above to render the compliance matrix.')">
			<template #icon>
				<TableLarge :size="40" />
			</template>
		</NcEmptyContent>

		<div v-else-if="!loading" class="cmv-tableWrap">
			<!-- Legend — states must not rely on colour alone (WCAG AA). -->
			<div class="cmv-legend" aria-hidden="false">
				<span class="cmv-legendItem"><CheckCircle :size="16" class="cmv-iconVerified" /> {{ t('softwarecatalog', 'Verified (with evidence)') }}</span>
				<span class="cmv-legendItem"><HelpCircle :size="16" class="cmv-iconClaimed" /> {{ t('softwarecatalog', 'Claimed (no evidence)') }}</span>
				<span class="cmv-legendItem"><MinusCircle :size="16" class="cmv-iconNone" /> {{ t('softwarecatalog', 'None') }}</span>
			</div>

			<table class="cmv-table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('softwarecatalog', 'Module') }}
						</th>
						<th v-for="column in matrix.columns" :key="column.uuid" scope="col">
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
							v-for="column in matrix.columns"
							:key="column.uuid"
							:class="['cmv-cell', 'cmv-cell--' + row.cells[column.uuid].state]">
							<button
								v-if="row.cells[column.uuid].record"
								type="button"
								class="cmv-cellButton"
								:aria-label="cellAriaLabel(row, column)"
								@click="openRecord(row.cells[column.uuid].record)">
								<CheckCircle v-if="row.cells[column.uuid].state === 'verified'" :size="18" class="cmv-iconVerified" />
								<HelpCircle v-else :size="18" class="cmv-iconClaimed" />
								<span class="cmv-cellText">{{ stateLabel(row.cells[column.uuid].state) }}</span>
							</button>
							<span v-else class="cmv-cellNone" :aria-label="cellAriaLabel(row, column)">
								<MinusCircle :size="18" class="cmv-iconNone" />
								<span class="cmv-cellText">{{ stateLabel('none') }}</span>
							</span>
						</td>
					</tr>
				</tbody>
			</table>

			<div v-if="matrix.unresolved.length > 0" class="cmv-unresolved">
				<NcNoteCard type="warning">
					{{ t('softwarecatalog', 'Some compliancy records only reference a standard by name and could not be matched to a standard version. They are excluded from the matrix.') + ' (' + matrix.unresolved.length + ')' }}
				</NcNoteCard>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" :size="40" class="cmv-loading" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSelect, NcEmptyContent, NcNoteCard } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { objectStore, navigationStore } from '../store/store.js'
import { buildComplianceMatrix, resolveUuid, standardLabel } from '../utils/complianceMatrix.js'

import Refresh from 'vue-material-design-icons/Refresh.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import HelpCircle from 'vue-material-design-icons/HelpCircle.vue'
import MinusCircle from 'vue-material-design-icons/MinusCircle.vue'
import TableLarge from 'vue-material-design-icons/TableLarge.vue'
import CheckboxMarkedCircleOutline from 'vue-material-design-icons/CheckboxMarkedCircleOutline.vue'

/**
 * @class ComplianceMatrixView
 * @module Views
 * @copyright 2026 Conduction B.V.
 * @license AGPL-3.0-or-later
 *
 * Filter-first compliance matrix: modules × selected standard versions, with
 * verified / claimed / none cell states. The buyer-facing answer to "does this
 * application support standard X, and is that a claim or a fact?".
 *
 * @spec openspec/changes/module-compliance-assessment/specs/module-compliance-assessment/spec.md
 */
export default {
	name: 'ComplianceMatrixView',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcEmptyContent,
		NcNoteCard,
		Refresh,
		CheckCircle,
		HelpCircle,
		MinusCircle,
		TableLarge,
		CheckboxMarkedCircleOutline,
	},

	data() {
		return {
			loading: true,
			selectedStandards: [],
		}
	},

	computed: {
		/**
		 * All compliancy records currently in the store.
		 * @return {Array} Compliancy records.
		 * @spec openspec/changes/module-compliance-assessment/specs/module-compliance-assessment/spec.md
		 */
		compliancy() {
			return objectStore.getCollection('compliancy')?.results || []
		},

		/**
		 * All module records currently in the store.
		 * @return {Array} Module records.
		 * @spec openspec/changes/module-compliance-assessment/specs/module-compliance-assessment/spec.md
		 */
		modules() {
			return objectStore.getCollection('module')?.results || []
		},

		/**
		 * All standaardversie elements (GEMMA elements with gemmaType=standaardversie).
		 * @return {Array} Standard version records.
		 * @spec openspec/changes/module-compliance-assessment/specs/module-compliance-assessment/spec.md
		 */
		standaardversies() {
			const elements = objectStore.getCollection('element')?.results || []
			return elements.filter((el) => (el.gemmaType || el.object?.gemmaType) === 'standaardversie')
		},

		/**
		 * Whether no standards are available to pick.
		 * @return {boolean} True when no standaardversie elements exist.
		 * @spec openspec/changes/module-compliance-assessment/specs/module-compliance-assessment/spec.md
		 */
		noStandardsImported() {
			return this.standaardversies.length === 0
		},

		/**
		 * NcSelect options for the standards picker.
		 * @return {Array<{uuid: string, label: string}>} Options.
		 * @spec openspec/changes/module-compliance-assessment/specs/module-compliance-assessment/spec.md
		 */
		standardOptions() {
			return this.standaardversies.map((standard) => ({
				uuid: resolveUuid(standard.uuid ?? standard.id ?? standard['@self']?.id ?? standard),
				label: standardLabel(standard),
				raw: standard,
			}))
		},

		/**
		 * The computed matrix for the current selection.
		 * @return {object} { rows, columns, unresolved }.
		 * @spec openspec/changes/module-compliance-assessment/specs/module-compliance-assessment/spec.md
		 */
		matrix() {
			const selected = this.selectedStandards.map((option) => option.raw || option)
			return buildComplianceMatrix({
				modules: this.modules,
				standaardversies: selected,
				compliancy: this.compliancy,
			})
		},
	},

	/**
	 * Load data and restore any shared selection on mount.
	 * @return {Promise<void>}
	 * @spec openspec/changes/module-compliance-assessment/specs/module-compliance-assessment/spec.md
	 */
	async mounted() {
		await this.loadData()
		this.restoreSelectionFromUrl()
	},

	methods: {
		t,

		/**
		 * Fetch the collections the matrix depends on.
		 * @return {Promise<void>}
		 * @spec openspec/changes/module-compliance-assessment/specs/module-compliance-assessment/spec.md
		 */
		async loadData() {
			this.loading = true
			try {
				if (!objectStore.settings && typeof objectStore.fetchSettings === 'function') {
					await objectStore.fetchSettings()
				}
				await Promise.all([
					this.fetchType('module'),
					this.fetchType('compliancy'),
					this.fetchType('element'),
				])
			} catch (error) {
				console.error('ComplianceMatrixView: failed to load data', error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch a single object type collection if the store exposes the action.
		 * @param {string} type Object type slug.
		 * @return {Promise<void>}
		 * @spec openspec/changes/module-compliance-assessment/specs/module-compliance-assessment/spec.md
		 */
		async fetchType(type) {
			// Register the object type from the resolved config before fetching —
			// `initializeVoorzieningenObjectTypes` only auto-registers when the
			// settings `availableRegisters` carries a register slugged exactly
			// 'voorzieningen', which is not guaranteed on every instance. Without
			// this the nc-vue object store throws "Object type <type> is not
			// registered". Mirrors the OrganisatieIndex registration fallback.
			if (typeof objectStore.registerObjectType === 'function'
				&& !objectStore.objectTypeRegistry?.[type]) {
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
					console.warn(`ComplianceMatrixView: ${type} collection unavailable`, error)
				}
			}
		},

		/**
		 * Encode the standard selection in the URL so the comparison is shareable.
		 * @return {void}
		 * @spec openspec/changes/module-compliance-assessment/specs/module-compliance-assessment/spec.md
		 */
		onSelectionChange() {
			const uuids = this.selectedStandards.map((option) => option.uuid).filter(Boolean)
			const url = new URL(window.location.href)
			if (uuids.length > 0) {
				url.searchParams.set('standards', uuids.join(','))
			} else {
				url.searchParams.delete('standards')
			}
			window.history.replaceState({}, '', url.toString())
		},

		/**
		 * Restore the selection from the URL `standards` query parameter.
		 * @return {void}
		 * @spec openspec/changes/module-compliance-assessment/specs/module-compliance-assessment/spec.md
		 */
		restoreSelectionFromUrl() {
			const url = new URL(window.location.href)
			const raw = url.searchParams.get('standards')
			if (!raw) {
				return
			}
			const wanted = new Set(raw.split(',').map((s) => s.trim()).filter(Boolean))
			this.selectedStandards = this.standardOptions.filter((option) => wanted.has(option.uuid))
		},

		/**
		 * Open the compliancy record behind a cell (detail navigation).
		 * @param {object} record The compliancy OR object.
		 * @return {void}
		 * @spec openspec/changes/module-compliance-assessment/specs/module-compliance-assessment/spec.md
		 */
		openRecord(record) {
			const id = record?.['@self']?.id || record?.id || resolveUuid(record)
			if (id && typeof navigationStore.setSelected === 'function') {
				navigationStore.setSelected('komplianties')
			}
		},

		/**
		 * Human label for a module row header.
		 * @param {object} module Module object.
		 * @return {string} Display label.
		 * @spec openspec/changes/module-compliance-assessment/specs/module-compliance-assessment/spec.md
		 */
		moduleLabel(module) {
			return module?.naam || module?.object?.naam || resolveUuid(module?.uuid ?? module?.id ?? module)
		},

		/**
		 * Translated label for a cell state.
		 * @param {string} state Cell state.
		 * @return {string} Label.
		 * @spec openspec/changes/module-compliance-assessment/specs/module-compliance-assessment/spec.md
		 */
		stateLabel(state) {
			if (state === 'verified') {
				return t('softwarecatalog', 'Verified')
			}
			if (state === 'claimed') {
				return t('softwarecatalog', 'Claimed')
			}
			return t('softwarecatalog', 'None')
		},

		/**
		 * Accessible label describing a cell for screen readers.
		 * @param {object} row    Matrix row.
		 * @param {object} column Matrix column.
		 * @return {string} Aria label.
		 * @spec openspec/changes/module-compliance-assessment/specs/module-compliance-assessment/spec.md
		 */
		cellAriaLabel(row, column) {
			return this.moduleLabel(row.module) + ' — ' + column.label + ': ' + this.stateLabel(row.cells[column.uuid].state)
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

.cmv-filters {
	margin-bottom: 16px;
	max-width: 480px;
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
