<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div class="sbom-components-panel">
		<NcLoadingIcon
			v-if="loading"
			:size="32"
			:name="t('softwarecatalog', 'Loading components')" />

		<template v-else>
			<!-- Summary counts (design Decision 6 / spec "summary counts"). -->
			<div class="sbom-summary" data-testid="sbom-summary">
				<div class="sbom-summary__tile">
					<span class="sbom-summary__value">{{ totalComponents }}</span>
					<span class="sbom-summary__label">{{
						t('softwarecatalog', 'Components')
					}}</span>
				</div>
				<div class="sbom-summary__tile">
					<span class="sbom-summary__value">{{
						distinctLicenseCount
					}}</span>
					<span class="sbom-summary__label">{{
						t('softwarecatalog', 'Distinct licenses')
					}}</span>
				</div>
				<div
					class="sbom-summary__tile"
					:class="{
						'sbom-summary__tile--warn': matchedVulnerabilityCount > 0,
					}">
					<span class="sbom-summary__value">{{
						matchedVulnerabilityCount
					}}</span>
					<span class="sbom-summary__label">{{
						t('softwarecatalog', 'Matched vulnerabilities')
					}}</span>
				</div>
			</div>

			<p
				v-if="lastImportedLabel"
				class="sbom-provenance"
				data-testid="sbom-provenance">
				{{ lastImportedLabel }}
			</p>

			<!-- Upload control — always available, so a first import and a
			     replace-on-reimport use the same control (design Decision 3). -->
			<div class="sbom-upload">
				<NcSelect
					v-model="format"
					class="sbom-upload__format"
					:options="formatOptions"
					:inputLabel="t('softwarecatalog', 'SBOM format')"
					label="label"
					:reduce="(option) => option.value"
					:clearable="false"
					:disabled="uploading" />

				<input
					id="sbom-file-input"
					ref="fileInput"
					type="file"
					accept=".json,application/json"
					class="sbom-upload__file-input"
					:disabled="uploading"
					data-testid="sbom-file-input"
					@change="handleFileSelect" />
				<label
					for="sbom-file-input"
					class="sbom-upload__file-label"
					:class="{ 'sbom-upload__file-label--disabled': uploading }">
					<TrayArrowUp :size="20" />
					<span>{{
						selectedFile
							? selectedFile.name
							: t('softwarecatalog', 'Choose an SBOM JSON file')
					}}</span>
					<span v-if="selectedFile" class="sbom-upload__file-size">{{
						formatFileSize(selectedFile.size)
					}}</span>
				</label>

				<NcButton
					variant="primary"
					:disabled="!selectedFile || uploading"
					data-testid="sbom-import-button"
					@click="importSbom">
					<template #icon>
						<NcLoadingIcon v-if="uploading" :size="20" />
					</template>
					{{ t('softwarecatalog', 'Import SBOM') }}
				</NcButton>
			</div>

			<NcNoteCard
				v-if="uploadError"
				type="error"
				data-testid="sbom-upload-error">
				{{ uploadError }}
			</NcNoteCard>
			<NcNoteCard
				v-if="uploadSuccessMessage"
				type="success"
				data-testid="sbom-upload-success">
				{{ uploadSuccessMessage }}
			</NcNoteCard>

			<!-- Empty state (spec: "no summary counts shown as non-zero" — the
			     tiles above already read 0/0/0 since `components` is empty). -->
			<NcEmptyContent
				v-if="totalComponents === 0"
				:name="t('softwarecatalog', 'No components imported yet')"
				:description="
					t(
						'softwarecatalog',
						'Import a CycloneDX or SPDX SBOM to see this version\'s components, licenses and any matching known vulnerabilities.',
					)
				"
				data-testid="sbom-empty">
				<template #icon>
					<PackageVariantClosed :size="36" />
				</template>
			</NcEmptyContent>

			<CnDataTable
				v-else
				:rows="rows"
				:columns="columns"
				rowKey="id"
				data-testid="sbom-component-table"
				:emptyText="t('softwarecatalog', 'No components imported yet')">
				<template #column-licenses="{ row }">
					<span>{{ row.licenses || '—' }}</span>
				</template>
				<template #column-match="{ row }">
					<span
						v-if="row.confirmedCount > 0"
						class="sbom-badge sbom-badge--confirmed"
						data-testid="sbom-match-confirmed">
						{{ t('softwarecatalog', 'Confirmed match') }}
					</span>
					<span
						v-if="row.possibleCount > 0"
						class="sbom-badge sbom-badge--possible"
						data-testid="sbom-match-possible">
						{{ t('softwarecatalog', 'Possible match') }}
					</span>
				</template>
			</CnDataTable>
		</template>
	</div>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import PackageVariantClosed from 'vue-material-design-icons/PackageVariantClosed.vue'
