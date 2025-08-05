import { defineStore } from 'pinia'
import { showError, showSuccess } from '@nextcloud/dialogs'

/**
 * Settings store for managing all settings-related state and business logic
 *
 * This store handles:
 * - Settings loading and saving
 * - ArchiMate import/export operations
 * - Status polling
 * - Error handling (500 vs 503 errors)
 * - Register and schema management
 *
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @version  1.0.0
 */
export const useSettingsStore = defineStore('settings', {
	state: () => ({
		// Loading states
		loading: false,
		saving: false,
		importing: false,
		exporting: false,
		loadingVersionInfo: false,

		// Settings data
		settings: {
			openRegisters: false,
			availableRegisters: [],
			consolidatedConfig: {},
		},

		// Version information
		versionInfo: {},

		// Register selections
		voorzieningenRegister: null,
		amefRegister: null,
		voorzieningenSchemas: [],
		amefSchemas: [],

		// Configuration
		configuration: {
			// AMEF register configuration
			amef_elements: { schema: null },
			amef_organization: { schema: null },
			amef_relationships: { schema: null },
			amef_views: { schema: null },
			amef_models: { schema: null },
			amef_properties: { schema: null },
			amef_property_definitions: { schema: null },
			// Voorzieningen register configuration
			voorzieningen_organisatie: { schema: null },
			voorzieningen_contactpersoon: { schema: null },
			voorzieningen_gebruiker: { schema: null },
			voorzieningen_contactgegevens: { schema: null },
		},

		// ArchiMate status and operations
		archimateStatus: {
			import: {},
			export: {},
		},
		isImportRunning: false,
		isExportRunning: false,
		statusPollingInterval: null,
		isStatusPolling: false, // Prevent concurrent status polls

		// Import/Export options
		importOptions: {
			updateExisting: true,
			deleteOrphaned: false,
		},
		exportOptions: {
			includeViews: true,
			includeRelationships: true,
		},
		selectedFile: null,

		// User groups
		genericUserGroups: [],
		organizationAdminGroups: [],
		superUserGroups: [],

		// Email settings
		emailSettings: {
			enabled: false,
			senderEmail: '',
			senderName: '',
			testReceiverOverride: '',
			organizationRegistrationEnabled: true,
			organizationActivationEnabled: true,
			userCreationEnabled: true,
			userPasswordEnabled: true,
			transportType: 'smtp',
			smtpHost: 'localhost',
			smtpPort: 587,
			smtpEncryption: 'tls',
			smtpUsername: '',
			smtpPassword: '',
			sendgridApiKey: '',
			mailgunApiKey: '',
			mailgunDomain: '',
			postmarkApiKey: '',
			sesAccessKey: '',
			sesSecretKey: '',
			sesRegion: 'us-east-1',
			mailjetApiKey: '',
			mailjetSecretKey: '',
		},

		// Error handling
		error: null,
		importError: null,
		exportError: null,
	}),

	getters: {
		/**
		 * Get register options for dropdowns
		 * @param {object} state - The store state
		 * @return {Array} Array of register options
		 */
		registerOptions: (state) => {
			if (!state.settings.availableRegisters) return []
			return state.settings.availableRegisters.map(register => ({
				label: register.title || register.name || `Register ${register.id}`,
				value: register.id.toString(),
			}))
		},

		/**
		 * Get Voorzieningen schema options
		 * @param {object} state - The store state
		 * @return {Array} Array of schema options
		 */
		voorzieningenSchemaOptions: (state) => {
			if (!state.voorzieningenSchemas || !Array.isArray(state.voorzieningenSchemas)) return []
			return state.voorzieningenSchemas.map(schema => ({
				label: schema.title || schema.name || `Schema ${schema.id}`,
				value: schema.id.toString(),
			}))
		},

		/**
		 * Get AMEF schema options
		 * @param {object} state - The store state
		 * @return {Array} Array of schema options
		 */
		amefSchemaOptions: (state) => {
			if (!state.amefSchemas || !Array.isArray(state.amefSchemas)) return []
			return state.amefSchemas.map(schema => ({
				label: schema.title || schema.name || `Schema ${schema.id}`,
				value: schema.id.toString(),
			}))
		},

		/**
		 * Check if any operation is running
		 * @param {object} state - The store state
		 * @return {boolean} True if any operation is running
		 */
		isAnyOperationRunning: (state) => {
			return state.isImportRunning || state.isExportRunning
		},

		/**
		 * Get consolidated configuration
		 * @param {object} state - The store state
		 * @return {object} Consolidated configuration
		 */
		consolidatedConfig: (state) => {
			return state.settings.consolidatedConfig || {}
		},
	},

	actions: {
		/**
		 * Set loading state
		 * @param {boolean} loading - Loading state
		 */
		setLoading(loading) {
			this.loading = loading
		},

		/**
		 * Set error message
		 * @param {string|null} error - Error message
		 */
		setError(error) {
			this.error = error
		},

		/**
		 * Clear error
		 */
		clearError() {
			this.error = null
		},

		/**
		 * Load all settings data (version info, settings, ArchiMate status)
		 * This is the main initialization function that should be called from components
		 * @return {Promise<void>}
		 */
		async loadSettings() {
			this.loading = true
			this.loadingVersionInfo = true
			this.clearError()

			try {
				// Load version info and settings in parallel
				const [versionResponse, settingsResponse] = await Promise.all([
					fetch('/index.php/apps/softwarecatalog/api/settings/version'),
					fetch('/index.php/apps/softwarecatalog/api/settings'),
				])

				// Handle version info response
				if (versionResponse.ok) {
					const versionData = await versionResponse.json()
					if (!versionData.error) {
						this.versionInfo = versionData
						if (versionData.openRegisterEnabled !== undefined) {
							this.settings.openRegisters = versionData.openRegisterEnabled
						}
					} else {
						console.error('Version API error:', versionData.error)
					}
				} else {
					console.error('Failed to load version info:', versionResponse.statusText)
				}

				// Handle settings response
				if (settingsResponse.ok) {
					const settingsData = await settingsResponse.json()
					if (!settingsData.error) {
						this.settings = settingsData

						// Load data from consolidated configuration
						const consolidatedConfig = settingsData.consolidatedConfig || {}

						// Load user groups
						if (consolidatedConfig.userGroups) {
							this.genericUserGroups = consolidatedConfig.userGroups.generic || []
							this.organizationAdminGroups = consolidatedConfig.userGroups.organizationAdmin || []
							this.superUserGroups = consolidatedConfig.userGroups.superUser || []
						}

						// Load email settings
						if (consolidatedConfig.email) {
							this.emailSettings = { ...this.emailSettings, ...consolidatedConfig.email }
						}

						// Load ArchiMate status
						if (consolidatedConfig.archimate) {
							this.archimateStatus = consolidatedConfig.archimate
							this.isImportRunning = consolidatedConfig.archimate.import?.status === 'running'
							this.isExportRunning = consolidatedConfig.archimate.export?.status === 'running'

							// Start polling if any operation is running
							if (this.isImportRunning || this.isExportRunning) {
								this.startStatusPolling()
							}
						}

						// Initialize configuration first, then populate register selections
						this.initializeConfiguration()
						this.populateRegisterSelections()
					} else {
						console.error('Settings API error:', settingsData.error)
						this.setError(settingsData.error)
					}
				} else {
					throw new Error(`Settings API failed: ${settingsResponse.status} ${settingsResponse.statusText}`)
				}

			} catch (error) {
				console.error('Failed to load settings:', error)
				this.setError('Failed to load settings: ' + error.message)
				// Set defaults to allow the component to function
				this.settings = {
					openRegisters: this.settings.openRegisters ?? false,
					availableRegisters: [],
					consolidatedConfig: {},
				}
			} finally {
				this.loading = false
				this.loadingVersionInfo = false
			}
		},

		/**
		 * Initialize configuration object
		 */
		initializeConfiguration() {
					// Initialize register-specific configuration
		this.configuration = {
			// AMEF register configuration
			amef_elements: { schema: null },
			amef_organization: { schema: null },
			amef_relationships: { schema: null },
			amef_views: { schema: null },
			amef_models: { schema: null },
			amef_properties: { schema: null },
			// Voorzieningen register configuration
			voorzieningen_organisatie: { schema: null },
			voorzieningen_contactpersoon: { schema: null },
			voorzieningen_gebruiker: { schema: null },
			voorzieningen_contactgegevens: { schema: null },
		}

			// Map consolidated config to our configuration structure
			const consolidatedConfig = this.settings.consolidatedConfig || {}

			// Helper function to find schema label by ID in a register
			const findSchemaLabel = (schemaId, schemas) => {
				if (!schemas || !Array.isArray(schemas)) return `Schema ${schemaId}`
				const schema = schemas.find(s => s.id.toString() === schemaId.toString())
				return schema ? schema.title || schema.name || `Schema ${schemaId}` : `Schema ${schemaId}`
			}

			// Map Voorzieningen schemas
			if (consolidatedConfig.voorzieningen) {
				const voorzieningenConfig = consolidatedConfig.voorzieningen

				if (voorzieningenConfig.organisatie_schema) {
					this.configuration.voorzieningen_organisatie.schema = {
						label: findSchemaLabel(voorzieningenConfig.organisatie_schema, this.voorzieningenSchemas),
						value: voorzieningenConfig.organisatie_schema,
					}
				}
				if (voorzieningenConfig.contactpersoon_schema) {
					this.configuration.voorzieningen_contactpersoon.schema = {
						label: findSchemaLabel(voorzieningenConfig.contactpersoon_schema, this.voorzieningenSchemas),
						value: voorzieningenConfig.contactpersoon_schema,
					}
				}
			}

			// Map AMEF schemas
			if (consolidatedConfig.amef) {
				const amefConfig = consolidatedConfig.amef

				if (amefConfig.organizations_schema) {
					this.configuration.amef_organization.schema = {
						label: findSchemaLabel(amefConfig.organizations_schema, this.amefSchemas),
						value: amefConfig.organizations_schema,
					}
				}
				if (amefConfig.elements_schema) {
					this.configuration.amef_elements.schema = {
						label: findSchemaLabel(amefConfig.elements_schema, this.amefSchemas),
						value: amefConfig.elements_schema,
					}
				}
				if (amefConfig.relationships_schema) {
					this.configuration.amef_relationships.schema = {
						label: findSchemaLabel(amefConfig.relationships_schema, this.amefSchemas),
						value: amefConfig.relationships_schema,
					}
				}
				if (amefConfig.views_schema) {
					this.configuration.amef_views.schema = {
						label: findSchemaLabel(amefConfig.views_schema, this.amefSchemas),
						value: amefConfig.views_schema,
					}
				}
				if (amefConfig.models_schema) {
					this.configuration.amef_models.schema = {
						label: findSchemaLabel(amefConfig.models_schema, this.amefSchemas),
						value: amefConfig.models_schema,
					}
				}
				if (amefConfig.properties_schema) {
					this.configuration.amef_properties.schema = {
						label: findSchemaLabel(amefConfig.properties_schema, this.amefSchemas),
						value: amefConfig.properties_schema,
					}
				}
			}
		},

		/**
		 * Populate register selections from consolidated configuration
		 */
		populateRegisterSelections() {
			const consolidatedConfig = this.settings.consolidatedConfig || {}

			// Check for Voorzieningen register usage
			if (consolidatedConfig.voorzieningen) {
				const voorzieningenConfig = consolidatedConfig.voorzieningen
				const voorzieningenRegisterId = voorzieningenConfig.register?.toString()

				if (voorzieningenRegisterId) {
					const voorzieningenRegister = this.settings.availableRegisters.find(
						r => r.id.toString() === voorzieningenRegisterId,
					)
					if (voorzieningenRegister) {
						this.voorzieningenRegister = {
							label: voorzieningenRegister.title,
							value: voorzieningenRegister.id.toString(),
						}
						this.voorzieningenSchemas = voorzieningenRegister.schemas || []
					}
				}
			}

			// Check for AMEF register usage
			if (consolidatedConfig.amef) {
				const amefConfig = consolidatedConfig.amef
				const amefRegisterId = amefConfig.register_id?.toString() || amefConfig.register?.toString()

				if (amefRegisterId) {
					const amefRegister = this.settings.availableRegisters.find(
						r => r.id.toString() === amefRegisterId,
					)
					if (amefRegister) {
						this.amefRegister = {
							label: amefRegister.title,
							value: amefRegister.id.toString(),
						}
						this.amefSchemas = amefRegister.schemas || []
					}
				}
			}

			// Populate schema selections from consolidated config
			this.populateSchemaSelections()
		},

		/**
		 * Populate schema selections from consolidated configuration
		 */
		populateSchemaSelections() {
			const consolidatedConfig = this.settings.consolidatedConfig || {}

			// Helper function to find schema label by ID in a register
			const findSchemaLabel = (schemaId, schemas) => {
				if (!schemas || !Array.isArray(schemas)) return `Schema ${schemaId}`
				const schema = schemas.find(s => s.id.toString() === schemaId.toString())
				return schema ? schema.title || schema.name || `Schema ${schemaId}` : `Schema ${schemaId}`
			}

			// Populate Voorzieningen schema selections
			if (consolidatedConfig.voorzieningen) {
				const voorzieningenConfig = consolidatedConfig.voorzieningen

				if (voorzieningenConfig.organisatie_schema) {
					this.configuration.voorzieningen_organisatie.schema = {
						label: findSchemaLabel(voorzieningenConfig.organisatie_schema, this.voorzieningenSchemas),
						value: voorzieningenConfig.organisatie_schema,
					}
				}
				if (voorzieningenConfig.contactpersoon_schema) {
					this.configuration.voorzieningen_contactpersoon.schema = {
						label: findSchemaLabel(voorzieningenConfig.contactpersoon_schema, this.voorzieningenSchemas),
						value: voorzieningenConfig.contactpersoon_schema,
					}
				}
				if (voorzieningenConfig.gebruiker_schema) {
					this.configuration.voorzieningen_gebruiker.schema = {
						label: findSchemaLabel(voorzieningenConfig.gebruiker_schema, this.voorzieningenSchemas),
						value: voorzieningenConfig.gebruiker_schema,
					}
				}
				if (voorzieningenConfig.contactgegevens_schema) {
					this.configuration.voorzieningen_contactgegevens.schema = {
						label: findSchemaLabel(voorzieningenConfig.contactgegevens_schema, this.voorzieningenSchemas),
						value: voorzieningenConfig.contactgegevens_schema,
					}
				}
			}

			// Populate AMEF schema selections
			if (consolidatedConfig.amef) {
				const amefConfig = consolidatedConfig.amef

				if (amefConfig.organizations_schema) {
					this.configuration.amef_organization.schema = {
						label: findSchemaLabel(amefConfig.organizations_schema, this.amefSchemas),
						value: amefConfig.organizations_schema,
					}
				}
				if (amefConfig.elements_schema) {
					this.configuration.amef_elements.schema = {
						label: findSchemaLabel(amefConfig.elements_schema, this.amefSchemas),
						value: amefConfig.elements_schema,
					}
				}
				if (amefConfig.relationships_schema) {
					this.configuration.amef_relationships.schema = {
						label: findSchemaLabel(amefConfig.relationships_schema, this.amefSchemas),
						value: amefConfig.relationships_schema,
					}
				}
				if (amefConfig.views_schema) {
					this.configuration.amef_views.schema = {
						label: findSchemaLabel(amefConfig.views_schema, this.amefSchemas),
						value: amefConfig.views_schema,
					}
				}
				if (amefConfig.models_schema) {
					this.configuration.amef_models.schema = {
						label: findSchemaLabel(amefConfig.models_schema, this.amefSchemas),
						value: amefConfig.models_schema,
					}
				}
				if (amefConfig.properties_schema) {
					this.configuration.amef_properties.schema = {
						label: findSchemaLabel(amefConfig.properties_schema, this.amefSchemas),
						value: amefConfig.properties_schema,
					}
				}
				if (amefConfig.property_definitions_schema) {
					this.configuration.amef_property_definitions.schema = {
						label: findSchemaLabel(amefConfig.property_definitions_schema, this.amefSchemas),
						value: amefConfig.property_definitions_schema,
					}
				}
			}
		},

		/**
		 * Import ArchiMate file with proper error handling (async approach)
		 * @return {void}
		 */
		importArchiMateFile() {
			console.log('importArchiMateFile() called')

			if (!this.selectedFile) {
				showError('No file selected for import')
				return
			}

			this.importing = true
			this.importError = null

			// Immediately set import as running and start polling
			// This is the proper async approach - don't wait for server response
			console.log('Setting import status to running and starting polling immediately')
			this.isImportRunning = true
			this.archimateStatus.import = {
				status: 'running',
				current_step: 'Starting import...',
				progress: 0,
				statistics: null,
			}

			// Start polling immediately - this is key for async operations
			console.log('Starting status polling immediately (async approach)')
			this.startStatusPolling()
			showSuccess('ArchiMate import started - monitoring progress...')

			// Now trigger the actual import in the background (fire and forget)
			try {
				console.log('Preparing FormData for import...')
				const formData = new FormData()
				formData.append('archiMateFile', this.selectedFile)
				formData.append('updateExisting', this.importOptions.updateExisting)
				formData.append('deleteOrphaned', this.importOptions.deleteOrphaned)
				formData.append('preserveIds', 'true')

				console.log('FormData prepared, file size:', this.selectedFile.size, 'bytes')
				console.log('Triggering background import request...')

				// Fire the request but don't wait for completion
				fetch('/index.php/apps/softwarecatalog/api/archimate/import', {
					method: 'POST',
					headers: {
						'X-Requested-With': 'XMLHttpRequest',
					},
					body: formData,
				}).then(response => {
					console.log('Background import request completed:', response.status, response.statusText)
					
					// Only handle immediate failures (500 errors)
					if (response.status === 500) {
						console.log('Import failed immediately with 500 error')
						this.stopStatusPolling()
						this.isImportRunning = false
						this.archimateStatus.import = {
							status: 'failed',
							current_step: 'Import failed',
							progress: 0,
							statistics: null,
							error: 'Server error (500)',
						}
						showError('Import failed: Server error. Please try with a smaller file or check server logs.')
					}
					// For all other responses (200, 503, etc.), let polling handle the status
				}).catch(error => {
					console.error('Background import request failed:', error)
					// Only stop polling if it's a network error, not a timeout
					if (error.name !== 'AbortError') {
						this.stopStatusPolling()
						this.isImportRunning = false
						this.archimateStatus.import = {
							status: 'failed',
							current_step: 'Import failed',
							progress: 0,
							statistics: null,
							error: error.message,
						}
						showError('Import failed: ' + error.message)
					}
				})

			} catch (error) {
				console.error('Failed to start import:', error)
				this.stopStatusPolling()
				this.isImportRunning = false
				this.importError = error.message
				showError('Failed to start ArchiMate import: ' + error.message)
			} finally {
				console.log('Import setup complete - setting importing = false')
				this.importing = false
			}
		},

		/**
		 * Start status polling with more frequent initial polls
		 */
		startStatusPolling() {
			console.log('startStatusPolling() called')

			if (this.statusPollingInterval) {
				console.log('Clearing existing polling interval')
				clearInterval(this.statusPollingInterval)
			}

			// Wait a brief moment for server to start the operation, then fetch status
			console.log('Setting up initial status check in 500ms...')
			setTimeout(() => {
				console.log('Running initial status check')
				this.refreshArchiMateStatus()
			}, 500) // Wait 500ms before first poll

			// Poll every 5 seconds
			console.log('Setting up status polling interval (5 seconds)')
			this.statusPollingInterval = setInterval(() => {
				console.log('Status poll (5-second interval)')
				this.refreshArchiMateStatus()
			}, 5000) // Poll every 5 seconds
		},

		/**
		 * Stop status polling
		 */
		stopStatusPolling() {
			console.log('stopStatusPolling() called')
			if (this.statusPollingInterval) {
				console.log('Clearing status polling interval')
				clearInterval(this.statusPollingInterval)
				this.statusPollingInterval = null
			} else {
				console.log('No polling interval to clear')
			}
			// Reset polling lock
			this.isStatusPolling = false
		},

		/**
		 * Refresh ArchiMate status from main settings endpoint
		 * Used for real-time polling during import/export operations
		 * Prevents concurrent calls to avoid stacking requests
		 *
		 * @return {Promise<void>}
		 */
		async refreshArchiMateStatus() {
			// Prevent concurrent status polls
			if (this.isStatusPolling) {
				console.log('Status poll already in progress, skipping this call')
				return
			}

			this.isStatusPolling = true
			console.log('refreshArchiMateStatus() called')

			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings')
				console.log('Settings API response status:', response.status)

				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}

				const data = await response.json()
				console.log('Settings API data:', data)

				if (!data.error && data.consolidatedConfig?.archimate) {
					console.log('Updating ArchiMate status:', data.consolidatedConfig.archimate)
					this.archimateStatus = data.consolidatedConfig.archimate

					// Update running states
					const wasImportRunning = this.isImportRunning
					const wasExportRunning = this.isExportRunning

					this.isImportRunning = data.consolidatedConfig.archimate.import?.status === 'running'
					this.isExportRunning = data.consolidatedConfig.archimate.export?.status === 'running'

					console.log(`Import: ${wasImportRunning} -> ${this.isImportRunning}, Export: ${wasExportRunning} -> ${this.isExportRunning}`)

					// Stop polling if no operations are running
					if (!this.isImportRunning && !this.isExportRunning) {
						console.log('No operations running, stopping polling')
						this.stopStatusPolling()
					}
				} else {
					console.log('No ArchiMate data in response or API error:', data.error)
				}
			} catch (error) {
				console.error('Failed to refresh ArchiMate status:', error)
			} finally {
				// Always release the polling lock
				this.isStatusPolling = false
			}
		},

		/**
		 * Clear ArchiMate import status
		 * @return {Promise<void>}
		 */
		async clearImportStatus() {
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/archimate/status/import/clear', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
				})

				if (response.ok) {
					// Stop polling
					this.stopStatusPolling()

					// Clear frontend state immediately
					this.archimateStatus.import = {
						status: null,
						current_step: null,
						progress: null,
						statistics: null,
					}
					this.isImportRunning = false
					this.importError = null

					// Refresh settings to get updated ArchiMate status
					await this.refreshArchiMateStatus()

					showSuccess('ArchiMate import status cleared successfully')
				} else {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}
			} catch (error) {
				console.error('Failed to clear import status:', error)
				showError('Failed to clear ArchiMate import status: ' + error.message)
			}
		},

		/**
		 * Clear ArchiMate export status
		 * @return {Promise<void>}
		 */
		async clearExportStatus() {
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/archimate/status/export/clear', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
				})

				if (response.ok) {
					// Stop polling
					this.stopStatusPolling()

					// Clear frontend state immediately
					this.archimateStatus.export = {
						status: null,
						current_step: null,
						progress: null,
						statistics: null,
					}
					this.isExportRunning = false
					this.exportError = null

					// Refresh settings to get updated ArchiMate status
					await this.refreshArchiMateStatus()

					showSuccess('ArchiMate export status cleared successfully')
				} else {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}
			} catch (error) {
				console.error('Failed to clear export status:', error)
				showError('Failed to clear ArchiMate export status: ' + error.message)
			}
		},

		/**
		 * Handle Voorzieningen register change
		 * Updates schemas when register selection changes
		 *
		 * @param {object} register Selected register object
		 * @return {void}
		 */
		handleVoorzieningenRegisterChange(register) {
			if (register) {
				const selectedRegister = this.settings.availableRegisters.find(
					r => r.id.toString() === register.value,
				)
				this.voorzieningenSchemas = selectedRegister?.schemas || []
			} else {
				this.voorzieningenSchemas = []
			}
		},

		/**
		 * Handle AMEF register change
		 * Updates schemas when register selection changes
		 *
		 * @param {object} register Selected register object
		 * @return {void}
		 */
		handleAmefRegisterChange(register) {
			if (register) {
				const selectedRegister = this.settings.availableRegisters.find(
					r => r.id.toString() === register.value,
				)
				this.amefSchemas = selectedRegister?.schemas || []
			} else {
				this.amefSchemas = []
			}
		},

		/**
		 * Validate configuration
		 * This method can be expanded to add validation logic
		 *
		 * @return {void}
		 */
		validateConfiguration() {
			// Configuration validation logic can be added here
		},

		/**
		 * Save configuration to backend
		 *
		 * @return {Promise<void>}
		 */
		async saveConfiguration() {
			try {
				const configToSave = {}

				// Save register-specific configuration
				const registerSpecificKeys = [
					'amef_elements',
					'amef_organization',
					'amef_relationships',
					'amef_views',
					'amef_models',
					'amef_properties',
					'amef_property_definitions',
					'voorzieningen_organisatie',
					'voorzieningen_contactpersoon',
				]

				registerSpecificKeys.forEach(configKey => {
					const config = this.configuration[configKey]
					if (config && config.schema) {
						// Always use openregister as source
						configToSave[`${configKey}_source`] = 'openregister'

						// Determine which register to use based on config key
						let registerId = null
						if (configKey.startsWith('voorzieningen_')) {
							registerId = this.voorzieningenRegister?.value
						} else if (configKey.startsWith('amef_')) {
							registerId = this.amefRegister?.value
						}

						// Set the register ID
						if (registerId) {
							configToSave[`${configKey}_register`] = registerId
						}

						// Set the schema ID
						configToSave[`${configKey}_schema`] = config.schema
					}
				})

				// Save user groups configuration
				if (this.genericUserGroups.length > 0 || this.organizationAdminGroups.length > 0 || this.superUserGroups.length > 0) {
					configToSave.userGroups = {
						generic: this.genericUserGroups.filter(group => group && group.trim()),
						organizationAdmin: this.organizationAdminGroups.filter(group => group && group.trim()),
						superUser: this.superUserGroups.filter(group => group && group.trim()),
					}
				}

				// Save email settings
				if (this.emailSettings && Object.keys(this.emailSettings).length > 0) {
					configToSave.email = { ...this.emailSettings }
				}

				// Send to backend
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-Requested-With': 'XMLHttpRequest',
					},
					body: JSON.stringify(configToSave),
				})

				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}

				const result = await response.json()
				if (result.success) {
					showSuccess('Configuration saved successfully')
					// Reload settings to get updated configuration
					await this.loadSettings()
				} else {
					throw new Error(result.message || 'Unknown error occurred')
				}
			} catch (error) {
				console.error('Failed to save configuration:', error)
				showError('Failed to save configuration: ' + error.message)
			}
		},

		/**
		 * Perform consolidated auto-configuration
		 * Sets up the entire application configuration in one operation
		 *
		 * @return {Promise<object>} Configuration result
		 */
		async consolidatedAutoConfigure() {
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings/auto-configure', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-Requested-With': 'XMLHttpRequest',
					},
					body: JSON.stringify({ force: true }),
				})

				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}

				const result = await response.json()

				if (result.success) {
					// Reload settings to reflect the new configuration
					await this.loadSettings()
					showSuccess('Auto-configuration completed successfully')
				} else {
					showError('Auto-configuration failed: ' + (result.message || 'Unknown error'))
				}

				return result
			} catch (error) {
				console.error('Failed to perform auto-configuration:', error)
				const errorResult = {
					success: false,
					message: 'Failed to perform auto-configuration: ' + error.message,
				}
				showError(errorResult.message)
				return errorResult
			}
		},

		/**
		 * Save email settings
		 *
		 * @return {Promise<void>}
		 */
		async saveEmailSettings() {
			try {
				// Use the centralized saveConfiguration method which includes email settings
				await this.saveConfiguration()
			} catch (error) {
				console.error('Failed to save email settings:', error)
				throw error
			}
		},

		/**
		 * Test email connection
		 *
		 * @return {Promise<object>} Test result
		 */
		async testEmailConnection() {
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/email/test', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-Requested-With': 'XMLHttpRequest',
					},
					body: JSON.stringify({
						type: 'connection',
						settings: this.emailSettings,
					}),
				})

				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}

				const result = await response.json()

				if (result.success) {
					showSuccess('Email connection test successful')
				} else {
					showError('Email connection test failed: ' + (result.message || 'Unknown error'))
				}

				return result
			} catch (error) {
				console.error('Connection test failed:', error)
				const errorResult = {
					success: false,
					message: 'Connection test failed: ' + error.message,
				}
				showError(errorResult.message)
				return errorResult
			}
		},

		/**
		 * Send test email
		 *
		 * @param {string} testEmail Test email address
		 * @return {Promise<object>} Test result
		 */
		async sendTestEmail(testEmail = '') {
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/email/test', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-Requested-With': 'XMLHttpRequest',
					},
					body: JSON.stringify({
						type: 'send',
						testEmail: testEmail || this.emailSettings.testReceiverOverride,
						settings: this.emailSettings,
					}),
				})

				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}

				const result = await response.json()

				if (result.success) {
					showSuccess('Test email sent successfully')
				} else {
					showError('Failed to send test email: ' + (result.message || 'Unknown error'))
				}

				return result
			} catch (error) {
				console.error('Failed to send test email:', error)
				const errorResult = {
					success: false,
					message: 'Failed to send test email: ' + error.message,
				}
				showError(errorResult.message)
				return errorResult
			}
		},

		/**
		 * Export to ArchiMate (direct download approach)
		 *
		 * @param {string} format Export format
		 * @return {void}
		 */
		async exportToArchiMate(format = 'xml') {
			this.exporting = true
			this.exportError = null

			try {
				console.log('Starting ArchiMate export, format:', format)

				// Create form data for the request
				const requestData = {
					format,
					includeRelationships: this.exportOptions.includeRelationships ?? true,
					includeViews: this.exportOptions.includeViews ?? true,
					organizationSpecific: false,
					selectedSchemas: [],
				}

				console.log('Export request data:', requestData)

				// Create a temporary link to trigger download
				const link = document.createElement('a')
				link.style.display = 'none'
				document.body.appendChild(link)

				// Fetch the export (which will return a file directly)
				const response = await fetch('/index.php/apps/softwarecatalog/api/archimate/export', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-Requested-With': 'XMLHttpRequest',
					},
					body: JSON.stringify(requestData),
				})

				console.log('Export response status:', response.status, response.statusText)

				if (response.status === 500) {
					// Handle server error
					const errorData = await response.json()
					throw new Error(errorData.message || 'Server error occurred')
				}

				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}

				// Check if response is JSON (error) or file content
				const contentType = response.headers.get('content-type')
				console.log('Response content type:', contentType)

				if (contentType && contentType.includes('application/json')) {
					// Handle JSON error response
					const errorData = await response.json()
					throw new Error(errorData.message || errorData.error || 'Export failed')
				}

				// Handle successful file download
				const blob = await response.blob()
				const url = window.URL.createObjectURL(blob)

				// Get filename from Content-Disposition header or create default
				const contentDisposition = response.headers.get('content-disposition')
				let fileName = `archimate_export_${new Date().toISOString().slice(0, 19).replace(/[:-]/g, '')}.xml`

				if (contentDisposition) {
					const fileNameMatch = contentDisposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/)
					if (fileNameMatch) {
						fileName = fileNameMatch[1].replace(/['"]/g, '')
					}
				}

				console.log('Downloading file:', fileName)

				// Trigger download
				link.href = url
				link.download = fileName
				link.click()

				// Cleanup
				document.body.removeChild(link)
				window.URL.revokeObjectURL(url)

				showSuccess(`Export completed! Downloaded ${fileName}`)

			} catch (error) {
				console.error('Export failed:', error)
				this.exportError = error.message
				showError('Failed to export ArchiMate: ' + error.message)
			} finally {
				console.log('Export completed - setting exporting = false')
				this.exporting = false
			}
		},

		/**
		 * Test ArchiMate round-trip functionality
		 *
		 * @return {Promise<object>} Test result
		 */
		async testRoundTrip() {
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/archimate/test-round-trip', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-Requested-With': 'XMLHttpRequest',
					},
				})

				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}

				const result = await response.json()

				if (result.success) {
					showSuccess('Round-trip test completed successfully')
				} else {
					showError('Round-trip test failed: ' + (result.message || 'Unknown error'))
				}

				return result
			} catch (error) {
				console.error('Round-trip test failed:', error)
				const errorResult = {
					success: false,
					message: 'Round-trip test failed: ' + error.message,
				}
				showError(errorResult.message)
				return errorResult
			}
		},

		/**
		 * Cleanup method to stop polling when store is destroyed
		 */
		cleanup() {
			this.stopStatusPolling()
			this.isStatusPolling = false
		},

		/**
		 * Reset store state
		 */
		reset() {
			this.stopStatusPolling()
			this.isStatusPolling = false
			this.loading = false
			this.saving = false
			this.importing = false
			this.exporting = false
			this.loadingVersionInfo = false
			this.settings = {
				openRegisters: false,
				availableRegisters: [],
				consolidatedConfig: {},
			}
			this.versionInfo = {}
			this.voorzieningenRegister = null
			this.amefRegister = null
			this.voorzieningenSchemas = []
			this.amefSchemas = []
			this.configuration = {}
			this.archimateStatus = {
				import: {},
				export: {},
			}
			this.isImportRunning = false
			this.isExportRunning = false
			this.selectedFile = null
			this.genericUserGroups = []
			this.organizationAdminGroups = []
			this.superUserGroups = []
			this.error = null
			this.importError = null
			this.exportError = null
		},
	},
})
