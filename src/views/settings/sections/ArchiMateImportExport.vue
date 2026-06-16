<!--
 - @copyright Copyright (c) 2023 Ruben Linde <info@conduction.nl>
 - @license AGPL-3.0-or-later
 -
 - This program is free software: you can redistribute it and/or modify
 - it under the terms of the GNU Affero General Public License as
 - published by the Free Software Foundation, either version 3 of the
 - License, or (at your option) any later version.
 -
 - This program is distributed in the hope that it will be useful,
 - but WITHOUT ANY WARRANTY; without even the implied warranty of
 - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 - GNU Affero General Public License for more details.
 -
 - You should have received a copy of the GNU Affero General Public License
 - along with this program. If not, see <http://www.gnu.org/licenses/>.
-->

<template>
	<AlwaysVisibleSection
		name="ArchiMate Import/Export"
		description="Import and export ArchiMate models to/from OpenRegister"
		:loading="store.loading"
		loading-text="Preparing ArchiMate tools..."
		:has-info-content="true">
		<!-- ArchiMate Operations Section -->
		<div class="operations-section">
			<!-- Import Section -->
			<div class="import-section">
				<!-- File Upload (shown only when no results) -->
				<div v-if="!importResult && !importError" class="file-upload-container">
					<input
						id="archimate-file-input"
						ref="fileInput"
						type="file"
						accept=".xml,.archimate"
						class="file-input"
						:disabled="importing"
						@change="handleFileSelect">
					<label for="archimate-file-input" class="file-upload-label" :class="{ disabled: importing }">
						<CloudUpload :size="24" />
						<span>{{ selectedFile ? selectedFile.name : 'Choose ArchiMate XML file' }}</span>
						<span v-if="selectedFile" class="file-size">{{ formatFileSize(selectedFile.size) }}</span>
					</label>
				</div>

				<!-- Import Results (shown instead of upload when there are results) -->
				<div v-else-if="importResult || importError" class="import-results-section">
					<!-- Success Results -->
					<div v-if="importResult && importResult.success" class="success-results">
						<!-- File Information -->
						<div class="result-card">
							<h5>File Information</h5>
							<div class="info-grid">
								<div class="info-item">
									<span class="label">File Name:</span>
									<span class="value">{{ importResult.file_info.name }}</span>
								</div>
								<div class="info-item">
									<span class="label">File Size:</span>
									<span class="value">{{ formatFileSize(importResult.file_info.size) }}</span>
								</div>
							</div>
						</div>

						<!-- Performance Metrics -->
						<div class="result-card">
							<h5>Performance Metrics</h5>
							<div class="metrics-grid">
								<div class="metric-item">
									<div class="metric-value">
										{{ formatTime(importResult.performance_metrics.total_time_seconds) }}
									</div>
									<div class="metric-label">
										Total Time
									</div>
								</div>
								<div class="metric-item">
									<div class="metric-value">
										{{ importResult.performance_metrics.objects_processed.toLocaleString() }}
									</div>
									<div class="metric-label">
										Objects Processed
									</div>
								</div>
								<div class="metric-item">
									<div class="metric-value">
										{{ Math.round(importResult.performance_metrics.items_per_second) }}/s
									</div>
									<div class="metric-label">
										Items per Second
									</div>
								</div>
								<div class="metric-item">
									<div class="metric-value">
										{{ formatMemory(importResult.performance_metrics.memory_usage.peak_memory_mb) }}
									</div>
									<div class="metric-label">
										Peak Memory
									</div>
								</div>
							</div>
						</div>

						<!-- Processing Breakdown -->
						<div class="result-card">
							<h5>Processing Breakdown</h5>
							<div class="breakdown-list">
								<div class="breakdown-item">
									<span class="breakdown-label">XML Parsing</span>
									<span class="breakdown-value">{{ formatTime(importResult.performance_metrics.timing_breakdown.xml_parsing_seconds) }}</span>
									<span class="breakdown-rate">({{ Math.round(importResult.performance_metrics.processing_rates.xml_parse_objects_per_second) }}/s)</span>
								</div>
								<div class="breakdown-item">
									<span class="breakdown-label">Data Transformation</span>
									<span class="breakdown-value">{{ formatTime(importResult.performance_metrics.timing_breakdown.data_transformation_seconds) }}</span>
									<span class="breakdown-rate">({{ Math.round(importResult.performance_metrics.processing_rates.transform_objects_per_second).toLocaleString() }}/s)</span>
								</div>
								<div class="breakdown-item">
									<span class="breakdown-label">Database Save</span>
									<span class="breakdown-value">{{ formatTime(importResult.performance_metrics.timing_breakdown.database_save_seconds) }}</span>
									<span class="breakdown-rate">({{ Math.round(importResult.performance_metrics.processing_rates.save_objects_per_second) }}/s)</span>
								</div>
							</div>
						</div>

						<!-- Statistics Summary -->
						<div v-if="importResult.statistics && importResult.statistics.summary" class="result-card">
							<h5>Import Summary</h5>
							<div class="summary-grid">
								<div class="summary-item created">
									<div class="summary-number">
										{{ importResult.statistics.summary.total_objects_created }}
									</div>
									<div class="summary-label">
										Created
									</div>
								</div>
								<div class="summary-item updated">
									<div class="summary-number">
										{{ importResult.statistics.summary.total_objects_updated }}
									</div>
									<div class="summary-label">
										Updated
									</div>
								</div>
								<div class="summary-item unchanged">
									<div class="summary-number">
										{{ importResult.statistics.summary.total_objects_unchanged }}
									</div>
									<div class="summary-label">
										Unchanged
									</div>
								</div>
								<div class="summary-item errors" :class="{ clickable: importResult.statistics.summary.total_errors > 0 }" @click="showErrorDetails">
									<div class="summary-number">
										{{ importResult.statistics.summary.total_errors }}
									</div>
									<div class="summary-label">
										Errors
									</div>
									<div v-if="importResult.statistics.summary.total_errors > 0" class="click-hint">
										Click to view details
									</div>
								</div>
							</div>
						</div>

						<!-- Detailed Errors Modal -->
						<div v-if="showErrors && importResult?.detailed_errors?.total_count > 0" class="error-details-modal">
							<div class="error-details-content">
								<div class="error-details-header">
									<h5>Import Errors Details</h5>
									<NcButton
										type="tertiary-no-background"
										@click="hideErrorDetails">
										<template #icon>
											<Close :size="20" />
										</template>
									</NcButton>
								</div>

								<!-- Error Summary -->
								<div class="error-summary">
									<div class="error-stats">
										<div class="stat-item">
											<span class="stat-number">{{ importResult.detailed_errors.total_count }}</span>
											<span class="stat-label">Total Errors</span>
										</div>
										<div class="stat-item">
											<span class="stat-number">{{ Object.keys(importResult.detailed_errors.by_section).length }}</span>
											<span class="stat-label">Affected Sections</span>
										</div>
									</div>
								</div>

								<!-- Most Common Errors -->
								<div v-if="importResult.detailed_errors.summary && importResult.detailed_errors.summary.length > 0" class="common-errors-section">
									<h6>Most Common Issues</h6>
									<div class="common-errors-list">
										<div
											v-for="error in importResult.detailed_errors.summary.slice(0, 5)"
											:key="error.type"
											class="common-error-item">
											<div class="error-type-badge" :class="error.type">
												{{ formatErrorType(error.type) }}
											</div>
											<div class="error-details">
												<div class="error-message">
													{{ error.message }}
												</div>
												<div class="error-meta">
													<span class="error-count">{{ error.total_count }} occurrences</span>
													<span v-if="error.affected_sections && error.affected_sections.length > 0" class="affected-sections">
														in {{ error.affected_sections.join(', ') }}
													</span>
												</div>
											</div>
										</div>
									</div>
								</div>

								<!-- Errors by Section -->
								<div class="errors-by-section">
									<h6>Errors by Section</h6>
									<div class="section-errors-list">
										<div
											v-for="(sectionData, sectionName) in importResult.detailed_errors.by_section"
											:key="sectionName"
											class="section-error-group">
											<div class="section-header">
												<h6>{{ sectionData.section_name }}</h6>
												<span class="section-error-count">{{ sectionData.total_errors }} errors</span>
											</div>
											<div class="section-error-details">
												<div
													v-for="errorGroup in sectionData.error_groups"
													:key="errorGroup.type"
													class="error-group">
													<div class="error-group-header">
														<div class="error-type-badge small" :class="errorGroup.type">
															{{ formatErrorType(errorGroup.type) }}
														</div>
														<span class="error-group-count">{{ errorGroup.count }}</span>
													</div>
													<div class="error-group-message">
														{{ errorGroup.message }}
													</div>
													<div v-if="errorGroup.examples && errorGroup.examples.length > 0" class="error-examples">
														<span class="examples-label">Examples:</span>
														<span class="examples-list">{{ errorGroup.examples.join(', ') }}</span>
													</div>
												</div>
											</div>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Error Results -->
					<div v-else-if="importError" class="error-results">
						<NcNoteCard type="error">
							<div class="error-header">
								<AlertCircle :size="24" />
								<h4>Import Failed</h4>
							</div>
							<div class="error-details">
								<!-- Configuration Error -->
								<div v-if="isConfigurationError(importError.message)" class="configuration-error">
									<p><strong>Configuration Missing:</strong></p>
									<div class="configuration-issues">
										<p>{{ getConfigurationErrorDescription(importError.message) }}</p>
										<div class="missing-items">
											<ul>
												<li v-for="item in getMissingConfigItems(importError.message)" :key="item">
													{{ item }}
												</li>
											</ul>
										</div>
									</div>
									<div class="configuration-help">
										<h5>How to Fix:</h5>
										<ol>
											<li>Go to the <strong>Registers</strong> section in these settings</li>
											<li>Use the <strong>Auto-Configure</strong> button to automatically set up AMEF schemas</li>
											<li>Or manually configure each schema ID in the register settings</li>
											<li>Return here to try the import again</li>
										</ol>
									</div>
								</div>

								<!-- General Error -->
								<div v-else>
									<p><strong>Error:</strong> {{ importError.message || 'Unknown error occurred during import' }}</p>
									<div v-if="importError.details" class="error-additional">
										<h5>Additional Details:</h5>
										<pre>{{ JSON.stringify(importError.details, null, 2) }}</pre>
									</div>
								</div>
							</div>
						</NcNoteCard>
					</div>
				</div>

				<!-- Import Button -->
				<div class="import-button-section">
					<NcButton
						v-if="!importResult && !importError"
						type="primary"
						:disabled="!selectedFile || importing"
						@click="importArchiMateFile">
						<template #icon>
							<NcLoadingIcon v-if="importing" :size="20" />
							<CloudUpload v-else :size="20" />
						</template>
						{{ importing ? 'Importing...' : 'Import' }}
					</NcButton>

					<NcButton
						v-else
						type="primary"
						@click="resetImport">
						<template #icon>
							<Refresh :size="20" />
						</template>
						Reset Import
					</NcButton>
				</div>
			</div>

			<!-- Export Section -->
			<div class="export-section">
				<h4>Export</h4>
				<p>Export ArchiMate models filtered by organization</p>

				<div class="export-controls">
					<div class="control-group">
						<label for="organization-select">Organization:</label>
						<NcSelect
							id="organization-select"
							v-model="selectedOrganization"
							:options="organizationOptions"
							input-label="Select Organization"
							placeholder="Generic"
							:disabled="exporting" />
					</div>

					<div v-if="selectedOrganization" class="control-group">
						<label>Include in organization export:</label>
						<div class="checkbox-group">
							<NcCheckboxRadioSwitch
								:checked.sync="includeModules"
								:disabled="exportingOrg">
								Modules
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
								:checked.sync="includeDeelnames"
								:disabled="exportingOrg">
								Deelnames
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
								:checked.sync="includeGebruik"
								:disabled="exportingOrg">
								Gebruik
							</NcCheckboxRadioSwitch>
						</div>
					</div>

					<NcButton
						type="secondary"
						:disabled="exporting"
						@click="exportArchiMateFile">
						<template #icon>
							<NcLoadingIcon v-if="exporting" :size="20" />
							<Download v-else :size="20" />
						</template>
						{{ exporting ? 'Exporting...' : 'Export Base' }}
					</NcButton>

					<NcButton
						type="primary"
						:disabled="exportingOrg || !selectedOrganization"
						@click="exportOrgArchiMateFile">
						<template #icon>
							<NcLoadingIcon v-if="exportingOrg" :size="20" />
							<Download v-else :size="20" />
						</template>
						{{ exportingOrg ? 'Exporting...' : 'Organization Export' }}
					</NcButton>
				</div>
			</div>
		</div>

		<!-- Info Content Slot -->
		<template #info-content>
			<div class="archimate-info">
				<h3>ArchiMate Operations</h3>
				<p>Import and export ArchiMate models with organization filtering support.</p>

				<h4>Supported Formats</h4>
				<ul>
					<li>ArchiMate XML (.xml)</li>
					<li>ArchiMate model files (.archimate)</li>
				</ul>
			</div>
		</template>
	</AlwaysVisibleSection>
