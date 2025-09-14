<template>
	<div class="dashboard">
		<!-- Header -->
		<div class="dashboardHeader">
			<h1 class="dashboardTitle">
				<ViewDashboard :size="32" />
				{{ t('softwarecatalog', 'Dashboard') }}
			</h1>
			<p class="dashboardDescription">
				{{ t('softwarecatalog', 'Overview of your software catalog and configurations') }}
			</p>
		</div>

		<div v-if="!loading" class="dashboardContent">
			<!-- Object Statistics Tables -->
			<div class="objectStatistics">
				<h2 class="sectionTitle">{{ t('softwarecatalog', 'Object Statistics') }}</h2>
				<p class="sectionDescription">{{ t('softwarecatalog', 'Overview of objects stored in configured registers') }}</p>
				
				<div class="statisticsTablesRow">
					<!-- First Table -->
					<div class="statisticsTableContainer">
						<div class="statisticsTableHeader">
							<span class="lastUpdated">{{ t('softwarecatalog', 'Last updated: {date}', { date: formatDate(new Date()) }) }}</span>
						</div>
						
						<table class="objectStatisticsTable">
							<thead>
								<tr>
									<th>{{ t('softwarecatalog', 'Object Type') }}</th>
									<th class="countHeader">{{ t('softwarecatalog', 'Count') }}</th>
									<th class="manageHeader">{{ t('softwarecatalog', 'Manage') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="stat in firstTableStats" :key="stat.objectType">
									<td>{{ stat.objectType }}</td>
									<td class="countCell">{{ stat.count.toLocaleString() }}</td>
									<td class="manageCell">
										<NcButton
											size="small"
											type="tertiary"
											@click="navigateToObjectType(stat.slug)">
											<template #icon>
												<component :is="getIconForObjectType(stat.slug)" :size="16" />
											</template>
											Manage
										</NcButton>
									</td>
								</tr>
							</tbody>
						</table>
					</div>

					<!-- Second Table -->
					<div class="statisticsTableContainer">
						<div class="statisticsTableHeader">
							<span class="lastUpdated">{{ t('softwarecatalog', 'Last updated: {date}', { date: formatDate(new Date()) }) }}</span>
						</div>
						
						<table class="objectStatisticsTable">
							<thead>
								<tr>
									<th>{{ t('softwarecatalog', 'Object Type') }}</th>
									<th class="countHeader">{{ t('softwarecatalog', 'Count') }}</th>
									<th class="manageHeader">{{ t('softwarecatalog', 'Manage') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="stat in secondTableStats" :key="stat.objectType">
									<td>{{ stat.objectType }}</td>
									<td class="countCell">{{ stat.count.toLocaleString() }}</td>
									<td class="manageCell">
										<NcButton
											size="small"
											type="tertiary"
											@click="navigateToObjectType(stat.slug)">
											<template #icon>
												<component :is="getIconForObjectType(stat.slug)" :size="16" />
											</template>
											Manage
										</NcButton>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- Quick Actions -->
			<div class="quickActions">
				<h2 class="sectionTitle">
					{{ t('softwarecatalog', 'Quick Actions') }}
				</h2>
				<div class="quickActionsGrid">
					<NcButton
						v-for="action in quickActions"
						:key="action.key"
						type="secondary"
						@click="action.handler">
						<template #icon>
							<component :is="action.icon" :size="20" />
						</template>
						{{ action.label }}
					</NcButton>
				</div>
			</div>
		</div>

		<!-- Loading State -->
		<div v-else class="dashboardLoading">
			<NcLoadingIcon :size="64" />
			<p>{{ t('softwarecatalog', 'Loading dashboard...') }}</p>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { objectStore, navigationStore } from '../store/store.js'

// Icons
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import ApplicationCog from 'vue-material-design-icons/ApplicationCog.vue'
import FileDocumentEdit from 'vue-material-design-icons/FileDocumentEdit.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'

/**
 * @class Dashboard
 * @module Views
 * @package
 * @author Claude AI
 * @copyright 2023 Conduction
 * @license EUPL-1.2
 * @version 1.0.0
 * @see https://github.com/OpenCatalogi/opencatalogi
 *
 * Dashboard view showing overview statistics and configuration status.
 */
export default {
	name: 'Dashboard',
	components: {
		NcButton,
		NcLoadingIcon,
		// Icons
		ViewDashboard,
		OfficeBuildingOutline,
		AccountMultiple,
		ApplicationCog,
		FileDocumentEdit,
		Cog,
		Plus,
		Refresh,
		DatabaseOutline,
	},

	data() {
		return {
			loading: true,
		}
	},

		computed: {
		/**
		 * Get object statistics for the table display
		 * @return {Array} Array of object statistics
		 */
		objectStatistics() {
			const stats = []

			// Find the voorzieningen register from settings
			if (!objectStore.settings?.availableRegisters) {
				return stats
			}

			const voorzieningenRegister = objectStore.settings.availableRegisters.find(
				register => register.slug === 'voorzieningen'
			)

			if (!voorzieningenRegister?.schemas) {
				return stats
			}

			// Create statistics for each schema in the voorzieningen register
			voorzieningenRegister.schemas.forEach(schema => {
				const collection = objectStore.getCollection(schema.slug)
				const count = collection?.results?.length || 0
				const pagination = objectStore.getPagination(schema.slug)
				const total = pagination?.total || count

				stats.push({
					register: voorzieningenRegister.title,
					objectType: schema.title,
					count: total,
					slug: schema.slug,
				})
			})

			// Sort by object type name for consistent display
			return stats.sort((a, b) => a.objectType.localeCompare(b.objectType))
		},

		/**
		 * Get first half of statistics for first table
		 * @return {Array} First half of statistics
		 */
		firstTableStats() {
			const stats = this.objectStatistics
			const midPoint = Math.ceil(stats.length / 2)
			return stats.slice(0, midPoint)
		},

		/**
		 * Get second half of statistics for second table
		 * @return {Array} Second half of statistics
		 */
		secondTableStats() {
			const stats = this.objectStatistics
			const midPoint = Math.ceil(stats.length / 2)
			return stats.slice(midPoint)
		},



		/**
		 * Get quick actions for the dashboard
		 * @return {Array} Array of quick actions
		 */
		quickActions() {
			return [
				{
					key: 'refresh_data',
					label: 'Refresh All Data',
					icon: Refresh,
					handler: this.refreshAllData,
				},
				{
					key: 'add_organisation',
					label: 'Add Organisation',
					icon: Plus,
					handler: () => navigationStore.setSelected('organisaties'),
				},
				{
					key: 'configure',
					label: 'Settings',
					icon: Cog,
					handler: () => {
						const settingsUrl = `${window.location.protocol}//${window.location.host}/index.php/settings/admin/softwarecatalog`
						window.open(settingsUrl, '_blank')
					},
				},
			]
		},


	},

	async mounted() {
		await this.loadDashboardData()
	},

	methods: {
		/**
		 * Load dashboard data
		 * @return {Promise<void>}
		 */
		async loadDashboardData() {
			this.loading = true
			try {
				// Ensure settings are loaded (avoid duplicate calls)
				if (!objectStore.settings && !objectStore.isLoading('settings')) {
					await objectStore.fetchSettings()
				}

				// Load data for all registered object types
				const registeredTypes = Object.keys(objectStore.objectTypeRegistry || {})
				await Promise.all(
					registeredTypes.map(async (objectType) => {
						try {
							await objectStore.fetchCollection(objectType, { _limit: 1 }) // Just get pagination info
						} catch (error) {
							console.warn(`Failed to fetch ${objectType} collection:`, error)
						}
					}),
				)
			} catch (error) {
				console.error('Failed to load dashboard data:', error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get schema configuration for object type
		 * @param {string} objectType - Object type slug
		 * @return {object | null} Schema configuration
		 */
		getSchemaConfig(objectType) {
			if (!objectStore.settings?.schemaConfigurations) {
				return null
			}

			// Look for schema in all registers
			const schemas = objectStore.settings.schemaConfigurations
			for (const register of Object.keys(schemas)) {
				const schemaData = schemas[register]?.schemas?.[objectType]
				if (schemaData) {
					return schemaData
				}
			}
			return null
		},

		/**
		 * Get icon component for icon name
		 * @param {string} iconName - Icon name from schema
		 * @return {object} Vue icon component
		 */
		getIconComponent(iconName) {
			const iconMap = {
				OfficeBuildingOutline,
				AccountMultiple,
				ApplicationCog,
				FileDocumentEdit,
				ViewDashboard,
				Cog,
				DatabaseOutline,
			}
			return iconMap[iconName] || OfficeBuildingOutline
		},

		/**
		 * Get icon component for specific object type
		 * @param {string} objectType - Object type slug
		 * @return {object} Vue icon component
		 */
		getIconForObjectType(objectType) {
			const iconMap = {
				organisatie: OfficeBuildingOutline,
				contactpersoon: AccountMultiple,
				voorziening: ApplicationCog,
				contract: FileDocumentEdit,
				product: ApplicationCog,
				module: ApplicationCog,
				koppeling: ApplicationCog,
				dienst: ApplicationCog,
				standaard: FileDocumentEdit,
				compliancy: FileDocumentEdit,
				kwetsbaarheid: FileDocumentEdit,
				beoordeling: FileDocumentEdit,
			}
			return iconMap[objectType] || DatabaseOutline
		},

		/**
		 * Navigate to object type management page
		 * @param {string} objectType - Object type slug to navigate to
		 * @return {void}
		 */
		navigateToObjectType(objectType) {
			// Handle special cases for plural routing
			const routeMap = {
				organisatie: 'organisaties',
				contactpersoon: 'contactpersonen',
				voorziening: 'voorzieningen',
				contract: 'contracten',
			}
			
			const route = routeMap[objectType] || `${objectType}s`
			navigationStore.setSelected(route)
		},

		/**
		 * Navigate to configuration page - opens admin settings in new tab
		 * @param {string} route - Route to navigate to (legacy parameter)
		 * @return {void}
		 */
		navigateToConfiguration(route) {
			const settingsUrl = `${window.location.protocol}//${window.location.host}/index.php/settings/admin/softwarecatalog`
			window.open(settingsUrl, '_blank')
		},

		/**
		 * Format object type name for display
		 * @param {string} objectType - The object type slug
		 * @return {string} Formatted object type name
		 */
		formatObjectTypeName(objectType) {
			// Convert camelCase/kebab-case to proper case
			return objectType
				.replace(/([a-z])([A-Z])/g, '$1 $2')
				.replace(/[-_]/g, ' ')
				.split(' ')
				.map(word => word.charAt(0).toUpperCase() + word.slice(1))
				.join(' ')
		},

		/**
		 * Format date for display
		 * @param {Date} date - Date to format
		 * @return {string} Formatted date string
		 */
		formatDate(date) {
			return date.toLocaleDateString('en-GB', {
				day: '2-digit',
				month: '2-digit',
				year: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			}).replace(',', ',')
		},

		/**
		 * Refresh all data - force reload settings and all collections
		 * @return {Promise<void>}
		 */
		async refreshAllData() {
			console.info('Dashboard: Refreshing all data...')
			this.loading = true
			
			try {
				// Force reload settings (even if already loaded)
				console.info('Dashboard: Force reloading settings...')
				await objectStore.fetchSettings(true) // Force refresh
				
				// Wait for object types to be registered
				await new Promise(resolve => setTimeout(resolve, 500))
				
				// Refresh all registered object collections
				const registeredTypes = Object.keys(objectStore.objectTypeRegistry || {})
				console.info('Dashboard: Refreshing collections for:', registeredTypes)
				
				if (registeredTypes.length > 0) {
					await Promise.all(
						registeredTypes.map(async (objectType) => {
							try {
								console.info(`Dashboard: Refreshing ${objectType} collection...`)
								await objectStore.fetchCollection(objectType, { _limit: 1 }) // Just get pagination info
							} catch (error) {
								console.warn(`Failed to refresh ${objectType} collection:`, error)
							}
						})
					)
				} else {
					console.warn('Dashboard: No object types registered after settings refresh')
				}
				
				console.info('Dashboard: All data refreshed successfully')
			} catch (error) {
				console.error('Dashboard: Failed to refresh all data:', error)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.dashboard {
	padding: 24px;
	max-width: 1200px;
	margin: 0 auto;
}

.dashboardHeader {
	margin-bottom: 32px;
}

.dashboardTitle {
	display: flex;
	align-items: center;
	gap: 12px;
	margin: 0 0 8px 0;
	font-size: 28px;
	font-weight: 600;
}

.dashboardDescription {
	margin: 0;
	color: var(--color-text-lighter);
	font-size: 16px;
}

.dashboardContent {
	display: flex;
	flex-direction: column;
	gap: 32px;
}

/* Removed old statistics card styles - replaced with table */

.sectionTitle {
	margin: 0 0 16px 0;
	font-size: 20px;
	font-weight: 600;
}

.configurationCards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
	gap: 16px;
}

.configurationCard {
	padding: 20px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.configurationCardHeader {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 12px;
}

.configurationCardHeader h3 {
	flex: 1;
	margin: 0;
	font-size: 16px;
	font-weight: 500;
}

.configurationCardDescription {
	margin: 0 0 16px 0;
	color: var(--color-text-lighter);
}

.quickActionsGrid {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
}

.dashboardLoading {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 16px;
	min-height: 400px;
	color: var(--color-text-lighter);
}



/* Object Statistics Tables */
.objectStatistics {
	margin-bottom: 32px;
}

.sectionDescription {
	margin: 0 0 16px 0;
	color: var(--color-text-lighter);
}

.statisticsTablesRow {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 24px;
}

.statisticsTableContainer {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
}

.statisticsTableHeader {
	padding: 16px;
	border-bottom: 1px solid var(--color-border);
	display: flex;
	justify-content: flex-end;
	background: var(--color-background-dark);
}

.lastUpdated {
	font-size: 12px;
	color: var(--color-text-lighter);
}

.objectStatisticsTable {
	width: 100%;
	border-collapse: collapse;
}

.objectStatisticsTable th {
	text-align: left;
	padding: 16px;
	background: var(--color-background-hover);
	font-weight: 600;
	color: var(--color-text-darker);
	border-bottom: 1px solid var(--color-border);
}

.countHeader,
.manageHeader {
	text-align: right;
}

.objectStatisticsTable td {
	padding: 16px;
	border-bottom: 1px solid var(--color-border-dark);
}

.objectStatisticsTable tbody tr:hover {
	background: var(--color-background-hover);
}

.objectStatisticsTable tbody tr:last-child td {
	border-bottom: none;
}

.countCell {
	font-weight: 600;
	text-align: right;
}

.manageCell {
	text-align: right;
}

@media (max-width: 768px) {
	.statisticsTablesRow {
		grid-template-columns: 1fr;
	}
}


</style>
