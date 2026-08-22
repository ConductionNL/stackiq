<template>
	<div>
		<NcSettingsSection
			name="Stackiq"
			description="A central place for managing your software"
			docUrl="https://softwarecatalog.conduction.nl" />

		<NcSettingsSection
			name="Data storage"
			description="Configure where to store your publication data">
			<div v-if="!loading">
				<!-- Warning if OpenRegister is not installed -->
				<NcNoteCard v-if="!settings.openRegisters" type="warning">
					Open Register is not installed. Please install it to use the Open
					Catalogi app with full functionality.
				</NcNoteCard>

				<!-- Register Selection -->
				<div class="register-selection">
					<h3>Register</h3>
					<p>Select the register to store all your publicatie data</p>

					<NcSelect
						v-model="selectedRegister"
						:options="registerOptions"
						inputLabel="Register"
						:disabled="loading || !settings.openRegisters"
						@update:modelValue="handleRegisterChange" />
				</div>

				<!-- Warning if selected register has no schemas -->
				<NcNoteCard v-if="selectedRegister && !hasSchemas" type="warning">
					The selected register has no schemas. Please create schemas in
					this register or select a different register.
				</NcNoteCard>

				<!-- Object Type Schema Configuration -->
				<div
					v-if="selectedRegister && hasSchemas"
					class="schema-configuration">
					<h3>Schema Configuration</h3>
					<p>Select which schema to use for each object type</p>

					<div
						v-for="objectType in settings.objectTypes"
						:key="objectType"
						class="object-type-section">
						<div class="object-type-header">
							<h4>{{ formatTitle(objectType) }}</h4>
						</div>

						<NcSelect
							v-model="configuration[objectType].schema"
							:options="computedSchemaOptions"
							inputLabel="Schema"
							:disabled="loading" />
					</div>
				</div>

				<!-- Save Buttons -->
				<div class="button-container">
					<NcButton
						variant="primary"
						:disabled="
							loading || saving || !selectedRegister || !hasSchemas
						"
						@click="saveAll">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="20" />
							<Save v-else :size="20" />
						</template>
						Save Configuration
					</NcButton>
				</div>
			</div>

			<!-- Loading State -->
			<NcLoadingIcon
				v-else
				class="loading-icon"
				:size="64"
				appearance="dark" />
		</NcSettingsSection>

		<NcSettingsSection
			name="Other Configuration"
			description="Configure additional application settings">
			<div v-if="!loading">
				<!-- Catalog Location -->
				<div class="catalog-location-section">
					<h3>Catalog Location</h3>
					<p>Set the base URL for your catalog interface</p>

					<NcTextField
						v-model="catalogLocation"
						:label="t('stackiq', 'Catalog Location URL')"
						:placeholder="
							t('stackiq', 'https://catalog.example.com')
						"
						:disabled="loading || savingCatalogLocation">
						<template #icon>
							<Web :size="16" />
						</template>
					</NcTextField>

					<div class="catalog-location-help">
						<p class="help-text">
							This URL will be used for "Go to organisation" links. The
							system will append "/beheer" to this URL.
						</p>
					</div>

					<!-- Save Catalog Location Button -->
					<div class="button-container">
						<NcButton
							variant="secondary"
							:disabled="
								loading
								|| savingCatalogLocation
								|| !catalogLocationChanged
							"
							@click="saveCatalogLocation">
							<template #icon>
								<NcLoadingIcon
									v-if="savingCatalogLocation"
									:size="20" />
								<Save v-else :size="20" />
							</template>
							Save Catalog Location
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Loading State -->
			<NcLoadingIcon
				v-else
				class="loading-icon"
				:size="64"
				appearance="dark" />
		</NcSettingsSection>
	</div>
</template>

<script>
import {
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcSettingsSection,
	NcTextField,
} from '@nextcloud/vue'
import { defineComponent } from 'vue'
import Save from 'vue-material-design-icons/ContentSave.vue'
import Web from 'vue-material-design-icons/Web.vue'

