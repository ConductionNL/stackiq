<template>
	<div>
		<NcSettingsSection
			name="Software Catalog Configuration"
			description="Configure OpenRegister schema mappings for Software Catalog objects"
			doc-url="https://docs.opencatalogi.nl" />

		<NcSettingsSection
			name="OpenRegister Integration"
			description="Configure which schemas to use for organizations, contacts, and users">
			<div v-if="!loading">
				<!-- Warning if OpenRegister is not installed -->
				<NcNoteCard v-if="!settings.openRegisters" type="warning">
					OpenRegister is not installed or not available. Please install it to use the Software Catalog with full functionality.
				</NcNoteCard>

				<!-- Initialization and Auto-Configure Section -->
				<div v-if="settings.openRegisters" class="initialization-section">
					<h3>Initialization</h3>
					<p>Initialize and auto-configure the Software Catalog settings</p>

					<div class="button-container">
						<NcButton
							type="secondary"
							:disabled="loading || initializing"
							@click="initializeSettings">
							<template #icon>
								<NcLoadingIcon v-if="initializing" :size="20" />
								<Cog v-else :size="20" />
							</template>
							Initialize & Auto-Configure
						</NcButton>

						<NcButton
							type="secondary"
							:disabled="loading || autoConfiguring"
							@click="autoConfigureSettings">
							<template #icon>
								<NcLoadingIcon v-if="autoConfiguring" :size="20" />
								<AutoFix v-else :size="20" />
							</template>
							Auto-Configure Only
						</NcButton>
					</div>

					<!-- Initialization Results -->
					<div v-if="initializationResults" class="initialization-results">
						<NcNoteCard v-if="initializationResults.errors && initializationResults.errors.length > 0" type="error">
							<template #icon>
								<Alert :size="20" />
							</template>
							<strong>Initialization Issues:</strong>
							<ul>
								<li v-for="error in initializationResults.errors" :key="error">{{ error }}</li>
							</ul>
						</NcNoteCard>

						<NcNoteCard v-if="initializationResults.autoConfigured" type="success">
							Auto-configuration completed successfully!
						</NcNoteCard>

						<NcNoteCard v-if="initializationResults.fullyConfigured" type="success">
							All object types are now configured.
						</NcNoteCard>
					</div>
				</div>

				<!-- Register Selection -->
				<div v-if="settings.openRegisters" class="register-selection">
					<h3>Register Selection</h3>
					<p>Select the register to store your Software Catalog data</p>

					<NcSelect
						v-model="selectedRegister"
						:options="registerOptions"
						input-label="Register"
						:disabled="loading"
						@change="handleRegisterChange" />
				</div>

				<!-- Warning if selected register has no schemas -->
				<NcNoteCard v-if="selectedRegister && !hasSchemas" type="warning">
					The selected register has no schemas. Please create schemas in this register or select a different register.
				</NcNoteCard>

				<!-- Object Type Schema Configuration -->
				<div v-if="selectedRegister && hasSchemas" class="schema-configuration">
					<h3>Schema Configuration</h3>
					<p>Configure schemas for each register type</p>

					<!-- AMEF Register Configuration -->
					<div v-if="isRegisterType('amef')" class="register-type-section">
						<h4>AMEF Register Configuration</h4>
						<p>Configure schemas for AMEF architectural elements</p>

						<div class="object-type-section">
							<div class="object-type-header">
								<h5>Organization Schema</h5>
								<span class="object-type-description">Schema for organizations in the AMEF register</span>
							</div>

							<NcSelect
								v-model="configuration.amef_organization.schema"
								:options="availableSchemaOptions"
								input-label="Organization Schema"
								:disabled="loading"
								@change="validateConfiguration" />
						</div>
					</div>

					<!-- Voorzieningen Register Configuration -->
					<div v-if="isRegisterType('voorzieningen')" class="register-type-section">
						<h4>Voorzieningen Register Configuration</h4>
						<p>Configure schemas for software catalog services</p>

						<div class="object-type-section">
							<div class="object-type-header">
								<h5>Gebruiker Schema</h5>
								<span class="object-type-description">Schema for users in the Voorzieningen register</span>
							</div>

							<NcSelect
								v-model="configuration.voorzieningen_gebruiker.schema"
								:options="availableSchemaOptions"
								input-label="Gebruiker Schema"
								:disabled="loading"
								@change="validateConfiguration" />
						</div>

						<div class="object-type-section">
							<div class="object-type-header">
								<h5>Organisatie Schema</h5>
								<span class="object-type-description">Schema for organizations in the Voorzieningen register</span>
							</div>

							<NcSelect
								v-model="configuration.voorzieningen_organisatie.schema"
								:options="availableSchemaOptions"
								input-label="Organisatie Schema"
								:disabled="loading"
								@change="validateConfiguration" />
						</div>

						<div class="object-type-section">
							<div class="object-type-header">
								<h5>Contactgegevens Schema</h5>
								<span class="object-type-description">Schema for contact information in the Voorzieningen register</span>
							</div>

							<NcSelect
								v-model="configuration.voorzieningen_contactgegevens.schema"
								:options="availableSchemaOptions"
								input-label="Contactgegevens Schema"
								:disabled="loading"
								@change="validateConfiguration" />
						</div>
					</div>

					<!-- Generic Object Types (for backward compatibility) -->
					<div v-if="!isSpecificRegister()" class="register-type-section">
						<h4>Generic Configuration</h4>
						<div v-for="objectType in settings.objectTypes" :key="objectType" class="object-type-section">
							<div class="object-type-header">
								<h5>{{ formatTitle(objectType) }}</h5>
								<span class="object-type-description">{{ getObjectTypeDescription(objectType) }}</span>
							</div>

							<NcSelect
								v-model="configuration[objectType].schema"
								:options="availableSchemaOptions"
								input-label="Schema"
								:disabled="loading"
								@change="validateConfiguration" />
						</div>
					</div>

					<!-- Configuration Status -->
					<div class="configuration-status">
						<h4>Configuration Status</h4>
						<div v-if="isRegisterType('amef')" class="status-group">
							<h5>AMEF Register</h5>
							<div class="status-item">
								<span class="status-label">Organization:</span>
								<span v-if="configuration.amef_organization?.schema" class="status-configured">✓ Configured</span>
								<span v-else class="status-missing">⚠ Not configured</span>
							</div>
						</div>

						<div v-if="isRegisterType('voorzieningen')" class="status-group">
							<h5>Voorzieningen Register</h5>
							<div class="status-item">
								<span class="status-label">Gebruiker:</span>
								<span v-if="configuration.voorzieningen_gebruiker?.schema" class="status-configured">✓ Configured</span>
								<span v-else class="status-missing">⚠ Not configured</span>
							</div>
							<div class="status-item">
								<span class="status-label">Organisatie:</span>
								<span v-if="configuration.voorzieningen_organisatie?.schema" class="status-configured">✓ Configured</span>
								<span v-else class="status-missing">⚠ Not configured</span>
							</div>
							<div class="status-item">
								<span class="status-label">Contactgegevens:</span>
								<span v-if="configuration.voorzieningen_contactgegevens?.schema" class="status-configured">✓ Configured</span>
								<span v-else class="status-missing">⚠ Not configured</span>
							</div>
						</div>

						<div v-if="!isSpecificRegister()" class="status-group">
							<h5>Generic Configuration</h5>
							<div v-for="objectType in settings.objectTypes" :key="objectType" class="status-item">
								<span class="status-label">{{ formatTitle(objectType) }}:</span>
								<span v-if="configuration[objectType]?.schema" class="status-configured">✓ Configured</span>
								<span v-else class="status-missing">⚠ Not configured</span>
							</div>
						</div>
					</div>
				</div>

				<!-- Save Buttons -->
				<div class="button-container">
					<NcButton
						type="primary"
						:disabled="loading || saving || !canSave"
						@click="saveConfiguration">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="20" />
							<Save v-else :size="20" />
						</template>
						Save Configuration
					</NcButton>

					<NcButton
						type="secondary"
						:disabled="loading"
						@click="loadSettings">
						<template #icon>
							<Refresh :size="20" />
						</template>
						Refresh
					</NcButton>
				</div>
			</div>

			<!-- Loading State -->
			<NcLoadingIcon v-else
				class="loading-icon"
				:size="64"
				appearance="dark" />
		</NcSettingsSection>
	</div>
