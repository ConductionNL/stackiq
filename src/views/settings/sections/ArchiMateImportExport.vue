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
		:loading="loading"
		loading-text="Preparing ArchiMate tools..."
		:has-info-content="true">
		<StandardTabs
			:tabs="[
				{ key: 'import', title: 'Import' },
				{ key: 'export', title: 'Export' },
				{ key: 'testing', title: 'Testing' }
			]"
			:active-tab="activeTab"
			@update:active-tab="activeTab = $event">
			<!-- Import Tab -->
			<div v-show="activeTab === 'import'" class="tab-panel">
				<div class="import-section">
					<!-- Import Status Display -->
					<div v-if="archimateStatus.import && (archimateStatus.import.status === 'running' || archimateStatus.import.status === 'completed')" class="status-display">
						<NcNoteCard type="info">
							<div class="import-progress-container">
								<div class="import-header">
									<div class="import-header-content">
										<div class="import-title-section">
											<h4>{{ archimateStatus.import.status === 'completed' ? 'Import Completed' : 'Import in Progress' }}</h4>
											<NcButton
												v-if="archimateStatus.import.status === 'running'"
												type="error"
												:disabled="cancelling"
												@click="cancelImport">
												<template #icon>
													<StopIcon :size="16" />
												</template>
												{{ cancelling ? 'Cancelling...' : 'Cancel Import' }}
											</NcButton>
											<NcButton
												v-else-if="archimateStatus.import.status === 'completed'"
												type="secondary"
												@click="clearCompletedImportStatus">
												<template #icon>
													<CheckCircle :size="16" />
												</template>
												Clear Results
											</NcButton>
										</div>
										<p>Current step: <strong>{{ archimateStatus.import.current_step }}</strong></p>
										<div class="progress-bar">
											<div class="progress-fill" :style="{ width: archimateStatus.import.progress + '%' }" />
											<span class="progress-text">{{ archimateStatus.import.progress }}%</span>
										</div>
									</div>
								</div>
							</div>
						</NcNoteCard>
					</div>

					<!-- File Upload FIRST -->
					<div class="file-upload-section">
						<h5>Select File</h5>
						<input
							type="file"
							accept=".archimate,.xml"
							:disabled="importing || isImportRunning"
							@change="handleFileSelect">
					</div>

					<!-- Import Configuration SECOND -->
					<div class="performance-mode-section">
						<h5>Processing Mode</h5>
						<p class="mode-description">
							Choose performance vs memory efficiency for large files.
						</p>
						<div class="mode-options">
							<label class="mode-option">
								<input v-model="processingMode"
									type="radio"
									value="speed"
									:disabled="importing || isImportRunning">
								<span class="mode-label">
									<div class="mode-header">
										<strong>High Performance</strong>
										<span class="mode-badge speed">Speed</span>
									</div>
									<div class="mode-details">
										<p>Best for most imports; uses more memory for faster processing.</p>
										<ul>
											<li>Parallel parsing</li>
											<li>Optimized batching</li>
										</ul>
									</div>
								</span>
							</label>
							<label class="mode-option">
								<input v-model="processingMode"
									type="radio"
									value="memory"
									:disabled="importing || isImportRunning">
								<span class="mode-label">
									<div class="mode-header">
										<strong>Memory Efficient</strong>
									</div>
									<div class="mode-details">
										<p>Lower memory footprint for limited environments.</p>
										<ul>
											<li>Streamed reading</li>
											<li>Small buffers</li>
										</ul>
									</div>
								</span>
							</label>
						</div>
						<!-- Fallback select -->
						<NcSelect
							v-model="processingMode"
							:options="[
								{ label: 'High Performance (Speed)', value: 'speed' },
								{ label: 'Memory Efficient', value: 'memory' },
							]"
							input-label="Processing Mode"
							:disabled="importing || isImportRunning" />
					</div>

					<!-- Import Button -->
					<div class="button-section">
						<NcButton
							type="primary"
							:disabled="!selectedFile || importing || isImportRunning"
							@click="importArchiMateFile">
							<template #icon>
								<NcLoadingIcon v-if="importing || isImportRunning" :size="20" />
								<CloudUpload v-else :size="20" />
							</template>
							{{ importing || isImportRunning ? 'Import in Progress...' : 'Import ArchiMate File' }}
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Export Tab -->
			<div v-show="activeTab === 'export'" class="tab-panel">
				<div class="export-section">
					<h4>Export to ArchiMate</h4>
					<p>Export existing OpenRegister data to ArchiMate format for use in architecture tools</p>

					<!-- Export Status Display -->
					<div v-if="archimateStatus.export && (archimateStatus.export.status === 'running' || archimateStatus.export.status === 'completed')" class="status-display">
						<NcNoteCard type="info">
							<div class="status-content">
								<p><strong>{{ archimateStatus.export.status === 'completed' ? 'Export Completed' : 'Export in Progress' }}</strong></p>
								<p>Current step: <strong>{{ archimateStatus.export.current_step }}</strong></p>
								<div class="progress-bar">
									<div class="progress-fill" :style="{ width: archimateStatus.export.progress + '%' }" />
									<span class="progress-text">{{ archimateStatus.export.progress }}%</span>
								</div>
							</div>
						</NcNoteCard>
					</div>

					<!-- Export Configuration -->
					<div class="export-config">
						<h5>Export Configuration</h5>
						<NcSelect
							v-model="exportFormat"
							:options="[
								{ label: 'ArchiMate (.archimate)', value: 'archimate' },
								{ label: 'XML', value: 'xml' },
								{ label: 'JSON', value: 'json' }
							]"
							input-label="Export Format"
							:disabled="exporting" />

						<NcButton
							type="primary"
							:disabled="exporting"
							@click="exportToArchiMate">
							<template #icon>
								<NcLoadingIcon v-if="exporting" :size="20" />
								<Download v-else :size="20" />
							</template>
							{{ exporting ? 'Exporting...' : 'Export ArchiMate File' }}
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Testing Tab -->
			<div v-show="activeTab === 'testing'" class="tab-panel">
				<div class="testing-section">
					<h4>Round-Trip Testing</h4>
					<p>Test the complete import/export cycle to verify data integrity</p>

					<div class="test-config">
						<h5>Test Configuration</h5>
						<p>This will perform a full import followed by export to verify data consistency.</p>

						<NcButton
							type="secondary"
							:disabled="testingRoundTrip"
							@click="testRoundTrip">
							<template #icon>
								<NcLoadingIcon v-if="testingRoundTrip" :size="20" />
								<Sync v-else :size="20" />
							</template>
							{{ testingRoundTrip ? 'Testing...' : 'Run Round-Trip Test' }}
						</NcButton>
					</div>
				</div>
			</div>
		</StandardTabs>

		<!-- Info Content Slot -->
		<template #info-content>
			<div class="archimate-info">
				<h3>About ArchiMate Import/Export</h3>
				<p>The ArchiMate Import/Export functionality allows you to work with architectural models in external tools while maintaining data consistency in OpenRegister.</p>

				<h4>Import Features</h4>
				<ul>
					<li>Import ArchiMate models (.archimate or .xml files)</li>
					<li>Automatic creation of organizations and elements</li>
					<li>Progress tracking and error handling</li>
					<li>Configurable processing modes for performance</li>
				</ul>

				<h4>Export Features</h4>
				<ul>
					<li>Export OpenRegister data to ArchiMate format</li>
					<li>Multiple export formats (ArchiMate, XML, JSON)</li>
					<li>Preserve relationships and metadata</li>
					<li>Compatible with external architecture tools</li>
				</ul>

				<h4>Testing</h4>
				<p>The round-trip testing feature verifies that data integrity is maintained throughout the import/export cycle.</p>
			</div>
		</template>
	</AlwaysVisibleSection>
