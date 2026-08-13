<template>
	<NcModal v-if="open" @close="closeBulkSyncDialog">
		<div class="bulk-sync-dialog">
			<div class="modal-header">
				<h2>Bulk Sync Module Standards</h2>
			</div>

			<div class="modal-content">
				<!-- Preview Section -->
				<div v-if="!syncCompleted" class="preview-section">
					<h3>What will happen:</h3>
					<ul class="preview-list">
						<li>Scan all {{ complianceCount }} compliance objects</li>
						<li>
							Extract standaardversie references from each compliance
							object
						</li>
						<li>
							Find the corresponding module for each compliance object
						</li>
						<li>
							Update module standaarden arrays with extracted
							standaardversie IDs
						</li>
						<li>Save modules only if changes are needed</li>
					</ul>

					<div v-if="bulkSyncLoading" class="loading-section">
						<NcLoadingIcon :size="24" />
						<p>Processing compliance objects...</p>
						<div class="progress-info">
							<p>
								Processed: {{ syncProgress.processed }} /
								{{ syncProgress.total }}
							</p>
							<p>Modules updated: {{ syncProgress.modulesUpdated }}</p>
						</div>
					</div>
				</div>

				<!-- Results Section -->
				<div v-if="syncCompleted" class="results-section">
					<h3>Sync Results:</h3>
					<div class="results-stats">
						<div class="stat-item">
							<span class="stat-label"
								>Compliance objects processed:</span
							>
							<span class="stat-value">{{
								syncResults.totalProcessed
							}}</span>
						</div>
						<div class="stat-item">
							<span class="stat-label">Modules found:</span>
							<span class="stat-value">{{
								syncResults.modulesFound
							}}</span>
						</div>
						<div class="stat-item">
							<span class="stat-label">Modules updated:</span>
							<span class="stat-value success-value">{{
								syncResults.modulesUpdated
							}}</span>
						</div>
						<div class="stat-item">
							<span class="stat-label"
								>Modules already up-to-date:</span
							>
							<span class="stat-value">{{
								syncResults.modulesAlreadyUpToDate || 0
							}}</span>
						</div>
						<div class="stat-item">
							<span class="stat-label"
								>Modules with no standards:</span
							>
							<span class="stat-value warning-value">{{
								syncResults.modulesWithNoStandards || 0
							}}</span>
						</div>
						<div class="stat-item">
							<span class="stat-label">Standards added:</span>
							<span class="stat-value">{{
								syncResults.standardsAdded
							}}</span>
						</div>
					</div>

					<!-- Modules Table -->
					<div
						v-if="syncResults.modules && syncResults.modules.length > 0"
						class="modules-table-section">
						<h4>
							Processed Modules ({{ syncResults.modules.length }}):
						</h4>
						<div class="modules-table-container">
							<table class="modules-table">
								<thead>
									<tr>
										<th scope="col">Module Name</th>
										<th scope="col">Status</th>
										<th scope="col">Reason</th>
										<th scope="col">Compliance Count</th>
										<th scope="col">Standards Count</th>
									</tr>
								</thead>
								<tbody>
									<tr
										v-for="module in syncResults.modules"
										:key="module.uuid"
										:class="'status-' + module.status">
										<td class="module-name">
											{{ module.name }}
											<span class="module-uuid">{{
												module.uuid
											}}</span>
										</td>
										<td class="module-status">
											<span
												class="status-badge"
												:class="'badge-' + module.status">
												{{ module.status }}
											</span>
										</td>
										<td class="module-reason">
											{{ module.reason }}
										</td>
										<td class="module-count">
											{{ module.complianceCount }}
										</td>
										<td class="module-count">
											{{ module.standardsCount }}
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>

					<div v-if="syncResults.errors.length > 0" class="errors-section">
						<h4>Errors:</h4>
						<ul class="error-list">
							<li
								v-for="errorMsg in syncResults.errors"
								:key="errorMsg">
								{{ errorMsg }}
							</li>
						</ul>
					</div>
				</div>
			</div>

			<div class="modal-actions">
				<NcButton
					v-if="!syncCompleted"
					:disabled="bulkSyncLoading"
					variant="primary"
					@click="startBulkSync">
					{{ bulkSyncLoading ? 'Syncing...' : 'Start Sync' }}
				</NcButton>
				<NcButton @click="closeBulkSyncDialog">
					{{ syncCompleted ? 'Close' : 'Cancel' }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { defineComponent } from 'vue'
import { NcButton, NcLoadingIcon, NcModal } from '@nextcloud/vue'

/**
 * Bulk Sync Module Standards dialog
 * Extracted from StatisticsOverview to satisfy modal-isolation (ADR-004).
 *
 * @author   Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @version  1.0.0
 */
export default defineComponent({
	name: 'BulkSyncDialog',

	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
	},

	props: {
		/**
		 * Whether the dialog is open.
		 */
		open: {
			type: Boolean,
			default: false,
		},

		/**
		 * Number of compliance objects to be processed.
		 */
		complianceCount: {
			type: Number,
			default: 0,
		},
	},

	emits: ['update:open', 'synced', 'loading-change'],

	data() {
		return {
			bulkSyncLoading: false,
			syncCompleted: false,

			// Sync progress tracking
			syncProgress: {
				processed: 0,
				total: 0,
				modulesUpdated: 0,
			},

			// Sync results
			syncResults: {
				totalProcessed: 0,
				modulesFound: 0,
				modulesUpdated: 0,
				standardsAdded: 0,
				errors: [],
			},
		}
	},

	watch: {
		/**
		 * Watch the open prop to reset sync state when dialog opens
		 *
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		open(value) {
			if (value === true) {
				this.syncCompleted = false
				this.resetSyncState()
			}
		},
	},

	methods: {
		/**
		 * Close the bulk sync dialog
		 *
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		closeBulkSyncDialog() {
			this.syncCompleted = false
			this.bulkSyncLoading = false
			this.$emit('loading-change', false)
			this.resetSyncState()
			this.$emit('update:open', false)
		},

		/**
		 * Reset sync state
		 *
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		resetSyncState() {
			this.syncProgress = {
				processed: 0,
				total: 0,
				modulesUpdated: 0,
			}
			this.syncResults = {
				totalProcessed: 0,
				modulesFound: 0,
				modulesUpdated: 0,
				standardsAdded: 0,
				errors: [],
			}
		},

		/**
		 * Start the bulk sync process
		 *
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		async startBulkSync() {
			this.bulkSyncLoading = true
			this.$emit('loading-change', true)
			this.syncProgress.total = this.complianceCount

			try {
				// Call the backend API to perform bulk sync
				const response = await this.performBulkSync()

				// Update results
				this.syncResults = response.data
				this.syncCompleted = true

				// Notify parent so it can refresh statistics
				this.$emit('synced')
			} catch (error) {
				console.error('Bulk sync failed:', error)
				this.syncResults.errors.push(`Sync failed: ${error.message}`)
				this.syncCompleted = true
			} finally {
				this.bulkSyncLoading = false
				this.$emit('loading-change', false)
			}
		},

		/**
		 * Perform the bulk sync API call
		 *
		 * @return {Promise} API response
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		async performBulkSync() {
			const response = await fetch(
				'/index.php/apps/softwarecatalog/api/bulk-sync-standards',
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-Requested-With': 'XMLHttpRequest',
					},
					body: JSON.stringify({}),
				},
			)

			if (!response.ok) {
				throw new Error(`HTTP error! status: ${response.status}`)
			}

			return await response.json()
		},
	},
})
</script>

<style scoped>
/* Bulk Sync Dialog Styles */
.bulk-sync-dialog {
	width: 900px;
	max-width: 95vw;
}

.modal-header {
	padding: 20px 20px 0 20px;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 20px;
}

.modal-header h2 {
	margin: 0 0 20px 0;
	color: var(--color-text);
}

.modal-content {
	padding: 0 20px;
	max-height: 60vh;
	overflow-y: auto;
}

.preview-section h3,
.results-section h3 {
	margin-top: 0;
	color: var(--color-text);
}

.preview-list {
	margin: 12px 0;
	padding-left: 20px;
}

.preview-list li {
	margin-bottom: 8px;
	color: var(--color-text-lighter);
}

.loading-section {
	text-align: center;
	padding: 20px 0;
}

.loading-section p {
	margin: 12px 0;
	color: var(--color-text-lighter);
}

.progress-info {
	margin-top: 16px;
	padding: 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.progress-info p {
	margin: 4px 0;
	font-family: monospace;
	font-size: 0.9em;
}

.results-stats {
	margin: 16px 0;
}

.stat-item {
	display: flex;
	justify-content: space-between;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border-dark);
}

.stat-item:last-child {
	border-bottom: none;
}

.stat-label {
	color: var(--color-text-lighter);
}

.stat-value {
	font-weight: 600;
	color: var(--color-text);
}

.stat-value.success-value {
	color: var(--color-success);
}

.stat-value.warning-value {
	color: var(--color-warning);
}

.errors-section {
	margin-top: 20px;
	padding: 16px;
	background-color: var(--color-error-light);
	border-radius: var(--border-radius);
}

.errors-section h4 {
	margin: 0 0 12px 0;
	color: var(--color-error);
}

.error-list {
	margin: 0;
	padding-left: 20px;
}

.error-list li {
	color: var(--color-error);
	margin-bottom: 4px;
}

.modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: 12px;
	padding: 20px;
	border-top: 1px solid var(--color-border);
	margin-top: 20px;
}

/* Modules Table Styles */
.modules-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;
}

.modules-table thead {
	position: sticky;
	top: 0;
	background-color: var(--color-background-hover);
	z-index: 1;
}

.modules-table th {
	padding: 10px 12px;
	text-align: left;
	font-weight: 600;
	border-bottom: 2px solid var(--color-border);
	white-space: nowrap;
}

.modules-table td {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border-dark);
}