</template>

<script>
import { defineComponent } from 'vue'
import {
	NcSettingsSection,
	NcNoteCard,
	NcSelect,
	NcButton,
	NcLoadingIcon,
} from '@nextcloud/vue'
import Save from 'vue-material-design-icons/ContentSave.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import AutoFix from 'vue-material-design-icons/AutoFix.vue'
import Alert from 'vue-material-design-icons/Alert.vue'

/**
 * Software Catalog Settings component
 *
 * @category Component
 * @package  OCA\SoftwareCatalog\Components
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/SoftwareCatalog
 * @version  1.0.0
 */
export default defineComponent({
	name: 'SoftwareCatalogSettings',
	components: {
		NcSettingsSection,
		NcNoteCard,
		NcSelect,
		NcButton,
		NcLoadingIcon,
		Save,
		Refresh,
		Cog,
		AutoFix,
		Alert,
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
			initializing: false,
			autoConfiguring: false,
			initializationResults: null,
			settings: {
				objectTypes: [],
				openRegisters: false,
				availableRegisters: [],
				configuration: {},
			},
			selectedRegister: null,
			configuration: {},
			schemaOptions: [],
		}
	},

	computed: {
		/**
		 * Generates options for register selection dropdown
		 *
		 * @return {Array<object>} Array of register options with label and value
		 */
		registerOptions() {
			return this.settings.availableRegisters.map(register => ({
				label: register.title,
				value: register.id.toString(),
			}))
		},

		/**
		 * Determines if the selected register has schemas
		 *
		 * @return {boolean} True if the selected register has schemas
		 */
		hasSchemas() {
			if (!this.selectedRegister) return false

			const register = this.settings.availableRegisters.find(
				r => r.id.toString() === this.selectedRegister.value,
			)

			return register && Array.isArray(register.schemas) && register.schemas.length > 0
		},

		/**
		 * Returns all available schema options (without filtering used ones for software catalog)
		 *
		 * @return {Array<object>} Array of available schema options
		 */
		availableSchemaOptions() {
			return this.schemaOptions
		},

		/**
		 * Determines if configuration can be saved
		 *
		 * @return {boolean} True if configuration is valid and can be saved
		 */
		canSave() {
			if (!this.selectedRegister || !this.hasSchemas) return false

			// Check if at least one schema is configured based on register type
			if (this.isRegisterType('amef')) {
				return this.configuration.amef_organization?.schema
			}

			if (this.isRegisterType('voorzieningen')) {
				return this.configuration.voorzieningen_gebruiker?.schema
					|| this.configuration.voorzieningen_organisatie?.schema
					|| this.configuration.voorzieningen_contactgegevens?.schema
			}

			// Check if at least one object type is configured (backward compatibility)
			return this.settings.objectTypes.some(type =>
				this.configuration[type] && this.configuration[type].schema
			)
		},
	},

	watch: {
		/**
		 * Watch for changes to the selected register
		 *
		 * @param {object} newRegister - The newly selected register
		 * @param {object} oldRegister - The previously selected register
		 */
		selectedRegister(newRegister, oldRegister) {
			if (newRegister && newRegister !== oldRegister) {
				this.handleRegisterChange()
			}
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
		 * Loads settings from the backend API
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async loadSettings() {
			this.loading = true
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings')

				const data = await response.json()

				if (data.error) {
					return
				}

				this.settings = data

				this.initializeConfiguration()
				this.autoSelectRegister()
			} catch (error) {
			} finally {
				this.loading = false
			}
		},

		/**
		 * Initializes the configuration object based on existing settings
		 */
		initializeConfiguration() {
			// Initialize register-specific configuration
			this.configuration = {
				// AMEF register configuration
				amef_organization: {
					schema: null,
				},
				// Voorzieningen register configuration
				voorzieningen_gebruiker: {
					schema: null,
				},
				voorzieningen_organisatie: {
					schema: null,
				},
				voorzieningen_contactgegevens: {
					schema: null,
				},
			}

			// Create empty configuration for each generic object type (backward compatibility)
			this.settings.objectTypes.forEach(type => {
				this.configuration = {
					...this.configuration,
					[type]: {
						schema: null,
					},
				}
			})

			// Handle existing configuration for register-specific schemas
			const configKeys = [
				'amef_organization',
				'voorzieningen_gebruiker',
				'voorzieningen_organisatie',
				'voorzieningen_contactgegevens'
			]

			configKeys.forEach(configKey => {
				const registerId = this.settings.configuration[`${configKey}_register`] || ''
				const schemaId = this.settings.configuration[`${configKey}_schema`] || ''

				// If we have existing configuration, use it to set the selected register
				if (registerId && !this.selectedRegister) {
					const register = this.settings.availableRegisters.find(r => r.id.toString() === registerId)
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
						r => r.id.toString() === this.selectedRegister.value,
					)
					if (register && Array.isArray(register.schemas)) {
						const schema = register.schemas.find(s => s.id.toString() === schemaId)
						if (schema) {
							this.configuration = {
								...this.configuration,
								[configKey]: {
									...this.configuration[configKey],
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

			// Handle backward compatibility for generic object types
			this.settings.objectTypes.forEach(type => {
				const registerId = this.settings.configuration[`${type}_register`] || ''
				const schemaId = this.settings.configuration[`${type}_schema`] || ''

				// If we have existing configuration, use it to set the selected register
				if (registerId && !this.selectedRegister) {
					const register = this.settings.availableRegisters.find(r => r.id.toString() === registerId)
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
						r => r.id.toString() === this.selectedRegister.value,
					)
					if (register && Array.isArray(register.schemas)) {
						const schema = register.schemas.find(s => s.id.toString() === schemaId)
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
		 * Automatically selects a suitable register
		 */
		autoSelectRegister() {
			if (this.settings.availableRegisters.length > 0 && !this.selectedRegister) {
				// Select the first available register
				const firstRegister = this.settings.availableRegisters[0]
				this.selectedRegister = {
					label: firstRegister.title,
					value: firstRegister.id.toString(),
				}
				this.updateSchemaOptions(firstRegister.id.toString())

				// Try to auto-select matching schemas
				if (Array.isArray(firstRegister.schemas)) {
					this.autoSelectMatchingSchemas(firstRegister)
				}
			}
		},

		/**
		 * Auto-selects schemas that match object type names
		 *
		 * @param {object} register - The selected register object
		 */
		autoSelectMatchingSchemas(register) {
			if (!register || !Array.isArray(register.schemas)) {
				return
			}

			// Handle register-specific auto-selection
			if (this.isRegisterType('amef')) {
				// For AMEF register, look for organization schema
				const orgSchema = register.schemas.find(
					schema => schema.title.toLowerCase().includes('organization')
				)
				if (orgSchema) {
					this.configuration = {
						...this.configuration,
						amef_organization: {
							...this.configuration.amef_organization,
							schema: {
								label: orgSchema.title,
								value: orgSchema.id.toString(),
							},
						},
					}
				}
			} else if (this.isRegisterType('voorzieningen')) {
				// For Voorzieningen register, look for gebruiker and organisatie schemas
				const gebruikerSchema = register.schemas.find(
					schema => schema.title.toLowerCase().includes('gebruiker')
				)
				if (gebruikerSchema) {
					this.configuration = {
						...this.configuration,
						voorzieningen_gebruiker: {
							...this.configuration.voorzieningen_gebruiker,
							schema: {
								label: gebruikerSchema.title,
								value: gebruikerSchema.id.toString(),
							},
						},
					}
				}

				const organisatieSchema = register.schemas.find(
					schema => schema.title.toLowerCase().includes('organisatie')
				)
				if (organisatieSchema) {
					this.configuration = {
						...this.configuration,
						voorzieningen_organisatie: {
							...this.configuration.voorzieningen_organisatie,
							schema: {
								label: organisatieSchema.title,
								value: organisatieSchema.id.toString(),
							},
						},
					}
				}

				const contactgegevensSchema = register.schemas.find(
					schema => schema.title.toLowerCase().includes('contactgegevens')
				)
				if (contactgegevensSchema) {
					this.configuration = {
						...this.configuration,
						voorzieningen_contactgegevens: {
							...this.configuration.voorzieningen_contactgegevens,
							schema: {
								label: contactgegevensSchema.title,
								value: contactgegevensSchema.id.toString(),
							},
						},
					}
				}
			} else {
				// Generic auto-selection for backward compatibility
				this.settings.objectTypes.forEach(type => {
					// Look for a schema with the same name as the object type
					const matchingSchema = register.schemas.find(
						schema => schema.title.toLowerCase().includes(type.toLowerCase()),
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
			}
		},

		/**
		 * Updates schema options based on the selected register
		 *
		 * @param {string} registerId - The ID of the selected register
		 */
		updateSchemaOptions(registerId) {
			const register = this.settings.availableRegisters.find(r => r.id.toString() === registerId)

			if (register && Array.isArray(register.schemas)) {
				this.schemaOptions = register.schemas.map(schema => ({
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
		 */
		formatTitle(objectType) {
			return objectType.charAt(0).toUpperCase() + objectType.slice(1)
		},

		/**
		 * Gets description for an object type
		 *
		 * @param {string} objectType - The object type
		 * @return {string} The description
		 */
		getObjectTypeDescription(objectType) {
			const descriptions = {
				organization: 'Organizations that register in the software catalog',
				contact: 'Contact persons associated with organizations',
				gebruiker: 'Users who can access the software catalog system',
			}
			return descriptions[objectType] || ''
		},

		/**
		 * Handles register change event
		 */
		handleRegisterChange() {
			if (this.selectedRegister) {
				this.updateSchemaOptions(this.selectedRegister.value)

				// Clear ALL schema selections - both register-specific and generic
				const allConfigKeys = [
					'amef_organization',
					'voorzieningen_gebruiker',
					'voorzieningen_organisatie',
					'voorzieningen_contactgegevens',
					...this.settings.objectTypes
				]

				allConfigKeys.forEach(configKey => {
					if (this.configuration[configKey]) {
						this.configuration = {
							...this.configuration,
							[configKey]: {
								...this.configuration[configKey],
								schema: null,
							},
						}
					}
				})

				// Auto-select matching schemas for the new register
				const register = this.settings.availableRegisters.find(
					r => r.id.toString() === this.selectedRegister.value,
				)

				if (register && Array.isArray(register.schemas)) {
					this.autoSelectMatchingSchemas(register)
				}
			}
		},

		/**
		 * Validates the current configuration
		 */
		validateConfiguration() {
			// This method can be expanded to add validation logic
		},

		/**
		 * Saves the configuration
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async saveConfiguration() {
			if (!this.canSave) return

			this.saving = true
			try {
				const configToSave = {}

				// Save register-specific configuration
				const registerSpecificKeys = [
					'amef_organization',
					'voorzieningen_gebruiker',
					'voorzieningen_organisatie',
					'voorzieningen_contactgegevens'
				]

				registerSpecificKeys.forEach(configKey => {
					const config = this.configuration[configKey]
					if (config) {
						// Always use openregister as source
						configToSave[`${configKey}_source`] = 'openregister'

						// Set the register ID
						configToSave[`${configKey}_register`] = this.selectedRegister.value

						// Set the schema ID if selected
						configToSave[`${configKey}_schema`] = config.schema ? config.schema.value : ''
					}
				})

				// Save generic object types configuration (backward compatibility)
				Object.entries(this.configuration).forEach(([type, config]) => {
					// Skip register-specific configs as they're handled above
					if (registerSpecificKeys.includes(type)) {
						return
					}

					// Only process generic object types
					if (this.settings.objectTypes.includes(type)) {
						// Always use openregister as source
						configToSave[`${type}_source`] = 'openregister'

						// Set the register ID for all object types
						configToSave[`${type}_register`] = this.selectedRegister.value

						// Set the schema ID if selected
						configToSave[`${type}_schema`] = config.schema ? config.schema.value : ''
					}
				})

				// Send configuration to backend
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(configToSave),
				})

				const result = await response.json()
				if (result.error) {
					// Configuration save failed, but we'll continue silently
				}
			} catch (error) {
			} finally {
				this.saving = false
			}
		},

		/**
		 * Initializes the settings
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async initializeSettings() {
			this.initializing = true
			this.initializationResults = null

			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings/initialize', {
					method: 'POST',
				})
				const data = await response.json()

				if (data.error) {
					this.initializationResults = { errors: [data.error] }
				} else {
					this.initializationResults = data
					// Reload settings to reflect any changes
					await this.loadSettings()
				}
			} catch (error) {
				this.initializationResults = { errors: ['Failed to initialize: ' + error.message] }
			} finally {
				this.initializing = false
			}
		},

		/**
		 * Auto-configures the settings
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async autoConfigureSettings() {
			this.autoConfiguring = true
			this.initializationResults = null

			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings/auto-configure', {
					method: 'POST',
				})
				const data = await response.json()

				if (data.error) {
					this.initializationResults = { errors: [data.error] }
				} else {
					this.initializationResults = data
					// Reload settings to reflect any changes
					await this.loadSettings()
				}
			} catch (error) {
				this.initializationResults = { errors: ['Failed to auto-configure: ' + error.message] }
			} finally {
				this.autoConfiguring = false
			}
		},

		/**
		 * Checks if a register is of a specific type
		 *
		 * @param {string} type - The type of the register
		 * @return {boolean} True if the register is of the specified type
		 */
		isRegisterType(type) {
			if (!this.selectedRegister) {
				return false
			}

			const register = this.settings.availableRegisters.find(r => r.id.toString() === this.selectedRegister.value)
			if (!register) {
				return false
			}

			// Check register name or slug to determine type
			const registerTitle = register.title ? register.title.toLowerCase() : ''
			const registerSlug = register.slug ? register.slug.toLowerCase() : ''
			const typeCheck = type.toLowerCase()

			// For exact matches or contains check
			const result = registerTitle === typeCheck
				|| registerSlug === typeCheck
				|| registerTitle.includes(typeCheck)
				|| registerSlug.includes(typeCheck)

			return result
		},

		/**
		 * Checks if the selected register is a specific register (amef or voorzieningen)
		 *
		 * @return {boolean} True if the register is a specific register type
		 */
		isSpecificRegister() {
			return this.isRegisterType('amef') || this.isRegisterType('voorzieningen')
		},
	},
})
</script>

<style scoped>
.initialization-section {
	margin-bottom: 2rem;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.initialization-results {
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
	align-items: flex-start;
	gap: 1rem;
}

.object-type-header {
	min-width: 200px;
	display: flex;
	flex-direction: column;
}

.object-type-description {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	margin-top: 0.25rem;
}

.configuration-status {
	margin: 2rem 0;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.status-item {
	display: flex;
	justify-content: space-between;
	margin-bottom: 0.5rem;
}

.status-label {
	font-weight: bold;
}

.status-configured {
	color: var(--color-success);
}

.status-missing {
	color: var(--color-warning);
}

.button-container {
	margin-top: 2rem;
	display: flex;
	gap: 1rem;
}

.loading-icon {
	display: flex;
	justify-content: center;
	margin: 2rem 0;
}

.register-type-section {
	margin-bottom: 2rem;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.status-group {
	margin-bottom: 1rem;
}
</style>