</template>

<script>
/**
 * ArchiMate Import/Export Component
 *
 * This component handles ArchiMate file import/export functionality,
 * including status monitoring and testing capabilities.
 *
 * @author Ruben Linde <info@conduction.nl>
 * @copyright 2023 Conduction B.V.
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 */

import { settingsStore } from '../../../store/store.js'

// Components
import AlwaysVisibleSection from '../../../components/AlwaysVisibleSection.vue'

// Nextcloud Vue components
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

// Icons
// import Upload from 'vue-material-design-icons/Upload.vue'
import Download from 'vue-material-design-icons/Download.vue'
import CloudUpload from 'vue-material-design-icons/CloudUpload.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
// import Alert from 'vue-material-design-icons/Alert.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import StopIcon from 'vue-material-design-icons/Stop.vue'

// Bootstrap Vue components for tabs
import StandardTabs from '../../../components/StandardTabs.vue'

export default {
	name: 'ArchiMateImportExport',

	components: {
		AlwaysVisibleSection,
		NcButton,
		NcSelect,
		NcNoteCard,
		NcLoadingIcon,
		// Upload,
		Download,
		CloudUpload,
		CheckCircle,
		// Alert,
		Sync,
		StopIcon,
		StandardTabs,
	},

	setup() {
		return {
			store: settingsStore,
		}
	},

	data() {
		return {
			activeTab: 'import', // Default active tab
			importing: false,
			exporting: false,
			testingRoundTrip: false,
			cancelling: false,
			importResult: null,
			exportResult: null,
			roundTripResult: null,
			exportFormat: 'archimate',
			processingMode: 'speed', // Default to high performance
		}
	},

	computed: {
		loading() { return this.store.loading },
		archimateStatus() { return this.store.archimateStatus || {} },
		selectedFile() { return this.store.selectedFile },
		isImportRunning() { return this.store.isImportRunning },
	},

	methods: {
		/**
		 * Handle file selection
		 *
		 * @param {Event} event File input change event
		 * @return {void}
		 */
		handleFileSelect(event) {
			const file = event.target.files[0]
			if (file) {
				// Store the file in the settings store
				this.store.selectedFile = file
				this.importResult = null
			}
		},

		/**
		 * Format file size for display
		 *
		 * @param {number} bytes File size in bytes
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
		 * Import ArchiMate file using the settings store
		 */
		async importArchiMateFile() {
			await this.store.importArchiMateFile(this.processingMode)
		},

		/**
		 * Cancel running ArchiMate import
		 */
		async cancelImport() {
			if (this.cancelling) {
				return // Prevent multiple cancel requests
			}

			this.cancelling = true

			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/archimate/import/cancel', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'OCS-APIREQUEST': 'true',
						requesttoken: OC.requestToken,
					},
				})

				const result = await response.json()

				if (result.success) {
					// Show success notification
					OC.Notification.showTemporary(
						`Import cancelled successfully${result.details.process_killed ? ' (process terminated)' : ''}`,
						{ type: 'success' },
					)

					// Refresh the status to reflect cancellation
					await this.store.refreshArchiMateStatus()
				} else {
					throw new Error(result.message || 'Failed to cancel import')
				}
			} catch (error) {
				console.error('Error cancelling import:', error)
				OC.Notification.showTemporary(
					'Failed to cancel import: ' + error.message,
					{ type: 'error' },
				)
			} finally {
				this.cancelling = false
			}
		},

		/**
		 * Export to ArchiMate - now triggers direct download
		 */
		async exportToArchiMate() {
			this.exporting = true
			try {
				await this.store.exportToArchiMate(this.exportFormat)
			} finally {
				this.exporting = false
			}
		},

		/**
		 * Clear import status
		 */
		async clearImportStatus() {
			await this.store.clearImportStatus()
		},

		/**
		 * Clear completed import status (user manually dismisses results)
		 */
		async clearCompletedImportStatus() {
			await this.store.clearImportStatus()
		},

		/**
		 * Clear export status
		 */
		async clearExportStatus() {
			await this.store.clearExportStatus()
		},

		/**
		 * Test round-trip functionality using the settings store
		 */
		async testRoundTrip() {
			this.testingRoundTrip = true
			this.roundTripResult = null

			try {
				const result = await this.store.testRoundTrip()
				this.roundTripResult = result
			} catch (error) {
				console.error('Round-trip test failed:', error)
				this.roundTripResult = {
					success: false,
					message: 'Round-trip test failed: ' + error.message,
				}
			} finally {
				this.testingRoundTrip = false
			}
		},

		/**
		 * Calculate progress percentage based on processed vs found objects
		 *
		 * @param {object} progress Progress object with found, created, updated, skipped counts
		 * @return {number} Progress percentage (0-100)
		 */
		calculateProgress(progress) {
			const found = progress.found || 0
			const processed = (progress.created || 0) + (progress.updated || 0) + (progress.skipped || 0)

			if (found === 0) {
				return 0
			}

			return Math.round((processed / found) * 100)
		},
	},
}
</script>

