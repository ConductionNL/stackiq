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
		loadingGeneralSettings: false,
		loadingSyncSettings: false,
		loadingStatistics: false,
		loadingOpenRegisterConfig: false,
		loadingUserGroups: false,
		loadingEmailConfig: false,
		loadingArchiMateStatus: false,
		loadingObjectCounts: false,
		loadingMainSettings: false,

		// Settings data
		settings: {
			availableRegisters: [],
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
			// Extended schemas
			voorzieningen_voorziening: { schema: null },
			voorzieningen_voorziening_aanbod: { schema: null },
			voorzieningen_voorziening_versie: { schema: null },
			voorzieningen_kwetsbaarheid: { schema: null },
			voorzieningen_contract: { schema: null },
			voorzieningen_standaard: { schema: null },
			voorzieningen_review: { schema: null },
			voorzieningen_koppeling: { schema: null },
			voorzieningen_beoordeeling: { schema: null },
			voorzieningen_voorziening_module: { schema: null },
			voorzieningen_verklaring: { schema: null },
			voorzieningen_koppeling_gebruik: { schema: null },
			voorzieningen_compliancy: { schema: null },
			voorzieningen_module_gebruik: { schema: null },
			voorzieningen_module_versie: { schema: null },
			voorzieningen_sector: { schema: null },
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

		// Statistics
		statistics: {
			voorzieningen: {
				config: {},
				object_counts: {},
				configured: false,
			},
			amef: {
				config: {},
				object_counts: {},
				configured: false,
			},
			timestamp: null,
		},
		loadingStats: false,

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
		 * Get formatted statistics for display
		 * @param {object} state - The store state
		 * @return {Array} Array of formatted statistics rows
		 */
		formattedStatistics: (state) => {
			const stats = []
			// Voorzieningen statistics
			if (state.statistics.voorzieningen.configured) {
				const voorzieningenCounts = state.statistics.voorzieningen.object_counts
				const voorzieningenSchemaMap = {
					totalOrganisatieObjects: 'Organisatie',
					totalContactpersoonObjects: 'Contactpersoon',
					totalVoorzieningObjects: 'Voorziening',
					totalVoorzieningAanbodObjects: 'Voorziening Aanbod',
					totalVoorzieningVersieObjects: 'Voorziening Versie',
					totalKwetsbaarheidObjects: 'Kwetsbaarheid',
					totalContractObjects: 'Contract',
					totalStandaardObjects: 'Standaard',
					totalReviewObjects: 'Review',
					totalKoppelingObjects: 'Koppeling',
					totalBeoordeelingObjects: 'Beoordeeling',
					totalVoorzieningModuleObjects: 'Voorziening Module',
					totalVerklaringObjects: 'Verklaring',
					totalKoppelingGebruikObjects: 'Koppeling Gebruik',
					totalCompliancyObjects: 'Compliancy',
					totalModuleGebruikObjects: 'Module Gebruik',
					totalModuleVersieObjects: 'Module Versie',
					totalSectorObjects: 'Sector',
				}
				Object.entries(voorzieningenSchemaMap).forEach(([countKey, displayName]) => {
					stats.push({
						register: 'Voorzieningen',
						type: displayName,
						count: voorzieningenCounts[countKey] || 0,
						configured: true,
					})
				})
			}
			// AMEF statistics
			if (state.statistics.amef.configured) {
				const amefCounts = state.statistics.amef.object_counts
				stats.push({
					register: 'AMEF',
					type: 'Elements',
					count: amefCounts.totalElementObjects || 0,
					configured: true,
				})
				stats.push({
					register: 'AMEF',
					type: 'Organizations',
					count: amefCounts.totalOrganizationObjects || 0,
					configured: true,
				})
				stats.push({
					register: 'AMEF',
					type: 'Relationships',
					count: amefCounts.totalRelationshipsObjects || 0,
					configured: true,
				})
				stats.push({
					register: 'AMEF',
					type: 'Views',
					count: amefCounts.totalViewObjects || 0,
					configured: true,
				})
				stats.push({
					register: 'AMEF',
					type: 'Models',
					count: amefCounts.totalModelObjects || 0,
					configured: true,
				})
				stats.push({
					register: 'AMEF',
					type: 'Properties',
					count: amefCounts.totalPropertyObjects || 0,
					configured: true,
				})
			}
			return stats
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
		 * Load statistics from the objects/counts endpoint
		 * @return {Promise<void>}
		 */
		async loadStatistics() {
			this.loadingStatistics = true

			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/objects/counts')

				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}

				const data = await response.json()

				if (data.success && data.counts) {
					// Update statistics with object counts
					if (data.counts.voorzieningen) {
						this.statistics.voorzieningen.object_counts = data.counts.voorzieningen
						this.statistics.voorzieningen.configured = true
					}
					if (data.counts.amef) {
						this.statistics.amef.object_counts = data.counts.amef
						this.statistics.amef.configured = true
					}
					this.statistics.timestamp = data.counts.timestamp
				} else {
					console.error('Statistics API error:', data.error)
					this.setError(data.error || 'Failed to load statistics')
				}

			} catch (error) {
				console.error('Failed to load statistics:', error)
				this.setError('Failed to load statistics: ' + error.message)
			} finally {
				this.loadingStatistics = false
			}
		},

		/**
		 * Load all settings from the API
		 */
		async loadSettings() {
			// Prevent multiple simultaneous calls
			if (this.loading) {
				return
			}
			this.loading = true
			this.loadingMainSettings = true
			this.clearError()

			try {
				// Load basic settings first (minimal data)
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings')
				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}
				const data = await response.json()
				if (data.success !== false) {
					// Basic app settings
					this.settings.availableRegisters = data.availableRegisters || []
					this.settings.catalogLocation = data.catalogLocation || ''
					this.settings.syncTimeWindow = data.syncTimeWindow || 10
					this.versionInfo = data.versionInfo || {}
					this.isFullyConfigured = data.isFullyConfigured || false
					this.configurationStatus = data.configurationStatus || {}
					// Initialize base configuration containers
					this.initializeConfiguration()
					// Load focused data from separate endpoints in parallel (don't wait for them)
					Promise.all([
						this.loadVersionInfo(),
						this.loadArchiMateStatus(),
						this.loadObjectCounts(),
						this.loadEmailConfig(),
						this.loadUserGroupsConfig(),
						this.loadAmefConfig(),
						this.loadVoorzieningenConfig(),
						this.loadGeneralConfig(),
						this.loadSyncConfig(),
					]).then(() => {
						// After focused loads, map register selections and schema choices from their configs
						this.populateRegisterSelectionsFromFocused()
						this.populateSchemaSelectionsFromFocused()
					}).catch(error => {
						console.error('Some focused endpoints failed to load:', error)
					})
				} else {
					this.setError(data.error || 'Failed to load settings')
				}
			} catch (error) {
				this.setError('Failed to load settings: ' + error.message)
			} finally {
				this.loading = false
				this.loadingMainSettings = false
			}
		},

		/**
		 * Update catalog location setting
		 *
		 * @param {string} catalogLocation - The new catalog location URL
		 * @return {Promise<void>}
		 */
		async updateCatalogLocation(catalogLocation) {
			try {
				this.settings.catalogLocation = catalogLocation
			} catch (error) {
				console.error('Failed to update catalog location in store:', error)
				throw error
			}
		},

		/**
		 * Update sync time window setting
		 *
		 * @param {number} syncTimeWindow - The new sync time window value
		 * @return {Promise<void>}
		 */
		async updateSyncTimeWindow(syncTimeWindow) {
			try {
				this.settings.syncTimeWindow = syncTimeWindow
			} catch (error) {
				console.error('Failed to update sync time window in store:', error)
				throw error
			}
		},

		/**
		 * Load general configuration from focused endpoint
		 *
		 * @return {Promise<void>}
		 */
		async loadGeneralConfig() {
			this.loadingGeneralSettings = true
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings/general/config', {
					headers: {
						'X-Requested-With': 'XMLHttpRequest',
					},
				})

				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}

				const data = await response.json()
				if (data.success && data.config) {
					this.settings.catalogLocation = data.config.catalogLocation || ''
				}
			} catch (error) {
				console.error('Failed to load general config:', error)
			} finally {
				this.loadingGeneralSettings = false
			}
		},

		/**
		 * Load organization synchronization configuration from focused endpoint
		 *
		 * @return {Promise<void>}
		 */
		async loadSyncConfig() {
			this.loadingSyncSettings = true
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings/sync/config', {
					headers: {
						'X-Requested-With': 'XMLHttpRequest',
					},
				})

				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}

				const data = await response.json()
				if (data.success && data.config) {
					this.settings.syncTimeWindow = parseInt(data.config.syncTimeWindow) || 10
				}
			} catch (error) {
				console.error('Failed to load sync config:', error)
			} finally {
				this.loadingSyncSettings = false
			}
		},

		/**
		 * Load version information from focused endpoint
		 */
		async loadVersionInfo() {
			this.loadingVersionInfo = true
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings/version')
				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}
				const data = await response.json()
				if (data.success !== false) {
					this.versionInfo = data
				}
			} catch (error) {
				// ignore
			} finally {
				this.loadingVersionInfo = false
			}
		},

		/**
		 * Load ArchiMate status from focused endpoint
		 */
		async loadArchiMateStatus() {
			this.loadingArchiMateStatus = true
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/archimate/status')
				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}
				const data = await response.json()
				if (data.success && data.status) {
					this.archimateStatus = data.status
					this.isImportRunning = data.status.import?.status === 'running'
					this.isExportRunning = data.status.export?.status === 'running'
					if (!this.isImportRunning && !this.isExportRunning) {
						this.stopStatusPolling()
					}
				}
			} catch (error) {
				// ignore
			} finally {
				this.loadingArchiMateStatus = false
			}
		},

		/**
		 * Load object counts from focused endpoint
		 */
		async loadObjectCounts() {
			this.loadingObjectCounts = true
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/objects/counts')
				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}
				const data = await response.json()
				if (data.success && data.counts) {
					if (data.counts.voorzieningen) {
						this.statistics.voorzieningen.object_counts = data.counts.voorzieningen
					}
					if (data.counts.amef) {
						this.statistics.amef.object_counts = data.counts.amef
					}
					this.statistics.timestamp = data.counts.timestamp
				}
			} catch (error) {
				// ignore
			} finally {
				this.loadingObjectCounts = false
			}
		},

		/**
		 * Load email configuration from focused endpoint
		 */
		async loadEmailConfig() {
			this.loadingEmailConfig = true
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/email/config')
				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}
				const data = await response.json()
				if (data.success && data.emailSettings) {
					this.emailSettings = data.emailSettings
					this.emailTemplates = data.emailTemplates || {}
				}
			} catch (error) {
				// ignore
			} finally {
				this.loadingEmailConfig = false
			}
		},

		/**
		 * Load user groups configuration from focused endpoint
		 */
		async loadUserGroupsConfig() {
			this.loadingUserGroups = true
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/user-groups/config')
				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}
				const data = await response.json()
				if (data.success && data.config) {
					this.userGroups = {
						generic: data.config.generic || [],
						organizationAdmin: data.config.organizationAdmin || [],
						superUser: data.config.superUser || [],
					}
					this.allGroups = data.config.allGroups || []
					// Populate top-level arrays used by components
					this.genericUserGroups = [...(data.config.generic || [])]
					this.organizationAdminGroups = [...(data.config.organizationAdmin || [])]
					this.superUserGroups = [...(data.config.superUser || [])]
				}
			} catch (error) {
				// ignore
			} finally {
				this.loadingUserGroups = false
			}
		},

		/**
		 * Load only user groups configuration (for individual component refresh)
		 */
		async loadUserGroupsOnly() {
			const response = await fetch('/index.php/apps/softwarecatalog/api/user-groups/config')
			if (!response.ok) {
				throw new Error(`HTTP ${response.status}: ${response.statusText}`)
			}
			const data = await response.json()
			if (data.success && data.config) {
				this.userGroups = {
					generic: data.config.generic || [],
					organizationAdmin: data.config.organizationAdmin || [],
					superUser: data.config.superUser || [],
				}
				this.allGroups = data.config.allGroups || []
				// Populate top-level arrays used by components
				this.genericUserGroups = [...(data.config.generic || [])]
				this.organizationAdminGroups = [...(data.config.organizationAdmin || [])]
				this.superUserGroups = [...(data.config.superUser || [])]
			}
		},

		/**
		 * Load AMEF configuration from focused endpoint
		 */
		async loadAmefConfig() {
			this.loadingOpenRegisterConfig = true
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/amef/config')
				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}
				const data = await response.json()
				if (data.success && data.config) {
					// Store raw AMEF config for mapping
					this.amefRawConfig = data.config
				}
			} catch (error) {
				// ignore
			} finally {
				this.loadingOpenRegisterConfig = false
			}
		},

		/**
		 * Load Voorzieningen configuration from focused endpoint
		 */
		async loadVoorzieningenConfig() {
			this.loadingOpenRegisterConfig = true
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/voorzieningen/config')
				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}
				const data = await response.json()
				if (data.success && data.config) {
					// Store raw Voorzieningen config for mapping
					this.voorzieningenRawConfig = data.config
				}
			} catch (error) {
				// ignore
			} finally {
				this.loadingOpenRegisterConfig = false
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
				amef_property_definitions: { schema: null },
				// Voorzieningen register configuration
				voorzieningen_organisatie: { schema: null },
				voorzieningen_contactpersoon: { schema: null },
				voorzieningen_voorziening: { schema: null },
				voorzieningen_voorziening_aanbod: { schema: null },
				voorzieningen_voorziening_versie: { schema: null },
				voorzieningen_kwetsbaarheid: { schema: null },
				voorzieningen_contract: { schema: null },
				voorzieningen_standaard: { schema: null },
				voorzieningen_review: { schema: null },
				voorzieningen_koppeling: { schema: null },
				voorzieningen_beoordeeling: { schema: null },
				voorzieningen_voorziening_module: { schema: null },
				voorzieningen_verklaring: { schema: null },
				voorzieningen_koppeling_gebruik: { schema: null },
				voorzieningen_compliancy: { schema: null },
				voorzieningen_module_gebruik: { schema: null },
				voorzieningen_module_versie: { schema: null },
				voorzieningen_sector: { schema: null },
			}
		},

		/**
		 * Populate register selections using the focused endpoint configs
		 */
		populateRegisterSelectionsFromFocused() {
			// Voorzieningen register
			if (this.voorzieningenRawConfig && this.voorzieningenRawConfig.register) {
				const regId = this.voorzieningenRawConfig.register.toString()
				const reg = this.settings.availableRegisters.find(r => r.id.toString() === regId)
				if (reg) {
					this.voorzieningenRegister = {
						label: reg.title || reg.name || `Register ${reg.id}`,
						value: reg.id.toString(),
					}
					this.voorzieningenSchemas = reg.schemas || []
				}
			}
			// AMEF register (singular key only; fallback kept for robustness)
			if (this.amefRawConfig && (this.amefRawConfig.register || this.amefRawConfig.register_id)) {
				const regId = (this.amefRawConfig.register || this.amefRawConfig.register_id).toString()
				const reg = this.settings.availableRegisters.find(r => r.id.toString() === regId)
				if (reg) {
					this.amefRegister = {
						label: reg.title || reg.name || `Register ${reg.id}`,
						value: reg.id.toString(),
					}
					this.amefSchemas = reg.schemas || []
				}
			}
		},

		/**
		 * Populate schema selections using the focused endpoint configs
		 */
		populateSchemaSelectionsFromFocused() {
			const findOption = (schemaId, options) => {
				if (!schemaId || !options || !Array.isArray(options)) return null
				const id = schemaId.toString()
				return options.find(o => o && o.value && o.value.toString() === id) || null
			}

			// Voorzieningen schemas
			const vc = this.voorzieningenRawConfig || {}
			const vMap = [
				['organisatie_schema', 'voorzieningen_organisatie'],
				['contactpersoon_schema', 'voorzieningen_contactpersoon'],
				['voorziening_schema', 'voorzieningen_voorziening'],
				['voorziening_aanbod_schema', 'voorzieningen_voorziening_aanbod'],
				['voorziening_versie_schema', 'voorzieningen_voorziening_versie'],
				['kwetsbaarheid_schema', 'voorzieningen_kwetsbaarheid'],
				['contract_schema', 'voorzieningen_contract'],
				['standaard_schema', 'voorzieningen_standaard'],
				['review_schema', 'voorzieningen_review'],
				['koppeling_schema', 'voorzieningen_koppeling'],
				['beoordeeling_schema', 'voorzieningen_beoordeeling'],
				['voorziening_module_schema', 'voorzieningen_voorziening_module'],
				['verklaring_schema', 'voorzieningen_verklaring'],
				['koppeling_gebruik_schema', 'voorzieningen_koppeling_gebruik'],
				['compliancy_schema', 'voorzieningen_compliancy'],
				['module_gebruik_schema', 'voorzieningen_module_gebruik'],
				['module_versie_schema', 'voorzieningen_module_versie'],
				['sector_schema', 'voorzieningen_sector'],
			]
			vMap.forEach(([cfgKey, uiKey]) => {
				if (vc[cfgKey]) {
					const opt = findOption(vc[cfgKey], this.voorzieningenSchemaOptions)
					if (opt) {
						this.configuration[uiKey].schema = opt
					}
				}
			})

			// AMEF schemas (singular keys)
			const ac = this.amefRawConfig || {}
			if (ac.organization_schema || ac.organizations_schema) {
				const opt = findOption((ac.organization_schema || ac.organizations_schema), this.amefSchemaOptions)
				if (opt) this.configuration.amef_organization.schema = opt
			}
			if (ac.element_schema || ac.elements_schema) {
				const opt = findOption((ac.element_schema || ac.elements_schema), this.amefSchemaOptions)
				if (opt) this.configuration.amef_elements.schema = opt
			}
			if (ac.relation_schema || ac.relationships_schema) {
				const opt = findOption((ac.relation_schema || ac.relationships_schema), this.amefSchemaOptions)
				if (opt) this.configuration.amef_relationships.schema = opt
			}
			if (ac.view_schema || ac.views_schema) {
				const opt = findOption((ac.view_schema || ac.views_schema), this.amefSchemaOptions)
				if (opt) this.configuration.amef_views.schema = opt
			}
			if (ac.model_schema || ac.models_schema) {
				const opt = findOption((ac.model_schema || ac.models_schema), this.amefSchemaOptions)
				if (opt) this.configuration.amef_models.schema = opt
			}
			if (ac.property_schema || ac.properties_schema) {
				const opt = findOption((ac.property_schema || ac.properties_schema), this.amefSchemaOptions)
				if (opt) this.configuration.amef_properties.schema = opt
			}
			if (ac['property-definition_schema'] || ac.property_definitions_schema) {
				const opt = findOption((ac['property-definition_schema'] || ac.property_definitions_schema), this.amefSchemaOptions)
				if (opt) this.configuration.amef_property_definitions.schema = opt
			}
		},

		/**
		 * Import ArchiMate file with proper error handling (async approach)
		 * @param {('speed'|'memory')} processingMode Processing strategy
		 * @return {void}
		 */
		importArchiMateFile(processingMode = 'speed') {
			if (!this.selectedFile) {
				showError('No file selected for import')
				return
			}
			this.importing = true
			this.importError = null
			this.isImportRunning = true
			this.archimateStatus.import = {
				status: 'running',
				current_step: 'Starting import...',
				progress: 0,
				statistics: null,
			}
			this.startStatusPolling()
			showSuccess('ArchiMate import started - monitoring progress...')
			try {
				const formData = new FormData()
				formData.append('archiMateFile', this.selectedFile)
				formData.append('updateExisting', this.importOptions.updateExisting)
				formData.append('deleteOrphaned', this.importOptions.deleteOrphaned)
				formData.append('preserveIds', 'true')
				formData.append('processingMode', processingMode)
				fetch('/index.php/apps/softwarecatalog/api/archimate/import', {
					method: 'POST',
					headers: { 'X-Requested-With': 'XMLHttpRequest' },
					body: formData,
				}).then(response => {
					if (response.status === 500) {
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
				}).catch(error => {
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
				this.stopStatusPolling()
				this.isImportRunning = false
				this.importError = error.message
				showError('Failed to start ArchiMate import: ' + error.message)
			} finally {
				this.importing = false
			}
		},

		/**
		 * Start status polling with more frequent initial polls
		 */
		startStatusPolling() {
			if (this.statusPollingInterval) {
				clearInterval(this.statusPollingInterval)
			}
			setTimeout(() => {
				this.refreshArchiMateStatus()
			}, 500)
			this.statusPollingInterval = setInterval(() => {
				this.refreshArchiMateStatus()
			}, 5000)
		},

		/**
		 * Stop status polling
		 */
		stopStatusPolling() {
			if (this.statusPollingInterval) {
				clearInterval(this.statusPollingInterval)
				this.statusPollingInterval = null
			}
			this.isStatusPolling = false
		},

		/**
		 * Refresh ArchiMate status from dedicated ArchiMate endpoint
		 * Used for real-time polling during import/export operations
		 * Prevents concurrent calls to avoid stacking requests
		 *
		 * @return {Promise<void>}
		 */
		async refreshArchiMateStatus() {
			if (this.isStatusPolling) {
				return
			}
			this.isStatusPolling = true
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/archimate/status')
				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}
				const data = await response.json()
				if (data.success && data.status) {
					this.archimateStatus = data.status
					this.isImportRunning = data.status.import?.status === 'running'
					this.isExportRunning = data.status.export?.status === 'running'
					if (!this.isImportRunning && !this.isExportRunning) {
						this.stopStatusPolling()
					}
				}
			} catch (error) {
				// ignore
			} finally {
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
		 * Save configuration to backend using focused endpoints
		 *
		 * @return {Promise<void>}
		 */
		async saveConfiguration() {
			try {
				// Save configurations to their respective focused endpoints
				const savePromises = []

				// Save AMEF configuration (clean payload)
				const amefConfig = {}
				const amefKeys = [
					'amef_elements',
					'amef_organization',
					'amef_relationships',
					'amef_views',
					'amef_models',
					'amef_properties',
					'amef_property_definitions',
				]
				// Map UI keys to API keys
				const amefMap = {
					amef_organization: 'organization_schema',
					amef_elements: 'element_schema',
					amef_relationships: 'relation_schema',
					amef_views: 'view_schema',
					amef_models: 'model_schema',
					amef_properties: 'property_schema',
					amef_property_definitions: 'property-definition_schema',
				}
				if (this.amefRegister?.value) {
					amefConfig.register = this.amefRegister.value
				}
				amefKeys.forEach(configKey => {
					const config = this.configuration[configKey]
					if (config && config.schema) {
						amefConfig[amefMap[configKey]] = config.schema.value || config.schema
					}
				})

				if (Object.keys(amefConfig).length > 0) {
					savePromises.push(
						fetch('/index.php/apps/softwarecatalog/api/amef/config', {
							method: 'POST',
							headers: {
								'Content-Type': 'application/json',
								'X-Requested-With': 'XMLHttpRequest',
							},
							body: JSON.stringify(amefConfig),
						}),
					)
				}

				// Save Voorzieningen configuration (clean payload)
				const voorzieningenConfig = {}
				const voorzieningenKeys = [
					'voorzieningen_organisatie',
					'voorzieningen_contactpersoon',
					'voorzieningen_voorziening',
					'voorzieningen_voorziening_aanbod',
					'voorzieningen_voorziening_versie',
					'voorzieningen_kwetsbaarheid',
					'voorzieningen_contract',
					'voorzieningen_standaard',
					'voorzieningen_review',
					'voorzieningen_koppeling',
					'voorzieningen_beoordeeling',
					'voorzieningen_voorziening_module',
					'voorzieningen_verklaring',
					'voorzieningen_koppeling_gebruik',
					'voorzieningen_compliancy',
					'voorzieningen_module_gebruik',
					'voorzieningen_module_versie',
					'voorzieningen_sector',
				]
				// Map UI keys to API keys
				const vzMap = {
					voorzieningen_organisatie: 'organisatie_schema',
					voorzieningen_contactpersoon: 'contactpersoon_schema',
					voorzieningen_voorziening: 'voorziening_schema',
					voorzieningen_voorziening_aanbod: 'voorziening_aanbod_schema',
					voorzieningen_voorziening_versie: 'voorziening_versie_schema',
					voorzieningen_kwetsbaarheid: 'kwetsbaarheid_schema',
					voorzieningen_contract: 'contract_schema',
					voorzieningen_standaard: 'standaard_schema',
					voorzieningen_review: 'review_schema',
					voorzieningen_koppeling: 'koppeling_schema',
					voorzieningen_beoordeeling: 'beoordeeling_schema',
					voorzieningen_voorziening_module: 'voorziening_module_schema',
					voorzieningen_verklaring: 'verklaring_schema',
					voorzieningen_koppeling_gebruik: 'koppeling_gebruik_schema',
					voorzieningen_compliancy: 'compliancy_schema',
					voorzieningen_module_gebruik: 'module_gebruik_schema',
					voorzieningen_module_versie: 'module_versie_schema',
					voorzieningen_sector: 'sector_schema',
				}
				if (this.voorzieningenRegister?.value) {
					voorzieningenConfig.register = this.voorzieningenRegister.value
				}
				voorzieningenKeys.forEach(configKey => {
					const config = this.configuration[configKey]
					if (config && config.schema) {
						voorzieningenConfig[vzMap[configKey]] = config.schema.value || config.schema
					}
				})

				if (Object.keys(voorzieningenConfig).length > 0) {
					savePromises.push(
						fetch('/index.php/apps/softwarecatalog/api/voorzieningen/config', {
							method: 'POST',
							headers: {
								'Content-Type': 'application/json',
								'X-Requested-With': 'XMLHttpRequest',
							},
							body: JSON.stringify(voorzieningenConfig),
						}),
					)
				}

				// Save user groups configuration
				if (this.genericUserGroups.length > 0 || this.organizationAdminGroups.length > 0 || this.superUserGroups.length > 0) {
					const userGroupsConfig = {
						generic: this.genericUserGroups.filter(group => group && group.trim()),
						organizationAdmin: this.organizationAdminGroups.filter(group => group && group.trim()),
						superUser: this.superUserGroups.filter(group => group && group.trim()),
					}

					savePromises.push(
						fetch('/index.php/apps/softwarecatalog/api/user-groups/config', {
							method: 'POST',
							headers: {
								'Content-Type': 'application/json',
								'X-Requested-With': 'XMLHttpRequest',
							},
							body: JSON.stringify(userGroupsConfig),
						}),
					)
				}

				// Save email settings
				if (this.emailSettings && Object.keys(this.emailSettings).length > 0) {
					savePromises.push(
						fetch('/index.php/apps/softwarecatalog/api/email/config', {
							method: 'POST',
							headers: {
								'Content-Type': 'application/json',
								'X-Requested-With': 'XMLHttpRequest',
							},
							body: JSON.stringify(this.emailSettings),
						}),
					)
				}

				// Save general settings (catalog location)
				if (this.settings.catalogLocation !== undefined) {
					savePromises.push(
						fetch('/index.php/apps/softwarecatalog/api/settings/general/config', {
							method: 'POST',
							headers: {
								'Content-Type': 'application/json',
								'X-Requested-With': 'XMLHttpRequest',
							},
							body: JSON.stringify({
								catalogLocation: this.settings.catalogLocation,
							}),
						}),
					)
				}

				// Save organization synchronization settings
				if (this.settings.syncTimeWindow !== undefined) {
					savePromises.push(
						fetch('/index.php/apps/softwarecatalog/api/settings/sync/config', {
							method: 'POST',
							headers: {
								'Content-Type': 'application/json',
								'X-Requested-With': 'XMLHttpRequest',
							},
							body: JSON.stringify({
								syncTimeWindow: this.settings.syncTimeWindow,
							}),
						}),
					)
				}



				// Execute all save operations
				if (savePromises.length > 0) {
					const responses = await Promise.all(savePromises)
					// Check all responses
					for (const response of responses) {
						if (!response.ok) {
							throw new Error(`HTTP ${response.status}: ${response.statusText}`)
						}
						const result = await response.json()
						if (!result.success) {
							throw new Error(result.message || 'Unknown error occurred')
						}
					}

					showSuccess('Configuration saved successfully')
					// Reload settings to get updated configuration
					await this.loadSettings()
				} else {
					showSuccess('No configuration changes to save')
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
		 * Reset auto-configuration flag and optionally schema/register keys
		 * Calls POST /api/settings/reset-auto-config
		 * @return {Promise<object>} Result
		 */
		async resetAutoConfig() {
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings/reset-auto-config', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-Requested-With': 'XMLHttpRequest',
					},
					body: JSON.stringify({ resetConfiguration: false }),
				})
				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}
				const result = await response.json()
				// Refresh version info after reset
				await this.loadVersionInfo()
				return result
			} catch (error) {
				return { success: false, message: error.message }
			}
		},

		/**
		 * Force update: forced import + version sync
		 * Calls POST /api/settings/force-update
		 * @return {Promise<object>} Result
		 */
		async forceUpdate() {
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings/force-update', {
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
				// Reload all settings so UI reflects the new configuration fully
				await this.loadSettings()
				return result
			} catch (error) {
				return { success: false, message: error.message }
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
				const requestData = {
					format,
					includeRelationships: this.exportOptions.includeRelationships ?? true,
					includeViews: this.exportOptions.includeViews ?? true,
					organizationSpecific: false,
					selectedSchemas: [],
				}
				const link = document.createElement('a')
				link.style.display = 'none'
				document.body.appendChild(link)
				const response = await fetch('/index.php/apps/softwarecatalog/api/archimate/export', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
					body: JSON.stringify(requestData),
				})
				if (response.status === 500) {
					const errorData = await response.json()
					throw new Error(errorData.message || 'Server error occurred')
				}
				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}
				const contentType = response.headers.get('content-type')
				if (contentType && contentType.includes('application/json')) {
					const errorData = await response.json()
					throw new Error(errorData.message || errorData.error || 'Export failed')
				}
				const blob = await response.blob()
				const url = window.URL.createObjectURL(blob)
				const contentDisposition = response.headers.get('content-disposition')
				let fileName = `archimate_export_${new Date().toISOString().slice(0, 19).replace(/[:-]/g, '')}.xml`
				if (contentDisposition) {
					const fileNameMatch = contentDisposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/)
					if (fileNameMatch) {
						fileName = fileNameMatch[1].replace(/['"]/g, '')
					}
				}
				link.href = url
				link.download = fileName
				link.click()
				document.body.removeChild(link)
				window.URL.revokeObjectURL(url)
				showSuccess(`Export completed! Downloaded ${fileName}`)
			} catch (error) {
				this.exportError = error.message
				showError('Failed to export ArchiMate: ' + error.message)
			} finally {
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
				availableRegisters: [],
			}
			this.versionInfo = {}
			this.voorzieningenRegister = null
			this.amefRegister = null
			this.voorzieningenSchemas = []
			this.amefSchemas = []
			this.configuration = {
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
				// Extended schemas
				voorzieningen_voorziening: { schema: null },
				voorzieningen_voorziening_aanbod: { schema: null },
				voorzieningen_voorziening_versie: { schema: null },
				voorzieningen_kwetsbaarheid: { schema: null },
				voorzieningen_contract: { schema: null },
				voorzieningen_standaard: { schema: null },
				voorzieningen_review: { schema: null },
				voorzieningen_koppeling: { schema: null },
				voorzieningen_beoordeeling: { schema: null },
				voorzieningen_voorziening_module: { schema: null },
				voorzieningen_verklaring: { schema: null },
				voorzieningen_koppeling_gebruik: { schema: null },
				voorzieningen_compliancy: { schema: null },
				voorzieningen_module_gebruik: { schema: null },
				voorzieningen_module_versie: { schema: null },
				voorzieningen_sector: { schema: null },
			}
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
			this.statistics = {
				voorzieningen: {
					config: {},
					object_counts: {},
					configured: false,
				},
				amef: {
					config: {},
					object_counts: {},
					configured: false,
				},
				timestamp: null,
			}
			this.loadingStats = false
			this.error = null
			this.importError = null
			this.exportError = null
		},
	},
})
