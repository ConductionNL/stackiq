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
	<CollapsibleSection
		name="ArchiMate Import/Export"
		description="Import ArchiMate files to create OpenRegister objects and export existing data to ArchiMate format"
		:loading="loading"
		:show-refresh-button="true"
		:refreshing="store.statusPollingInterval !== null"
		refresh-button-text="Refresh Status"
		:has-info-content="true"
		@refresh="store.refreshArchiMateStatus">
		<BTabs>
			<BTab title="Import" active>
				<!-- Import Section -->
				<div class="import-section">
					<h4>Import ArchiMate File</h4>
					<p>Upload an ArchiMate file (.archimate or .xml) to automatically create organizations and elements in OpenRegister</p>

					<!-- Import Status Display -->
					<div v-if="archimateStatus.import && (archimateStatus.import.status === 'running' || archimateStatus.import.status === 'completed')" class="status-display">
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

							<div v-if="archimateStatus.import.model_info && archimateStatus.import.model_info.identifier" class="model-info-simple">
								<h5>Model Information</h5>
								<div class="model-details">
									<span><strong>Identifier:</strong> {{ archimateStatus.import.model_info.identifier }}</span>
									<span v-if="archimateStatus.import.model_info.name"><strong>Name:</strong> {{ archimateStatus.import.model_info.name }}</span>
									<span v-if="archimateStatus.import.model_info.action"><strong>Action:</strong> {{ archimateStatus.import.model_info.action }}</span>
								</div>
							</div>
							
							<!-- Clean Schema Progress Table -->
							<div v-if="archimateStatus.import.schema_progress" class="schema-progress-clean">
								<h5>Schema Progress</h5>
								<table class="progress-table-clean">
									<thead>
										<tr>
											<th>Schema</th>
											<th>Found</th>
											<th>Created</th>
											<th>Updated</th>
											<th>Skipped</th>
											<th>Processed</th>
											<th>Progress</th>
										</tr>
									</thead>
									<tbody>
										<tr v-for="(progress, schema) in archimateStatus.import.schema_progress" :key="schema">
											<td class="schema-name">{{ schema.charAt(0).toUpperCase() + schema.slice(1) }}</td>
											<td>{{ progress.found }}</td>
											<td class="created">{{ progress.created }}</td>
											<td class="updated">{{ progress.updated }}</td>
											<td class="skipped">{{ progress.skipped }}</td>
											<td>{{ progress.created + progress.updated + progress.skipped }}</td>
											<td class="progress-cell">
												<div class="progress-bar-inline">
													<div class="progress-fill-inline" :style="{ width: calculateProgress(progress) + '%' }" />
													<span class="progress-text-inline">{{ calculateProgress(progress) }}%</span>
												</div>
											</td>
										</tr>
									</tbody>
								</table>
							</div>
							
							<!-- Final Results Display (when completed) -->
							<div v-if="archimateStatus.import.status === 'completed' && archimateStatus.import.final_results" class="final-results-display">
								<h5>Import Results</h5>
								
								<!-- Summary Statistics -->
								<div class="results-summary">
									<div class="summary-grid">
										<div class="summary-item created">
											<span class="summary-number">{{ archimateStatus.import.final_results.summary.total_objects_created }}</span>
											<span class="summary-label">Created</span>
										</div>
										<div class="summary-item updated">
											<span class="summary-number">{{ archimateStatus.import.final_results.summary.total_objects_updated }}</span>
											<span class="summary-label">Updated</span>
										</div>
										<div class="summary-item skipped">
											<span class="summary-number">{{ archimateStatus.import.final_results.summary.total_objects_skipped }}</span>
											<span class="summary-label">Skipped</span>
										</div>
										<div class="summary-item errors" v-if="archimateStatus.import.final_results.summary.total_errors > 0">
											<span class="summary-number">{{ archimateStatus.import.final_results.summary.total_errors }}</span>
											<span class="summary-label">Errors</span>
										</div>
									</div>
								</div>
								
								<!-- Performance Info -->
								<div class="performance-info">
									<div class="performance-row">
										<span><strong>Processing Time:</strong> {{ archimateStatus.import.final_results.processing_times.total_time_seconds }}s</span>
										<span><strong>Processing Speed:</strong> {{ archimateStatus.import.final_results.performance_metrics.items_per_second.toFixed(2) }} items/sec</span>
									</div>
									<div class="performance-row">
										<span><strong>File:</strong> {{ archimateStatus.import.final_results.file_info.name }}</span>
										<span><strong>Size:</strong> {{ (archimateStatus.import.final_results.file_info.size / 1024 / 1024).toFixed(2) }} MB</span>
									</div>
								</div>
							</div>
						</div>
					</div>

					<!-- Import Error Display -->
					<div v-else-if="archimateStatus.import && archimateStatus.import.status === 'failed'" class="status-display">
						<NcNoteCard type="error">
							<template #icon>
								<Alert :size="20" />
							</template>
							<p><strong>Import Failed</strong></p>
							<p><strong>Error:</strong> {{ archimateStatus.import.error }}</p>
						</NcNoteCard>
					</div>

					<!-- File Upload Section -->
					<div v-if="!isImportRunning" class="file-upload-section">
						<input
							ref="fileInput"
							type="file"
							accept=".archimate,.xml"
							style="display: none"
							:disabled="importing || isImportRunning"
							@change="handleFileSelect">

						<NcButton
							type="secondary"
							:disabled="importing || isImportRunning"
							@click="$refs.fileInput.click()">
							<template #icon>
								<Upload :size="20" />
							</template>
							Select ArchiMate File
						</NcButton>

						<div v-if="selectedFile" class="selected-file">
							<p><strong>Selected file:</strong> {{ selectedFile.name }} ({{ formatFileSize(selectedFile.size) }})</p>
						</div>

						<!-- Performance Mode Selection -->
						<div v-if="selectedFile" class="performance-mode-section">
							<h5>Processing Mode</h5>
							<p class="mode-description">Choose how to process your import:</p>
							
							<div class="mode-options">
								<div class="mode-option">
									<input
										id="mode-speed"
										type="radio"
										v-model="processingMode"
										value="speed"
										:disabled="importing || isImportRunning">
									<label for="mode-speed" class="mode-label">
										<div class="mode-header">
											<strong>🚀 High Performance</strong>
											<span class="mode-badge speed">Recommended</span>
										</div>
										<div class="mode-details">
											<p>Optimized for speed with parallel processing</p>
											<ul>
												<li>Faster import times</li>
												<li>Uses more memory (up to 2GB)</li>
												<li>Best for large files</li>
											</ul>
										</div>
									</label>
								</div>

								<div class="mode-option">
									<input
										id="mode-memory"
										type="radio"
										v-model="processingMode"
										value="memory"
										:disabled="importing || isImportRunning">
									<label for="mode-memory" class="mode-label">
										<div class="mode-header">
											<strong>💾 Memory Efficient</strong>
										</div>
										<div class="mode-details">
											<p>Optimized for memory usage with streaming</p>
											<ul>
												<li>Lower memory usage</li>
												<li>Slower import times</li>
												<li>Best for limited memory</li>
											</ul>
										</div>
									</label>
								</div>
							</div>
						</div>

						<NcButton
							v-if="selectedFile"
							type="primary"
							:disabled="importing || isImportRunning || !selectedFile"
							@click="importArchiMateFile">
							<template #icon>
								<NcLoadingIcon v-if="importing || isImportRunning" :size="20" />
								<CloudUpload v-else :size="20" />
							</template>
							{{ importing || isImportRunning ? 'Import in Progress...' : 'Import ArchiMate File' }}
						</NcButton>

						<!-- Force Clear Button for Running Import -->
						<NcButton
							v-if="archimateStatus.import && archimateStatus.import.status === 'running'"
							type="error"
							@click="clearImportStatus">
							<template #icon>
								<Alert :size="20" />
							</template>
							Force Clear (if stuck)
						</NcButton>
					</div>

					<!-- Import Results -->
					<div v-if="importResult" class="import-results">
						<NcNoteCard :type="importResult.success ? 'success' : 'error'">
							<template #icon>
								<CheckCircle v-if="importResult.success" :size="20" />
								<Alert v-else :size="20" />
							</template>
							<div class="result-content">
								<p><strong>{{ importResult.message }}</strong></p>

								<div v-if="importResult.success && importResult.file_info" class="file-info">
									<h5>File Information:</h5>
									<ul>
										<li><strong>File Name:</strong> {{ importResult.file_info.name }}</li>
										<li><strong>File Size:</strong> {{ (importResult.file_info.size / 1024 / 1024).toFixed(2) }} MB</li>
										<li><strong>File Type:</strong> {{ importResult.file_info.mime_type }}</li>
									</ul>
								</div>

								<div v-if="importResult.performance_metrics" class="performance-metrics">
									<h5>Performance Metrics:</h5>
									<ul>
										<li><strong>Processing Method:</strong> {{ importResult.performance_metrics.processing_method }}</li>
										<li><strong>Batch Size:</strong> {{ importResult.performance_metrics.batch_size_used }}</li>
										<li>
											<strong>Items/Second:</strong> {{ importResult.performance_metrics.items_per_second.toFixed(2) }}
										</li>
									</ul>
								</div>

								<div v-if="importResult.processing_times" class="processing-times">
									<h5>Processing Times:</h5>
									<ul>
										<li><strong>Total Time:</strong> {{ importResult.processing_times.total_time_seconds.toFixed(2) }}s</li>
										<li><strong>Validation:</strong> {{ importResult.processing_times.validation_time_seconds.toFixed(3) }}s</li>
										<li><strong>Parsing:</strong> {{ importResult.processing_times.parse_time_seconds.toFixed(3) }}s</li>
										<li><strong>Conversion:</strong> {{ importResult.processing_times.convert_time_seconds.toFixed(2) }}s</li>
									</ul>
								</div>

								<div v-if="importResult.summary" class="summary-stats">
									<h5>Summary Statistics:</h5>
									<ul>
										<li><strong>Objects Created:</strong> {{ importResult.summary.total_objects_created || 0 }}</li>
										<li><strong>Objects Updated:</strong> {{ importResult.summary.total_objects_updated || 0 }}</li>
										<li v-if="importResult.summary.total_objects_deleted">
											<strong>Objects Deleted:</strong> {{ importResult.summary.total_objects_deleted }}
										</li>
										<li v-if="importResult.summary.total_errors">
											<strong>Total Errors:</strong> {{ importResult.summary.total_errors }}
										</li>
									</ul>
								</div>

								<!-- Schema Statistics -->
								<div v-if="importResult.schema_statistics" class="schema-statistics">
									<h6>Schema Breakdown:</h6>
									<div class="schema-grid">
										<div v-for="(stats, schema) in importResult.schema_statistics" :key="schema" class="schema-item">
											<h6>{{ schema.charAt(0).toUpperCase() + schema.slice(1) }}</h6>
											<ul>
												<li>
													🔍 Found: {{ stats.found }}
												</li>
												<li>
													✅ Created: {{ stats.created }}
												</li>
												<li>
													🔄 Updated: {{ stats.updated }}
												</li>
												<li>
													⏭️ Skipped: {{ stats.skipped }}
												</li>
												<li>
													🗑️ Deleted: {{ stats.deleted }}
												</li>
												<li>
													❌ Errors: {{ stats.errors.length }}
												</li>
												<li>
													⏱️ Time: {{ stats.processing_time.toFixed(3) }}s
												</li>
											</ul>
										</div>
									</div>
								</div>
							</div>
						</NcNoteCard>
					</div>
				</div>
			</BTab>

			<BTab title="Export">
				<!-- Export Section -->
				<div class="export-section">
					<h4>Export to ArchiMate</h4>
					<p>Export existing OpenRegister data to ArchiMate format for use in architecture tools</p>

					<!-- Export Status Display -->
					<div v-if="archimateStatus.export && (archimateStatus.export.status === 'running' || archimateStatus.export.status === 'completed')" class="status-display">
						<NcNoteCard type="info">
							<template #icon>
								<!-- Removed loading spinner since progress bar provides visual feedback -->
							</template>
							<div class="status-content">
								<p><strong>{{ archimateStatus.export.status === 'completed' ? 'Export Completed' : 'Export in Progress' }}</strong></p>
								<p>Current step: <strong>{{ archimateStatus.export.current_step }}</strong></p>
								<div class="progress-bar">
									<div class="progress-fill" :style="{ width: archimateStatus.export.progress + '%' }" />
									<span class="progress-text">{{ archimateStatus.export.progress }}%</span>
								</div>
								<div v-if="archimateStatus.export.statistics" class="export-stats">
									<h5>Export Statistics:</h5>
									<ul>
										<li>Objects Found: {{ archimateStatus.export.statistics.objects_found }}</li>
										<li>Objects Exported: {{ archimateStatus.export.statistics.objects_exported }}</li>
										<li>XML Size: {{ (archimateStatus.export.statistics.xml_size_bytes / 1024).toFixed(2) }} KB</li>
									</ul>
								</div>
								
								<!-- Final Export Results (when completed) -->
								<div v-if="archimateStatus.export.status === 'completed' && archimateStatus.export.final_results" class="final-results-display">
									<h5>Export Results</h5>
									<div class="results-summary">
										<div class="summary-grid">
											<div class="summary-item created">
												<span class="summary-number">{{ archimateStatus.export.final_results.summary.objects_exported }}</span>
												<span class="summary-label">Exported</span>
											</div>
											<div class="summary-item updated">
												<span class="summary-number">{{ archimateStatus.export.final_results.summary.xml_size_mb }}</span>
												<span class="summary-label">MB</span>
											</div>
										</div>
									</div>
									<div class="performance-info">
										<div class="performance-row">
											<span><strong>Processing Time:</strong> {{ archimateStatus.export.final_results.performance_metrics.total_time_seconds }}s</span>
											<span><strong>Speed:</strong> {{ archimateStatus.export.final_results.performance_metrics.objects_per_second }} objects/sec</span>
										</div>
										<div class="performance-row">
											<span><strong>File:</strong> {{ archimateStatus.export.final_results.file_info.name }}</span>
											<span><strong>Size:</strong> {{ (archimateStatus.export.final_results.file_info.size_bytes / 1024).toFixed(2) }} KB</span>
										</div>
									</div>
								</div>
							</div>
						</NcNoteCard>
					</div>

					<!-- Export Error Display -->
					<div v-else-if="archimateStatus.export && archimateStatus.export.status === 'failed'" class="status-display">
						<NcNoteCard type="error">
							<template #icon>
								<Alert :size="20" />
							</template>
							<p><strong>Export Failed</strong></p>
							<p><strong>Error:</strong> {{ archimateStatus.export.error }}</p>
						</NcNoteCard>
					</div>

					<!-- Export Configuration -->
					<div class="export-config">
						<NcSelect
							v-model="exportFormat"
							:options="[
								{ label: 'ArchiMate (.archimate)', value: 'archimate' },
								{ label: 'XML', value: 'xml' },
								{ label: 'JSON', value: 'json' }
							]"
							input-label="Export Format"
							placeholder="Select export format" />
					</div>

					<NcButton
						type="primary"
						:disabled="exporting || !exportFormat"
						@click="exportToArchiMate">
						<template #icon>
							<NcLoadingIcon v-if="exporting" :size="20" />
							<Download v-else :size="20" />
						</template>
						{{ exporting ? 'Starting Export...' : 'Export to ArchiMate' }}
					</NcButton>

					<!-- Clear Button for Export -->
					<NcButton
						v-if="archimateStatus.export && archimateStatus.export.status === 'running'"
						type="error"
						@click="clearExportStatus">
						<template #icon>
							<Alert :size="20" />
						</template>
						Force Clear (if stuck)
					</NcButton>
					<NcButton
						v-else-if="archimateStatus.export && archimateStatus.export.status === 'completed'"
						type="secondary"
						@click="clearExportStatus">
						<template #icon>
							<CheckCircle :size="20" />
						</template>
						Clear Results
					</NcButton>

					<!-- Export Results -->
					<div v-if="exportResult" class="export-results">
						<NcNoteCard :type="exportResult.success ? 'success' : 'error'">
							<template #icon>
								<CheckCircle v-if="exportResult.success" :size="20" />
								<Alert v-else :size="20" />
							</template>
							<p><strong>{{ exportResult.message }}</strong></p>
							<p v-if="exportResult.success && exportResult.file_name"><strong>File:</strong> {{ exportResult.file_name }}</p>
							<p v-if="exportResult.success && exportResult.statistics"><strong>Objects exported:</strong> {{ exportResult.statistics?.objects_exported || 0 }}</p>
						</NcNoteCard>
					</div>
				</div>
			</BTab>

			<BTab title="Testing">
				<!-- Test Section -->
				<div class="test-section">
					<h4>Round-Trip Test</h4>
					<p>Test data integrity by exporting and re-importing data to compare results</p>

					<NcButton
						type="secondary"
						:disabled="testingRoundTrip"
						@click="testRoundTrip">
						<template #icon>
							<NcLoadingIcon v-if="testingRoundTrip" :size="20" />
							<Sync v-else :size="20" />
						</template>
						{{ testingRoundTrip ? 'Testing...' : 'Test Round-Trip' }}
					</NcButton>

					<div v-if="roundTripResult" class="round-trip-results">
						<NcNoteCard :type="roundTripResult.success ? 'success' : 'error'">
							<p><strong>{{ roundTripResult.message }}</strong></p>
							<div v-if="roundTripResult.comparison" class="comparison-results">
								<h5>Comparison Results:</h5>
								<ul>
									<li>Elements matched: {{ roundTripResult.comparison.elements_matched }}</li>
									<li>Organizations matched: {{ roundTripResult.comparison.organizations_matched }}</li>
									<li>Differences found: {{ roundTripResult.comparison.differences }}</li>
								</ul>
							</div>
						</NcNoteCard>
					</div>
				</div>
			</BTab>
		</BTabs>

		<!-- Info Content Slot -->
		<template #info-content>
			<div class="archimate-info">
				<h3>About ArchiMate Import/Export</h3>
				<p>ArchiMate is an enterprise architecture modeling language that provides a uniform representation for architecture descriptions, analysis, and visualization.</p>

				<h4>Import Functionality</h4>
				<p>Import ArchiMate files (.archimate or .xml) to automatically create organizations and elements in OpenRegister:</p>
				<ul>
					<li><strong>Organizations</strong> - Business entities from your ArchiMate model</li>
					<li><strong>Elements</strong> - Applications, services, and other architecture components</li>
					<li><strong>Relationships</strong> - Connections between elements</li>
				</ul>

				<h4>Export Functionality</h4>
				<p>Export existing OpenRegister data to ArchiMate format for use in architecture tools:</p>
				<ul>
					<li>Generate .archimate files compatible with Archi and other tools</li>
					<li>Include all organizations, elements, and relationships</li>
					<li>Maintain data integrity and relationships</li>
				</ul>

				<h4>Status Monitoring</h4>
				<p>Track the progress of import/export operations:</p>
				<ul>
					<li><strong>Running</strong> - Operation is currently in progress</li>
					<li><strong>Completed</strong> - Operation finished successfully</li>
					<li><strong>Failed</strong> - Operation encountered an error</li>
					<li><strong>Cancelled</strong> - Operation was manually stopped</li>
				</ul>

				<h4>Testing</h4>
				<p>The round-trip test validates data integrity by:</p>
				<ul>
					<li>Exporting current data to ArchiMate format</li>
					<li>Re-importing the exported data</li>
					<li>Comparing original vs. imported data</li>
					<li>Reporting any differences or data loss</li>
				</ul>

				<h4>Best Practices</h4>
				<ul>
					<li>Test imports with small files first</li>
					<li>Backup your data before large imports</li>
					<li>Use the round-trip test to validate data integrity</li>
					<li>Monitor import/export status for large operations</li>
				</ul>
			</div>
		</template>
	</CollapsibleSection>
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
import CollapsibleSection from '../../../components/CollapsibleSection.vue'

// Nextcloud Vue components
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

// Icons
import Upload from 'vue-material-design-icons/Upload.vue'
import Download from 'vue-material-design-icons/Download.vue'
import CloudUpload from 'vue-material-design-icons/CloudUpload.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Alert from 'vue-material-design-icons/Alert.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import StopIcon from 'vue-material-design-icons/Stop.vue'

// Bootstrap Vue components for tabs
import { BTabs, BTab } from 'bootstrap-vue'

export default {
	name: 'ArchiMateImportExport',

	components: {
		CollapsibleSection,
		NcButton,
		NcSelect,
		NcNoteCard,
		NcLoadingIcon,
		Upload,
		Download,
		CloudUpload,
		CheckCircle,
		Alert,
		Sync,
		StopIcon,
		BTabs,
		BTab,
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
						'requesttoken': OC.requestToken
					}
				})

				const result = await response.json()
				
				if (result.success) {
					// Show success notification
					OC.Notification.showTemporary(
						`Import cancelled successfully${result.details.process_killed ? ' (process terminated)' : ''}`,
						{ type: 'success' }
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
					{ type: 'error' }
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
		 * @param {Object} progress Progress object with found, created, updated, skipped counts
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