<style scoped>
.import-section,
.export-section,
.test-section {
	padding: 1rem 0;
}

.file-upload-section {
	margin: 1rem 0;
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.selected-file {
	padding: 0.5rem;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.performance-mode-section {
	margin: 1rem 0;
	padding: 1rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.performance-mode-section h5 {
	margin: 0 0 0.5rem 0;
	font-size: 0.9rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.mode-description {
	margin: 0 0 1rem 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.mode-options {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.mode-option {
	position: relative;
}

.mode-option input[type="radio"] {
	position: absolute;
	opacity: 0;
	pointer-events: none;
}

.mode-label {
	display: block;
	padding: 1rem;
	background: var(--color-main-background);
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	transition: all 0.2s ease;
}

.mode-option input[type="radio"]:checked + .mode-label {
	border-color: var(--color-primary);
	background: var(--color-primary-light);
}

.mode-option input[type="radio"]:disabled + .mode-label {
	opacity: 0.6;
	cursor: not-allowed;
}

.mode-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 0.5rem;
}

.mode-badge {
	padding: 0.25rem 0.5rem;
	border-radius: 12px;
	font-size: 0.7rem;
	font-weight: 600;
	text-transform: uppercase;
}

.mode-badge.speed {
	background: var(--color-success);
	color: var(--color-success-text);
}

.mode-details p {
	margin: 0 0 0.5rem 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.mode-details ul {
	margin: 0;
	padding-left: 1rem;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.mode-details li {
	margin-bottom: 0.25rem;
}

.export-config {
	margin: 1rem 0;
}

.status-display {
	margin: 1rem 0;
}

.status-content {
	padding: 0.5rem 0;
}

.progress-bar {
	width: 100%;
	height: 8px;
	background-color: var(--color-background-dark);
	border-radius: 4px;
	margin: 0.5rem 0;
	position: relative;
	overflow: hidden;
}

.progress-fill {
	height: 100%;
	background-color: var(--color-primary);
	transition: width 0.3s ease;
}

.progress-text {
	position: absolute;
	top: 50%;
	left: 50%;
	transform: translate(-50%, -50%);
	font-size: 0.75rem;
	font-weight: 600;
	color: var(--color-primary-text);
}

.import-stats,
.export-stats {
	margin-top: 1rem;
}

.import-stats h5,
.export-stats h5 {
	margin: 0.5rem 0 0.25rem 0;
	font-size: 0.875rem;
	font-weight: 600;
}

.import-stats ul,
.export-stats ul {
	margin: 0;
	padding-left: 1rem;
	font-size: 0.875rem;
}

.import-results,
.export-results,
.round-trip-results {
	margin: 1rem 0;
}

.result-content {
	padding: 0.5rem 0;
}

.file-info,
.performance-metrics,
.processing-times,
.summary-stats {
	margin-top: 1rem;
	padding: 1rem;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.file-info h5,
.performance-metrics h5,
.processing-times h5,
.summary-stats h5 {
	margin: 0 0 0.5rem 0;
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.file-info ul,
.performance-metrics ul,
.processing-times ul,
.summary-stats ul {
	margin: 0;
	padding-left: 1rem;
}

.file-info li,
.performance-metrics li,
.processing-times li,
.summary-stats li {
	margin-bottom: 0.25rem;
	font-size: 0.875rem;
}

.model-info {
	margin-top: 1rem;
	padding: 0.75rem;
	background: var(--color-primary-light);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-primary);
}

.model-info h5 {
	margin: 0 0 0.5rem 0;
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-primary-text);
}

.model-info ul {
	margin: 0;
	padding-left: 1rem;
}

.model-info li {
	margin-bottom: 0.25rem;
	font-size: 0.875rem;
	color: var(--color-primary-text);
}

.schema-progress-table {
	margin-top: 1rem;
	padding: 1rem;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.schema-progress-table h5 {
	margin: 0 0 1rem 0;
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.progress-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.8rem;
}

.progress-table th,
.progress-table td {
	padding: 0.5rem;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.progress-table th {
	font-weight: 600;
	color: var(--color-main-text);
	background: var(--color-background-hover);
}

.progress-table td {
	color: var(--color-text-maxcontrast);
}

.progress-table .created {
	color: var(--color-success);
	font-weight: 600;
}

.progress-table .updated {
	color: var(--color-warning);
	font-weight: 600;
}

.progress-table .skipped {
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.progress-bar-small {
	position: relative;
	width: 60px;
	height: 16px;
	background: var(--color-background-hover);
	border-radius: 8px;
	overflow: hidden;
}

.progress-fill-small {
	height: 100%;
	background: var(--color-primary);
	transition: width 0.3s ease;
}

.progress-text-small {
	position: absolute;
	top: 50%;
	left: 50%;
	transform: translate(-50%, -50%);
	font-size: 0.7rem;
	font-weight: 600;
	color: var(--color-primary-text);
	text-shadow: 0 0 2px rgba(0, 0, 0, 0.5);
}

.schema-statistics {
	margin-top: 1rem;
	padding: 1rem;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.schema-statistics h6 {
	margin: 0 0 1rem 0;
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.schema-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 1rem;
}

.schema-item {
	padding: 0.75rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border-dark);
}

.schema-item h6 {
	margin: 0 0 0.5rem 0;
	font-size: 0.8rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.schema-item ul {
	margin: 0;
	padding: 0;
	list-style: none;
}

.schema-item li {
	font-size: 0.75rem;
	margin-bottom: 0.25rem;
	color: var(--color-text-maxcontrast);
}

.comparison-results {
	margin-top: 1rem;
}

.comparison-results h5 {
	margin: 0 0 0.5rem 0;
	font-size: 0.875rem;
	font-weight: 600;
}

.comparison-results ul {
	margin: 0;
	padding-left: 1rem;
	font-size: 0.875rem;
}

/* Clean import progress styles */
.import-progress-container {
	padding: 1rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	border: 1px solid var(--color-border);
	margin: 1rem 0;
}

.import-header {
	margin-bottom: 1rem;
}

.import-header-content {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.import-title-section {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 0.5rem;
}

.import-header h4 {
	margin: 0;
	color: var(--color-primary);
	font-size: 1.1rem;
}

.import-header p {
	margin: 0 0 1rem 0;
	color: var(--color-text-maxcontrast);
}

.model-info-simple {
	margin: 1rem 0;
	padding: 0.75rem;
	background: var(--color-primary-light);
	border-radius: var(--border-radius);
	border-left: 4px solid var(--color-primary);
}

.model-info-simple h5 {
	margin: 0 0 0.5rem 0;
	font-size: 0.9rem;
	font-weight: 600;
	color: var(--color-primary-text);
}

.model-details {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.model-details span {
	font-size: 0.85rem;
	color: var(--color-primary-text);
}

.schema-progress-clean {
	margin-top: 1rem;
}

.schema-progress-clean h5 {
	margin: 0 0 0.75rem 0;
	font-size: 0.9rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.progress-table-clean {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.85rem;
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	overflow: hidden;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.progress-table-clean th {
	padding: 0.75rem 0.5rem;
	text-align: left;
	font-weight: 600;
	color: var(--color-main-text);
	background: var(--color-background-dark);
	border-bottom: 2px solid var(--color-border);
}

.progress-table-clean td {
	padding: 0.5rem;
	text-align: left;
	border-bottom: 1px solid var(--color-border-dark);
	color: var(--color-text-maxcontrast);
}

.progress-table-clean .schema-name {
	font-weight: 600;
	color: var(--color-main-text);
}

.progress-table-clean .created {
	color: var(--color-success);
	font-weight: 600;
}

.progress-table-clean .updated {
	color: var(--color-warning);
	font-weight: 600;
}

.progress-table-clean .skipped {
	color: var(--color-text-lighter);
	font-weight: 600;
}

.progress-cell {
	width: 80px;
}

.progress-bar-inline {
	position: relative;
	width: 60px;
	height: 18px;
	background: var(--color-background-dark);
	border-radius: 9px;
	overflow: hidden;
}

.progress-fill-inline {
	height: 100%;
	background: var(--color-primary);
	transition: width 0.3s ease;
}

.progress-text-inline {
	position: absolute;
	top: 50%;
	left: 50%;
	transform: translate(-50%, -50%);
	font-size: 0.7rem;
	font-weight: 600;
	color: var(--color-primary-text);
	text-shadow: 0 0 2px rgba(0, 0, 0, 0.7);
}

/* Final Results Display Styles */
.final-results-display {
	margin-top: 1.5rem;
	padding: 1rem;
	background: var(--color-success-light);
	border-radius: var(--border-radius);
	border-left: 4px solid var(--color-success);
}

.final-results-display h5 {
	margin: 0 0 1rem 0;
	font-size: 0.9rem;
	font-weight: 600;
	color: var(--color-success-text);
}

.results-summary {
	margin-bottom: 1rem;
}

.summary-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
	gap: 1rem;
}

.summary-item {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 0.75rem;
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	border: 2px solid transparent;
}

.summary-item.created {
	border-color: var(--color-success);
}

.summary-item.updated {
	border-color: var(--color-warning);
}

.summary-item.skipped {
	border-color: var(--color-text-lighter);
}

.summary-item.errors {
	border-color: var(--color-error);
}

.summary-number {
	font-size: 1.5rem;
	font-weight: 700;
	color: var(--color-main-text);
}

.summary-label {
	font-size: 0.8rem;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	margin-top: 0.25rem;
}

.performance-info {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.performance-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	font-size: 0.85rem;
}

.performance-row span {
	color: var(--color-success-text);
}
</style>
