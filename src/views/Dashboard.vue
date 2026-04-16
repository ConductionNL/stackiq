<template>
	<CnDashboardPage
		:title="t('softwarecatalog', 'Dashboard')"
		:description="t('softwarecatalog', 'Overview of your software catalog and configurations')"
		:widgets="widgetDefs"
		:layout="dashboardLayout"
		:loading="loading">
		<template #actions>
			<NcButton type="secondary" @click="refreshAllData">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Refresh v-else :size="20" />
				</template>
				{{ t('softwarecatalog', 'Refresh') }}
			</NcButton>
		</template>

		<!-- Beheer info box widget -->
		<template #widget-info-box>
			<NcNoteCard type="info" class="infoBox">
				<div class="infoBoxContent">
					<h3 class="infoBoxTitle">
						{{ t('softwarecatalog', 'Organisation management') }}
					</h3>
					<p class="infoBoxText">
						{{ t('softwarecatalog', 'Organisations can be accepted and managed via the organisations page. Creating and editing users also goes via the organisation page, as they are part of organisations.') }}
					</p>
					<div class="infoBoxActions">
						<NcButton
							type="primary"
							@click="navigateToOrganizations">
							<template #icon>
								<OfficeBuildingOutline :size="16" />
							</template>
							{{ t('softwarecatalog', 'Go to Organisations') }}
						</NcButton>
					</div>
				</div>
			</NcNoteCard>
		</template>

		<!-- Statistics table 1 widget -->
		<template #widget-stats-table-1>
			<div class="statisticsTableContainer">
				<div class="statisticsTableHeader">
					<span class="lastUpdated">{{ t('softwarecatalog', 'Last updated: {date}', { date: formatDate(new Date()) }) }}</span>
				</div>

				<table class="objectStatisticsTable">
					<thead>
						<tr>
							<th>{{ t('softwarecatalog', 'Object type') }}</th>
							<th class="countHeader">
								{{ t('softwarecatalog', 'Count') }}
							</th>
							<th class="manageHeader">
								{{ t('softwarecatalog', 'Manage') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="stat in firstTableStats" :key="stat.objectType">
							<td>{{ stat.objectType }}</td>
							<td class="countCell">
								{{ stat.count.toLocaleString() }}
							</td>
							<td class="manageCell">
								<NcButton
									v-if="stat.slug === 'organisatie'"
									size="small"
									type="tertiary"
									@click="navigateToObjectType(stat.slug)">
									<template #icon>
										<component :is="getIconForObjectType(stat.slug)" :size="16" />
									</template>
									{{ t('softwarecatalog', 'Manage') }}
								</NcButton>
								<span v-else class="disabledManage">
									<component :is="getIconForObjectType(stat.slug)" :size="16" />
									<span class="strikethrough">{{ t('softwarecatalog', 'Manage') }}</span>
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</template>

		<!-- Statistics table 2 widget -->
		<template #widget-stats-table-2>
			<div class="statisticsTableContainer">
				<div class="statisticsTableHeader">
					<span class="lastUpdated">{{ t('softwarecatalog', 'Last updated: {date}', { date: formatDate(new Date()) }) }}</span>
				</div>

				<table class="objectStatisticsTable">
					<thead>
						<tr>
							<th>{{ t('softwarecatalog', 'Object type') }}</th>
							<th class="countHeader">
								{{ t('softwarecatalog', 'Count') }}
							</th>
							<th class="manageHeader">
								{{ t('softwarecatalog', 'Manage') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="stat in secondTableStats" :key="stat.objectType">
							<td>{{ stat.objectType }}</td>
							<td class="countCell">
								{{ stat.count.toLocaleString() }}
							</td>
							<td class="manageCell">
								<NcButton
									v-if="stat.slug === 'organisatie'"
									size="small"
									type="tertiary"
									@click="navigateToObjectType(stat.slug)">
									<template #icon>
										<component :is="getIconForObjectType(stat.slug)" :size="16" />
									</template>
									{{ t('softwarecatalog', 'Manage') }}
								</NcButton>
								<span v-else class="disabledManage">
									<component :is="getIconForObjectType(stat.slug)" :size="16" />
									<span class="strikethrough">{{ t('softwarecatalog', 'Manage') }}</span>
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</template>
	</CnDashboardPage>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
// eslint-disable-next-line import/named
import { CnDashboardPage } from '@conduction/nextcloud-vue'
import { objectStore, navigationStore } from '../store/store.js'

// Icons
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import ApplicationCog from 'vue-material-design-icons/ApplicationCog.vue'
import FileDocumentEdit from 'vue-material-design-icons/FileDocumentEdit.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'

/**
 * @class Dashboard
 * @module Views
 * @package
 * @author Claude AI
 * @copyright 2023 Conduction
 * @license EUPL-1.2
 * @version 2.0.0
 * @see https://github.com/OpenCatalogi/opencatalogi
 *
 * Dashboard view showing overview statistics and configuration status.
 * Uses CnDashboardPage for standard widget-based layout.
 */
export default {
	name: 'Dashboard',
	components: {
		CnDashboardPage,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		OfficeBuildingOutline,
		AccountMultiple,
		ApplicationCog,
		FileDocumentEdit,
		Cog,
		Refresh,
		DatabaseOutline,
	},

	data() {
		return {
			loading: true,
			dashboardLayout: [
				{ id: 1, widgetId: 'info-box', gridX: 0, gridY: 0, gridWidth: 12, showTitle: false },
				{ id: 2, widgetId: 'stats-table-1', gridX: 0, gridY: 1, gridWidth: 6, showTitle: false },
				{ id: 3, widgetId: 'stats-table-2', gridX: 6, gridY: 1, gridWidth: 6, showTitle: false },
			],
		}
	},

	computed: {
		/**
		 * Widget definitions for CnDashboardPage
		 * @return {Array} Widget definition array
		 */
		widgetDefs() {
			return [
				{ id: 'info-box', title: t('softwarecatalog', 'Management information') },
				{ id: 'stats-table-1', title: t('softwarecatalog', 'Object statistics (1)') },
				{ id: 'stats-table-2', title: t('softwarecatalog', 'Object statistics (2)') },
			]
		},

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
				register => register.slug === 'voorzieningen',
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
				suite: ApplicationCog,
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
		 * Navigate to organizations page
		 * @return {void}
		 */
		navigateToOrganizations() {
			navigationStore.setSelected('organisaties')
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
						}),
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
/* Info Box Styles */
.infoBox {
	margin: 0;
}

.infoBoxContent {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.infoBoxTitle {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.infoBoxText {
	margin: 0;
	font-size: 14px;
	line-height: 1.5;
}

.infoBoxActions {
	display: flex;
	justify-content: flex-start;
	margin-top: 8px;
}

/* Statistics Table Styles */
.statisticsTableContainer {
	background: var(--color-main-background);
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

.disabledManage {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-lighter);
	opacity: 0.6;
}

.strikethrough {
	text-decoration: line-through;
}
</style>