</template>

<script>
/**
 * ArchiMate Import Component
 *
 * This component provides a streamlined ArchiMate file import interface
 * with real-time processing and detailed results display.
 *
 * @author Ruben Linde <info@conduction.nl>
 * @copyright 2023 Conduction B.V.
 * @license AGPL-3.0-or-later
 * @version 2.0.0
 */

import { settingsStore } from '../../../store/store.js'
import { withHeartbeat } from '../../../utils/heartbeat.js'

// Components
import AlwaysVisibleSection from '../../../components/AlwaysVisibleSection.vue'

// Nextcloud Vue components
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'

// Icons
import CloudUpload from 'vue-material-design-icons/CloudUpload.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Close from 'vue-material-design-icons/Close.vue'

export default {
	name: 'ArchiMateImportExport',

	components: {
		AlwaysVisibleSection,
		NcButton,
		NcNoteCard,
		NcLoadingIcon,
		NcSelect,
		NcCheckboxRadioSwitch,
		CloudUpload,
		AlertCircle,
		Download,
		Refresh,
		Close,
	},

	/**
	 * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-7
	 */
	setup() {
		return {
			store: settingsStore,
		}
	},

	data() {
		return {
			importing: false,
			exporting: false,
			exportingOrg: false,
			selectedFile: null,
			selectedOrganization: null,
			importResult: null,
			importError: null,
			showErrors: false,
			includeModules: true,
			includeDeelnames: false,
			includeGebruik: false,
			organizationOptions: [
				{ label: 'Generic', value: null },
			],
		}
	},

	/**
	 * Load organizations when component is created
	 */
	async created() {
		await this.loadOrganizations()
	},

	methods: {
		/**
		 * Handle file selection from file input
		 *
		 * @param {Event} event - File input change event
		 * @return {void}
		  * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-7
		 */
		handleFileSelect(event) {
			const file = event.target.files[0]
			if (file) {
				this.selectedFile = file
				// Clear previous results when new file is selected
				this.importResult = null
				this.importError = null
			}
		},

		/**
		 * Format file size for display
		 *
		 * @param {number} bytes - File size in bytes
		 * @return {string} Formatted file size string
		  * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-7
		 */
		formatFileSize(bytes) {
			if (bytes === 0) return '0 Bytes'
			const k = 1024
			const sizes = ['Bytes', 'KB', 'MB', 'GB']
			const i = Math.floor(Math.log(bytes) / Math.log(k))
			return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
		},

		/**
		 * Format time duration for display
		 *
		 * @param {number} seconds - Time in seconds
		 * @return {string} Formatted time string
		  * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-7
		 */
		formatTime(seconds) {
			if (seconds < 1) {
				return `${Math.round(seconds * 1000)}ms`
			} else if (seconds < 60) {
				return `${seconds.toFixed(1)}s`
			} else {
				const minutes = Math.floor(seconds / 60)
				const remainingSeconds = Math.round(seconds % 60)
				return `${minutes}m ${remainingSeconds}s`
			}
		},

		/**
		 * Format memory size for display
		 *
		 * @param {number} megabytes - Memory size in megabytes
		 * @return {string} Formatted memory string
		  * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-7
		 */
		formatMemory(megabytes) {
			if (megabytes < 1024) {
				return `${Math.round(megabytes)} MB`
			} else {
				return `${(megabytes / 1024).toFixed(1)} GB`
			}
		},

		/**
		 * Import ArchiMate file with simplified processing
		 *
		 * @async
		 * @return {Promise<void>}
		  * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-7
		 */
		async importArchiMateFile() {
			if (!this.selectedFile) {
				return
			}

			this.importing = true
			this.importResult = null
			this.importError = null

			try {
				// Create FormData for file upload
				const formData = new FormData()
				formData.append('archiMateFile', this.selectedFile)

				// Wrap the import operation with heartbeat to prevent 504 timeouts
				const result = await withHeartbeat(async () => {
					// Make the API call
					// Note: Do not set Content-Type header for FormData - let browser set it with boundary
					// Also remove OCS-APIREQUEST for file uploads as it interferes with multipart form data
					const response = await fetch('/index.php/apps/softwarecatalog/api/archimate/import', {
						method: 'POST',
						headers: {
							'X-Requested-With': 'XMLHttpRequest',
						},
						body: formData,
					})

					const result = await response.json()

					if (!result.success) {
						throw new Error(result.error || result.message || 'Import failed')
					}

					return result
				}, 30000) // 30-second heartbeat interval

				// Handle successful result
				this.importResult = result
				// Show success notification
				OC.Notification.showTemporary(
					`Successfully imported ${result.performance_metrics.objects_processed} objects in ${this.formatTime(result.performance_metrics.total_time_seconds)}`,
					{ type: 'success' },
				)

			} catch (error) {
				console.error('Error importing ArchiMate file:', error)
				this.importError = {
					message: error.message || 'Failed to import ArchiMate file',
					details: error.details || null,
				}

				// Show error notification
				OC.Notification.showTemporary(
					'Import failed: ' + this.importError.message,
					{ type: 'error' },
				)
			} finally {
				this.importing = false
			}
		},

		/**
		 * Export ArchiMate file with organization filter
		 *
		 * @async
		 * @return {Promise<void>}
		  * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-7
		 */
		async exportArchiMateFile() {
			this.exporting = true

			try {
				// Prepare export data with organization filter
				const exportData = {
					organization: this.selectedOrganization?.value ?? null,
				}

				// Wrap the export operation with heartbeat to prevent 504 timeouts
				await withHeartbeat(async () => {
					// Make the API call
					const response = await fetch('/index.php/apps/softwarecatalog/api/archimate/export', {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							'OCS-APIREQUEST': 'true',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify(exportData),
					})

					// Handle file download
					if (response.ok) {
						const blob = await response.blob()
						const url = window.URL.createObjectURL(blob)

						// Get filename from response headers or create default
						const contentDisposition = response.headers.get('content-disposition')
						let fileName = 'archimate_export.xml'
						if (contentDisposition) {
							const match = contentDisposition.match(/filename="?([^"]*)"?/)
							if (match) {
								fileName = match[1]
							}
						}

						// Create download link and trigger download
						const a = document.createElement('a')
						a.href = url
						a.download = fileName
						document.body.appendChild(a)
						a.click()
						window.URL.revokeObjectURL(url)
						document.body.removeChild(a)

						// Show success notification
						const orgName = this.selectedOrganization
							? this.organizationOptions.find(opt => opt.value === this.selectedOrganization)?.label
							: 'Generic'
						OC.Notification.showTemporary(
							`ArchiMate file exported successfully for ${orgName}`,
							{ type: 'success' },
						)
					} else {
						const errorData = await response.json()
						throw new Error(errorData.message || 'Export failed')
					}
				}, 30000) // 30-second heartbeat interval

			} catch (error) {
				console.error('Error exporting ArchiMate file:', error)

				// Show error notification
				OC.Notification.showTemporary(
					'Export failed: ' + error.message,
					{ type: 'error' },
				)
			} finally {
				this.exporting = false
			}
		},

		/**
		 * Export organization-specific ArchiMate file with enriched views
		  * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-7
		 */
		async exportOrgArchiMateFile() {
			if (!this.selectedOrganization) return
			this.exportingOrg = true

			try {
				const orgUuid = this.selectedOrganization?.value ?? this.selectedOrganization

				// Build query string from checkbox states
				const params = new URLSearchParams()
				params.set('modules', String(this.includeModules))
				params.set('deelnames', String(this.includeDeelnames))
				params.set('gebruik', String(this.includeGebruik))

				const url = `/index.php/apps/softwarecatalog/api/archimate/export/organization/${encodeURIComponent(orgUuid)}?${params.toString()}`

				await withHeartbeat(async () => {
					const response = await fetch(url, {
						method: 'GET',
						headers: {
							'OCS-APIREQUEST': 'true',
							requesttoken: OC.requestToken,
						},
					})

					if (response.ok) {
						const blob = await response.blob()
						const blobUrl = window.URL.createObjectURL(blob)

						const contentDisposition = response.headers.get('content-disposition')
						let fileName = 'archimate_org_export.xml'
						if (contentDisposition) {
							const match = contentDisposition.match(/filename="?([^"]*)"?/)
							if (match) {
								fileName = match[1]
							}
						}

						const a = document.createElement('a')
						a.href = blobUrl
						a.download = fileName
						document.body.appendChild(a)
						a.click()
						window.URL.revokeObjectURL(blobUrl)
						document.body.removeChild(a)

						const orgLabel = this.organizationOptions.find(
							opt => opt.value === orgUuid,
						)?.label ?? 'Organization'
						OC.Notification.showTemporary(
							`Organization ArchiMate file exported for ${orgLabel}`,
							{ type: 'success' },
						)
					} else {
						const errorData = await response.json()
						throw new Error(errorData.message || 'Organization export failed')
					}
				}, 30000)

			} catch (error) {
				console.error('Error exporting organization ArchiMate file:', error)
				OC.Notification.showTemporary(
					'Organization export failed: ' + error.message,
					{ type: 'error' },
				)
			} finally {
				this.exportingOrg = false
			}
		},

		/**
		 * Clear import results and errors
		 *
		 * @return {void}
		  * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-7
		 */
		clearResults() {
			this.importResult = null
			this.importError = null
		},

		/**
		 * Reset import state and show upload interface again
		 *
		 * @return {void}
		  * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-7
		 */
		resetImport() {
			// Clear all import state
			this.importResult = null
			this.importError = null
			this.selectedFile = null
			this.importing = false
			this.showErrors = false

			// Reset the file input
			if (this.$refs.fileInput) {
				this.$refs.fileInput.value = ''
			}
		},

		/**
		 * Show detailed error information
		 *
		 * @return {void}
		  * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-7
		 */
		showErrorDetails() {
			if (this.importResult?.statistics?.summary?.total_errors > 0) {
				this.showErrors = true
			}
		},

		/**
		 * Hide detailed error information
		 *
		 * @return {void}
		  * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-7
		 */
		hideErrorDetails() {
			this.showErrors = false
		},

		/**
		 * Format error type for display
		 *
		 * @param {string} errorType - The error type to format
		 * @return {string} Formatted error type
		  * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-7
		 */
		formatErrorType(errorType) {
			const typeMap = {
				validation: 'Validation',
				schema: 'Schema',
				reference: 'Reference',
				property: 'Property',
				constraint: 'Constraint',
				relationship: 'Relationship',
				data_type: 'Data Type',
				encoding: 'Encoding',
				general: 'General',
			}
			return typeMap[errorType] || errorType.charAt(0).toUpperCase() + errorType.slice(1)
		},

		/**
		 * Check if error is a configuration error
		 *
		 * @param {string} errorMessage - Error message to check
		 * @return {boolean} True if it's a configuration error
		  * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-7
		 */
		isConfigurationError(errorMessage) {
			return errorMessage && (
				errorMessage.includes('missing configuration')
				|| errorMessage.includes('is not configured')
				|| errorMessage.includes('Please configure all AMEF schema IDs')
			)
		},

		/**
		 * Get configuration error description
		 *
		 * @param {string} errorMessage - Full error message
		 * @return {string} Clean description of the error
		  * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-7
		 */
		getConfigurationErrorDescription(errorMessage) {
			if (!errorMessage) return ''

			// Handle new error format from updated validation
			if (errorMessage.includes('is not configured')) {
				// Extract schema type from error message
				const schemaMatch = errorMessage.match(/Schema ID for (\w+) '([^']+)' is not configured/)
				if (schemaMatch) {
					return `Missing configuration for ${schemaMatch[1]} schema (${schemaMatch[2]})`
				}
				return 'Required AMEF schema configuration is missing.'
			}

			// Extract the main description before the missing items list (legacy format)
			const lines = errorMessage.split('\n')
			const mainLine = lines.find(line => line.includes('cannot proceed'))
			if (mainLine) {
				return mainLine.replace('ArchiMate import ', 'Import ').trim()
			}
			return 'Required configuration is missing for ArchiMate import.'
		},

		/**
		 * Extract missing configuration items from error message
		 *
		 * @param {string} errorMessage - Full error message
		 * @return {Array} Array of missing configuration items
		  * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-7
		 */
		getMissingConfigItems(errorMessage) {
			if (!errorMessage) return []

			// Handle new error format from updated validation
			if (errorMessage.includes('is not configured')) {
				const schemaMatch = errorMessage.match(/Schema ID for (\w+) '([^']+)' is not configured/)
				if (schemaMatch) {
					return [`${schemaMatch[2]} schema ID (for ${schemaMatch[1]} objects)`]
				}

				// Check for register ID configuration error
				if (errorMessage.includes('register ID is not configured')) {
					return ['AMEF Register ID']
				}

				return ['AMEF schema configuration']
			}

			// Handle legacy error format
			const lines = errorMessage.split('\n')
			const missingItems = []
			let inMissingSection = false

			for (const line of lines) {
				if (line.includes('Missing configuration:')) {
					inMissingSection = true
					continue
				}

				if (inMissingSection && line.trim().startsWith('-')) {
					// Clean up the item text
					const item = line.replace(/^-\s*/, '').trim()
					missingItems.push(item)
				} else if (inMissingSection && !line.trim().startsWith('-') && line.trim() !== '') {
					// End of missing items list
					break
				}
			}

			return missingItems
		},

		/**
		 * Load organization options from the API
		 *
		 * @async
		 * @return {Promise<void>}
		  * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-7
		 */
		async loadOrganizations() {
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/voorzieningen/config', {
					headers: {
						'OCS-APIREQUEST': 'true',
						requesttoken: OC.requestToken,
					},
				})
				if (!response.ok) throw new Error('Failed to load config')
				const data = await response.json()

				// Get voorzieningen register and organisatie schema from config
				const voorzRegister = data?.config?.register
				const orgSchema = data?.config?.organisatie_schema
				if (!voorzRegister || !orgSchema) {
					console.debug('Voorzieningen config not available, using default Generic option')
					return
				}

				// Load organizations from OpenRegister
				const orgResponse = await fetch(
					`/index.php/apps/openregister/api/objects/${voorzRegister}/${orgSchema}?_limit=5000&_fields=id,naam`,
					{
						headers: {
							'OCS-APIREQUEST': 'true',
							requesttoken: OC.requestToken,
						},
					},
				)
				if (!orgResponse.ok) throw new Error('Failed to load organizations')
				const orgData = await orgResponse.json()

				const orgs = orgData?.results || orgData || []
				if (Array.isArray(orgs) && orgs.length > 0) {
					this.organizationOptions = orgs
						.filter(org => org.naam || org.name)
						.map(org => ({
							label: org.naam || org.name,
							value: org.id || org['@self']?.id,
						}))
						.sort((a, b) => a.label.localeCompare(b.label))
				}
			} catch (error) {
				console.warn('Could not load organizations:', error.message)
			}
		},
	},
}
</script>