.modules-table-section {
	margin-top: 24px;
}

.modules-table-section h4 {
	margin: 0 0 12px 0;
	color: var(--color-text);
}

.modules-table-container {
	max-height: 400px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.modules-table tbody tr:last-child td {
	border-bottom: none;
}

.modules-table tbody tr:hover {
	background-color: var(--color-background-hover);
}

/* Row coloring by status */
.modules-table tbody tr.status-updated {
	background-color: rgba(70, 180, 70, 0.05);
}

.modules-table tbody tr.status-skipped {
	background-color: rgba(255, 180, 0, 0.05);
}

.modules-table tbody tr.status-error {
	background-color: rgba(255, 50, 50, 0.05);
}

/* Module name cell */
.module-name {
	max-width: 200px;
	font-weight: 500;
}

.module-uuid {
	display: block;
	font-size: 0.85em;
	color: var(--color-text-lighter);
	font-family: monospace;
	margin-top: 4px;
}

/* Status badges */
.module-status {
	text-align: center;
}

.modules-table .status-badge {
	display: inline-block;
	padding: 3px 8px;
	border-radius: 12px;
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.status-badge {
	display: inline-block;
	padding: 4px 8px;
	border-radius: 12px;
	font-size: 0.8em;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.badge-updated {
	background-color: var(--color-success);
	color: white;
}

.badge-up-to-date {
	background-color: var(--color-primary-element);
	color: white;
}

.badge-skipped {
	background-color: var(--color-warning);
	color: white;
}

.badge-error {
	background-color: var(--color-error);
	color: white;
}

/* Module reason and counts */
.module-reason {
	color: var(--color-text-lighter);
	max-width: 250px;
}

.module-count {
	text-align: center;
	font-family: monospace;
	font-weight: 600;
}
</style>