/**
 * @class Settings
 * @module Components
 * @package
 * @author Claude AI
 * @copyright 2023 Conduction
 * @license EUPL-1.2
 * @version 1.0.0
 * @see https://github.com/OpenCatalogi/opencatalogi
 *
 * Settings component for the Open Catalogi that allows users to configure
 * data storage options for different object types using Open Registers.
 */
export default defineComponent({
	name: 'Settings',
	components: {
		NcSettingsSection,
		NcNoteCard,
		NcSelect,
		NcButton,
		NcLoadingIcon,
		NcTextField,
		Save,
		Web,
	},

	/**
	 * Component data
	 *
	 * @return {object} Component data
	 */
	data() {
		return {
			loading: true,
			saving: false,
			savingCatalogLocation: false,
			loadingConfiguration: false,
			configurationResults: null,
			settings: {
				objectTypes: [],
				openRegisters: false,
				availableRegisters: [],
				configuration: {},
				catalogLocation: '',
			},

			selectedRegister: null,
			configuration: {},
			schemaOptions: [],
			catalogLocation: '',
			originalCatalogLocation: '',
		}
	},

	computed: {
		/**
		 * Generates options for register selection dropdown
		 *
		 * @return {Array<object>} Array of register options with label and value
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		registerOptions() {
			return this.settings.availableRegisters.map((register) => ({
				label: register.title,
				value: register.id.toString(),
			}))
		},

		/**
		 * Determines if the selected register has schemas
		 *
		 * @return {boolean} True if the selected register has schemas, false otherwise
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		hasSchemas() {
			if (!this.selectedRegister) return false

			const register = this.settings.availableRegisters.find(
				(r) => r.id.toString() === this.selectedRegister.value,
			)

			return (
				register
				&& Array.isArray(register.schemas)
				&& register.schemas.length > 0
			)
		},

		/**
		 * Returns filtered schema options, excluding those that are already used
		 *
		 * @return {Array<object>} Array of available schema options
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		computedSchemaOptions() {
			const usedSchemaIds = Object.values(this.configuration)
				.filter((config) => config.schema !== null)
				.map((config) => config.schema.value)

			return this.schemaOptions.filter(
				(option) => !usedSchemaIds.includes(option.value),
			)
		},

		/**
		 * Check if catalog location has changed
		 *
		 * @return {boolean} True if catalog location has changed
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		catalogLocationChanged() {
			return this.catalogLocation !== this.originalCatalogLocation
		},
	},

	/**
	 * Lifecycle hook that loads settings when component is created
	 */
	async created() {
		await this.loadSettings()
	},

	methods: {
		/**
		 * Loads settings from the backend API and initializes the configuration
		 *
		 * @async
		 * @return {Promise<void>}
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		async loadSettings() {
			try {
				const response = await fetch(
					'/index.php/apps/stackiq/api/settings',
				)
				const data = await response.json()
				this.settings = data

				// Initialize catalog location
				this.catalogLocation = data.catalogLocation || ''
				this.originalCatalogLocation = this.catalogLocation

				// Initialize configuration object
				this.initializeConfiguration()

				// Find and select the Publication register if it exists
				this.autoSelectOpenCatalogiRegister()

				this.loading = false
			} catch (error) {
				console.error('Failed to load settings:', error)
			}
		},

		/**
		 * Initializes the configuration object based on existing settings
		 *
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		initializeConfiguration() {
			// Create empty configuration for each object type
			this.settings.objectTypes.forEach((type) => {
				const registerId =
					this.settings.configuration[`${type}_register`] || ''
				const schemaId = this.settings.configuration[`${type}_schema`] || ''

				this.configuration = {
					...this.configuration,
					[type]: {
						schema: null,
					},
				}

				// If we have existing configuration, use it to set the selected register
				if (registerId && !this.selectedRegister) {
					const register = this.settings.availableRegisters.find(
						(r) => r.id.toString() === registerId,
					)
					if (register) {
						this.selectedRegister = {
							label: register.title,
							value: register.id.toString(),
						}
						this.updateSchemaOptions(register.id.toString())
					}
				}

				// If we have a schema configured, set it
				if (schemaId && this.selectedRegister) {
					const register = this.settings.availableRegisters.find(
						(r) => r.id.toString() === this.selectedRegister.value,
					)
					if (register && Array.isArray(register.schemas)) {
						const schema = register.schemas.find(
							(s) => s.id.toString() === schemaId,
						)
						if (schema) {
							this.configuration = {
								...this.configuration,
								[type]: {
									...this.configuration[type],
									schema: {
										label: schema.title,
										value: schema.id.toString(),
									},
								},
							}
						}
					}
				}
			})
		},

		/**
		 * Automatically selects the opencatalogi register if it exists
		 *
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		autoSelectOpenCatalogiRegister() {
			// Look for a register with "opencatalogi" in the name
			const opencatalogiRegister = this.settings.availableRegisters.find(
				(register) => register.title.toLowerCase().includes('publication'),
			)

			if (opencatalogiRegister) {
				this.selectedRegister = {
					label: opencatalogiRegister.title,
					value: opencatalogiRegister.id.toString(),
				}
				this.updateSchemaOptions(opencatalogiRegister.id.toString())

				// Only try to auto-select schemas if the register has schemas
				if (Array.isArray(opencatalogiRegister.schemas)) {
					this.autoSelectMatchingSchemas(opencatalogiRegister)
				}
			} else if (
				this.settings.availableRegisters.length > 0
				&& !this.selectedRegister
			) {
				// If no Open Catalogi register but we have registers, select the first one
				const firstRegister = this.settings.availableRegisters[0]
				this.selectedRegister = {
					label: firstRegister.title,
					value: firstRegister.id.toString(),
				}
				this.updateSchemaOptions(firstRegister.id.toString())

				// Only try to auto-select schemas if the register has schemas
				if (Array.isArray(firstRegister.schemas)) {
					this.autoSelectMatchingSchemas(firstRegister)
				}
			}
		},

		/**
		 * Auto-selects schemas that match object type names
		 *
		 * @param {object} register - The selected register object
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		autoSelectMatchingSchemas(register) {
			// Only proceed if register has schemas array
			if (!register || !Array.isArray(register.schemas)) {
				return
			}

			this.settings.objectTypes.forEach((type) => {
				// Look for a schema with the same name as the object type
				const matchingSchema = register.schemas.find(
					(schema) => schema.title.toLowerCase() === type.toLowerCase(),
				)

				if (matchingSchema) {
					this.configuration = {
						...this.configuration,
						[type]: {
							...this.configuration[type],
							schema: {
								label: matchingSchema.title,
								value: matchingSchema.id.toString(),
							},
						},
					}
				}
			})
		},

		/**
		 * Updates schema options based on the selected register
		 *
		 * @param {string} registerId - The ID of the selected register
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		updateSchemaOptions(registerId) {
			const register = this.settings.availableRegisters.find(
				(r) => r.id.toString() === registerId,
			)
			if (register && Array.isArray(register.schemas)) {
				this.schemaOptions = register.schemas.map((schema) => ({
					label: schema.title,
					value: schema.id.toString(),
				}))
			} else {
				this.schemaOptions = []
			}
		},

		/**
		 * Formats an object type string to title case
		 *
		 * @param {string} objectType - The object type to format
		 * @return {string} The formatted title
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		formatTitle(objectType) {
			return objectType.charAt(0).toUpperCase() + objectType.slice(1)
		},

		/**
		 * Handles register change event
		 *
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		handleRegisterChange() {
			if (this.selectedRegister) {
				// Update schema options for the new register
				this.updateSchemaOptions(this.selectedRegister.value)

				// Reset all schema selections
				this.settings.objectTypes.forEach((type) => {
					this.configuration = {
						...this.configuration,
						[type]: {
							...this.configuration[type],
							schema: null,
						},
					}
				})

				// Auto-select matching schemas
				const register = this.settings.availableRegisters.find(
					(r) => r.id.toString() === this.selectedRegister.value,
				)
				if (register && Array.isArray(register.schemas)) {
					this.autoSelectMatchingSchemas(register)
				}
			}
		},

		/**
		 * Saves all configuration settings to the backend
		 *
		 * @async
		 * @return {Promise<void>}
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		async saveAll() {
			if (!this.selectedRegister || !this.hasSchemas) {
				return
			}

			this.saving = true
			try {
				const configToSave = {}

				// Set all object types to use openregister as source
				Object.entries(this.configuration).forEach(([type, config]) => {
					// Always use openregister as source
					configToSave[`${type}_source`] = 'openregister'

					// Set the register ID for all object types
					configToSave[`${type}_register`] = this.selectedRegister.value

					// Set the schema ID if selected
					configToSave[`${type}_schema`] = config.schema
						? config.schema.value
						: ''
				})

				// Send configuration to backend
				await fetch('/index.php/apps/stackiq/api/settings', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(configToSave),
				})
			} catch (error) {
				console.error('Failed to save settings:', error)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Loads configuration from the backend API
		 *
		 * @async
		 * @return {Promise<void>}
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		async loadConfiguration() {
			this.loadingConfiguration = true
			this.configurationResults = null

			try {
				const response = await fetch(
					'/index.php/apps/stackiq/api/settings/load',
				)
				const data = await response.json()

				if (data.error) {
					this.configurationResults = { error: data.error }
				} else {
					this.configurationResults = { success: true }
					// Reload settings to reflect any changes
					await this.loadSettings()
				}
			} catch (error) {
				this.configurationResults = {
					error: 'Failed to load configuration: ' + error.message,
				}
			} finally {
				this.loadingConfiguration = false
			}
		},

		/**
		 * Handle catalog location input change
		 *
		 * @param {string} value - New catalog location value
		 * @return {void}
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		onCatalogLocationChange(value) {
			this.catalogLocation = value
		},

		/**
		 * Save catalog location to backend
		 *
		 * @async
		 * @return {Promise<void>}
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		async saveCatalogLocation() {
			if (!this.catalogLocationChanged) {
				return
			}

			this.savingCatalogLocation = true
			try {
				const response = await fetch(
					'/index.php/apps/stackiq/api/settings/catalog-location',
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
						},
						body: JSON.stringify({
							catalogLocation: this.catalogLocation,
						}),
					},
				)

				if (response.ok) {
					this.originalCatalogLocation = this.catalogLocation
					console.info('Catalog location saved successfully')
				} else {
					console.error('Failed to save catalog location')
				}
			} catch (error) {
				console.error('Failed to save catalog location:', error)
			} finally {
				this.savingCatalogLocation = false
			}
		},
	},
})
</script>

<style scoped>
.load-configuration {
	margin-bottom: 2rem;
}

.configuration-results {
	margin-top: 1rem;
}

.register-selection {
	margin-bottom: 2rem;
	max-width: 400px;
}

.schema-configuration {
	margin-top: 2rem;
}

.object-type-section {
	margin-bottom: 1.5rem;
	display: flex;
	align-items: center;
	gap: 1rem;
}

.object-type-header {
	min-width: 150px;
}

.button-container {
	margin-top: 2rem;
}

.loading-icon {
	display: flex;
	justify-content: center;
	margin: 2rem 0;
}

.catalog-location-section {
	margin-bottom: 2rem;
	max-width: 500px;
}

.catalog-location-help {
	margin-top: 0.5rem;
	margin-bottom: 1rem;
}

.help-text {
	font-size: 12px;
	color: var(--color-text-lighter);
	margin: 0;
}
</style>
