<template>
	<NcAppContent>
		<template #default>
			<!-- Dashboard -->
			<Dashboard v-if="navigationStore.selected === 'dashboard'" />

			<!-- Custom Override Pages (maintain existing specialized pages) -->
			<OrganisatieIndex v-if="navigationStore.selected === 'organisaties'" />

			<!-- Dynamic Object Index Pages -->
			<ObjectIndex
				v-else-if="isDynamicObjectRoute(navigationStore.selected)"
				:object-type="getObjectTypeFromRoute(navigationStore.selected)"
				:custom-card-component="getCustomCardComponent(navigationStore.selected)"
				:card-display-mode="getCardDisplayMode(navigationStore.selected)" />

			<!-- Settings (if you have a settings page) -->
			<div v-else-if="navigationStore.selected === 'settings'" class="settingsPlaceholder">
				<h2>{{ t('softwarecatalog', 'Settings') }}</h2>
				<p>{{ t('softwarecatalog', 'Settings page would go here') }}</p>
			</div>

			<!-- Default/fallback -->
			<div v-else class="defaultView">
				<!-- Clean default view -->
			</div>
		</template>
	</NcAppContent>
</template>

<script>
import { NcAppContent } from '@nextcloud/vue'
import { navigationStore, objectStore } from '../store/store.js'

import Dashboard from './Dashboard.vue'
import ObjectIndex from './ObjectIndex.vue'
import OrganisatieIndex from './organisaties/OrganisatieIndex.vue'
import OrganisatieCard from '../components/cards/OrganisatieCard.vue'

/**
 * @class Views
 * @module Views
 * @package
 * @author Claude AI
 * @copyright 2023 Conduction
 * @license EUPL-1.2
 * @version 1.0.0
 * @see https://github.com/OpenCatalogi/opencatalogi
 *
 * Main view router component that handles dynamic and custom page routing.
 */
export default {
	name: 'Views',
	components: {
		NcAppContent,
		Dashboard,
		ObjectIndex,
		OrganisatieIndex,
	},

	data() {
		return {
			navigationStore,
		}
	},

	methods: {
		/**
		 * Check if the current route is a dynamic object route
		 * @param {string} route - Current route
		 * @return {boolean} True if dynamic object route
		 */
		isDynamicObjectRoute(route) {
			// Skip custom override routes
			const customRoutes = ['dashboard', 'organisaties', 'settings']
			if (customRoutes.includes(route)) {
				return false
			}

			// Check if there's a registered object type for this route
			const objectType = this.getObjectTypeFromRoute(route)
			return !!(objectType && objectStore.objectTypeRegistry?.[objectType])
		},

		/**
		 * Get object type from route name
		 * @param {string} route - Route name (plural)
		 * @return {string|null} Object type (singular)
		 */
		getObjectTypeFromRoute(route) {
			// Map plural route names to singular object types
			const routeMap = {
				contactpersonen: 'contactpersoon',
				voorzieningen: 'voorziening',
				contracten: 'contract',
				standaarden: 'standaard',
				reviews: 'review',
				complianties: 'compliancy',
				moduleversies: 'moduleversie',
			}

			return routeMap[route] || null
		},

		/**
		 * Get custom card component for route (if any)
		 * @param {string} route - Route name
		 * @return {object | null} Custom card component
		 */
		getCustomCardComponent(route) {
			// Map routes to custom card components
			const customCards = {
				organisaties: OrganisatieCard,
				// Add more custom cards as needed
			}

			return customCards[route] || null
		},

		/**
		 * Get card display mode for route
		 * @param {string} route - Route name
		 * @return {string} Card display mode
		 */
		getCardDisplayMode(route) {
			// Map routes to preferred display modes
			const displayModes = {
				contactpersonen: 'properties',
				voorzieningen: 'mixed',
				contracten: 'properties',
				standaarden: 'description',
				reviews: 'mixed',
				complianties: 'properties',
				moduleversies: 'properties',
			}

			return displayModes[route] || 'mixed'
		},
	},
}
</script>
