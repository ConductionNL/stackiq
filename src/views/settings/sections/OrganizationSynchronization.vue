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
		name="Organization Synchronization"
		description="Monitor and manage organization and contact person synchronization"
		:loading="loading"
		:show-refresh-button="true"
		:refreshing="loadingSyncStatus"
		refresh-button-text="Refresh Status"
		:has-info-content="true"
		@refresh="loadSyncStatus">
		<div>
			<div class="sync-section">
				<h3>Synchronization Status</h3>
				<p>Monitor the status of organization and contact person synchronization</p>

				<!-- Time Window Configuration -->
				<div class="time-window-configuration">
					<h4>Incremental Sync Time Window</h4>
					<p>Configure how far back to look for updated organizations during incremental synchronization</p>

					<div class="time-window-row">
						<div class="time-window-selector">
							<NcSelect
								v-model="selectedTimeWindow"
								:options="timeWindowOptions"
								input-label="Time Window"
								:disabled="loading || loadingSyncStatus"
								@change="handleTimeWindowChange" />
						</div>

						<!-- Sync Actions in same row -->
						<div class="sync-actions">
							<NcButton
								type="secondary"
								:disabled="loading || loadingSyncStatus"
								@click="loadSyncStatus">
								<template #icon>
									<NcLoadingIcon v-if="loadingSyncStatus" :size="20" />
									<Refresh v-else :size="20" />
								</template>
								Refresh Status
							</NcButton>

							<NcButton
								type="primary"
								:disabled="loading || performingSync || !syncStatus?.configured"
								@click="performManualSync">
								<template #icon>
									<NcLoadingIcon v-if="performingSync" :size="20" />
									<Sync v-else :size="20" />
								</template>
								{{ selectedTimeWindow && selectedTimeWindow.value === 0 ? 'Full Sync Now' : 'Incremental Sync Now' }}
							</NcButton>
						</div>
					</div>

					<div class="time-window-description">
						{{ getTimeWindowDescription() }}
					</div>
				</div>

				<div class="sync-status">
					<div v-if="syncStatus" class="status-info">
						<div class="configuration-overview">
							<div class="config-item">
								<span class="config-label">Configuration:</span>
								<span v-if="syncStatus.configured" class="status-configured">✓ Configured</span>
								<span v-else class="status-missing">⚠ Not configured</span>
							</div>

							<div v-if="syncStatus.configured" class="config-details">
								<div class="config-item">
									<span class="config-label">Sync Mode:</span>
									<span class="config-value">{{ syncStatus.syncMode || 'Unknown' }}</span>
								</div>
								<div class="config-item">
									<span class="config-label">Time Window:</span>
									<span class="config-value">{{ formatTimeWindow(syncStatus.timeWindow) }}</span>
								</div>
								<div class="config-item">
									<span class="config-label">Total Organizations:</span>
									<span class="config-value">{{ formatNumber(syncStatus.totalOrganizationObjects) || 0 }}</span>
								</div>
								<div class="config-item">
									<span class="config-label">Organizations to Process:</span>
									<span class="config-value" :class="getProcessingClass(syncStatus.organizationsToProcess)">{{ syncStatus.organizationsToProcess || 0 }}</span>
								</div>
								<div class="config-item">
									<span class="config-label">Contact Persons to Process:</span>
									<span class="config-value" :class="getProcessingClass(syncStatus.contactPersonsToProcess)">{{ syncStatus.contactPersonsToProcess || 0 }}</span>
								</div>
								<div v-if="syncStatus.efficiencyImprovement" class="config-item">
									<span class="config-label">Efficiency Improvement:</span>
									<span class="config-value efficiency-highlight">{{ syncStatus.efficiencyImprovement }}</span>
								</div>
								<div class="config-item">
									<span class="config-label">Organization Entities:</span>
									<span class="config-value">{{ formatNumber(syncStatus.totalOrganizationEntities) || 0 }}</span>
								</div>
								<div class="config-item">
									<span class="config-label">Contact Schema:</span>
									<span v-if="syncStatus.contactSchemaConfigured" class="status-configured">✓ Configured</span>
									<span v-else class="status-missing">⚠ Not configured</span>
								</div>
								<div class="config-item">
									<span class="config-label">Last Sync:</span>
									<span class="config-value">{{ formatLastSyncTime(syncStatus.lastSyncTime) }}</span>
								</div>
							</div>
						</div>
						<div v-if="syncStatus.message" class="status-message">
							{{ syncStatus.message }}
						</div>
					</div>
					<div v-else class="status-loading">
						<NcLoadingIcon :size="20" />
						Loading sync status...
					</div>
				</div>

				<div v-if="syncResult" class="sync-result">
					<NcNoteCard :type="syncResult.success ? 'success' : 'error'">
						<template #icon>
							<CheckCircle v-if="syncResult.success" :size="20" />
							<Alert v-else :size="20" />
						</template>
						<div class="sync-result-content">
							<strong>{{ syncResult.message }}</strong>
							<div v-if="syncResult.success && syncResult.results" class="sync-statistics">
								<h5>Synchronization Results:</h5>
								<ul>
									<li>Organizations processed: {{ syncResult.results.organizationsProcessed }}</li>
									<li>Entities created: {{ syncResult.results.entitiesCreated }}</li>
									<li>Entities updated: {{ syncResult.results.entitiesUpdated }}</li>
									<li>Contact persons processed: {{ syncResult.results.contactPersonsProcessed }}</li>
									<li>Users created: {{ syncResult.results.usersCreated }}</li>
									<li>Users updated: {{ syncResult.results.usersUpdated }}</li>
									<li>Duration: {{ syncResult.results.duration }}</li>
								</ul>
								<div v-if="syncResult.results.errors && syncResult.results.errors.length > 0" class="sync-errors">
									<h5>Errors encountered:</h5>
									<ul>
										<li v-for="error in syncResult.results.errors" :key="error">
											{{ error }}
										</li>
									</ul>
								</div>
							</div>
						</div>
					</NcNoteCard>
				</div>

				<div class="sync-info">
					<h4>About Synchronization</h4>
					<p>The synchronization process ensures that:</p>
					<ul>
						<li><strong>Organization entities:</strong> Every organization object has a corresponding organization entity</li>
						<li><strong>User accounts:</strong> Contact persons have Nextcloud user accounts</li>
						<li><strong>Relationships:</strong> Organization entities maintain correct user lists</li>
						<li><strong>Status consistency:</strong> Organization active status reflects the 'beoordeling' field</li>
					</ul>
					<p><strong>Time-based filtering:</strong> Organizations remain in the sync queue based on their last update time in OpenRegister, not when they were last processed. An organization will naturally "age out" of the time window once it hasn't been updated for longer than the selected time period.</p>
					<p><strong>Automatic synchronization:</strong> This process runs every 5 minutes in the background using incremental sync (10-minute window by default). Use manual sync for immediate updates or troubleshooting.</p>
				</div>
			</div>
		</div>

		<!-- Info Content Slot -->
		<template #info-content>
			<div class="organization-sync-info">
				<h3>About Organization Synchronization</h3>
				<p>This section manages the automatic synchronization of organization and contact person data between the Software Catalog and OpenRegister.</p>

				<h4>How Synchronization Works</h4>
				<p>The system automatically synchronizes organization data from OpenRegister every 5 minutes using an incremental sync process. Only organizations that have been updated within the specified time window are processed.</p>

				<h4>Time Window Configuration</h4>
				<p>The time window determines how far back the system looks for updated organizations:</p>
				<ul>
					<li><strong>10 minutes</strong> - Most recent changes only (default)</li>
					<li><strong>1 hour</strong> - Recent changes within the last hour</li>
					<li><strong>24 hours</strong> - Changes from the last day</li>
					<li><strong>7 days</strong> - Changes from the last week</li>
				</ul>

				<h4>Manual Synchronization</h4>
				<p>Use manual sync when you need immediate updates or for troubleshooting. Manual sync processes all organizations regardless of the time window.</p>

				<h4>Sync Statistics</h4>
				<p>The status display shows:</p>
				<ul>
					<li><strong>Efficiency</strong> - Percentage of successfully processed organizations</li>
					<li><strong>Processing Time</strong> - Average time per organization</li>
					<li><strong>Total Organizations</strong> - Number of organizations in the current sync scope</li>
					<li><strong>Last Sync</strong> - When the last synchronization was completed</li>
				</ul>

				<h4>Troubleshooting</h4>
				<ul>
					<li>Low efficiency may indicate data quality issues or network problems</li>
					<li>High processing times may suggest performance optimization is needed</li>
					<li>Use manual sync to test changes immediately</li>
					<li>Check the time window if recent changes aren't appearing</li>
				</ul>
			</div>
		</template>
	</CollapsibleSection>
