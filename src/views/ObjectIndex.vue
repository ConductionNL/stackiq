<template>
	<GenericObjectTable
		:object-type="objectType"
		:object-type-plural="objectTypePlural"
		:title="objectTitle"
		:description="objectDescription"
		:empty-icon="objectIcon"
		:card-icon="objectIcon"
		:properties="objectProperties"
		:object-actions="objectActions"
		:mass-actions="objectMassActions"
		:actions="defaultActions"
		:add-action="addAction"
		:help-url="helpUrl"
		:card-display-mode="cardDisplayMode"
		:custom-card-component="customCardComponent"
		:filters="objectFilters"
		@mounted="onMounted" />
</template>

<script>
import GenericObjectTable from '../components/GenericObjectTable.vue'
import { objectStore } from '../store/store.js'

// Default icons - these will be overridden by schema icons when available
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'

/**
 * @class ObjectIndex
 * @module Views
 * @package
 * @author Claude AI
 * @copyright 2023 Conduction
 * @license EUPL-1.2
 * @version 1.0.0
 * @see https://github.com/OpenCatalogi/opencatalogi
 *
 * Dynamic ObjectIndex component that can display any object type
 * based on schema configuration from settings.
 */
export default {
	name: 'ObjectIndex',
	components: {
		GenericObjectTable,
	},
	props: {
		/**
		 * Object type slug from schema
		 * @type {string}
		 */
		objectType: {
			type: String,
			required: true,
		},
		/**
		 * Custom card component override
		 * @type {object | null}
		 */
		customCardComponent: {
			type: [String, Object],
			default: null,
		},
		/**
		 * Card display mode override
		 * @type {string}
		 */
		cardDisplayMode: {
			type: String,
			default: 'mixed',
		},
	},

	data() {
		return {}
	},

	computed: {
		/**
		 * Get schema configuration from settings
		 * @return {object|null} Schema configuration
		 */
		schemaConfig() {
			if (!objectStore.settings?.schemaConfigurations) {
				return null
			}

			// Look for schema in all registers
			const schemas = objectStore.settings.schemaConfigurations
			for (const register of Object.keys(schemas)) {
				const schemaData = schemas[register]?.schemas?.[this.objectType]
				if (schemaData) {
					return schemaData
				}
			}
			return null
		},

		/**
		 * Get object type plural form
		 * @return {string} Pluralized object type name
		 */
		objectTypePlural() {
			if (this.schemaConfig?.title) {
				// Simple pluralization - add 's' or 'en' for Dutch
				const title = this.schemaConfig.title.toLowerCase()
				if (title.endsWith('e')) {
					return this.schemaConfig.title + 'n'
				}
				return this.schemaConfig.title + 's'
			}
			return this.objectType + 's'
		},

		/**
		 * Get object title from schema
		 * @return {string} Object title
		 */
		objectTitle() {
			return this.schemaConfig?.title || this.objectType
		},

		/**
		 * Get object description from schema
		 * @return {string} Object description
		 */
		objectDescription() {
			return this.schemaConfig?.description || `Manage your ${this.objectTypePlural.toLowerCase()} and their configurations`
		},

		/**
		 * Get object icon from schema
		 * @return {object} Vue icon component
		 */
		objectIcon() {
			const iconName = this.schemaConfig?.icon
			if (iconName) {
				// Dynamic import would be ideal, but for now use a mapping
				const iconMap = this.getIconMap()
				return iconMap[iconName] || OfficeBuildingOutline
			}
			return OfficeBuildingOutline
		},

		/**
		 * Generate object properties for table display
		 * @return {Array} Array of property configurations
		 */
		objectProperties() {
			if (!this.schemaConfig?.properties) {
				return []
			}

			// Generate properties from schema
			const properties = []
			const schemaProps = this.schemaConfig.properties

			// Get first 6 most important properties (visible, ordered)
			const visibleProps = Object.keys(schemaProps)
				.filter(key => schemaProps[key].visible !== false)
				.sort((a, b) => {
					const orderA = schemaProps[a].order || 999
					const orderB = schemaProps[b].order || 999
					return orderA - orderB
				})
				.slice(0, 6)

			visibleProps.forEach(propKey => {
				const prop = schemaProps[propKey]
				properties.push({
					key: propKey,
					label: prop.title || propKey,
					sortable: true,
					searchable: true,
				})
			})

			return properties
		},

		/**
		 * Generate object filters based on schema
		 * @return {Array} Array of filter configurations
		 */
		objectFilters() {
			const filters = []

			// Add status filter if status field exists
			if (this.schemaConfig?.properties?.status) {
				const statusProp = this.schemaConfig.properties.status
				if (statusProp.enum && statusProp.enum.length > 0) {
					filters.push({
						key: 'status',
						label: 'Status',
						options: [
							{ value: 'all', label: 'Alle statussen' },
							...statusProp.enum.map(status => ({
								value: status,
								label: status,
							})),
						],
					})
				}
			}

			return filters
		},

		/**
		 * Object-specific actions
		 * @return {Array} Array of object actions
		 */
		objectActions() {
			return [
				{
					id: 'view',
					label: 'View',
					icon: Eye,
					handler: (item) => {
						console.info('View item:', item)
						// TODO: Navigate to detail page
					},
				},
				{
					id: 'edit',
					label: 'Edit',
					icon: Pencil,
					handler: (item) => {
						console.info('Edit item:', item)
						// TODO: Navigate to edit page
					},
				},
				{
					id: 'delete',
					label: 'Delete',
					icon: Delete,
					handler: (item) => {
						console.info('Delete item:', item)
						// TODO: Implement delete functionality
					},
				},
			]
		},

		/**
		 * Mass actions for bulk operations
		 * @return {Array} Array of mass actions
		 */
		objectMassActions() {
			return [
				{
					id: 'delete',
					label: 'Delete Selected',
					icon: Delete,
					handler: (selectedItems) => {
						console.info('Delete items:', selectedItems)
						// TODO: Implement bulk delete
					},
				},
			]
		},

		/**
		 * Default page actions
		 * @return {Array} Array of default actions
		 */
		defaultActions() {
			return [
				{
					id: 'help',
					label: 'Help',
					icon: HelpCircleOutline,
					handler: () => {
						if (this.helpUrl) {
							window.open(this.helpUrl, '_blank')
						}
					},
				},
			]
		},

		/**
		 * Add action configuration
		 * @return {object} Add action configuration
		 */
		addAction() {
			return {
				id: 'add',
				label: `Add ${this.objectTitle}`,
				icon: Plus,
				handler: () => {
					objectStore.clearActiveObject(this.objectType)
					console.info(`Add new ${this.objectType}`)
					// TODO: Navigate to create page
				},
			}
		},

		/**
		 * Help URL for this object type
		 * @return {string|null} Help URL
		 */
		helpUrl() {
			return `https://conduction.gitbook.io/softwarecatalog-nextcloud/beheerders/${this.objectType}`
		},
	},

	methods: {
		/**
		 * Handle component mounted event
		 * @return {Promise<void>}
		 */
		async onMounted() {
			// Ensure settings are loaded and object types are registered
			if (!objectStore.settings) {
				await objectStore.fetchSettings()
			}

			// Fetch the collection for this object type
			try {
				await objectStore.fetchCollection(this.objectType)
			} catch (error) {
				console.error(`Failed to fetch ${this.objectType} collection:`, error)
			}
		},

		/**
		 * Get icon mapping for dynamic icon loading
		 * @return {object} Icon name to component mapping
		 */
		getIconMap() {
			// This would ideally be dynamically imported, but for now we use a static map
			// In a future version, we could implement dynamic icon loading
			return {
				OfficeBuildingOutline,
				AccountMultiple: require('vue-material-design-icons/AccountMultiple.vue').default,
				ApplicationCog: require('vue-material-design-icons/ApplicationCog.vue').default,
				FileDocumentEdit: require('vue-material-design-icons/FileDocumentEdit.vue').default,
				CheckCircle: require('vue-material-design-icons/CheckCircle.vue').default,
				ViewModule: require('vue-material-design-icons/ViewModule.vue').default,
				FileDocumentCheck: require('vue-material-design-icons/FileDocumentCheck.vue').default,
				Star: require('vue-material-design-icons/Star.vue').default,
			}
		},
	},
}
</script>
