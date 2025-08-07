<template>
	<NcSettingsSection
		name="Object Statistics"
		description="Overview of objects stored in configured registers">
		
		<div class="statistics-section">
			<!-- Refresh Button -->
			<div class="statistics-header">
				<NcButton
					:disabled="loadingStats"
					@click="refreshStatistics">
					<template #icon>
						<RefreshIcon :size="16" />
					</template>
					{{ loadingStats ? 'Loading...' : 'Refresh Statistics' }}
				</NcButton>
				
				<span v-if="statistics.timestamp" class="last-updated">
					Last updated: {{ formatTimestamp(statistics.timestamp) }}
				</span>
			</div>

			<!-- Statistics Table -->
			<div v-if="formattedStatistics.length > 0" class="statistics-table">
				<table>
					<thead>
						<tr>
							<th>Register</th>
							<th>Object Type</th>
							<th>Count</th>
							<th>Status</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="stat in formattedStatistics" :key="`${stat.register}-${stat.type}`">
							<td class="register-cell">{{ stat.register }}</td>
							<td class="type-cell">{{ stat.type }}</td>
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
	</NcSettingsSection>
</template>

<script>
import { defineComponent } from 'vue'
import { 
	NcSettingsSection, 
	NcButton, 
	NcEmptyContent, 
	NcLoadingIcon,
	NcNoteCard
} from '@nextcloud/vue'
import { useSettingsStore } from '../../../store/modules/settings.js'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import ChartLineIcon from 'vue-material-design-icons/ChartLine.vue'

/**
 * Statistics Overview component
 * Displays object counts for all configured registers
 *
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later
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
		RefreshIcon,
		ChartLineIcon,
	},

	setup() {
		const settingsStore = useSettingsStore()
		
		return {
			settingsStore,
		}
	},

	computed: {
		statistics() {
			return this.settingsStore.statistics
		},
		
		formattedStatistics() {
			return this.settingsStore.formattedStatistics
		},
		
		loadingStats() {
			return this.settingsStore.loadingStats
		},
		
		error() {
			return this.settingsStore.error
		},
	},

	async mounted() {
		// Load statistics when component mounts
		await this.refreshStatistics()
	},

	methods: {
		/**
		 * Refresh statistics data
		 */
		async refreshStatistics() {
			await this.settingsStore.loadStatistics()
		},

		/**
		 * Format number with thousand separators
		 * @param {number} num - Number to format
		 * @return {string} Formatted number
		 */
		formatNumber(num) {
			if (num === 0) return '0'
			return num.toLocaleString()
		},

		/**
		 * Format timestamp for display
		 * @param {number} timestamp - Unix timestamp
		 * @return {string} Formatted date/time
		 */
		formatTimestamp(timestamp) {
			if (!timestamp) return 'Unknown'
			const date = new Date(timestamp * 1000)
			return date.toLocaleString()
		},
	},
})
</script>

<style scoped>
.statistics-section {
	margin-top: 16px;
}

.statistics-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
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
</style>