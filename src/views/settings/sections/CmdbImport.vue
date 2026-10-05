<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<AlwaysVisibleSection
		:name="t('stackiq', 'CMDB import')"
		:description="
			t(
				'stackiq',
				'Import a TOPdesk CMDB export (.xlsx) as the applications one municipality uses',
			)
		"
		:hasInfoContent="true">
		<div class="cmdb-import" data-testid="cmdb-import">
			<!-- 1. Municipality: an existing one, or a typed new name -->
			<div class="cmdb-import__field">
				<NcSelect
					v-model="municipality"
					inputId="cmdb-import-municipality"
					class="cmdb-import__select"
					:inputLabel="t('stackiq', 'Municipality')"
					:placeholder="t('stackiq', 'Choose or type a municipality')"
					:options="municipalityOptions"
					label="label"
					:taggable="true"
					:createOption="createMunicipalityOption"
					:loading="loadingMunicipalities"
					:disabled="importing"
					:clearable="true"
					data-testid="cmdb-import-municipality" />
				<p
					v-if="municipality && municipality.isNew"
					class="cmdb-import__help"
					data-testid="cmdb-import-new-municipality">
					{{
						t(
							'stackiq',
							'A new municipality "{name}" is created, unless one with this name already exists.',
							{ name: municipality.label },
							asText,
						)
					}}
				</p>
				<p v-else class="cmdb-import__help">
					{{
						t(
							'stackiq',
							'Pick an existing organisation of type Municipality, or type a new name and press Enter.',
						)
					}}
				</p>
				<p
					v-if="municipalityLoadError"
					class="cmdb-import__help cmdb-import__help--warning">
					{{ municipalityLoadError }}
				</p>
			</div>

			<!-- 2. The export file -->
			<div class="cmdb-import__field">
				<input
					id="cmdb-import-file"
					type="file"
					accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
					class="cmdb-import__file-input"
					:disabled="importing"
					aria-describedby="cmdb-import-file-help"
					data-testid="cmdb-import-file"
					@change="handleFileSelect" />
				<label
					for="cmdb-import-file"
					class="cmdb-import__file-label"
					:class="{ 'cmdb-import__file-label--disabled': importing }">
					<TrayArrowUp :size="20" />
					<span class="cmdb-import__file-label-text">{{
						selectedFile
							? selectedFile.name
							: t('stackiq', 'Choose the TOPdesk export')
					}}</span>
					<span v-if="selectedFile" class="cmdb-import__file-size">{{
						formatFileSize(selectedFile.size)
					}}</span>
				</label>
				<p id="cmdb-import-file-help" class="cmdb-import__help">
					{{
						t(
							'stackiq',
							'Excel workbook (.xlsx) with the sheet "{first}" or "{second}". By default the file may be at most {size}.',
							{
								first: profileDefaults.sheets[0],
								second: profileDefaults.sheets[1],
								size: formatMegabytes(profileDefaults.maxFileBytes),
							},
							asText,
						)
					}}
				</p>
			</div>

			<!-- 3. Options -->
			<div class="cmdb-import__field">
				<NcCheckboxRadioSwitch
					v-model="updateExisting"
					:disabled="importing"
					data-testid="cmdb-import-update-existing">
					{{ t('stackiq', 'Update existing records') }}
				</NcCheckboxRadioSwitch>
				<p class="cmdb-import__help">
					{{
						t(
							'stackiq',
							'When off, applications imported before are left as they are and reported as skipped.',
						)
					}}
				</p>
				<p class="cmdb-import__help" data-testid="cmdb-import-update-fields">
					{{
						t(
							'stackiq',
							"When on, a re-import overwrites the application's name, descriptions, application type, hosting model, BBN level, source fields and supplier, and the usage's status, phase-out date and business owner, with the values from the export. The usage's TIME classification and internal note are only set when the usage is created or the field is empty, so changes made in stackiq stay.",
						)
					}}
				</p>
			</div>
			<div class="cmdb-import__field">
				<NcCheckboxRadioSwitch
					v-model="publish"
					:disabled="importing"
					aria-describedby="cmdb-import-publish-help"
					data-testid="cmdb-import-publish">
					{{
						t('stackiq', 'Publish the applications this import creates')
					}}
				</NcCheckboxRadioSwitch>
				<p id="cmdb-import-publish-help" class="cmdb-import__help">
					{{
						t(
							'stackiq',
							'A published application is visible to anyone, including anonymous visitors of OpenCatalogi. When off, the applications this import creates stay unpublished until you publish them by hand. Applications imported before keep their publication as it is.',
						)
					}}
				</p>
			</div>

			<!-- 4. Actions -->
			<div class="cmdb-import__actions">
				<NcButton
					variant="primary"
					:disabled="!canImport"
					data-testid="cmdb-import-start"
					@click="startImport">
					<template #icon>
						<NcLoadingIcon v-if="importing" :size="20" />
						<DatabaseImport v-else :size="20" />
					</template>
					{{
						importing
							? t('stackiq', 'Importing…')
							: t('stackiq', 'Import')
					}}
				</NcButton>
				<NcButton
					v-if="importing"
					variant="secondary"
					:disabled="cancelling"
					data-testid="cmdb-import-cancel"
					@click="cancelImport">
					<template #icon>
						<Close :size="20" />
					</template>
					{{ t('stackiq', 'Cancel import') }}
				</NcButton>
			</div>

			<!-- Live region: progress while running, then the outcome. Always in
			     the DOM so screen readers announce what is put in it. -->
			<div class="cmdb-import__live" aria-live="polite">
				<div
					v-if="importing"
					class="cmdb-import__progress"
					data-testid="cmdb-import-progress">
					<p>{{ t('stackiq', 'Importing the export…') }}</p>
					<NcProgressBar
						:value="progressView ? progressView.percentage : 0"
						size="medium"
						:aria-label="t('stackiq', 'Import progress')" />
					<p v-if="progressView && progressView.detail">
						{{ progressView.detail }}
					</p>
					<p v-if="cancelStatus" data-testid="cmdb-import-cancel-status">
						{{ cancelStatus }}
					</p>
				</div>

				<p
					v-if="report && !importing"
					class="cmdb-import__finished"
					data-testid="cmdb-import-finished">
					{{ finishedText }}
				</p>
			</div>

			<!-- Errors: one per code, never as HTML -->
			<NcNoteCard
				v-if="errorView"
				type="error"
				:heading="errorView.title"
				data-testid="cmdb-import-error">
				<p v-if="errorView.hint">
					{{ errorView.hint }}
				</p>
				<p v-if="errorView.serverMessage">
					{{ errorView.serverMessage }}
				</p>
				<p class="cmdb-import__code">
					{{
						t(
							'stackiq',
							'Error code: {code}',
							{ code: errorView.code },
							asText,
						)
					}}
				</p>
			</NcNoteCard>

			<!-- The report -->
			<div
				v-if="report"
				class="cmdb-import__report"
				data-testid="cmdb-import-report">
				<NcNoteCard
					v-if="report.cancelled"
					type="warning"
					data-testid="cmdb-import-cancelled">
					{{
						t(
							'stackiq',
							'The import was cancelled. The rows processed before it stopped are kept.',
						)
					}}
				</NcNoteCard>

				<h3 class="cmdb-import__heading">
					{{ t('stackiq', 'Summary') }}
				</h3>
				<ul class="cmdb-import__summary" data-testid="cmdb-import-summary">
					<li
						v-for="tile in summaryTiles"
						:key="tile.key"
						class="cmdb-import__tile"
						:class="'cmdb-import__tile--' + tile.key"
						:data-testid="'cmdb-import-summary-' + tile.key">
						<span class="cmdb-import__tile-value">{{ tile.value }}</span>
						<span class="cmdb-import__tile-label">{{ tile.label }}</span>
					</li>
				</ul>

				<NcNoteCard
					v-if="importWarnings.length > 0"
					type="warning"
					:heading="t('stackiq', 'Warnings for the whole file')"
					data-testid="cmdb-import-warnings">
					<ul class="cmdb-import__warning-list">
						<li v-for="(warning, index) in importWarnings" :key="index">
							{{ warning }}
						</li>
					</ul>
				</NcNoteCard>

				<h3 class="cmdb-import__heading">
					{{ t('stackiq', 'Rows') }}
				</h3>
				<div class="cmdb-import__filter">
					<NcSelect
						v-model="outcomeFilter"
						inputId="cmdb-import-outcome-filter"
						class="cmdb-import__select"
						:inputLabel="t('stackiq', 'Show rows with outcome')"
						:options="outcomeFilterOptions"
						label="label"
						:clearable="false"
						:searchable="false"
						data-testid="cmdb-import-outcome-filter" />
				</div>
				<CnDataTable
					:rows="visibleRows"
					:columns="columns"
					rowKey="key"
					:sortKey="sortKey"
					:sortOrder="sortOrder"
					:emptyText="t('stackiq', 'No rows with this outcome')"
					data-testid="cmdb-import-rows"
					@sort="onSort">
					<template #column-row="{ row }">
						<span data-testid="cmdb-import-row-number">{{
							row.row
						}}</span>
					</template>
					<template #column-appId="{ row }">
						<span data-testid="cmdb-import-app-id">{{ row.appId }}</span>
					</template>
					<template #column-name="{ row }">
						<a
							v-if="row.moduleUuid"
							:href="moduleUrl(row.moduleUuid)"
							class="cmdb-import__module-link"
							data-testid="cmdb-import-module-link">
							{{ row.name || row.appId }}
						</a>
						<span v-else>{{ row.name || '—' }}</span>
					</template>
					<template #column-outcome="{ row }">
						<CnStatusBadge
							:label="outcomeLabel(row.outcome)"
							:colorKey="row.outcome"
							:colorMap="outcomeColors"
							size="small"
							:data-outcome="row.outcome" />
					</template>
					<template #column-notes="{ row }">
						<span>{{ row.notes || '—' }}</span>
					</template>
				</CnDataTable>
				<div
					v-if="sortedRows.length > visibleRows.length"
					class="cmdb-import__more"
					data-testid="cmdb-import-more">
					<p class="cmdb-import__help">
						{{
							t(
								'stackiq',
								'Showing {shown} of {total} rows.',
								{
									shown: visibleRows.length,
									total: sortedRows.length,
								},
								asText,
							)
						}}
					</p>
					<NcButton
						variant="secondary"
						data-testid="cmdb-import-show-more"
						@click="showMoreRows">
						{{
							t(
								'stackiq',
								'Show {count} more rows',
								{
									count: Math.min(
										reportPageSize,
										sortedRows.length - visibleRows.length,
									),
								},
								asText,
							)
						}}
					</NcButton>
				</div>
			</div>
		</div>

		<template #info-content>
			<div class="cmdb-import-info">
				<h3>{{ t('stackiq', 'CMDB import') }}</h3>
				<p>
					{{
						t(
							'stackiq',
							'Each application row of the export becomes or updates an application, its vendor, and a usage that links it to the chosen municipality. The application owner becomes a contact person of the municipality in Nextcloud Contacts; owners are never shown to the public.',
						)
					}}
				</p>
				<h4>{{ t('stackiq', 'The file') }}</h4>
				<ul>
					<li>
						{{
							t(
								'stackiq',
								'The sheets "{first}" (applications without arranged maintenance) and "{second}" (with arranged maintenance) are read; other sheets, including the "Invoer" sheets, are ignored.',
								{
									first: profileDefaults.sheets[0],
									second: profileDefaults.sheets[1],
								},
								asText,
							)
						}}
					</li>
					<li>
						{{
							t(
								'stackiq',
								'Row 1 holds the column names. "APPID" and "Applicatie Naam" are required; column order does not matter.',
							)
						}}
					</li>
					<li>
						{{
							t(
								'stackiq',
								'Formula cells are read as the value Excel saved with the workbook; formulas are never calculated. Save the workbook in Excel before importing it.',
							)
						}}
					</li>
					<li>
						{{
							t(
								'stackiq',
								'Importing a newer export again updates the same applications, matched on APPID per municipality. Applications missing from it are left as they are.',
							)
						}}
					</li>
				</ul>
			</div>
		</template>
	</AlwaysVisibleSection>