<style scoped>
/* Operations Section Styles */
.operations-section {
	padding: 2rem 0;
}

/* File Upload Container */
.file-upload-container {
	margin-bottom: 2rem;
}

.file-input {
	position: absolute;
	width: 1px;
	height: 1px;
	padding: 0;
	margin: -1px;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	white-space: nowrap;
	border: 0;
}

.file-upload-label {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 3rem 2rem;
	border: 2px dashed var(--color-border-dark);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
	cursor: pointer;
	transition: all 0.2s ease;
	min-height: 120px;
	text-align: center;
	gap: 0.5rem;
}

.file-upload-label:hover {
	border-color: var(--color-primary);
	background: var(--color-primary-light);
}

.file-upload-label.disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.file-upload-label span {
	font-size: 1rem;
	font-weight: 500;
	color: var(--color-main-text);
}

.file-size {
	font-size: 0.875rem !important;
	color: var(--color-text-maxcontrast) !important;
	font-weight: normal !important;
}

/* Import Section */
.import-section {
	margin-bottom: 3rem;
}

.import-button-section {
	margin-top: 1rem;
}

.import-results-section {
	margin-bottom: 1rem;
}

/* Export Section */
.export-section {
	padding: 2rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.export-section h4 {
	margin: 0 0 0.5rem 0;
	font-size: 1.1rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.export-section p {
	margin: 0 0 1.5rem 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.export-controls {
	display: flex;
	flex-direction: column;
	gap: 1.5rem;
}

.control-group {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.control-group label {
	font-weight: 500;
	font-size: 0.9rem;
	color: var(--color-main-text);
}

.checkbox-group {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem 1.5rem;
}

@media (max-width: 768px) {
	.export-section {
		padding: 1rem;
	}
}

/* Result Cards */
.result-card {
	margin: 1rem 0;
	padding: 1.5rem;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.result-card h5 {
	margin: 0 0 1rem 0;
	font-size: 1rem;
	font-weight: 600;
	color: var(--color-main-text);
	border-bottom: 2px solid var(--color-primary);
	padding-bottom: 0.5rem;
}

/* Info Grid */
.info-grid {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.info-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.5rem 0;
	border-bottom: 1px solid var(--color-border-dark);
}

.info-item:last-child {
	border-bottom: none;
}

.info-item .label {
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	flex: 0 0 40%;
}

.info-item .value {
	font-weight: 600;
	color: var(--color-main-text);
	text-align: right;
}

/* Metrics Grid */
.metrics-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 1.5rem;
}

.metric-item {
	text-align: center;
	padding: 1rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.metric-value {
	font-size: 1.5rem;
	font-weight: 700;
	color: var(--color-primary);
	margin-bottom: 0.5rem;
}

.metric-label {
	font-size: 0.875rem;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

/* Processing Breakdown */
.breakdown-list {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.breakdown-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 1rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	border-left: 4px solid var(--color-primary);
}

.breakdown-label {
	font-weight: 500;
	color: var(--color-main-text);
	flex: 1;
}

.breakdown-value {
	font-weight: 600;
	color: var(--color-primary);
	margin-right: 1rem;
}

.breakdown-rate {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
	padding: 0.25rem 0.5rem;
	border-radius: 12px;
}

/* Summary Grid */
.summary-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
	gap: 1rem;
}

.summary-item {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 1.5rem 1rem;
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	border: 2px solid transparent;
	transition: all 0.2s ease;
}

.summary-item.created {
	border-color: var(--color-success);
	background: var(--color-success-light);
}

.summary-item.updated {
	border-color: var(--color-warning);
	background: var(--color-warning-light);
}

.summary-item.unchanged {
	border-color: var(--color-text-lighter);
	background: var(--color-background-hover);
}

.summary-item.errors {
	border-color: var(--color-error);
	background: var(--color-error-light);
}

.summary-number {
	font-size: 2rem;
	font-weight: 700;
	color: var(--color-main-text);
	margin-bottom: 0.5rem;
}

.summary-label {
	font-size: 0.875rem;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

/* Error Display */
.error-results {
	margin-top: 1rem;
}

.error-header {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-bottom: 1rem;
	padding: 1rem;
}

.error-header h4 {
	margin: 0;
	font-size: 1.1rem;
	font-weight: 600;
	color: var(--color-error-text);
}

.error-details {
	padding: 1rem;
	flex: 1;
	min-width: 0;
}

.error-details p {
	margin: 0 0 1rem 0;
	color: var(--color-error-text);
}

.error-additional {
	margin-top: 1rem;
	padding: 1rem;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.error-additional h5 {
	margin: 0 0 0.5rem 0;
	font-size: 0.875rem;
	font-weight: 600;
}

.error-additional pre {
	margin: 0;
	padding: 0.5rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	font-size: 0.8rem;
	line-height: 1.4;
	overflow-x: auto;
	color: var(--color-text-maxcontrast);
}

/* Configuration Error Styles */
.configuration-error {
	margin-top: 1rem;
}

.configuration-error p {
	margin: 0 0 1rem 0;
	color: var(--color-error-text);
	font-size: 1rem;
}

.configuration-issues {
	margin-bottom: 1.5rem;
	padding: 1rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	border-left: 4px solid var(--color-warning);
}

.configuration-issues p {
	margin: 0 0 1rem 0;
	color: var(--color-main-text);
	font-weight: 500;
}

.missing-items {
	margin-top: 0.5rem;
}

.missing-items ul {
	margin: 0;
	padding-left: 1.5rem;
	list-style-type: none;
}

.missing-items li {
	position: relative;
	margin: 0.5rem 0;
	padding: 0.5rem 0.75rem;
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	color: var(--color-main-text);
	font-family: var(--font-face, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', Arial, sans-serif);
	font-size: 0.875rem;
	border-left: 3px solid var(--color-error);
}

.missing-items li::before {
	content: '✗';
	position: absolute;
	left: -0.5rem;
	top: 0.5rem;
	color: var(--color-error);
	font-weight: bold;
	font-size: 0.75rem;
	background: var(--color-main-background);
	width: 1rem;
	height: 1rem;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: 50%;
	border: 1px solid var(--color-error);
}

.configuration-help {
	padding: 1.5rem;
	background: var(--color-primary-light);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-primary-element-lighter);
}

.configuration-help h5 {
	margin: 0 0 1rem 0;
	font-size: 1rem;
	font-weight: 600;
	color: var(--color-primary-element-text);
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.configuration-help h5::before {
	content: '💡';
	font-size: 1.2rem;
}

.configuration-help ol {
	margin: 0;
	padding-left: 1.5rem;
	color: var(--color-main-text);
}

.configuration-help li {
	margin: 0.75rem 0;
	line-height: 1.5;
	font-size: 0.9rem;
}

.configuration-help strong {
	color: var(--color-primary-element-text);
	font-weight: 600;
}

/* Responsive Design */
@media (max-width: 768px) {
	.metrics-grid {
		grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
	}

	.summary-grid {
		grid-template-columns: repeat(2, 1fr);
	}

	.breakdown-item {
		flex-direction: column;
		align-items: flex-start;
		gap: 0.5rem;
	}

	.breakdown-value {
		margin-right: 0;
	}

	.info-item {
		flex-direction: column;
		align-items: flex-start;
		gap: 0.25rem;
	}

	.info-item .label {
		flex: none;
	}

	.info-item .value {
		text-align: left;
	}

	/* Configuration Error Mobile Styles */
	.configuration-error {
		margin-top: 0.5rem;
	}

	.configuration-issues {
		margin-bottom: 1rem;
		padding: 0.75rem;
	}

	.configuration-help {
		padding: 1rem;
	}

	.configuration-help h5 {
		font-size: 0.9rem;
	}

	.configuration-help li {
		margin: 0.5rem 0;
		font-size: 0.85rem;
	}

	.missing-items li {
		font-size: 0.8rem;
		padding: 0.4rem 0.6rem;
	}

	.missing-items li::before {
		font-size: 0.7rem;
		width: 0.85rem;
		height: 0.85rem;
		top: 0.4rem;
	}
}

/* Clickable Error Tile Styles */
.summary-item.clickable {
	cursor: pointer;
	transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.summary-item.clickable:hover {
	transform: translateY(-2px);
	box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.click-hint {
	font-size: 0.75rem !important;
	color: var(--color-text-maxcontrast) !important;
	margin-top: 0.25rem;
	font-style: italic;
}

/* Error Details Modal Styles */
.error-details-modal {
	position: fixed;
	top: 0;
	left: 0;
	width: 100vw;
	height: 100vh;
	background: rgba(0, 0, 0, 0.5);
	z-index: 10000;
	display: flex;
	justify-content: center;
	align-items: flex-start;
	padding: 2rem;
	overflow-y: auto;
}

.error-details-content {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
	width: 100%;
	max-width: 900px;
	max-height: 90vh;
	overflow-y: auto;
	margin-top: 2rem;
}

.error-details-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 1.5rem 2rem;
	border-bottom: 2px solid var(--color-border);
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large) var(--border-radius-large) 0 0;
}

.error-details-header h5 {
	margin: 0;
	font-size: 1.2rem;
	font-weight: 600;
	color: var(--color-main-text);
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.error-details-header h5::before {
	content: '⚠️';
	font-size: 1.3rem;
}

/* Error Summary Styles */
.error-summary {
	padding: 1.5rem 2rem;
	background: var(--color-error-light);
	border-bottom: 1px solid var(--color-border);
}

.error-stats {
	display: flex;
	justify-content: center;
	gap: 3rem;
}

.stat-item {
	text-align: center;
}

.stat-number {
	display: block;
	font-size: 2rem;
	font-weight: 700;
	color: var(--color-error);
	margin-bottom: 0.25rem;
}

.stat-label {
	font-size: 0.875rem;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

/* Common Errors Section */
.common-errors-section {
	padding: 1.5rem 2rem;
	border-bottom: 1px solid var(--color-border);
}

.common-errors-section h6 {
	margin: 0 0 1rem 0;
	font-size: 1rem;
	font-weight: 600;
	color: var(--color-main-text);
	border-bottom: 2px solid var(--color-warning);
	padding-bottom: 0.5rem;
}

.common-errors-list {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.common-error-item {
	display: flex;
	align-items: flex-start;
	gap: 1rem;
	padding: 1rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	border-left: 4px solid var(--color-warning);
}

.error-type-badge {
	padding: 0.25rem 0.75rem;
	border-radius: 12px;
	font-size: 0.75rem;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	white-space: nowrap;
	flex-shrink: 0;
}

.error-type-badge.small {
	padding: 0.2rem 0.5rem;
	font-size: 0.7rem;
}

/* Error type colors */
.error-type-badge.validation { background: #ffebee; color: #c62828; }

.error-type-badge.schema { background: #e3f2fd; color: #1565c0; }

.error-type-badge.reference { background: #f3e5f5; color: #7b1fa2; }

.error-type-badge.property { background: #e8f5e8; color: #2e7d32; }

.error-type-badge.constraint { background: #fff3e0; color: #ef6c00; }

.error-type-badge.relationship { background: #fce4ec; color: #ad1457; }

.error-type-badge.data_type { background: #e0f2f1; color: #00695c; }

.error-type-badge.encoding { background: #f1f8e9; color: #558b2f; }

.error-type-badge.general { background: #f5f5f5; color: #424242; }

.error-message {
	font-weight: 500;
	color: var(--color-main-text);
	margin-bottom: 0.5rem;
	word-wrap: break-word;
}

.error-meta {
	display: flex;
	flex-wrap: wrap;
	gap: 1rem;
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
}

.error-count {
	font-weight: 600;
	color: var(--color-error);
}

.affected-sections {
	font-style: italic;
}

/* Errors by Section */
.errors-by-section {
	padding: 1.5rem 2rem;
}

.errors-by-section h6 {
	margin: 0 0 1rem 0;
	font-size: 1rem;
	font-weight: 600;
	color: var(--color-main-text);
	border-bottom: 2px solid var(--color-primary);
	padding-bottom: 0.5rem;
}

.section-errors-list {
	display: flex;
	flex-direction: column;
	gap: 1.5rem;
}

.section-error-group {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	overflow: hidden;
}

.section-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 1rem 1.5rem;
	background: var(--color-background-hover);
	border-bottom: 1px solid var(--color-border);
}

.section-header h6 {
	margin: 0;
	font-size: 0.9rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.section-error-count {
	font-size: 0.875rem;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	padding: 0.25rem 0.5rem;
	background: var(--color-error-light);
	border-radius: 10px;
	border: 1px solid var(--color-error);
}

.section-error-details {
	padding: 1rem 1.5rem;
}

.error-group {
	margin-bottom: 1rem;
	padding: 1rem;
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border-dark);
}

.error-group:last-child {
	margin-bottom: 0;
}

.error-group-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 0.5rem;
}

.error-group-count {
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-error);
	padding: 0.2rem 0.5rem;
	background: var(--color-error-light);
	border-radius: 8px;
}

.error-group-message {
	color: var(--color-main-text);
	margin-bottom: 0.5rem;
	font-size: 0.9rem;
	line-height: 1.4;
}

.error-examples {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	margin-top: 0.5rem;
	padding: 0.5rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.examples-label {
	font-weight: 600;
	margin-right: 0.5rem;
}

.examples-list {
	font-family: var(--font-face-monospace, monospace);
}

/* Responsive Design for Error Details */
@media (max-width: 768px) {
	.error-details-modal {
		padding: 1rem;
	}

	.error-details-header {
		padding: 1rem;
	}

	.error-summary,
	.common-errors-section,
	.errors-by-section {
		padding: 1rem;
	}

	.error-stats {
		gap: 2rem;
	}

	.stat-number {
		font-size: 1.5rem;
	}

	.common-error-item {
		flex-direction: column;
		align-items: flex-start;
		gap: 0.75rem;
	}

	.section-header {
		flex-direction: column;
		align-items: flex-start;
		gap: 0.5rem;
		padding: 0.75rem 1rem;
	}

	.error-group-header {
		flex-direction: column;
		align-items: flex-start;
		gap: 0.5rem;
	}

	.section-error-details {
		padding: 0.75rem 1rem;
	}

	.error-meta {
		flex-direction: column;
		gap: 0.25rem;
	}
}
</style>