</template>

<script>
/**
 * Organization Synchronization Component
 *
 * This component handles the monitoring and management of organization
 * and contact person synchronization processes.
 *
 * @author Ruben Linde <info@conduction.nl>
 * @copyright 2023 Conduction B.V.
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 */

import { settingsStore } from '../../../store/store.js'
import { showError, showSuccess } from '@nextcloud/dialogs'

// Components
import CollapsibleSection from '../../../components/CollapsibleSection.vue'

// Nextcloud Vue components
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

// Icons
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Alert from 'vue-material-design-icons/Alert.vue'

export default {
	name: 'OrganizationSynchronization',

	components: {
		CollapsibleSection,
		NcButton,
		NcSelect,
		NcNoteCard,
		NcLoadingIcon,
		Refresh,
		Sync,
		CheckCircle,
		Alert,
	},

	/**
	 * Component setup function for Composition API
	 * Provides access to the settings store
	 *
	 * @return {object} Setup object with store reference
	 */
	setup() {
		return {
			store: settingsStore,
		}
	},

	/**
	 * Component data
	 *
	 * @return {object} Component data
	 */
	data() {
		return {
			loadingSyncStatus: false,
			performingSync: false,
			syncStatus: null,
			syncResult: null,
			selectedTimeWindow: { value: 10, label: '10 minutes' },
			timeWindowOptions: [
				{ value: 0, label: 'Full sync (all organizations)' },
				{ value: 5, label: '5 minutes' },
				{ value: 10, label: '10 minutes' },
				{ value: 15, label: '15 minutes' },
				{ value: 30, label: '30 minutes' },
				{ value: 60, label: '1 hour' },
				{ value: 120, label: '2 hours' },
				{ value: 360, label: '6 hours' },
				{ value: 720, label: '12 hours' },
				{ value: 1440, label: '24 hours' },
			],
		}
	},

	computed: {
		// Store-connected computed properties
		loading() { return this.store.loading },
	},

	/**
	 * Component created lifecycle hook
	 * Load sync status when component is created
	 *
	 * @return {Promise<void>}
	 */
	async created() {
		await this.loadSyncStatus()
	},

	methods: {
		/**
		 * Load synchronization status
		 *
		 * @return {Promise<void>}
		 */
		async loadSyncStatus() {
			this.loadingSyncStatus = true
			try {
				const timeWindow = this.selectedTimeWindow?.value || 10
				const response = await fetch(`/index.php/apps/softwarecatalog/api/settings/sync-status?timeWindow=${timeWindow}`)

				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}

				const data = await response.json()
				this.syncStatus = data
			} catch (error) {
				console.error('Failed to load sync status:', error)
				showError('Failed to load synchronization status: ' + error.message)
			} finally {
				this.loadingSyncStatus = false
			}
		},

		/**
		 * Perform manual synchronization
		 *
		 * @return {Promise<void>}
		 */
		async performManualSync() {
			this.performingSync = true
			this.syncResult = null

			try {
				const timeWindow = this.selectedTimeWindow?.value || 10
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings/sync', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-Requested-With': 'XMLHttpRequest',
					},
					body: JSON.stringify({ timeWindow }),
				})

				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}

				const result = await response.json()
				this.syncResult = result

				if (result.success) {
					showSuccess('Manual synchronization completed successfully')
					// Reload sync status to reflect changes
					await this.loadSyncStatus()
				} else {
					throw new Error(result.message || 'Synchronization failed')
				}
			} catch (error) {
				console.error('Failed to perform manual sync:', error)
				this.syncResult = {
					success: false,
					message: 'Failed to perform manual synchronization: ' + error.message,
				}
				showError('Failed to perform manual synchronization: ' + error.message)
			} finally {
				this.performingSync = false
			}
		},

		/**
		 * Handle time window change
		 *
		 * @return {Promise<void>}
		 */
		async handleTimeWindowChange() {
			await this.loadSyncStatus()
		},

		/**
		 * Get time window description
		 *
		 * @return {string} Description of the current time window
		 */
		getTimeWindowDescription() {
			if (!this.selectedTimeWindow) return ''

			if (this.selectedTimeWindow.value === 0) {
				return 'Full synchronization will process all organizations regardless of when they were last updated.'
			}

			return `Incremental synchronization will process organizations updated within the last ${this.selectedTimeWindow.label}.`
		},

		/**
		 * Format time window for display
		 *
		 * @param {number} minutes Time window in minutes
		 * @return {string} Formatted time window
		 */
		formatTimeWindow(minutes) {
			if (!minutes || minutes === 0) return 'Full sync'
			if (minutes < 60) return `${minutes} minutes`
			if (minutes < 1440) return `${Math.floor(minutes / 60)} hours`
			return `${Math.floor(minutes / 1440)} days`
		},

		/**
		 * Format number for display
		 *
		 * @param {number} num Number to format
		 * @return {string} Formatted number
		 */
		formatNumber(num) {
			if (!num) return '0'
			return num.toLocaleString()
		},

		/**
		 * Format last sync time for display
		 *
		 * @param {string} timestamp Last sync timestamp
		 * @return {string} Formatted time
		 */
		formatLastSyncTime(timestamp) {
			if (!timestamp) return 'Never'
			try {
				const date = new Date(timestamp)
				return date.toLocaleString()
			} catch {
				return 'Invalid date'
			}
		},

		/**
		 * Get CSS class for processing count
		 *
		 * @param {number} count Processing count
		 * @return {string} CSS class name
		 */
		getProcessingClass(count) {
			if (!count || count === 0) return 'processing-none'
			if (count < 10) return 'processing-low'
			if (count < 100) return 'processing-medium'
			return 'processing-high'
		},
	},
}
</script>

