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
								<div class="summary-item skipped">
									<div class="summary-number">
										{{ importResult.statistics.summary.total_objects_skipped }}
									</div>
									<div class="summary-label">
										Skipped
									</div>
								</div>
								<div class="summary-item errors">
									<div class="summary-number">
										{{ importResult.statistics.summary.total_errors }}
									</div>
									<div class="summary-label">
										Errors
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
								<p><strong>Error:</strong> {{ importError.message || 'Unknown error occurred during import' }}</p>
								<div v-if="importError.details" class="error-additional">
									<h5>Additional Details:</h5>
									<pre>{{ JSON.stringify(importError.details, null, 2) }}</pre>
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

					<NcButton
						type="secondary"
						:disabled="exporting"
						@click="exportArchiMateFile">
						<template #icon>
							<NcLoadingIcon v-if="exporting" :size="20" />
							<Download v-else :size="20" />
						</template>
						{{ exporting ? 'Exporting...' : 'Export' }}
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

// Icons
import CloudUpload from 'vue-material-design-icons/CloudUpload.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

export default {
	name: 'ArchiMateImportExport',

	components: {
		AlwaysVisibleSection,
		NcButton,
		NcNoteCard,
		NcLoadingIcon,
		NcSelect,
		CloudUpload,
		AlertCircle,
		Download,
		Refresh,
	},

	setup() {
		return {
			store: settingsStore,
		}
	},

	data() {
		return {
			importing: false,
			exporting: false,
			selectedFile: null,
			selectedOrganization: null,
			importResult: null,
			importError: null,
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
					const response = await fetch('/index.php/apps/softwarecatalog/api/archimate/import', {
						method: 'POST',
						headers: {
							'OCS-APIREQUEST': 'true',
							requesttoken: OC.requestToken,
						},
						body: formData,
					})

					const result = await response.json()

					if (!result.success) {
						throw new Error(result.message || 'Import failed')
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
		 * Clear import results and errors
		 *
		 * @return {void}
		 */
		clearResults() {
			this.importResult = null
			this.importError = null
		},

		/**
		 * Reset import state and show upload interface again
		 *
		 * @return {void}
		 */
		resetImport() {
			// Clear all import state
			this.importResult = null
			this.importError = null
			this.selectedFile = null
			this.importing = false

			// Reset the file input
			if (this.$refs.fileInput) {
				this.$refs.fileInput.value = ''
			}
		},

			/**
	 * Load organization options from the API
	 *
	 * @async
	 * @return {Promise<void>}
	 */
	async loadOrganizations() {
		try {
			// Get organization objects from OpenRegister
			// This would need to be implemented based on your organization schema
			// For now, we'll keep the default Generic option
			const response = await fetch('/index.php/apps/openregister/api/objects/6/35', {
				method: 'GET',
				headers: {
					'Content-Type': 'application/json',
					'OCS-APIREQUEST': 'true',
					requesttoken: OC.requestToken,
				},
			})

			if (response.ok) {
				const result = await response.json()
				const organizations = result.results || []

				// Add organization options
				const orgOptions = [
					{ label: 'Generic', value: null },
					...organizations.map(org => ({
						label: org.naam || org.title || org.name || 'Unknown Organization',
						value: org.id,
					})),
				]

				this.organizationOptions = orgOptions
			}
		} catch (error) {
			console.warn('Failed to load organizations, using default options:', error)
			// Keep default options if loading fails
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

.summary-item.skipped {
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
}
</style>