</template>

<script>
import { CnDataTable, CnStatusBadge } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
	NcProgressBar,
	NcSelect,
} from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import DatabaseImport from 'vue-material-design-icons/DatabaseImport.vue'
import TrayArrowUp from 'vue-material-design-icons/TrayArrowUp.vue'
import AlwaysVisibleSection from '../../../components/AlwaysVisibleSection.vue'
import { startProgressPolling } from '../../../utils/archiMateImportProgress.js'
import {
	AS_TEXT,
	buildImportForm,
	cancelCmdbImport,
	cancelFailureText,
	checkFile,
	cmdbProgressView,
	errorText,
	followInterruptedImport,
	formatMegabytes,
	importUrl,
	interruptedImportError,
	isKnownError,
	makeCmdbOperationId,
	moduleUrl,
	municipalityOptions,
	normaliseError,
	outcomeLabel,
	OUTCOMES,
	PROFILE_DEFAULTS,
	REPORT_PAGE_SIZE,
	reportRows,
	sortReportRows,
	typedMunicipalityOption,
} from '../../../utils/cmdbImport.js'

/**
 * The "CMDB import" section of stackiq's admin settings.
 *
 * Rendered by the settings page (src/settings.js), never by the app's router.
 * Every value from the report is rendered as text. Translations with
 * placeholders are built with AS_TEXT (no HTML escaping), because Vue escapes
 * the text it renders; none of them may go into v-html.
 *
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014
 */
