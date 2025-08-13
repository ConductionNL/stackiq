<template>
	<NcAppNavigation>
		<NcAppNavigationList>
			<!-- Dashboard -->
			<NcAppNavigationItem
				:active="navigationStore.selected === 'dashboard'"
				name="Dashboard"
				@click="navigationStore.setSelected('dashboard')">
				<template #icon>
					<ViewDashboard :size="20" />
				</template>
			</NcAppNavigationItem>

			<!-- Dynamic menu items based on available schemas -->
			<NcAppNavigationItem
				v-for="menuItem in dynamicMenuItems"
				:key="menuItem.slug"
				:active="navigationStore.selected === menuItem.routeName"
				:name="menuItem.title"
				@click="navigationStore.setSelected(menuItem.routeName)">
				<template #icon>
					<component :is="menuItem.icon" :size="20" />
				</template>
			</NcAppNavigationItem>
		</NcAppNavigationList>
	</NcAppNavigation>
</template>
<script>
import {
	NcAppNavigation,
	NcAppNavigationList,
	NcAppNavigationItem,
} from '@nextcloud/vue'
import { navigationStore, objectStore } from '../store/store.js'

// Icons
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import ApplicationCog from 'vue-material-design-icons/ApplicationCog.vue'
import FileDocumentEdit from 'vue-material-design-icons/FileDocumentEdit.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import ViewModule from 'vue-material-design-icons/ViewModule.vue'
import FileDocumentCheck from 'vue-material-design-icons/FileDocumentCheck.vue'
import Star from 'vue-material-design-icons/Star.vue'

/**
 * @class MainMenu
 * @module Navigation
 * @package
 * @author Claude AI
 * @copyright 2023 Conduction
 * @license EUPL-1.2
 * @version 1.0.0
 * @see https://github.com/OpenCatalogi/opencatalogi
 *
 * Dynamic navigation menu that generates menu items based on available schemas.
 */
