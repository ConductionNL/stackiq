<template>
	<NcSettingsSection
		name="Object Statistics"
		description="Overview of objects stored in configured registers">
		<!-- Refresh Button in Title Section -->
		<template #title>
			<div class="section-title-with-button">
				<span>Object Statistics</span>
				<NcButton
					:disabled="loadingStats"
					class="title-refresh-button"
					@click="refreshStatistics">
					<template #icon>
						<RefreshIcon :size="16" />
					</template>
					{{ loadingStats ? 'Loading...' : 'Refresh' }}
				</NcButton>
			</div>
		</template>

		<div class="statistics-section">
			<!-- Last Updated Info -->
			<div class="statistics-header">
				<span v-if="statistics.timestamp" class="last-updated">
					Last updated: {{ formatTimestamp(statistics.timestamp) }}
				</span>
			</div>

			<!-- Statistics Table -->
			<div v-if="formattedStatistics.length > 0" class="statistics-table">
				<table>
					<thead>
						<tr>
							<th scope="col">Register</th>
							<th scope="col">Object Type</th>
							<th scope="col">Count</th>
							<th scope="col">Status</th>
							<th scope="col">Actions</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="stat in formattedStatistics" :key="`${stat.register}-${stat.type}`">
							<td class="register-cell">
								{{ stat.register }}
							</td>
							<td class="type-cell">
								{{ stat.type }}
							</td>
							<td class="count-cell" :class="{ 'high-count': stat.count > 10000 }">
								{{ formatNumber(stat.count) }}
							</td>
							<td class="status-cell">
								<span
									class="status-badge"
									:class="stat.configured ? 'status-configured' : 'status-not-configured'">
									{{ stat.configured ? 'Configured' : 'Not Configured' }}
								</span>
							</td>
							<td class="actions-cell">
								<!-- Show sync button only for Compliancy objects -->
								<NcButton
									v-if="stat.type === 'Compliancy' && stat.configured"
									:disabled="bulkSyncLoading"
									class="sync-button"
									@click="showBulkSyncDialog">
									<template #icon>
										<SyncIcon :size="16" />
									</template>
									{{ bulkSyncLoading ? 'Syncing...' : 'Sync Standards' }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- No Data Message -->
			<NcEmptyContent
				v-else-if="!loadingStats"
				name="No Statistics Available"
				description="No configured registers found or statistics could not be loaded.">
				<template #icon>
					<ChartLineIcon />
				</template>
			</NcEmptyContent>

			<!-- Loading State -->
			<div v-if="loadingStats" class="loading-container">
				<NcLoadingIcon :size="32" />
				<p>Loading statistics...</p>
			</div>

			<!-- Error State -->
			<NcNoteCard v-if="error && !loadingStats" type="error">
				<p>{{ error }}</p>
			</NcNoteCard>
		</div>

		<!-- Bulk Sync Dialog -->
		<BulkSyncDialog
			:open="showSyncDialog"
			:compliance-count="complianceCount"
			@update:open="showSyncDialog = $event"
			@loading-change="bulkSyncLoading = $event"
			@synced="refreshStatistics" />
	</NcSettingsSection>
</template>

<script>
import { defineComponent } from 'vue'
import {
	NcSettingsSection,
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import { useSettingsStore } from '../../../store/modules/settings.js'
import BulkSyncDialog from '../../../modals/BulkSyncDialog.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import ChartLineIcon from 'vue-material-design-icons/ChartLine.vue'
import SyncIcon from 'vue-material-design-icons/Sync.vue'

/**
 * Statistics Overview component
 * Displays object counts for all configured registers
 *
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2
 * @version  1.0.0
 */
export default defineComponent({
	name: 'StatisticsOverview',

	components: {
		NcSettingsSection,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		BulkSyncDialog,
		RefreshIcon,
		ChartLineIcon,
		SyncIcon,
	},

	/**
	 * @spec openspec/specs/fe-settings-ui/spec.md
	 */
	setup() {
		const settingsStore = useSettingsStore()

		return {
			settingsStore,
		}
	},

	data() {
		return {
			// Bulk sync dialog state
			showSyncDialog: false,
			bulkSyncLoading: false,
		}
	},

	computed: {
		/**
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		statistics() {
			return this.settingsStore.statistics
		},

		/**
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		formattedStatistics() {
			return this.settingsStore.formattedStatistics
		},

		/**
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		loadingStats() {
			return this.settingsStore.loadingStatistics
		},

		/**
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		error() {
			return this.settingsStore.error
		},

		// Get compliance count for the dialog
		/**
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		complianceCount() {
			const complianceStat = this.formattedStatistics.find(stat => stat.type === 'Compliancy')
			return complianceStat ? complianceStat.count : 0
		},
	},

	/**
	 * @spec openspec/specs/fe-settings-ui/spec.md
	 */
	async mounted() {
		// Load statistics when component mounts
		await this.refreshStatistics()
	},

	methods: {
		/**
		 * Refresh statistics data
		  * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		async refreshStatistics() {
			await this.settingsStore.loadStatistics()
		},

		/**
		 * Format number with thousand separators
		 * @param {number} num - Number to format
		 * @return {string} Formatted number
		  * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		formatNumber(num) {
			if (num === 0) return '0'
			return num.toLocaleString()
		},

		/**
		 * Format timestamp for display
		 * @param {number} timestamp - Unix timestamp
		 * @return {string} Formatted date/time
		  * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		formatTimestamp(timestamp) {
			if (!timestamp) return 'Unknown'
			const date = new Date(timestamp * 1000)
			return date.toLocaleString()
		},

		/**
		 * Show the bulk sync dialog
		  * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		showBulkSyncDialog() {
			this.showSyncDialog = true
		},
	},
})
</script>

<style scoped>
.statistics-section {
	margin-top: 16px;
}

.section-title-with-button {
	display: flex;
	align-items: center;
	gap: 12px;
}

.title-refresh-button {
	margin-left: auto;
}

.statistics-header {
	display: flex;
	align-items: center;
	justify-content: flex-end;
	margin-bottom: 16px;
}

.last-updated {
	color: var(--color-text-lighter);
	font-size: 0.9em;
}

.statistics-table {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
}

.statistics-table table {
	width: 100%;
	border-collapse: collapse;
}

.statistics-table th {
	background-color: var(--color-background-hover);
	padding: 12px 16px;
	text-align: left;
	font-weight: 600;
	border-bottom: 1px solid var(--color-border);
}

.statistics-table td {
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border-dark);
}

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

.statistics-table tbody tr:last-child td {
	border-bottom: none;
}

.statistics-table tbody tr:hover {
	background-color: var(--color-background-hover);
}

.register-cell {
	font-weight: 500;
}

.type-cell {
	color: var(--color-text-lighter);
}

.count-cell {
	font-family: monospace;
	text-align: right;
	font-weight: 600;
}

.count-cell.high-count {
	color: var(--color-warning);
}

.status-cell {
	text-align: center;
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

.status-configured {
	background-color: var(--color-success);
	color: var(--color-success-text);
}

.status-not-configured {
	background-color: var(--color-warning);
	color: var(--color-warning-text);
}

.actions-cell {
	text-align: center;
}

.sync-button {
	font-size: 0.9em;
	padding: 6px 12px;
}

.loading-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 32px;
	gap: 16px;
}

.loading-container p {
	color: var(--color-text-lighter);
	margin: 0;
}

/* Responsive design */
@media (max-width: 768px) {
	.statistics-header {
		flex-direction: column;
		align-items: flex-start;
		gap: 8px;
	}

	.statistics-table {
		overflow-x: auto;
	}

	.statistics-table table {
		min-width: 500px;
	}
}

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