export default {
	name: 'CmdbImport',

	components: {
		AlwaysVisibleSection,
		CnDataTable,
		CnStatusBadge,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcProgressBar,
		NcSelect,
		Close,
		DatabaseImport,
		TrayArrowUp,
	},

	data() {
		return {
			municipality: null,
			municipalityOptions: [],
			loadingMunicipalities: false,
			municipalityLoadError: '',
			selectedFile: null,
			updateExisting: true,
			publish: true,
			importing: false,
			cancelling: false,
			cancelStatus: '',
			operationId: null,
			progress: null,
			stopProgressPolling: null,
			unmounted: false,
			report: null,
			error: null,
			outcomeFilter: null,
			sortKey: null,
			sortOrder: null,
			reportPageSize: REPORT_PAGE_SIZE,
			visibleRowCount: REPORT_PAGE_SIZE,
			profileDefaults: PROFILE_DEFAULTS,
			asText: AS_TEXT,
			outcomeColors: {
				created: 'success',
				updated: 'info',
				unchanged: 'default',
				skipped: 'warning',
				failed: 'error',
			},
		}
	},

	computed: {
		/**
		 * Whether everything the import needs has been chosen.
		 *
		 * @return {boolean} True when the Import button may be pressed
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014
		 */
		canImport() {
			return (
				!this.importing
				&& this.selectedFile !== null
				&& this.municipality !== null
				&& (Boolean(this.municipality.id)
					|| String(this.municipality.label || '').trim() !== '')
			)
		},

		/**
		 * The progress bar's view of the running import.
		 *
		 * @return {object|null} The view
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-a-running-import-shall-report-its-progress-and-shall-stop-when-cancelled-req-cmdb-013
		 */
		progressView() {
			return cmdbProgressView(this.progress)
		},

		/**
		 * The words for the current error, if any.
		 *
		 * @return {object|null} Title, hint, code and the server's message for an unknown code
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-columns-shall-be-resolved-by-header-name-and-a-missing-required-column-shall-stop-the-import-with-422-req-cmdb-003
		 */
		errorView() {
			if (!this.error) {
				return null
			}
			const words = errorText(this.error)
			return {
				...words,
				code: this.error.error,
				serverMessage: isKnownError(this.error.error)
					? ''
					: this.error.message || '',
			}
		},

		/**
		 * The summary counts, in the order the contract lists them.
		 *
		 * @return {Array<object>} One tile per count
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-each-row-shall-be-processed-in-isolation-and-reported-with-its-outcome-req-cmdb-011
		 */
		summaryTiles() {
			const summary = this.report?.summary || {}
			return (
				[
					{ key: 'rowsRead', label: t('stackiq', 'Rows read') },
					{ key: 'created', label: t('stackiq', 'Created') },
					{ key: 'updated', label: t('stackiq', 'Updated') },
					{ key: 'unchanged', label: t('stackiq', 'Unchanged') },
					{ key: 'skipped', label: t('stackiq', 'Skipped') },
					{ key: 'failed', label: t('stackiq', 'Failed') },
					{ key: 'warnings', label: t('stackiq', 'Warnings') },
					{
						key: 'unpublished',
						label: t('stackiq', 'Created unpublished'),
					},
				]
					.map((tile) => ({
						...tile,
						value: Number(summary[tile.key]) || 0,
					}))
					// Only an import run with publishing off leaves modules unpublished.
					.filter((tile) => tile.key !== 'unpublished' || tile.value > 0)
			)
		},

		/**
		 * The sentence that says the import finished, and for whom.
		 *
		 * @return {string} The sentence
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014
		 */
		finishedText() {
			const summary = this.report?.summary || {}
			const counts = {
				name: this.report?.municipality?.name || '',
				read: Number(summary.rowsRead) || 0,
				created: Number(summary.created) || 0,
				updated: Number(summary.updated) || 0,
				unchanged: Number(summary.unchanged) || 0,
			}
			if (this.report?.cancelled) {
				return t(
					'stackiq',
					'Import for {name} cancelled after {read} rows.',
					counts,
					AS_TEXT,
				)
			}
			if (this.report?.municipality?.created) {
				return t(
					'stackiq',
					'Import finished. The municipality {name} was created. {read} rows read: {created} created, {updated} updated, {unchanged} unchanged.',
					counts,
					AS_TEXT,
				)
			}
			return t(
				'stackiq',
				'Import for {name} finished. {read} rows read: {created} created, {updated} updated, {unchanged} unchanged.',
				counts,
				AS_TEXT,
			)
		},

		/**
		 * Import-level warnings, such as a missing optional column.
		 *
		 * @return {Array<string>} One line per warning
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-columns-shall-be-resolved-by-header-name-and-a-missing-required-column-shall-stop-the-import-with-422-req-cmdb-003
		 */
		importWarnings() {
			const warnings = this.report?.importWarnings
			if (!Array.isArray(warnings)) {
				return []
			}
			return warnings.map((warning) =>
				warning && warning.sheet
					? `${warning.sheet}: ${warning.message ?? ''}`
					: String(warning?.message ?? warning ?? ''),
			)
		},

		/**
		 * The choices of the outcome filter: all rows, or one outcome.
		 *
		 * @return {Array<object>} The options
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014
		 */
		outcomeFilterOptions() {
			return [
				{ id: 'all', label: t('stackiq', 'All outcomes') },
				...OUTCOMES.map((outcome) => ({
					id: outcome,
					label: outcomeLabel(outcome),
				})),
			]
		},

		/**
		 * The table rows that match the outcome filter.
		 *
		 * @return {Array<object>} The rows
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014
		 */
		filteredRows() {
			const rows = reportRows(this.report?.rows)
			const wanted = this.outcomeFilter?.id || 'all'
			if (wanted === 'all') {
				return rows
			}
			return rows.filter((row) => row.outcome === wanted)
		},

		/**
		 * The filtered rows in the order of the column the admin sorted on.
		 *
		 * @return {Array<object>} The rows
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014
		 */
		sortedRows() {
			return sortReportRows(this.filteredRows, this.sortKey, this.sortOrder)
		},

		/**
		 * The rows the table renders: the first visibleRowCount sorted rows.
		 *
		 * @return {Array<object>} The rows
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014
		 */
		visibleRows() {
			return this.sortedRows.slice(0, this.visibleRowCount)
		},

		/**
		 * The report table's columns.
		 *
		 * @return {Array<object>} The columns
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-each-row-shall-be-processed-in-isolation-and-reported-with-its-outcome-req-cmdb-011
		 */
		columns() {
			return [
				{ key: 'sheet', label: t('stackiq', 'Sheet'), sortable: true },
				{ key: 'row', label: t('stackiq', 'Row'), sortable: true },
				{
					key: 'appId',
					label: t('stackiq', 'APPID'),
					sortable: true,
				},
				{ key: 'name', label: t('stackiq', 'Application'), sortable: true },
				{ key: 'outcome', label: t('stackiq', 'Outcome'), sortable: true },
				{ key: 'notes', label: t('stackiq', 'Reasons and warnings') },
			]
		},
	},

	watch: {
		/**
		 * Another outcome filter starts at the first page again.
		 *
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014
		 */
		outcomeFilter() {
			this.visibleRowCount = REPORT_PAGE_SIZE
		},
	},

	/**
	 * Load the municipalities for the chooser.
	 *
	 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-every-import-shall-have-exactly-one-consuming-municipality-chosen-by-the-admin-req-cmdb-004
	 */
	created() {
		this.outcomeFilter = this.outcomeFilterOptions[0]
		this.loadMunicipalities()
	},

	/**
	 * Stop polling when the page is left mid-import.
	 *
	 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-a-running-import-shall-report-its-progress-and-shall-stop-when-cancelled-req-cmdb-013
	 */
	beforeUnmount() {
		this.unmounted = true
		this.stopPolling()
	},

	methods: {
		t,
		formatMegabytes,
		moduleUrl,
		outcomeLabel,

		/**
		 * Read the organisations of type Municipality from OpenRegister.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-every-import-shall-have-exactly-one-consuming-municipality-chosen-by-the-admin-req-cmdb-004
		 */
		async loadMunicipalities() {
			this.loadingMunicipalities = true
			this.municipalityLoadError = ''
			try {
				const configResponse = await axios.get(
					generateUrl('/apps/stackiq/api/voorzieningen/config'),
				)
				const register = configResponse?.data?.config?.register
				const schema = configResponse?.data?.config?.organisatie_schema
				if (!register || !schema) {
					this.municipalityLoadError = t(
						'stackiq',
						'The organisation register is not configured, so existing municipalities cannot be listed. You can still type the name of a municipality.',
					)
					return
				}
				const response = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/{register}/{schema}',
						{
							register,
							schema,
						},
					),
					{ params: { type: 'Municipality', _limit: 1000 } },
				)
				this.municipalityOptions = municipalityOptions(
					response?.data?.results || [],
				)
			} catch {
				this.municipalityLoadError = t(
					'stackiq',
					'Existing municipalities could not be loaded. You can still type the name of a municipality.',
				)
			} finally {
				this.loadingMunicipalities = false
			}
		},

		/**
		 * Turn a typed name into the chooser's option for that name.
		 *
		 * The page does not match the name to a listed municipality: it sends
		 * the name, and the server reuses, creates or refuses (see
		 * typedMunicipalityOption()).
		 *
		 * @param {string|object} typed What the admin typed
		 * @return {object} The option
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-every-import-shall-have-exactly-one-consuming-municipality-chosen-by-the-admin-req-cmdb-004
		 */
		createMunicipalityOption(typed) {
			return typedMunicipalityOption(typed)
		},

		/**
		 * Keep the chosen file and clear the result of a previous run.
		 *
		 * @param {Event} event The change event of the file input
		 * @return {void}
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014
		 */
		handleFileSelect(event) {
			const file = event?.target?.files?.[0] || null
			this.selectedFile = file
			this.error = null
			this.report = null
			if (file) {
				const problem = checkFile(file)
				if (problem) {
					this.error = { ...problem, message: '', status: 0 }
				}
			}
		},

		/**
		 * File size for display.
		 *
		 * @param {number} bytes The size
		 * @return {string} The size with its unit
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014
		 */
		formatFileSize(bytes) {
			if (bytes < 1024 * 1024) {
				return t(
					'stackiq',
					'{size} KB',
					{
						size: Math.max(1, Math.round((bytes || 0) / 1024)),
					},
					AS_TEXT,
				)
			}
			return t(
				'stackiq',
				'{size} MB',
				{
					size: (bytes / (1024 * 1024)).toFixed(1),
				},
				AS_TEXT,
			)
		},

		/**
		 * Upload the export and show its report.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014
		 */
		async startImport() {
			if (!this.canImport) {
				return
			}
			const problem = checkFile(this.selectedFile)
			if (problem) {
				this.error = { ...problem, message: '', status: 0 }
				this.report = null
				return
			}

			this.importing = true
			this.cancelling = false
			this.cancelStatus = ''
			this.error = null
			this.report = null
			this.progress = null
			this.operationId = makeCmdbOperationId()
			this.stopProgressPolling = startProgressPolling({
				operationId: this.operationId,
				http: axios,
				onProgress: (progress) => {
					this.progress = progress
				},
			})

			try {
				const form = buildImportForm({
					file: this.selectedFile,
					municipality: {
						uuid: this.municipality.isNew ? null : this.municipality.id,
						name: this.municipality.name || this.municipality.label,
					},
					updateExisting: this.updateExisting,
					publish: this.publish,
					operationId: this.operationId,
				})
				const response = await axios.post(importUrl(), form)
				this.showReport(response.data)
			} catch (error) {
				const normalised = normaliseError(error)
				if (normalised.interrupted) {
					await this.recoverInterruptedImport(normalised)
				} else {
					this.error = normalised
				}
			} finally {
				this.stopPolling()
				this.importing = false
				this.cancelling = false
				this.cancelStatus = ''
				this.operationId = null
			}
		},

		/**
		 * Show a finished import's report.
		 *
		 * @param {object} report The report
		 * @return {void}
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-each-row-shall-be-processed-in-isolation-and-reported-with-its-outcome-req-cmdb-011
		 */
		showReport(report) {
			this.report = report
			this.outcomeFilter = this.outcomeFilterOptions[0]
			this.visibleRowCount = REPORT_PAGE_SIZE
			// A created municipality is an existing one from now on: select it,
			// so a second import goes to the same organisation (WCAG 3.3.7).
			const imported = report?.municipality
			if (imported?.uuid && this.municipality?.isNew) {
				const listed = this.municipalityOptions.find(
					(option) => option.id === imported.uuid,
				)
				if (listed) {
					this.municipality = listed
					return
				}
				const name = String(imported.name || this.municipality.name)
				const option = {
					id: imported.uuid,
					label: name,
					name,
					isNew: false,
				}
				this.municipalityOptions = [
					...this.municipalityOptions,
					option,
				].sort((a, b) => a.label.localeCompare(b.label))
				this.municipality = option
			}
		},

		/**
		 * The request was cut off (no answer, or a gateway error) while the
		 * import may still be running: follow the operation on the server and
		 * show its report when it has one, or say that the outcome is unknown.
		 *
		 * @param {object} normalised The normalised error of the request
		 * @return {Promise<void>}
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-a-running-import-shall-report-its-progress-and-shall-stop-when-cancelled-req-cmdb-013
		 */
		async recoverInterruptedImport(normalised) {
			this.stopPolling()
			const outcome = await followInterruptedImport({
				operationId: this.operationId,
				http: axios,
				onProgress: (progress) => {
					this.progress = progress
				},
				shouldStop: () => this.unmounted,
			})
			if (outcome.state === 'finished') {
				this.showReport(outcome.report)
				return
			}
			this.error = interruptedImportError(outcome, normalised)
		},

		/**
		 * Ask the server to stop the running import before its next row.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-a-running-import-shall-report-its-progress-and-shall-stop-when-cancelled-req-cmdb-013
		 */
		async cancelImport() {
			if (!this.operationId) {
				return
			}
			this.cancelling = true
			this.cancelStatus = ''
			try {
				await cancelCmdbImport({
					operationId: this.operationId,
					http: axios,
				})
				this.cancelStatus = t(
					'stackiq',
					'Cancelling the import. It stops before the next row; the rows already processed stay imported.',
				)
			} catch (error) {
				// The import keeps running; say why, and let the admin press Cancel again.
				this.cancelling = false
				this.cancelStatus = cancelFailureText(normaliseError(error))
			}
		},

		/**
		 * Keep the sort the admin chose on a column header.
		 *
		 * @param {{key: string|null, order: string|null}} sort The table's sort event
		 * @return {void}
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014
		 */
		onSort({ key, order }) {
			this.sortKey = key
			this.sortOrder = order
		},

		/**
		 * Show the next page of report rows below the ones already shown.
		 *
		 * @return {void}
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014
		 */
		showMoreRows() {
			this.visibleRowCount += REPORT_PAGE_SIZE
		},

		/**
		 * Stop following the progress of the import.
		 *
		 * @return {void}
		 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-a-running-import-shall-report-its-progress-and-shall-stop-when-cancelled-req-cmdb-013
		 */
		stopPolling() {
			if (this.stopProgressPolling) {
				this.stopProgressPolling()
				this.stopProgressPolling = null
			}
		},
	},
}
</script>