export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationList,
		NcAppNavigationItem,
		// Icons
		ViewDashboard,
		OfficeBuildingOutline,
		AccountMultiple,
		ApplicationCog,
		FileDocumentEdit,
		CheckCircle,
		ViewModule,
		FileDocumentCheck,
		Star,
	},

	data() {
		return {
			navigationStore,
			objectStore, // Make objectStore reactive
		}
	},

	computed: {
		/**
		 * Generate dynamic menu items from voorzieningen register schemas
		 * @return {Array} Array of menu item configurations
		 */
		dynamicMenuItems() {
			if (!this.objectStore.settings?.availableRegisters) {
				console.warn('MainMenu: No available registers found', this.objectStore.settings)
				return []
			}

			// Find the voorzieningen register
			const voorzieningenRegister = this.objectStore.settings.availableRegisters.find(
				register => register.slug === 'voorzieningen'
			)

			if (!voorzieningenRegister?.schemas) {
				console.warn('MainMenu: No voorzieningen register found or no schemas available')
				return []
			}

			const menuItems = []
			
			console.info('MainMenu: Building dynamic menu items from voorzieningen register', {
				registerTitle: voorzieningenRegister.title,
				schemaCount: voorzieningenRegister.schemas.length,
			})

			// Create menu items for each schema in the voorzieningen register
			voorzieningenRegister.schemas.forEach(schema => {
				console.info(`MainMenu: Processing schema ${schema.slug}`, {
					title: schema.title,
					description: schema.description,
					shouldInclude: this.shouldIncludeSchemaInMenu(schema),
				})

				if (this.shouldIncludeSchemaInMenu(schema)) {
					const menuItem = {
						slug: schema.slug,
						title: schema.title,
						routeName: this.getRouteNameFromSchema(schema),
						icon: this.getIconComponentFromSchema(schema),
						order: this.getMenuOrderFromSchema(schema),
					}
					console.info(`MainMenu: Adding menu item for ${schema.slug}`, menuItem)
					menuItems.push(menuItem)
				}
			})

			// Sort menu items by predefined order
			return menuItems.sort((a, b) => a.order - b.order)
		},
	},

	watch: {
		// Watch for changes in objectStore.settings to trigger menu updates
		'objectStore.settings': {
			handler(newSettings) {
				console.info('MainMenu: Settings changed, menu should update', newSettings)
				this.$forceUpdate()
			},
			deep: true,
		},
	},

	async mounted() {
		// Ensure settings are loaded for dynamic menu generation
		if (!this.objectStore.settings && !this.objectStore.isLoading('settings')) {
			try {
				await this.objectStore.fetchSettings()
			} catch (error) {
				console.error('Failed to load settings for menu generation:', error)
			}
		}
	},

	methods: {
		/**
		 * Check if schema should be included in the menu
		 * @param {object} schema - Schema object from voorzieningen register
		 * @return {boolean} True if should be included in menu
		 */
		shouldIncludeSchemaInMenu(schema) {
			// Include all schemas from voorzieningen register that have a configuration with icon
			// or are in our list of important schemas
			const alwaysIncludeSchemas = [
				'organisatie',
				'contactpersoon',
				'voorziening',
				'contract',
				'standaard',
				'review',
				'compliancy',
				'moduleversie',
				'voorzieningaanbod',
				'voorzieningmodule',
				'kwetsbaarheid',
				'beoordeeling',
				'verklaring',
				'koppeling',
			]

			// Include if it's in our always-include list or if it has a configuration with icon
			return alwaysIncludeSchemas.includes(schema.slug) || 
				(schema.configuration && this.hasIconForSchema(schema.slug))
		},

		/**
		 * Check if schema has an icon defined in the schema configurations
		 * @param {string} schemaSlug - Schema slug
		 * @return {boolean} True if schema has an icon
		 */
		hasIconForSchema(schemaSlug) {
			// Check if we have schema configurations with icon data
			const schemaConfigs = this.objectStore.settings?.schemaConfigurations
			if (schemaConfigs) {
				for (const register of Object.keys(schemaConfigs)) {
					const registerData = schemaConfigs[register]
					if (registerData?.schemas?.[schemaSlug]?.icon) {
						return true
					}
				}
			}
			return false
		},

		/**
		 * Get route name for schema object
		 * @param {object} schema - Schema object from voorzieningen register
		 * @return {string} Route name
		 */
		getRouteNameFromSchema(schema) {
			// Convert schema slug to plural form for route names
			const pluralMap = {
				organisatie: 'organisaties',
				contactpersoon: 'contactpersonen',
				voorziening: 'voorzieningen',
				voorzieningaanbod: 'voorzieningaanbods',
				voorzieningversie: 'voorzieningversies',
				voorzieningmodule: 'voorzieningmodules',
				contract: 'contracten',
				standaard: 'standaarden',
				review: 'reviews',
				compliancy: 'compliancies',
				moduleversie: 'moduleversies',
				kwetsbaarheid: 'kwetsbaarheden',
				beoordeeling: 'beoordelingen',
				verklaring: 'verklaringen',
				koppeling: 'koppelingen',
				koppelinggebruik: 'koppelinggebruiks',
				modulegebruik: 'modulegebruiks',
				property: 'properties',
			}
			return pluralMap[schema.slug] || `${schema.slug}s`
		},

		/**
		 * Get menu order for schema object
		 * @param {object} schema - Schema object from voorzieningen register
		 * @return {number} Menu order
		 */
		getMenuOrderFromSchema(schema) {
			// Define the order of menu items based on importance
			const orderMap = {
				organisatie: 1,
				contactpersoon: 2,
				voorziening: 3,
				voorzieningaanbod: 4,
				voorzieningmodule: 5,
				moduleversie: 6,
				contract: 7,
				standaard: 8,
				compliancy: 9,
				review: 10,
				kwetsbaarheid: 11,
				beoordeeling: 12,
				verklaring: 13,
				koppeling: 14,
				koppelinggebruik: 15,
				modulegebruik: 16,
				property: 17,
			}
			return orderMap[schema.slug] || 999
		},

		/**
		 * Get icon component for schema object
		 * @param {object} schema - Schema object from voorzieningen register
		 * @return {object} Vue icon component
		 */
		getIconComponentFromSchema(schema) {
			// First check if we have icon in schema configurations
			const schemaConfigs = this.objectStore.settings?.schemaConfigurations
			if (schemaConfigs) {
				for (const register of Object.keys(schemaConfigs)) {
					const registerData = schemaConfigs[register]
					if (registerData?.schemas?.[schema.slug]?.icon) {
						return this.getIconComponent(registerData.schemas[schema.slug].icon)
					}
				}
			}

			// Fallback to predefined icon mapping based on schema slug
			const iconMap = {
				organisatie: 'OfficeBuildingOutline',
				contactpersoon: 'AccountMultiple',
				voorziening: 'ApplicationCog',
				voorzieningaanbod: 'ApplicationCog',
				voorzieningmodule: 'ViewModule',
				moduleversie: 'ViewModule',
				contract: 'FileDocumentEdit',
				standaard: 'FileDocumentCheck',
				compliancy: 'CheckCircle',
				review: 'Star',
				kwetsbaarheid: 'CheckCircle',
				beoordeeling: 'Star',
				verklaring: 'FileDocumentCheck',
				koppeling: 'ViewModule',
				koppelinggebruik: 'ViewModule',
				modulegebruik: 'ViewModule',
				property: 'ApplicationCog',
			}

			const iconName = iconMap[schema.slug] || 'OfficeBuildingOutline'
			return this.getIconComponent(iconName)
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
				CheckCircle,
				ViewModule,
				FileDocumentCheck,
				Star,
			}
			return iconMap[iconName] || OfficeBuildingOutline
		},
	},
}
</script>
