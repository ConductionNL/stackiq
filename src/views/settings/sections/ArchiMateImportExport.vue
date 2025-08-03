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
		@refresh="store.fetchArchiMateStatus">
		<BTabs>
			<BTab title="Import" active>
				<!-- Import Section -->
				<div class="import-section">
					<h4>Import ArchiMate File</h4>
					<p>Upload an ArchiMate file (.archimate or .xml) to automatically create organizations and elements in OpenRegister</p>

					<!-- Import Status Display -->
					<div v-if="archimateStatus.import && archimateStatus.import.status === 'running'" class="status-display">
						<NcNoteCard type="info">
							<template #icon>
								<NcLoadingIcon :size="20" />
							</template>
							<div class="status-content">
								<p><strong>Import in Progress</strong></p>
								<p>Current step: <strong>{{ archimateStatus.import.current_step }}</strong></p>
								<div class="progress-bar">
									<div class="progress-fill" :style="{ width: archimateStatus.import.progress + '%' }" />
									<span class="progress-text">{{ archimateStatus.import.progress }}%</span>
								</div>
								<div v-if="archimateStatus.import.statistics" class="import-stats">
									<h5>Processing Statistics:</h5>
									<ul>
										<li>Elements: {{ archimateStatus.import.statistics.elements_processed }}</li>
										<li>Views: {{ archimateStatus.import.statistics.views_processed }}</li>
										<li>Relationships: {{ archimateStatus.import.statistics.relationships_processed }}</li>
										<li>Organizations: {{ archimateStatus.import.statistics.organizations_processed }}</li>
									</ul>
									<h5>Object Statistics:</h5>
									<ul>
										<li>Created: {{ archimateStatus.import.statistics.objects_created }}</li>
										<li>Updated: {{ archimateStatus.import.statistics.objects_updated }}</li>
									</ul>
								</div>
							</div>
						</NcNoteCard>
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
					<div class="file-upload-section">
						<input
							ref="fileInput"
							type="file"
							accept=".archimate,.xml"
							style="display: none"
							@change="handleFileSelect">

						<NcButton
							type="secondary"
							:disabled="importing"
							@click="$refs.fileInput.click()">
							<template #icon>
								<Upload :size="20" />
							</template>
							Select ArchiMate File
						</NcButton>

						<div v-if="selectedFile" class="selected-file">
							<p><strong>Selected file:</strong> {{ selectedFile.name }} ({{ formatFileSize(selectedFile.size) }})</p>
						</div>

						<NcButton
							v-if="selectedFile"
							type="primary"
							:disabled="importing || !selectedFile"
							@click="importArchiMateFile">
							<template #icon>
								<NcLoadingIcon v-if="importing" :size="20" />
								<CloudUpload v-else :size="20" />
							</template>
							{{ importing ? 'Starting Import...' : 'Import ArchiMate File' }}
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
					<div v-if="archimateStatus.export && archimateStatus.export.status === 'running'" class="status-display">
						<NcNoteCard type="info">
							<template #icon>
								<NcLoadingIcon :size="20" />
							</template>
							<div class="status-content">
								<p><strong>Export in Progress</strong></p>
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
							label="Export Format"
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

					<!-- Force Clear Button for Running Export -->
					<NcButton
						v-if="archimateStatus.export && archimateStatus.export.status === 'running'"
						type="error"
						@click="clearExportStatus">
						<template #icon>
							<Alert :size="20" />
						</template>
						Force Clear (if stuck)
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
			importResult: null,
			exportResult: null,
			roundTripResult: null,
			exportFormat: 'archimate',
		}
	},

	computed: {
		loading() { return this.store.loading },
		archimateStatus() { return this.store.archimateStatus || {} },
		selectedFile() { return this.store.selectedFile },
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
			await this.store.importArchiMateFile()
		},

		/**
		 * Export to ArchiMate
		 */
		async exportToArchiMate() {
			await this.store.exportToArchiMate(this.exportFormat)
		},

		/**
		 * Clear import status
		 */
		async clearImportStatus() {
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
</style>