<style scoped>
.cmdb-import {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	max-width: 900px;
}

.cmdb-import__field {
	max-width: 500px;
}

.cmdb-import__select {
	width: 100%;
}

.cmdb-import__help {
	margin: 0.25rem 0 0;
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
}

.cmdb-import__help--warning {
	color: var(--color-warning-text);
}

/* The native input stays in the DOM, keyboard-focusable and labelled; the
   label is styled as the visible control: a plain button-like control, not a
   drop zone, because the input takes no dropped files. */
.cmdb-import__file-input {
	position: absolute;
	width: 1px;
	height: 1px;
	padding: 0;
	margin: -1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
	border: 0;
}

.cmdb-import__file-label {
	display: inline-flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.5rem;
	min-height: var(--default-clickable-area, 44px);
	padding: 0.5rem 1rem;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-element, var(--border-radius-large));
	background: var(--color-main-background);
	cursor: pointer;
}

.cmdb-import__file-input:focus-visible + .cmdb-import__file-label {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.cmdb-import__file-label:hover {
	border-color: var(--color-primary-element);
}

.cmdb-import__file-label--disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.cmdb-import__file-label-text {
	font-weight: 500;
	color: var(--color-main-text);
	overflow-wrap: anywhere;
}

.cmdb-import__file-size {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
}

.cmdb-import__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
}

.cmdb-import__progress {
	max-width: 500px;
}

.cmdb-import__finished {
	margin: 0;
	font-weight: 500;
}

.cmdb-import__code {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
}

.cmdb-import__heading {
	margin: 1rem 0 0.5rem;
	font-weight: 600;
}

.cmdb-import__summary {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
	gap: 0.5rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.cmdb-import__tile {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 0.75rem 0.5rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.cmdb-import__tile--created {
	border-color: var(--color-success);
}

.cmdb-import__tile--failed {
	border-color: var(--color-error);
}

.cmdb-import__tile--skipped,
.cmdb-import__tile--warnings {
	border-color: var(--color-warning);
}

.cmdb-import__tile-value {
	font-size: 1.5rem;
	font-weight: 700;
}

.cmdb-import__tile-label {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
}

.cmdb-import__warning-list {
	margin: 0;
	padding-inline-start: 1.25rem;
	list-style: disc;
}

.cmdb-import__filter {
	max-width: 300px;
	margin-bottom: 0.5rem;
}

.cmdb-import__more {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.5rem 1rem;
	margin-top: 0.5rem;
}

.cmdb-import__module-link {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