import TrayArrowUp from 'vue-material-design-icons/TrayArrowUp.vue'
import { objectStore } from '../../store/store.js'
import { resolveUuid } from '../../utils/lifecyclePhase.js'
import { matchComponents } from '../../utils/sbomVulnerabilityMatch.js'

/**
 * @class SbomComponentsPanel
 * @module Components/Sbom
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * ModuleversieDetail "Components" sidebar tab: renders the imported
 * `sbomComponent` set for a `moduleVersie` (name/version/purl/licenses) with
 * summary counts (total, distinct licenses, matched vulnerabilities) and an
 * upload control that posts a CycloneDX/SPDX JSON file to `SbomController`.
 * Re-importing REPLACES the previous set server-side (design Decision 3);
 * this panel just refetches after a successful import. Vulnerability
 * matching is computed at render time via `sbomVulnerabilityMatch.js` —
 * never persisted, never re-fetched from a stored field (design Decision 6).
 *
 * @spec openspec/specs/sbom-import/spec.md#requirement-the-module-version-detail-page-shows-imported-components-with-summary-counts
 */
export default {
	name: 'SbomComponentsPanel',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		CnDataTable,
		TrayArrowUp,
		PackageVariantClosed,
	},

	props: {
		/** The moduleVersie OR object uuid (passed by CnObjectSidebar as `objectId`). */
		objectId: {
			type: [String, Number],
			default: null,
		},
	},

	data() {
		return {
			loading: true,
			uploading: false,
			selectedFile: null,
			format: 'cyclonedx-json',
			uploadError: null,
			uploadSuccessMessage: null,
			formatOptions: [
				{
					value: 'cyclonedx-json',
					label: t('softwarecatalog', 'CycloneDX (JSON)'),
				},
				{ value: 'spdx-json', label: t('softwarecatalog', 'SPDX (JSON)') },
			],

			columns: [
				{ key: 'name', label: t('softwarecatalog', 'Name'), sortable: true },
				{
					key: 'version',
					label: t('softwarecatalog', 'Version'),
					sortable: true,
				},
				{
					key: 'purl',
					label: t('softwarecatalog', 'Package URL'),
					cellClass: 'sbom-cell--purl',
				},
				{ key: 'licenses', label: t('softwarecatalog', 'Licenses') },
				{ key: 'match', label: t('softwarecatalog', 'Vulnerability match') },
			],
		}
	},

	computed: {
		/**
		 * The moduleVersie being inspected: the active object, else looked up
		 * by objectId in the fetched collection.
		 *
		 * @return {object|null} The moduleVersie record.
		 * @spec openspec/specs/sbom-import/spec.md#requirement-moduleversie-records-sbom-import-provenance
		 */
		moduleVersie() {
			const active =
				typeof objectStore.getActiveObject === 'function'
					? objectStore.getActiveObject('moduleVersion')
					: null
			if (
				active
				&& resolveUuid(
					active.uuid ?? active.id ?? active['@self']?.id ?? active,
				) === String(this.objectId)
			) {
				return active
			}
			return (
				(objectStore.getCollection('moduleVersion')?.results || []).find(
					(v) =>
						resolveUuid(v.uuid ?? v.id ?? v['@self']?.id ?? v)
						=== String(this.objectId),
				)
				|| active
				|| null
			)
		},

		/**
		 * The moduleVersie's raw data bag.
		 *
		 * @return {object} The property bag.
		 * @spec openspec/specs/sbom-import/spec.md#requirement-imported-components-persist-as-openregister-objects-scoped-to-a-moduleversie
		 */
		moduleVersieData() {
			if (!this.moduleVersion) {
				return {}
			}
			return this.moduleVersion.object || this.moduleVersion
		},

		/**
		 * The moduleVersie's parent module uuid — scopes the possible-match
		 * heuristic (design Decision 6, never a catalogue-wide scan).
		 *
		 * @return {string} The parent module uuid, or ''.
		 * @spec openspec/specs/sbom-import/spec.md#requirement-components-are-matched-against-existing-kwetsbaarheden-without-external-calls
		 */
		parentModuleId() {
			return resolveUuid(this.moduleVersieData.module)
		},

		/**
		 * The imported `sbomComponent` set for this moduleVersie, sorted by name.
		 *
		 * @return {Array<object>} The component records.
		 * @spec openspec/specs/sbom-import/spec.md#requirement-imported-components-persist-as-openregister-objects-scoped-to-a-moduleversie
		 */
		components() {
			const all = objectStore.getCollection('sbomComponent')?.results || []
			return all
				.filter(
					(c) =>
						resolveUuid((c.object || c).moduleVersion)
						=== String(this.objectId),
				)
				.slice()
				.sort((a, b) =>
					String((a.object || a).name || '').localeCompare(
						String((b.object || b).name || ''),
					),
				)
		},

		/**
		 * All kwetsbaarheid records — the vulnerability-match candidate set.
		 *
		 * @return {Array<object>} The kwetsbaarheid records.
		 * @spec openspec/specs/sbom-import/spec.md#requirement-components-are-matched-against-existing-kwetsbaarheden-without-external-calls
		 */
		kwetsbaarheden() {
			return objectStore.getCollection('vulnerability')?.results || []
		},

		/**
		 * Per-component confirmed/possible matches, plus the distinct
		 * matched-vulnerability count for the summary tile.
		 *
		 * @return {{rows: Array<object>, matchedVulnerabilityCount: number}} The match computation.
		 * @spec openspec/specs/sbom-import/spec.md#requirement-components-are-matched-against-existing-kwetsbaarheden-without-external-calls
		 */
		matches() {
			return matchComponents(
				this.components,
				this.kwetsbaarheden,
				this.parentModuleId,
			)
		},

		/**
		 * Display rows for CnDataTable: name/version/purl/licenses plus match badge counts.
		 *
		 * @return {Array<object>} The table rows.
		 * @spec openspec/specs/sbom-import/spec.md#requirement-the-module-version-detail-page-shows-imported-components-with-summary-counts
		 */
		rows() {
			return this.matches.rows.map(({ component, confirmed, possible }) => {
				const data = component.object || component
				return {
					id:
						resolveUuid(
							component.uuid
								?? component.id
								?? component['@self']?.id
								?? component,
						) || data.name,
					name: data.name || '',
					version: data.version || '',
					purl: data.purl || '',
					licenses: Array.isArray(data.licenses)
						? data.licenses.join(', ')
						: '',
					confirmedCount: confirmed.length,
					possibleCount: possible.length,
				}
			})
		},

		/**
		 * Total imported component count.
		 *
		 * @return {number} The count.
		 * @spec openspec/specs/sbom-import/spec.md#requirement-the-module-version-detail-page-shows-imported-components-with-summary-counts
		 */
		totalComponents() {
			return this.components.length
		},

		/**
		 * Distinct, non-empty license count across the imported set.
		 *
		 * @return {number} The count.
		 * @spec openspec/specs/sbom-import/spec.md#requirement-the-module-version-detail-page-shows-imported-components-with-summary-counts
		 */
		distinctLicenseCount() {
			const set = new Set()
			for (const component of this.components) {
				const licenses = (component.object || component).licenses
				if (Array.isArray(licenses)) {
					for (const license of licenses) {
						if (typeof license === 'string' && license !== '') {
							set.add(license)
						}
					}
				}
			}
			return set.size
		},

		/**
		 * The distinct matched-vulnerability count (confirmed + possible,
		 * deduplicated) across the whole component list.
		 *
		 * @return {number} The count.
		 * @spec openspec/specs/sbom-import/spec.md#requirement-components-are-matched-against-existing-kwetsbaarheden-without-external-calls
		 */
		matchedVulnerabilityCount() {
			return this.matches.matchedVulnerabilityCount
		},

		/**
		 * "Last imported <date> from <file>, <format>" provenance line, or ''
		 * when no import has happened yet.
		 *
		 * @return {string} The label.
		 * @spec openspec/specs/sbom-import/spec.md#requirement-moduleversie-records-sbom-import-provenance
		 */
		lastImportedLabel() {
			const data = this.moduleVersieData
			if (!data.sbomLastImportedAt) {
				return ''
			}
			const date = new Date(data.sbomLastImportedAt)
			const dateLabel = Number.isNaN(date.getTime())
				? data.sbomLastImportedAt
				: date.toLocaleString()
			const formatLabel =
				data.sbomFormat === 'spdx-json' ? 'SPDX' : 'CycloneDX'
			return t(
				'softwarecatalog',
				'Last imported {date} from {file} ({format})',
				{
					date: dateLabel,
					file: data.sbomFileName || '?',
					format: formatLabel,
				},
			)
		},
	},

	async mounted() {
		await this.loadData()
	},

	methods: {
		t,

		/**
		 * Load the collections this panel's read-time join depends on.
		 *
		 * @return {Promise<void>} Resolves once loaded.
		 * @spec openspec/specs/sbom-import/spec.md#requirement-the-module-version-detail-page-shows-imported-components-with-summary-counts
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
					this.fetchType('sbomComponent'),
					this.fetchType('vulnerability'),
					this.fetchType('moduleVersion'),
				])
			} catch (error) {
				// eslint-disable-next-line no-console
				console.error('SbomComponentsPanel: failed to load data', error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch one object type collection, registering the type if needed.
		 *
		 * @param {string} type Object type slug.
		 * @return {Promise<void>} Resolves once fetched.
		 * @spec openspec/specs/sbom-import/spec.md#requirement-the-module-version-detail-page-shows-imported-components-with-summary-counts
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
		 * Handle file selection from the file input.
		 *
		 * @param {Event} event The file input change event.
		 * @return {void}
		 * @spec openspec/specs/sbom-import/spec.md#requirement-uploaded-sbom-files-are-bounded-in-size-and-json-only
		 */
		handleFileSelect(event) {
			const file = event.target.files[0]
			if (file) {
				this.selectedFile = file
				this.uploadError = null
				this.uploadSuccessMessage = null
			}
		},

		/**
		 * Format a byte count for display.
		 *
		 * @param {number} bytes The size in bytes.
		 * @return {string} A human-readable size.
		 * @spec openspec/specs/sbom-import/spec.md#requirement-uploaded-sbom-files-are-bounded-in-size-and-json-only
		 */
		formatFileSize(bytes) {
			if (!bytes) {
				return '0 Bytes'
			}
			const k = 1024
			const sizes = ['Bytes', 'KB', 'MB', 'GB']
			const i = Math.floor(Math.log(bytes) / Math.log(k))
			return `${parseFloat((bytes / Math.pow(k, i)).toFixed(2))} ${sizes[i]}`
		},

		/**
		 * Upload the selected file to `SbomController::importSbom`. On success,
		 * refetches the sbomComponent and moduleVersie collections so the
		 * table/summary/provenance reflect the REPLACED set (design Decision
		 * 3) with no page reload.
		 *
		 * @return {Promise<void>} Resolves once the import completes or fails.
		 * @spec openspec/specs/sbom-import/spec.md#requirement-re-import-replaces-the-previous-component-set-and-is-soft-delete-aware
		 */
		async importSbom() {
			if (!this.selectedFile || !this.objectId) {
				return
			}

			this.uploading = true
			this.uploadError = null
			this.uploadSuccessMessage = null

			try {
				const formData = new FormData()
				formData.append('sbomFile', this.selectedFile)
				formData.append('format', this.format)

				const url = generateUrl(
					'/apps/softwarecatalog/api/moduleversies/{moduleVersieUuid}/sbom',
					{
						moduleVersieUuid: String(this.objectId),
					},
				)

				// Multipart upload: no Content-Type header — the browser sets
				// the correct boundary (mirrors ArchiMate import's upload path).
				const response = await fetch(url, {
					method: 'POST',
					headers: { 'X-Requested-With': 'XMLHttpRequest' },
					body: formData,
				})
				const result = await response.json()

				if (!response.ok || result.success === false) {
					throw new Error(
						result.message || t('softwarecatalog', 'SBOM import failed'),
					)
				}

				this.uploadSuccessMessage = t(
					'softwarecatalog',
					'Imported {count} components.',
					{ count: result.componentCount ?? 0 },
				)
				this.selectedFile = null
				if (this.$refs.fileInput) {
					this.$refs.fileInput.value = ''
				}

				// Re-fetch so the replaced set (and provenance) render immediately.
				await Promise.all([
					objectStore.fetchCollection('sbomComponent', { _limit: 1000 }),
					objectStore.fetchCollection('moduleVersion', { _limit: 1000 }),
				])
			} catch (error) {
				this.uploadError =
					error.message || t('softwarecatalog', 'SBOM import failed')
			} finally {
				this.uploading = false
			}
		},
	},
}
</script>

<style scoped>
.sbom-components-panel {
	padding: 12px;
}

.sbom-summary {
	display: flex;
	gap: 12px;
	margin-bottom: 12px;
}

.sbom-summary__tile {
	flex: 1;
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 10px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 8px);
}

.sbom-summary__tile--warn {
	border-color: var(--color-warning);
}

.sbom-summary__value {
	font-size: 20px;
	font-weight: bold;
}

.sbom-summary__label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.sbom-provenance {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 12px;
}

.sbom-upload {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin-bottom: 12px;
}

.sbom-upload__format {
	min-width: 180px;
}

.sbom-upload__file-input {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	opacity: 0;
}

.sbom-upload__file-label {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px;
	border: 1px dashed var(--color-border-dark);
	border-radius: var(--border-radius, 8px);
	cursor: pointer;
}

.sbom-upload__file-label--disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.sbom-upload__file-size {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.sbom-badge {
	display: inline-block;
	font-size: 12px;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 16px);
	border: 1px solid var(--color-border-dark);
	margin-right: 4px;
}

.sbom-badge--confirmed {
	color: var(--color-error);
	border-color: var(--color-error);
}

.sbom-badge--possible {
	color: var(--color-warning);
	border-color: var(--color-warning);
}
</style>