<style scoped>
.sync-section {
	margin-top: 16px;
}

.time-window-configuration {
	margin-bottom: 24px;
	padding: 16px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.time-window-configuration h4 {
	margin: 0 0 8px 0;
	font-weight: 600;
	color: var(--color-main-text);
}

.time-window-configuration p {
	margin: 0 0 16px 0;
	color: var(--color-text-maxcontrast);
}

.time-window-row {
	display: flex;
	gap: 16px;
	align-items: flex-start;
	margin-bottom: 12px;
}

.time-window-selector {
	flex: 1;
	max-width: 300px;
}

.sync-actions {
	display: flex;
	gap: 12px;
}

.time-window-description {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.sync-status {
	margin-bottom: 24px;
}

.status-info {
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	padding: 16px;
}

.configuration-overview {
	margin-bottom: 16px;
}

.config-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.config-item:last-child {
	border-bottom: none;
}

.config-label {
	font-weight: 600;
	color: var(--color-main-text);
	min-width: 200px;
}

.config-value {
	color: var(--color-text-maxcontrast);
	text-align: right;
}

.status-configured {
	color: var(--color-success);
	font-weight: 600;
}

.status-missing {
	color: var(--color-warning);
	font-weight: 600;
}

.processing-none {
	color: var(--color-success);
}

.processing-low {
	color: var(--color-warning);
}

.processing-medium {
	color: var(--color-warning);
	font-weight: 600;
}

.processing-high {
	color: var(--color-error);
	font-weight: 600;
}

.efficiency-highlight {
	color: var(--color-success);
	font-weight: 600;
}

.status-message {
	margin-top: 16px;
	padding: 12px;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
	color: var(--color-main-text);
	font-style: italic;
}

.status-loading {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 16px;
	color: var(--color-text-maxcontrast);
}

.sync-result {
	margin-bottom: 24px;
}

.sync-result-content h5 {
	margin: 16px 0 8px 0;
	font-weight: 600;
	color: var(--color-main-text);
}

.sync-statistics ul,
.sync-errors ul {
	margin: 0;
	padding-left: 20px;
}

.sync-statistics li,
.sync-errors li {
	margin-bottom: 4px;
}

.sync-errors {
	margin-top: 16px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.sync-errors h5 {
	color: var(--color-error);
}

.sync-errors li {
	color: var(--color-error);
}

.sync-info {
	padding: 16px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.sync-info h4 {
	margin: 0 0 12px 0;
	font-weight: 600;
	color: var(--color-main-text);
}

.sync-info p {
	margin: 0 0 12px 0;
	color: var(--color-text-maxcontrast);
}

.sync-info ul {
	margin: 0 0 16px 0;
	padding-left: 20px;
}

.sync-info li {
	margin-bottom: 8px;
	color: var(--color-main-text);
}

.loading-icon {
	display: flex;
	justify-content: center;
	margin: 40px 0;
}
</style>
