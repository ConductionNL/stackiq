import { defineStore } from 'pinia'

/**
 * @typedef {object} Schema
 * @property {number} id - The schema ID
 * @property {string} uuid - The schema UUID
 * @property {string} slug - The schema slug
 * @property {string} title - The schema title
 * @property {object} properties - The schema properties
 */

/**
 * @typedef {object} Settings
 * @property {Array<string>} objectTypes - Available object types
 * @property {object} configuration - Configuration settings
 */

/** @typedef {{[key: string]: any}} ObjectState */
/** @typedef {ReturnType<typeof setTimeout>} Timer */

/**
 * @typedef {object} RelatedDataTypes
 * @property {object} logs - Log entries
 * @property {object} uses - Usage records
 * @property {object} used - Where object is used
 * @property {object} files - Associated files
 */

/**
 * Store for managing objects in SoftwareCatalog.
 * @module Store
 * @package
 * @author Ruben Linde
 * @copyright 2024
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @see {@link https://github.com/opencatalogi/softwarecatalog}
 */
export const useObjectStore = defineStore('object', {
	state: () => ({
		/** @type {{objectTypes: Array<string>, configuration: {[key: string]: any}}|null} */
		settings: null,
		/** @type {{[key: string]: {[key: string]: any}}} */
		objects: {},
		/** @type {{[key: string]: {results: Array<any>}}} */
		collections: {},
		/** @type {{[key: string]: boolean}} */
		loading: {},
		/** @type {{[key: string]: string|null}} */
		errors: {},
		/** @type {{[key: string]: any}} */
		activeObjects: {},
		/** @type {{[key: string]: {logs: Array<any>, uses: any, used: any, files: any}}} */
		relatedData: {},
		/** @type {{[key: string]: string}} */
		searchTerms: {},
		/** @type {{[key: string]: ReturnType<typeof setTimeout>|null}} */
		searchDebounceTimers: {},
		/** @type {{[key: string]: {total: number, page: number, pages: number, limit: number, next: string|null, prev: string|null}}} */
		pagination: {},
		/** @type {{[key: string]: boolean|null}} */
		success: {},
		/** @type {{[key: string]: {schema: string, register: string}}} */
		objectTypeRegistry: {},
		/** @type {Array<string>} */
		selectedObjects: [],
		/** @type {{[key: string]: string}} */
		objectErrors: {},
		/** @type {{[key: string]: {label: string, key: string, description: string, enabled: boolean}}} */
		metadata: {
			name: {
				label: 'Name',
				key: 'name',
				description: 'Display name of the object',
				enabled: true,
			},
			description: {
				label: 'Description',
				key: 'description',
				description: 'Description of the object',
				enabled: false,
			},
			objectId: {
				label: 'ID',
				key: 'id',
				description: 'Unique identifier of the object',
				enabled: false,
			},
			uri: {
				label: 'URI',
				key: 'uri',
				description: 'URI of the object',
				enabled: false,
			},
			version: {
				label: 'Version',
				key: 'version',
				description: 'Version of the object',
				enabled: false,
			},
			register: {
				label: 'Register',
				key: 'register',
				description: 'Register of the object',
				enabled: false,
			},
			schema: {
				label: 'Schema',
				key: 'schema',
				description: 'Schema of the object',
				enabled: false,
			},
			files: {
				label: 'Files',
				key: 'files',
				description: 'Attached files count',
				enabled: true,
			},
			locked: {
				label: 'Locked',
				key: 'locked',
				description: 'Whether the object is locked',
				enabled: false,
			},
			organization: {
				label: 'Organization',
				key: 'organization',
				description: 'Organization owning the object',
				enabled: false,
			},
			validation: {
				label: 'Validation',
				key: 'validation',
				description: 'Validation status of the object',
				enabled: false,
			},
			owner: {
				label: 'Owner',
				key: 'owner',
				description: 'Owner of the object',
				enabled: false,
			},
			application: {
				label: 'Application',
				key: 'application',
				description: 'Application of the object',
				enabled: false,
			},
			folder: {
				label: 'Folder',
				key: 'folder',
				description: 'Folder of the object',
				enabled: false,
			},
			geo: {
				label: 'Geo',
				key: 'geo',
				description: 'Geographic information',
				enabled: false,
			},
			retention: {
				label: 'Retention',
				key: 'retention',
				description: 'Retention policy',
				enabled: false,
			},
			size: {
				label: 'Size',
				key: 'size',
				description: 'Size of the object',
				enabled: false,
			},
			published: {
				label: 'Published',
				key: 'published',
				description: 'Publication date',
				enabled: false,
			},
			depublished: {
				label: 'Depublished',
				key: 'depublished',
				description: 'Depublication date',
				enabled: false,
			},
			deleted: {
				label: 'Deleted',
				key: 'deleted',
				description: 'Deletion date',
				enabled: false,
			},
			created: {
				label: 'Created',
				key: 'created',
				description: 'Creation date and time',
				enabled: false,
			},
			updated: {
				label: 'Updated',
				key: 'updated',
				description: 'Last update date and time',
				enabled: false,
			},
		},
		/** @type {{[key: string]: {label: string, key: string, description: string, enabled: boolean}}} */
		properties: {},
		/** @type {{[key: string]: boolean}} */
		columnFilters: {},
	}),

	getters: {
		/**
		 * Get object types from settings
		 * @param {ObjectState} state - Store state
		 * @return {Array<string>}
		 */
		objectTypes: (state) => state.settings?.objectTypes || [],

		/**
		 * Get available registers from settings
		 * @param {ObjectState} state - Store state
		 * @return {Array<{id: string, title: string, schemas: Array<{id: string, title: string}>}>}
		 */
		availableRegisters: (state) => state.settings?.availableRegisters || [],

		/**
		 * Get available schemas from settings
		 * @param {ObjectState} state - Store state
		 * @return {Array<{id: string, title: string, registerId: string, registerTitle: string}>}
		 */
		availableSchemas: (state) => {
			if (!state.settings?.availableRegisters) return []
			return state.settings.availableRegisters.flatMap((register) =>
				register.schemas.map((schema) => ({
					...schema,
					registerId: register.id,
					registerTitle: register.title,
				})),
			)
		},

		/**
		 * Get loading state for specific type
		 * @param {ObjectState} state - Store state
		 * @return {(type: string) => boolean}
		 */
		isLoading: (state) => (type) => state.loading[type] || false,

		/**
		 * Get error for specific type
		 * @param {ObjectState} state - Store state
		 * @return {(type: string) => string|null}
		 */
		getError: (state) => (type) => state.errors[type] || null,

		/**
		 * Get collection for specific type
		 * @param {ObjectState} state - Store state
		 * @return {(type: string) => {results: Array<any>}}
		 */
		getCollection: (state) => (type) => {
			console.info('getCollection called for type:', type, {
				collection: state.collections[type],
				collectionType: typeof state.collections[type],
				hasResults: state.collections[type]?.results?.length > 0,
			})
			return state.collections[type] || { results: [] }
		},

		/**
		 * Get search term for specific type
		 * @param {ObjectState} state - Store state
		 * @return {(type: string) => string}
		 */
		getSearchTerm: (state) => (type) => state.searchTerms[type] || '',

		/**
		 * Get single object
		 * @param {ObjectState} state - Store state
		 * @return {(type: string, id: string) => object | null}
		 */
		getObject: (state) => (type, id) => state.objects[type]?.[id] || null,

		/**
		 * Get active object for type
		 * @param {ObjectState} state - Store state
		 * @return {(type: string) => object | null}
		 */
		getActiveObject: (state) => (type) => state.activeObjects[type] || null,

		/**
		 * Get related data for active object
		 * @param {ObjectState} state - Store state
		 * @return {(type: string, dataType: string) => object | null}
		 */
		getRelatedData: (state) => (type, dataType) =>
			state.relatedData[type]?.[dataType] || null,

		/**
		 * Get pagination info for type
		 * @param {ObjectState} state - Store state
		 * @return {(type: string) => {total: number, page: number, pages: number, limit: number}}
		 */
		getPagination: (state) => (type) => {
			if (state.pagination[type]) {
				return state.pagination[type]
			}
			// Default limit depends on the data type
			const defaultLimit = type.includes('files') ? 500 : 20
			return { total: 0, page: 1, pages: 1, limit: defaultLimit }
		},

		/**
		 * Check if there are more pages to load for type
		 * @param {ObjectState} state - Store state
		 * @return {(type: string) => boolean}
		 */
		hasMorePages: (state) => (type) => {
			const pagination = state.pagination[type]
			return pagination
				? pagination.next !== null || pagination.page < pagination.pages
				: false
		},

		/**
		 * Check if there are previous pages available
		 * @param {ObjectState} state - Store state
		 * @return {(type: string) => boolean}
		 */
		hasPreviousPages: (state) => (type) => {
			const pagination = state.pagination[type]
			return pagination
				? pagination.prev !== null || pagination.page > 1
				: false
		},

		/**
		 * Get audit trails for type
		 * @param {ObjectState} state - Store state
		 * @return {(type: string) => Array<any>}
		 */
		getAuditTrails: (state) => (type) => state.relatedData[type]?.logs || [],

		/**
		 * Get state for specific type
		 * @param {ObjectState} state - Store state
		 * @return {(type: string) => {success: boolean|null, error: string|null}}
		 */
		getState: (state) => (type) => ({
			success: state.success[type] || null,
			error: state.errors[type] || null,
		}),

		/**
		 * Get object type configuration for a schema slug
		 * @param {ObjectState} state - Store state
		 * @return {(slug: string) => {schema: string, register: string}|null}
		 */
		getObjectType: (state) => (slug) => state.objectTypeRegistry[slug] || null,

		/**
		 * Check if an object type exists
		 * @param {ObjectState} state - Store state
		 * @return {(slug: string) => boolean}
		 */
		hasObjectType: (state) => (slug) => !!state.objectTypeRegistry[slug],

		/**
		 * Get enabled metadata columns
		 * @param {ObjectState} state - Store state
		 * @return {Array<{id: string, label: string, key: string, description: string}>}
		 */
		enabledMetadata: (state) => {
			return Object.entries(state.metadata)
				.filter(([_, meta]) => meta.enabled)
				.map(([id, meta]) => ({
					id: `meta_${id}`,
					...meta,
				}))
		},

		/**
		 * Get enabled property columns
		 * @param {ObjectState} state - Store state
		 * @return {Array<{id: string, label: string, key: string, description: string}>}
		 */
		enabledProperties: (state) => {
			return Object.entries(state.properties)
				.filter(([_, prop]) => prop.enabled)
				.map(([key, prop]) => ({
					id: `prop_${key}`,
					key,
					...prop,
				}))
		},

		/**
		 * Get all enabled columns (metadata + properties)
		 * @param {ObjectState} state - Store state
		 * @return {Array<{id: string, label: string, key: string, description: string}>}
		 */
		enabledColumns: (state) => {
			const metadata = Object.entries(state.metadata)
				.filter(([_, meta]) => meta.enabled)
				.map(([id, meta]) => ({
					id: `meta_${id}`,
					...meta,
				}))

			const properties = Object.entries(state.properties)
				.filter(([_, prop]) => prop.enabled)
				.map(([key, prop]) => ({
					id: `prop_${key}`,
					key,
					...prop,
				}))

			return [...metadata, ...properties]
		},

		/**
		 * Check if all objects are selected
		 * @param {ObjectState} state - Store state
		 * @return {boolean}
		 */
		isAllSelected: (state) => {
			const organisatieCollection = state.collections.organisatie
			if (!organisatieCollection?.results?.length) return false
			return organisatieCollection.results.every((org) =>
				state.selectedObjects.includes(org['@self']?.id || org.id),
			)
		},
	},

	actions: {
		/**
		 * Set collection for type
		 * @param {string} type - Object type
		 * @param {Array} results - Collection results
		 * @param {boolean} append - Whether to append results to existing collection
		 */
		setCollection(type, results, append = false) {
			console.info('setCollection called with:', {
				type,
				resultsLength: results?.length,
				append,
				currentCollection: this.collections[type],
				currentCollectionType: typeof this.collections[type],
			})

			// Initialize if needed
			if (!this.collections[type] || !append) {
				console.info('Initializing collection for type:', type)
				this.collections[type] = { results: [] }
			}

			// Update the collection using reactive assignment
			const newResults = append
				? [...(this.collections[type].results || []), ...results]
				: results

			console.info('Setting new results:', {
				newResultsLength: newResults?.length,
				firstItem: newResults?.[0],
			})

			// Use reactive assignment for collections
			this.collections = {
				...this.collections,
				[type]: {
					results: newResults,
				},
			}

			console.info('Collection after update:', {
				type,
				collection: this.collections[type],
				length: this.collections[type]?.results?.length,
			})
		},

		/**
		 * Set loading state for type
		 * @param {string} type - Object type
		 * @param {boolean} isLoading - Loading state
		 */
		setLoading(type, isLoading) {
			this.loading = {
				...this.loading,
				[type]: isLoading,
			}
			console.info('Loading state set:', { type, isLoading })
		},

		/**
		 * Set error for type
		 * @param {string} type - Object type
		 * @param {string|null} error - Error message
		 */
		setError(type, error) {
			this.errors = {
				...this.errors,
				[type]: error,
			}
			if (error) {
				console.error('Error set for type:', type, error)
			}
		},

		/**
		 * Set active object for type and fetch related data
		 * @param {string} type - Object type
		 * @param {object} object - Object to set as active
		 * @return {Promise<void>}
		 */
		async setActiveObject(type, object) {
			console.info('setActiveObject called with:', { type, object })
			// Log the current state before update
			console.info('Current activeObjects state:', { ...this.activeObjects })
			// Update using reactive assignment
			this.activeObjects = {
				...this.activeObjects,
				[type]: object,
			}
			// Log the state after update
			console.info('Updated activeObjects state:', { ...this.activeObjects })

			// Initialize related data structure if not exists
			console.info('Initializing relatedData for type:', type)
			this.relatedData = {
				...this.relatedData,
				[type]: {
					logs: null,
					uses: null,
					used: null,
					files: null,
				},
			}

			// Fetch related data in parallel
			if (object?.id) {
				console.info('Fetching related data for:', {
					type,
					objectId: object.id,
				})

				// For organisaties, extract schema and register info from the object itself
				let organisatieData = null
				if (type === 'organisatie' && object['@self']) {
					organisatieData = {
						source: 'openregister',
						schema: object['@self'].schema,
						register: object['@self'].register,
					}
					console.info('Using organisatie-specific config:', organisatieData)
				}

				const fetchPromises = []
				const dataTypes = ['logs', 'uses', 'used', 'files']
				for (const dataType of dataTypes) {
					if (!this.relatedData[type][dataType]) {
						// Set default limit to 500 for files, 20 for other data types
						const defaultLimit = dataType === 'files' ? 500 : 20
						fetchPromises.push(
							this.fetchRelatedData(
								type,
								object.id,
								dataType,
								{ _limit: defaultLimit, _page: 1 },
								organisatieData,
							),
						)
					}
				}
				await Promise.all(fetchPromises)
				console.info('Finished fetching related data')
			} else {
				console.info('No object ID provided, skipping related data fetch')
			}
			console.info('setActiveObject completed')
		},

		/**
		 * Clear active object for type
		 * @param {string} type - Object type
		 */
		clearActiveObject(type) {
			this.activeObjects = {
				...this.activeObjects,
				[type]: null,
			}
			this.relatedData = {
				...this.relatedData,
				[type]: {
					logs: null,
					uses: null,
					used: null,
					files: null,
				},
			}
		},

		/**
		 * Register a new object type
		 * @param {string} slug - The schema slug to use as type identifier
		 * @param {string} schema - The schema ID
		 * @param {string} register - The register ID
		 * @param {boolean} fetchCollection - Whether to fetch the collection immediately
		 * @return {Promise<void>}
		 */
		async registerObjectType(slug, schema, register, fetchCollection = false) {
			if (this.objectTypeRegistry[slug]) {
				console.info(`Object type ${slug} already registered`)
				return
			}

			// Add the object type configuration
			this.objectTypeRegistry = {
				...this.objectTypeRegistry,
				[slug]: { schema, register },
			}

			// Initialize the collection for this type
			if (!this.collections[slug]) {
				this.collections = {
					...this.collections,
					[slug]: { results: [] },
				}
			}

			// Optionally fetch the initial collection
			if (fetchCollection) {
				await this.fetchCollection(slug)
			}
		},

		/**
		 * Unregister an object type
		 * @param {string} slug - The schema slug to unregister
		 */
		unregisterObjectType(slug) {
			if (!this.objectTypeRegistry[slug]) {
				return
			}

			// Remove the object type configuration
			const { [slug]: _, ...remainingTypes } = this.objectTypeRegistry
			this.objectTypeRegistry = remainingTypes

			// Clear associated data
			if (this.collections[slug]) {
				const { [slug]: _, ...remainingCollections } = this.collections
				this.collections = remainingCollections
			}

			if (this.activeObjects[slug]) {
				const { [slug]: _, ...remainingActiveObjects } = this.activeObjects
				this.activeObjects = remainingActiveObjects
			}

			if (this.relatedData[slug]) {
				const { [slug]: _, ...remainingRelatedData } = this.relatedData
				this.relatedData = remainingRelatedData
			}
		},

		/**
		 * Get schema configuration for object type
		 * @param {string} objectType - Type of object
		 * @return {{source: string, schema: string, register: string}}
		 * @throws {Error} If settings not found or invalid configuration
		 */
		getSchemaConfig(objectType) {
			// First check if this is a registered object type
			const objectTypeConfig = this.objectTypeRegistry[objectType]
			if (objectTypeConfig) {
				return {
					source: 'openregister',
					schema: objectTypeConfig.schema,
					register: objectTypeConfig.register,
				}
			}

			// Fall back to settings configuration
			if (!this.settings) {
				throw new Error('Settings not loaded')
			}

			// Check for voorzieningen-specific configuration first (our primary use case)
			if (objectType === 'organisatie') {
				const voorzieningenConfig = this.settings.voorzieningen || {}
				if (
					voorzieningenConfig.register
          && voorzieningenConfig.organisatie_schema
				) {
					return {
						source: 'openregister',
						schema: voorzieningenConfig.organisatie_schema,
						register: voorzieningenConfig.register,
					}
				}
			}

			// Check legacy settings format in configuration object
			const config = this.settings.configuration || {}

			// Try voorzieningen-prefixed configuration first
			let source
        = config[`voorzieningen_${objectType}_source`]
        || config[`${objectType}_source`]
			const schema
        = config[`voorzieningen_${objectType}_schema`]
        || config[`${objectType}_schema`]
			const register
        = config[`voorzieningen_${objectType}_register`]
        || config[`${objectType}_register`]
        || config.voorzieningen_register

			// Default to openregister as source
			if (!source) {
				source = 'openregister'
			}

			if (!schema || !register) {
				throw new Error(
					`Invalid configuration for object type: ${objectType}. Schema: ${schema}, Register: ${register}`,
				)
			}

			return { source, schema, register }
		},

		/**
		 * Constructs the API endpoint URL for objects
		 * @param {string} type - Object type
		 * @param {string|null} id - Object ID (optional)
		 * @param {string|null} action - Additional action (e.g., 'logs', 'uses') (optional)
		 * @param {object} params - Query parameters
		 * @param {object|null} organisatieData - Organisation data if used should be provided as object with schema and register keys (optional)
		 * @return {string} The constructed URL
		 * @private
		 */
		_constructApiUrl(
			type,
			id = null,
			action = null,
			params = {},
			organisatieData = null,
		) {
			let config = null
			if (organisatieData) {
				config = organisatieData
			} else {
				config = this.getSchemaConfig(type)
			}
			// Use OpenRegister API directly - objects are stored in OpenRegister, not SoftwareCatalog
			const baseUrl = '/index.php/apps/openregister/api/objects'

			// Ensure register and schema are strings (extract id if they're objects)
			const registerId = typeof config.register === 'object' ? config.register?.id || config.register?.uuid : config.register
			const schemaId = typeof config.schema === 'object' ? config.schema?.id || config.schema?.uuid : config.schema

			// Construct the path with register and schema
			let url = `${baseUrl}/${registerId}/${schemaId}`

			// Add ID and action if provided
			if (id) {
				url += `/${id}`
				if (action) {
					// Special case for audit trails
					if (action === 'logs') {
						url += '/audit-trails'
					} else {
						url += `/${action}`
					}
				}
			}

			// Add pagination and other query parameters
			const queryParams = new URLSearchParams({
				_limit: params._limit || 20,
				_page: params._page || 1,
				...params,
			})

			// Handle _extend parameter - convert comma-separated string to multiple _extend[] parameters
			const extendValue = params._extend || params.extend || '@self.schema'
			if (extendValue) {
				// Split comma-separated extends into individual parameters
				const extendParts = extendValue.split(',').map(part => part.trim())
				extendParts.forEach(part => {
					queryParams.append('_extend[]', part)
				})
			}

			// Remove schema and register from query params as they're now in the URL
			// Keep _source parameter as it controls database vs index queries
			queryParams.delete('_schema')
			queryParams.delete('_register')
			// Remove the old extend parameters to avoid duplication
			queryParams.delete('extend')
			queryParams.delete('_extend')

			return `${url}?${queryParams}`
		},

		/**
		 * Fetch collection of objects
		 * @param {string} type - Object type
		 * @param {object} params - Query parameters
		 * @param {boolean} append - Whether to append results to existing collection
		 * @return {Promise<void>}
		 */
		async fetchCollection(type, params = {}, append = false) {
			console.info('fetchCollection started:', { type, params, append })
			this.setLoading(type, true)
			this.setState(type, { success: null, error: null })

			try {
				// Ensure settings are loaded first
				if (!this.settings) {
					await this.fetchSettings()
				}

				// Add _extend parameter if not explicitly set
				// For organizations, force database queries to ensure fresh data
				const queryParams = {
					...params,
					_extend: params._extend || params.extend || '@self.schema',
					// Force database queries for organizations to bypass any index caching
					...((type === 'organisatie' || type === 'contactpersoon' || type === 'moduleVersie') && !params._source
						? { _source: 'database' }
						: {}),
				}

				// Log the final URL for debugging
				const apiUrl = this._constructApiUrl(type, null, null, queryParams)
				console.info('fetchCollection API URL:', apiUrl)
				if (type === 'organisatie') {
					console.info('Organization fetch - ensuring database source:', {
						type,
						hasSourceParam: queryParams._source === 'database',
						queryParams,
					})
				}

				const response = await fetch(apiUrl)
				if (!response.ok) throw new Error(`Failed to fetch ${type} collection`)

				const data = await response.json()
				console.info('API Response:', data)

				// Update pagination info - handle both pagination formats
				const paginationInfo = {
					total: data.total || 0,
					page: data.page || 1,
					pages:
            data.pages
            || (data.next ? Math.ceil((data.total || 0) / (data.limit || 20)) : 1),
					limit: data.limit || 20,
					next: data.next || null,
					prev: data.prev || null,
				}

				this.setPagination(type, paginationInfo)

				// Set the collection using the new method
				this.setCollection(type, data.results, append)

				// Update objects cache with extended data
				if (!this.objects[type]) {
					this.objects[type] = {}
				}
				data.results.forEach((item) => {
					this.objects[type][item.id] = { ...item }
				})
			} catch (error) {
				console.error(`Error fetching ${type} collection:`, error)
				this.setState(type, { success: false, error: error.message })
				throw error
			} finally {
				this.setLoading(type, false)
			}
		},

		/**
		 * Fetch single object
		 * @param {string} type - Object type
		 * @param {string} id - Object ID
		 * @param {object} params - Query parameters
		 * @return {Promise<void>}
		 */
		async fetchObject(type, id, params = {}) {
			this.setLoading(`${type}_${id}`, true)
			this.setState(type, { success: null, error: null })

			try {
				// Ensure settings are loaded first
				if (!this.settings) {
					await this.fetchSettings()
				}

				// Add _extend parameter if not explicitly set
				// For organizations, force database queries to ensure fresh data
				const queryParams = {
					...params,
					_extend: params._extend || params.extend || '@self.schema',
					// Force database queries for organizations to bypass any index caching
					...(type === 'organisatie' && !params._source
						? { _source: 'database' }
						: {}),
				}

				// Log the final URL for debugging
				const apiUrl = this._constructApiUrl(type, id, null, queryParams)
				console.info('fetchObject API URL:', apiUrl)
				if (type === 'organisatie') {
					console.info('Organization fetch - ensuring database source:', {
						type,
						id,
						hasSourceParam: queryParams._source === 'database',
						queryParams,
					})
				}

				const response = await fetch(apiUrl)
				if (!response.ok) throw new Error(`Failed to fetch ${type} object`)

				const data = await response.json()
				if (!this.objects[type]) this.objects[type] = {}
				this.objects[type][id] = data

				// If this object is currently active, update it and its related data
				if (this.activeObjects[type]?.id === id) {
					await this.setActiveObject(type, data)
				}
			} catch (error) {
				console.error(`Error fetching ${type} object:`, error)
				this.setState(type, { success: false, error: error.message })
				throw error
			} finally {
				this.setLoading(`${type}_${id}`, false)
			}
		},

		/**
		 * Fetch related data for object
		 * @param {string} type - Object type
		 * @param {string} id - Object ID
		 * @param {string} dataType - Type of related data (logs, uses, used, files)
		 * @param {object} params - Query parameters
		 * @param {object|null} organisatieData - Organisatie data with schema and register info (optional)
		 * @return {Promise<void>}
		 */
		async fetchRelatedData(
			type,
			id,
			dataType,
			params = {},
			organisatieData = null,
		) {
			this.setLoading(`${type}_${id}_${dataType}`, true)
			this.setState(type, { success: null, error: null })

			try {
				// Ensure settings are loaded first
				if (!this.settings) {
					await this.fetchSettings()
				}

				// Add _extend parameter for 'uses' and 'used' data types
				const queryParams = {
					...params,
					...(dataType === 'uses' || dataType === 'used'
						? { _extend: params._extend || params.extend || '@self.schema' }
						: {}),
				}

				const response = await fetch(
					this._constructApiUrl(
						type,
						id,
						dataType,
						queryParams,
						organisatieData,
					),
				)
				if (!response.ok) { throw new Error(`Failed to fetch ${dataType} for ${type}`) }

				const data = await response.json()
				if (!this.relatedData[type]) {
					this.relatedData[type] = {}
				}

				// Update pagination info for related data
				if (data.total !== undefined || data.page !== undefined) {
					const paginationKey = `${type}_${dataType}`
					// Use the limit from the request params if the API doesn't return it
					const requestedLimit = params._limit || params.limit
					// Convert string limits to numbers and provide fallbacks
					const apiLimit = data.limit ? parseInt(data.limit, 10) : null
					const actualLimit
            = apiLimit || requestedLimit || (dataType === 'files' ? 500 : 20)
					const paginationInfo = {
						total: data.total || 0,
						page: data.page || 1,
						pages: data.pages || Math.ceil((data.total || 0) / actualLimit),
						limit: actualLimit,
						next: data.next || null,
						prev: data.prev || null,
					}
					this.setPagination(paginationKey, paginationInfo)
				}

				// For audit trails, store the results array
				if (dataType === 'logs') {
					this.relatedData[type][dataType] = data.results || []
				} else {
					this.relatedData[type][dataType] = data
				}
			} catch (error) {
				console.error(`Error fetching ${dataType} for ${type}:`, error)
				this.setState(type, { success: false, error: error.message })
				throw error
			} finally {
				this.setLoading(`${type}_${id}_${dataType}`, false)
			}
		},

		/**
		 * Fetch and update settings
		 * @return {Promise<void>}
		 */
		async fetchSettings() {
			try {
				// Fetch main settings
				const settingsResponse = await fetch(
					'/index.php/apps/softwarecatalog/api/settings',
				)
				if (!settingsResponse.ok) throw new Error('Failed to fetch settings')
				this.settings = await settingsResponse.json()

				// Fetch voorzieningen-specific configuration for better performance
				try {
					const voorzieningenResponse = await fetch(
						'/index.php/apps/softwarecatalog/api/voorzieningen/config',
					)
					if (voorzieningenResponse.ok) {
						const voorzieningenData = await voorzieningenResponse.json()
						if (voorzieningenData.success && voorzieningenData.config) {
							this.settings.voorzieningen = voorzieningenData.config
						}
					}
				} catch (error) {
					console.warn('Failed to fetch voorzieningen config:', error)
				}

				// After loading settings, initialize voorzieningen object types
				await this.initializeVoorzieningenObjectTypes()
			} catch (error) {
				console.error('Error fetching settings:', error)
				throw error
			}
		},

		/**
		 * Initialize voorzieningen object types from settings
		 * @return {Promise<void>}
		 */
		async initializeVoorzieningenObjectTypes() {
			try {
				console.info(
					'ObjectStore: Initializing voorzieningen object types from settings',
				)
				if (!this.settings?.availableRegisters) {
					console.warn(
						'ObjectStore: No available registers found for voorzieningen initialization',
					)
					return
				}

				// Find the voorzieningen register
				const voorzieningenRegister = this.settings.availableRegisters.find(
					(register) => register.slug === 'voorzieningen',
				)

				if (!voorzieningenRegister?.schemas) {
					console.warn(
						'ObjectStore: No voorzieningen register found or no schemas available',
					)
					return
				}

				let registeredCount = 0

				// Register each schema from the voorzieningen register
				for (const schema of voorzieningenRegister.schemas) {
					console.info(
						`ObjectStore: Registering voorzieningen object type: ${schema.slug}`,
						{
							title: schema.title,
							description: schema.description,
							id: schema.id,
							uuid: schema.uuid,
						},
					)

					// Register the object type using schema ID and voorzieningen register ID
					await this.registerObjectType(
						schema.slug,
						schema.id,
						voorzieningenRegister.id,
					)
					registeredCount++
				}

				console.info(
					`ObjectStore: Registered ${registeredCount} voorzieningen object types from register`,
				)
			} catch (error) {
				console.warn('Failed to initialize voorzieningen object types:', error)
			}
		},

		/**
		 * Create new object
		 * @param {string} type - Object type
		 * @param {object} data - Object data
		 * @return {Promise<object>}
		 */
		async createObject(type, data) {
			this.setLoading(`${type}_create`, true)
			this.setError(`${type}_create`, null)
			this.setState(type, { success: null, error: null })

			try {
				// Ensure settings are loaded first
				if (!this.settings) {
					await this.fetchSettings()
				}

				const response = await fetch(this._constructApiUrl(type), {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify(data),
				})
				if (!response.ok) throw new Error(`Failed to create ${type} object`)

				const newObject = await response.json()
				if (!this.objects[type]) this.objects[type] = {}
				this.objects[type][newObject.id] = newObject

				// Refresh the collection to ensure it's up to date
				await this.fetchCollection(type)

				// Set the active object
				this.setActiveObject(type, newObject)

				// Set success state
				this.setState(type, { success: true, error: null })

				return newObject
			} catch (error) {
				console.error(`Error creating ${type} object:`, error)
				this.setError(`${type}_create`, error.message)
				this.setState(type, { success: false, error: error.message })
				throw error
			} finally {
				this.setLoading(`${type}_create`, false)
			}
		},

		async saveObject(objectItem, { register, schema }) {
			if (!objectItem || !register || !schema) {
				throw new Error('Object item, register and schema are required')
			}

			// Extract IDs from register and schema in case they are objects
			const registerId = this.extractId(register)
			const schemaId = this.extractId(schema)

			if (!registerId || !schemaId) {
				throw new Error('Could not extract register or schema ID')
			}

			const isNewObject = !objectItem['@self']?.id
			const objectId = objectItem['@self']?.id

			// Build endpoint URL
			let endpoint = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}`
			if (!isNewObject && objectId) {
				endpoint += `/${objectId}`
			}

			// Update timestamp
			if (!objectItem['@self']) {
				objectItem['@self'] = {}
			}
			objectItem['@self'].updated = new Date().toISOString()

			try {
				const response = await fetch(endpoint, {
					method: isNewObject ? 'POST' : 'PUT',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify(objectItem),
				})

				if (!response.ok) {
					throw new Error(
						`Failed to save object: ${response.status} ${response.statusText}`,
					)
				}

				const data = await response.json()
				return { response, data }
			} catch (error) {
				console.error('Error saving object:', error)
				throw error
			}
		},

		/**
		 * Update existing object (full replacement)
		 * @param {string} type - Object type
		 * @param {string} id - Object ID
		 * @param {object} data - Updated object data
		 * @return {Promise<object>}
		 */
		async updateObject(type, id, data) {
			this.setLoading(`${type}_${id}`, true)
			this.setError(`${type}_${id}`, null)
			this.setState(type, { success: null, error: null })

			try {
				// Ensure settings are loaded first
				if (!this.settings) {
					await this.fetchSettings()
				}

				const response = await fetch(this._constructApiUrl(type, id), {
					method: 'PUT',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify(data),
				})
				if (!response.ok) throw new Error(`Failed to update ${type} object`)

				const updatedObject = await response.json()
				if (!this.objects[type]) this.objects[type] = {}
				this.objects[type][id] = updatedObject

				// Refresh the collection to ensure it's up to date
				await this.fetchCollection(type)

				// If this is the active object, update it
				if (this.activeObjects[type]?.id === id) {
					this.activeObjects[type] = updatedObject
				}

				// Set success state
				this.setState(type, { success: true, error: null })

				return updatedObject
			} catch (error) {
				console.error(`Error updating ${type} object:`, error)
				this.setError(`${type}_${id}`, error.message)
				this.setState(type, { success: false, error: error.message })
				throw error
			} finally {
				this.setLoading(`${type}_${id}`, false)
			}
		},

		/**
		 * Patch existing object (partial update)
		 * @param {string} type - Object type
		 * @param {string} id - Object ID
		 * @param {object} changes - Object with only the changed properties
		 * @return {Promise<object>}
		 */
		async patchObject(type, id, changes) {
			this.setLoading(`${type}_${id}`, true)
			this.setError(`${type}_${id}`, null)
			this.setState(type, { success: null, error: null })

			try {
				// Ensure settings are loaded first
				if (!this.settings) {
					await this.fetchSettings()
				}

				const response = await fetch(this._constructApiUrl(type, id), {
					method: 'PATCH',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify(changes),
				})
				if (!response.ok) throw new Error(`Failed to patch ${type} object`)

				const updatedObject = await response.json()
				if (!this.objects[type]) this.objects[type] = {}
				this.objects[type][id] = updatedObject

				// Refresh the collection to ensure it's up to date
				await this.fetchCollection(type)

				// If this is the active object, update it
				if (this.activeObjects[type]?.id === id) {
					this.activeObjects[type] = updatedObject
				}

				// Set success state
				this.setState(type, { success: true, error: null })

				return updatedObject
			} catch (error) {
				console.error(`Error patching ${type} object:`, error)
				this.setError(`${type}_${id}`, error.message)
				this.setState(type, { success: false, error: error.message })
				throw error
			} finally {
				this.setLoading(`${type}_${id}`, false)
			}
		},

		/**
		 * Extract ID from a value that can be either a primitive or an object
		 * @param {string|number|object} value - The value to extract ID from
		 * @return {string|number} The extracted ID
		 */
		extractId(value) {
			if (value === null || value === undefined) {
				return value
			}

			// If it's an object, try to get id property
			if (typeof value === 'object') {
				return value.id || value.uuid || value._id
			}

			// If it's a primitive, return as-is
			return value
		},

		/**
		 * Delete object
		 * @param {object} objectItem - Object to delete
		 * @return {Promise<void>}
		 */
		async deleteObject(objectItem) {
			const objectId = objectItem.id || objectItem['@self']?.id
			const register = objectItem['@self']?.register || objectItem.register
			const schema = objectItem['@self']?.schema || objectItem.schema

			if (!objectId || !register || !schema) {
				throw new Error(
					'Object must have id, register, and schema information',
				)
			}

			// Extract IDs from register and schema in case they are objects
			const registerId = this.extractId(register)
			const schemaId = this.extractId(schema)

			if (!registerId || !schemaId) {
				throw new Error('Could not extract register or schema ID')
			}

			this.setLoading(`delete_${objectId}`, true)
			this.setError(`delete_${objectId}`, null)

			try {
				const endpoint = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${objectId}`

				const response = await fetch(endpoint, {
					method: 'DELETE',
				})

				if (!response.ok) {
					throw new Error(
						`Failed to delete object: ${response.status} ${response.statusText}`,
					)
				}

				// Remove from selection if it's currently selected
				const isSelected = this.selectedObjects.some(
					(obj) => (obj.id || obj['@self']?.id) === objectId,
				)
				if (isSelected) {
					const remainingSelected = this.selectedObjects.filter(
						(obj) => (obj.id || obj['@self']?.id) !== objectId,
					)
					this.setSelectedObjects(remainingSelected)
				}

				return true
			} catch (error) {
				console.error('Error deleting object:', error)
				this.setError(`delete_${objectId}`, error.message)
				throw error
			} finally {
				this.setLoading(`delete_${objectId}`, false)
			}
		},

		/**
		 * Publish object
		 * @param {object} objectItem - Object to publish
		 * @return {Promise<object>} The updated object
		 */
		async publishObject(objectItem) {
			const objectId = objectItem.id || objectItem['@self']?.id
			const register = objectItem['@self']?.register || objectItem.register
			const schema = objectItem['@self']?.schema || objectItem.schema

			if (!objectId || !register || !schema) {
				throw new Error(
					'Object must have id, register, and schema information',
				)
			}

			// Extract IDs from register and schema in case they are objects
			const registerId = this.extractId(register)
			const schemaId = this.extractId(schema)

			if (!registerId || !schemaId) {
				throw new Error('Could not extract register or schema ID')
			}

			this.setLoading(`publish_${objectId}`, true)
			this.setError(`publish_${objectId}`, null)

			try {
				const endpoint = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${objectId}/publish`

				const response = await fetch(endpoint, {
					method: 'POST',
				})

				if (!response.ok) {
					throw new Error(
						`Failed to publish object: ${response.status} ${response.statusText}`,
					)
				}

				const updatedObject = await response.json()

				// Update active object if it matches the published object
				const activePublication = this.activeObjects.publication
				if (
					activePublication
          && (activePublication.id === objectId
            || activePublication['@self']?.id === objectId)
				) {
					this.activeObjects = {
						...this.activeObjects,
						publication: updatedObject,
					}
				}

				// Remove from selection if it's currently selected
				const isSelected = this.selectedObjects.some(
					(obj) => (obj.id || obj['@self']?.id) === objectId,
				)
				if (isSelected) {
					const remainingSelected = this.selectedObjects.filter(
						(obj) => (obj.id || obj['@self']?.id) !== objectId,
					)
					this.setSelectedObjects(remainingSelected)
				}

				return updatedObject
			} catch (error) {
				console.error('Error publishing object:', error)
				this.setError(`publish_${objectId}`, error.message)
				throw error
			} finally {
				this.setLoading(`publish_${objectId}`, false)
			}
		},

		/**
		 * Depublish object
		 * @param {object} objectItem - Object to depublish
		 * @return {Promise<object>} The updated object
		 */
		async depublishObject(objectItem) {
			const objectId = objectItem.id || objectItem['@self']?.id
			const register = objectItem['@self']?.register || objectItem.register
			const schema = objectItem['@self']?.schema || objectItem.schema

			if (!objectId || !register || !schema) {
				throw new Error(
					'Object must have id, register, and schema information',
				)
			}

			// Extract IDs from register and schema in case they are objects
			const registerId = this.extractId(register)
			const schemaId = this.extractId(schema)

			if (!registerId || !schemaId) {
				throw new Error('Could not extract register or schema ID')
			}

			this.setLoading(`depublish_${objectId}`, true)
			this.setError(`depublish_${objectId}`, null)

			try {
				const endpoint = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${objectId}/depublish`

				const response = await fetch(endpoint, {
					method: 'POST',
				})

				if (!response.ok) {
					throw new Error(
						`Failed to depublish object: ${response.status} ${response.statusText}`,
					)
				}

				const updatedObject = await response.json()

				// Update active object if it matches the depublished object
				const activePublication = this.activeObjects.publication
				if (
					activePublication
          && (activePublication.id === objectId
            || activePublication['@self']?.id === objectId)
				) {
					this.activeObjects = {
						...this.activeObjects,
						publication: updatedObject,
					}
				}

				// Remove from selection if it's currently selected
				const isSelected = this.selectedObjects.some(
					(obj) => (obj.id || obj['@self']?.id) === objectId,
				)
				if (isSelected) {
					const remainingSelected = this.selectedObjects.filter(
						(obj) => (obj.id || obj['@self']?.id) !== objectId,
					)
					this.setSelectedObjects(remainingSelected)
				}

				return updatedObject
			} catch (error) {
				console.error('Error depublishing object:', error)
				this.setError(`depublish_${objectId}`, error.message)
				throw error
			} finally {
				this.setLoading(`depublish_${objectId}`, false)
			}
		},

		/**
		 * Validate object by saving it without modifications
		 * @param {object} objectItem - Object to validate
		 * @return {Promise<object>} The validated object
		 */
		async validateObject(objectItem) {
			const objectId = objectItem.id || objectItem['@self']?.id
			const register = objectItem['@self']?.register || objectItem.register
			const schema = objectItem['@self']?.schema || objectItem.schema

			if (!objectId || !register || !schema) {
				throw new Error(
					'Object must have id, register, and schema information',
				)
			}

			// Extract IDs from register and schema in case they are objects
			const registerId = this.extractId(register)
			const schemaId = this.extractId(schema)

			if (!registerId || !schemaId) {
				throw new Error('Could not extract register or schema ID')
			}

			this.setLoading(`validate_${objectId}`, true)
			this.setError(`validate_${objectId}`, null)

			try {
				// Save the object as-is to trigger validation and enrichment
				const result = await this.saveObject(objectItem, {
					register: registerId,
					schema: schemaId,
				})

				// Update active object if it matches the validated object
				const activePublication = this.activeObjects.publication
				if (
					activePublication
          && (activePublication.id === objectId
            || activePublication['@self']?.id === objectId)
				) {
					this.activeObjects = {
						...this.activeObjects,
						publication: result.data,
					}
				}

				return result.data
			} catch (error) {
				console.error('Error validating object:', error)
				this.setError(`validate_${objectId}`, error.message)
				throw error
			} finally {
				this.setLoading(`validate_${objectId}`, false)
			}
		},

		/**
		 * Lock object
		 * @param {object} objectItem - Object to lock
		 * @param {string} process - Process name (optional)
		 * @param {number} duration - Duration in seconds (optional)
		 * @return {Promise<object>} The updated object
		 */
		async lockObject(objectItem, process = null, duration = null) {
			const objectId = objectItem.id || objectItem['@self']?.id
			const register = objectItem['@self']?.register || objectItem.register
			const schema = objectItem['@self']?.schema || objectItem.schema

			if (!objectId || !register || !schema) {
				throw new Error(
					'Object must have id, register, and schema information',
				)
			}

			// Extract IDs from register and schema in case they are objects
			const registerId = this.extractId(register)
			const schemaId = this.extractId(schema)

			if (!registerId || !schemaId) {
				throw new Error('Could not extract register or schema ID')
			}

			this.setLoading(`lock_${objectId}`, true)
			this.setError(`lock_${objectId}`, null)

			try {
				const endpoint = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${objectId}/lock`

				const body = {}
				if (process) body.process = process
				if (duration) body.duration = duration

				const response = await fetch(endpoint, {
					method: 'POST',
					headers: Object.keys(body).length > 0 ? { 'Content-Type': 'application/json' } : undefined,
					body: Object.keys(body).length > 0 ? JSON.stringify(body) : undefined,
				})

				if (!response.ok) {
					throw new Error(
						`Failed to lock object: ${response.status} ${response.statusText}`,
					)
				}

				const updatedObject = await response.json()

				// Update active object if it matches the locked object
				const activePublication = this.activeObjects.publication
				if (
					activePublication
          && (activePublication.id === objectId
            || activePublication['@self']?.id === objectId)
				) {
					this.activeObjects = {
						...this.activeObjects,
						publication: updatedObject,
					}
				}

				return updatedObject
			} catch (error) {
				console.error('Error locking object:', error)
				this.setError(`lock_${objectId}`, error.message)
				throw error
			} finally {
				this.setLoading(`lock_${objectId}`, false)
			}
		},

		/**
		 * Unlock object
		 * @param {object} objectItem - Object to unlock
		 * @return {Promise<object>} The updated object
		 */
		async unlockObject(objectItem) {
			const objectId = objectItem.id || objectItem['@self']?.id
			const register = objectItem['@self']?.register || objectItem.register
			const schema = objectItem['@self']?.schema || objectItem.schema

			if (!objectId || !register || !schema) {
				throw new Error(
					'Object must have id, register, and schema information',
				)
			}

			// Extract IDs from register and schema in case they are objects
			const registerId = this.extractId(register)
			const schemaId = this.extractId(schema)

			if (!registerId || !schemaId) {
				throw new Error('Could not extract register or schema ID')
			}

			this.setLoading(`unlock_${objectId}`, true)
			this.setError(`unlock_${objectId}`, null)

			try {
				const endpoint = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${objectId}/unlock`

				const response = await fetch(endpoint, {
					method: 'POST',
				})

				if (!response.ok) {
					throw new Error(
						`Failed to unlock object: ${response.status} ${response.statusText}`,
					)
				}

				const updatedObject = await response.json()

				// Update active object if it matches the unlocked object
				const activePublication = this.activeObjects.publication
				if (
					activePublication
          && (activePublication.id === objectId
            || activePublication['@self']?.id === objectId)
				) {
					this.activeObjects = {
						...this.activeObjects,
						publication: updatedObject,
					}
				}

				return updatedObject
			} catch (error) {
				console.error('Error unlocking object:', error)
				this.setError(`unlock_${objectId}`, error.message)
				throw error
			} finally {
				this.setLoading(`unlock_${objectId}`, false)
			}
		},

		/**
		 * Set search term for type
		 * @param {string} type - Object type
		 * @param {string} term - Search term
		 */
		setSearchTerm(type, term) {
			// Initialize search term if it doesn't exist
			if (!this.searchTerms[type]) {
				this.searchTerms = {
					...this.searchTerms,
					[type]: '',
				}
			}

			// Update search term with reactive assignment
			this.searchTerms = {
				...this.searchTerms,
				[type]: term,
			}

			// Clear existing debounce timer
			if (this.searchDebounceTimers[type]) {
				clearTimeout(this.searchDebounceTimers[type])
			}

			// Set new debounce timer
			this.searchDebounceTimers = {
				...this.searchDebounceTimers,
				[type]: setTimeout(() => {
					this.fetchCollection(type, term ? { _search: term } : {})
				}, 500),
			}
		},

		/**
		 * Clear search term for type
		 * @param {string} type - Object type
		 */
		clearSearchTerm(type) {
			// Clear the search term
			this.searchTerms = {
				...this.searchTerms,
				[type]: '',
			}

			// Clear any existing debounce timer
			if (this.searchDebounceTimers[type]) {
				clearTimeout(this.searchDebounceTimers[type])
				this.searchDebounceTimers = {
					...this.searchDebounceTimers,
					[type]: null,
				}
			}

			// Fetch collection without search term
			this.fetchCollection(type)
		},

		/**
		 * Set pagination info for type
		 * @param {string} type - Object type
		 * @param {{total: number, page: number, pages: number, limit: number}} pagination - Pagination info
		 */
		setPagination(type, pagination) {
			this.pagination = {
				...this.pagination,
				[type]: pagination,
			}
		},

		/**
		 * Load next page of results
		 * @param {string} type - Object type
		 * @return {Promise<void>}
		 */
		async loadMore(type) {
			const pagination = this.getPagination(type)

			if (pagination.next) {
				// Extract query parameters from the next URL
				const url = new URL(pagination.next)
				const params = Object.fromEntries(url.searchParams)
				await this.fetchCollection(type, params, true)
			} else if (pagination.page < pagination.pages) {
				await this.fetchCollection(
					type,
					{
						_page: pagination.page + 1,
						_limit: pagination.limit,
					},
					true,
				)
			}
		},

		/**
		 * Load previous page of results
		 * @param {string} type - Object type
		 * @return {Promise<void>}
		 */
		async loadPrevious(type) {
			const pagination = this.getPagination(type)

			if (pagination.prev) {
				// Extract query parameters from the prev URL
				const url = new URL(pagination.prev)
				const params = Object.fromEntries(url.searchParams)
				await this.fetchCollection(type, params, false)
			} else if (pagination.page > 1) {
				await this.fetchCollection(
					type,
					{
						_page: pagination.page - 1,
						_limit: pagination.limit,
					},
					false,
				)
			}
		},

		/**
		 * Preload collections for all available schemas
		 * This function should be called once when the application initializes
		 * @return {Promise<void>}
		 */
		async preloadCollections() {
			try {
				// Ensure settings are loaded first
				if (!this.settings) {
					await this.fetchSettings()
				}

				// Get all available object types from settings
				const objectTypes = this.objectTypes

				console.info('Preloading collections for object types:', objectTypes)

				// Load collections for all object types in parallel
				await Promise.allSettled(
					objectTypes.map(async (type) => {
						try {
							await this.fetchCollection(type)
						} catch (error) {
							console.warn(
								`Failed to preload collection for type ${type}:`,
								error,
							)
							// Don't throw here to allow other types to load
						}
					}),
				)

				console.info('Finished preloading collections')
			} catch (error) {
				console.error('Error during preload:', error)
				// Don't throw here to allow the application to continue
			}
		},

		/**
		 * Set state for specific type
		 * @param {string} type - Object type
		 * @param {{success: boolean|null, error: string|null}} state - State to set
		 */
		setState(type, { success, error }) {
			if (success !== undefined) {
				this.success = {
					...this.success,
					[type]: success,
				}
			}
			if (error !== undefined) {
				this.errors = {
					...this.errors,
					[type]: error,
				}
			}
		},

		/**
		 * Copy an existing object
		 * @param {string} type - Object type
		 * @param {string} id - Object ID to copy
		 * @return {Promise<object>} The newly created copy
		 */
		async copyObject(type, id) {
			this.setLoading(`${type}_${id}_copy`, true)
			this.setError(`${type}_${id}_copy`, null)
			this.setState(type, { success: null, error: null })

			try {
				// Ensure settings are loaded first
				if (!this.settings) {
					await this.fetchSettings()
				}

				// Get the original object
				const originalObject = this.objects[type]?.[id]
				if (!originalObject) {
					throw new Error(`Object ${id} of type ${type} not found`)
				}

				// Create a copy of the object without the id
				const { id: _, ...objectData } = originalObject

				// Add "Copy of" to the title or name
				if (objectData.title) {
					objectData.title = `Kopie van ${objectData.title}`
				} else if (objectData.name) {
					objectData.name = `Kopie van ${objectData.name}`
				}

				// Create the new object
				const newObject = await this.createObject(type, objectData)

				// Set success state
				this.setState(type, { success: true, error: null })

				return newObject
			} catch (error) {
				console.error(`Error copying ${type} object:`, error)
				this.setError(`${type}_${id}_copy`, error.message)
				this.setState(type, { success: false, error: error.message })
				throw error
			} finally {
				this.setLoading(`${type}_${id}_copy`, false)
			}
		},

		/**
		 * Set selected objects
		 * @param {Array<string>} objects - Array of object IDs
		 */
		setSelectedObjects(objects) {
			this.selectedObjects = objects
		},

		/**
		 * Set error for a specific object
		 * @param {string} objectId - The object ID
		 * @param {string} error - The error message
		 */
		setObjectError(objectId, error) {
			this.objectErrors[objectId] = error
		},

		/**
		 * Clear error for a specific object
		 * @param {string} objectId - The object ID
		 */
		clearObjectError(objectId) {
			delete this.objectErrors[objectId]
		},

		/**
		 * Clear all object errors
		 */
		clearAllObjectErrors() {
			this.objectErrors = {}
		},

		/**
		 * Get error for a specific object
		 * @param {string} objectId - The object ID
		 * @return {string|null} The error message or null if no error
		 */
		getObjectError(objectId) {
			return this.objectErrors[objectId] || null
		},

		/**
		 * Toggle selection of all objects
		 */
		toggleSelectAllObjects() {
			const organisatieCollection = this.collections.organisatie
			if (!organisatieCollection?.results?.length) return

			if (this.isAllSelected) {
				this.selectedObjects = []
			} else {
				this.selectedObjects = organisatieCollection.results.map(
					(org) => org['@self']?.id || org.id,
				)
			}
		},

		/**
		 * Update column filter
		 * @param {string} id - Column ID
		 * @param {boolean} enabled - Whether the column is enabled
		 */
		updateColumnFilter(id, enabled) {
			this.columnFilters = {
				...this.columnFilters,
				[id]: enabled,
			}

			// Update metadata or properties based on the column ID
			if (id.startsWith('meta_')) {
				const metaKey = id.replace('meta_', '')
				if (this.metadata[metaKey]) {
					this.metadata[metaKey].enabled = enabled
				}
			} else if (id.startsWith('prop_')) {
				const propKey = id.replace('prop_', '')
				if (this.properties[propKey]) {
					this.properties[propKey].enabled = enabled
				}
			}
		},

		/**
		 * Initialize properties from schema
		 * @param {object} schema - Schema object
		 */
		initializeProperties(schema) {
			if (!schema?.properties) {
				this.properties = {}
				return
			}

			const properties = {}
			Object.entries(schema.properties).forEach(([key, property]) => {
				properties[key] = {
					label: property.title || key,
					key,
					description: property.description || `Property: ${key}`,
					enabled: false, // Start with properties disabled
				}
			})

			this.properties = properties
		},

		/**
		 * Initialize column filters
		 */
		initializeColumnFilters() {
			const filters = {}

			// Initialize metadata filters
			Object.keys(this.metadata).forEach((key) => {
				filters[`meta_${key}`] = this.metadata[key].enabled
			})

			// Initialize property filters
			Object.keys(this.properties).forEach((key) => {
				filters[`prop_${key}`] = this.properties[key].enabled
			})

			this.columnFilters = filters
		},

		/**
		 * Mass delete objects
		 * @param {Array<object|string>} objects - Array of objects or object IDs to delete
		 * @param {Function} onProgress - Callback function called after each deletion (optional)
		 * @return {Promise<{successful: Array, failed: Array}>} Results of the operation
		 */
		async massDeleteObjects(objects, onProgress = null) {
			// Clear previous object errors
			this.clearAllObjectErrors()

			const results = await Promise.allSettled(
				objects.map(async (obj) => {
					try {
						// Handle both object and ID inputs
						const objectId
              = typeof obj === 'string' ? obj : obj.id || obj['@self']?.id
						const objectToDelete = typeof obj === 'string' ? { id: obj } : obj

						// Use the individual deleteObject method
						await this.deleteObject(objectToDelete)

						// Clear any previous error for this object
						this.clearObjectError(objectId)

						// Call progress callback if provided
						if (onProgress) {
							onProgress(obj, true)
						}

						return { success: true, id: objectId, object: obj }
					} catch (error) {
						const objectId = obj.id || obj['@self']?.id
						const errorMessage = error.message || 'Unknown error'

						console.error(`Failed to delete object ${objectId}:`, error)

						// Store object-specific error
						this.setObjectError(objectId, errorMessage)

						// Call progress callback if provided
						if (onProgress) {
							onProgress(obj, false, errorMessage)
						}

						return {
							success: false,
							id: objectId,
							object: obj,
							error: errorMessage,
						}
					}
				}),
			)

			// Separate successful and failed operations
			const successful = results
				.filter((r) => r.status === 'fulfilled' && r.value.success)
				.map((r) => r.value)
			const failed = results
				.filter(
					(r) =>
						r.status === 'rejected'
            || (r.status === 'fulfilled' && !r.value.success),
				)
				.map((r) => r.value || { success: false, error: 'Unknown error' })

			// Clear selection of successfully processed objects
			if (successful.length > 0) {
				const successfulIds = successful.map((r) => r.id)
				const remainingSelected = this.selectedObjects.filter(
					(id) => !successfulIds.includes(id),
				)
				this.setSelectedObjects(remainingSelected)
			}

			return { successful, failed }
		},

		/**
		 * Mass publish objects
		 * @param {Array<object>} objects - Array of objects to publish
		 * @param {Function} onProgress - Callback function called after each publication (optional)
		 * @return {Promise<{successful: Array, failed: Array}>} Results of the operation
		 */
		async massPublishObjects(objects, onProgress = null) {
			// Clear previous object errors
			this.clearAllObjectErrors()

			const results = await Promise.allSettled(
				objects.map(async (obj) => {
					try {
						const objectId = obj.id || obj['@self']?.id

						// Use the individual publishObject method
						await this.publishObject(obj)

						// Clear any previous error for this object
						this.clearObjectError(objectId)

						// Call progress callback if provided
						if (onProgress) {
							onProgress(obj, true)
						}

						return { success: true, id: objectId, object: obj }
					} catch (error) {
						const objectId = obj.id || obj['@self']?.id
						const errorMessage = error.message || 'Unknown error'

						console.error(`Failed to publish object ${objectId}:`, error)

						// Store object-specific error
						this.setObjectError(objectId, errorMessage)

						// Call progress callback if provided
						if (onProgress) {
							onProgress(obj, false, errorMessage)
						}

						return {
							success: false,
							id: objectId,
							object: obj,
							error: errorMessage,
						}
					}
				}),
			)

			// Separate successful and failed operations
			const successful = results
				.filter((r) => r.status === 'fulfilled' && r.value.success)
				.map((r) => r.value)
			const failed = results
				.filter(
					(r) =>
						r.status === 'rejected'
            || (r.status === 'fulfilled' && !r.value.success),
				)
				.map((r) => r.value || { success: false, error: 'Unknown error' })

			// Clear selection of successfully processed objects
			if (successful.length > 0) {
				const successfulIds = successful.map((r) => r.id)
				const remainingSelected = this.selectedObjects.filter(
					(id) => !successfulIds.includes(id),
				)
				this.setSelectedObjects(remainingSelected)
			}

			return { successful, failed }
		},

		/**
		 * Mass depublish objects
		 * @param {Array<object>} objects - Array of objects to depublish
		 * @param {Function} onProgress - Callback function called after each depublication (optional)
		 * @return {Promise<{successful: Array, failed: Array}>} Results of the operation
		 */
		async massDepublishObjects(objects, onProgress = null) {
			// Clear previous object errors
			this.clearAllObjectErrors()

			const results = await Promise.allSettled(
				objects.map(async (obj) => {
					try {
						const objectId = obj.id || obj['@self']?.id

						// Use the individual depublishObject method
						await this.depublishObject(obj)

						// Clear any previous error for this object
						this.clearObjectError(objectId)

						// Call progress callback if provided
						if (onProgress) {
							onProgress(obj, true)
						}

						return { success: true, id: objectId, object: obj }
					} catch (error) {
						const objectId = obj.id || obj['@self']?.id
						const errorMessage = error.message || 'Unknown error'

						console.error(`Failed to depublish object ${objectId}:`, error)

						// Store object-specific error
						this.setObjectError(objectId, errorMessage)

						// Call progress callback if provided
						if (onProgress) {
							onProgress(obj, false, errorMessage)
						}

						return {
							success: false,
							id: objectId,
							object: obj,
							error: errorMessage,
						}
					}
				}),
			)

			// Separate successful and failed operations
			const successful = results
				.filter((r) => r.status === 'fulfilled' && r.value.success)
				.map((r) => r.value)
			const failed = results
				.filter(
					(r) =>
						r.status === 'rejected'
            || (r.status === 'fulfilled' && !r.value.success),
				)
				.map((r) => r.value || { success: false, error: 'Unknown error' })

			// Clear selection of successfully processed objects
			if (successful.length > 0) {
				const successfulIds = successful.map((r) => r.id)
				const remainingSelected = this.selectedObjects.filter(
					(id) => !successfulIds.includes(id),
				)
				this.setSelectedObjects(remainingSelected)
			}

			return { successful, failed }
		},

		/**
		 * Mass validate objects
		 * @param {Array<object>} objects - Array of objects to validate
		 * @param {Function} onProgress - Callback function called after each validation (optional)
		 * @return {Promise<{successful: Array, failed: Array}>} Results of the operation
		 */
		async massValidateObjects(objects, onProgress = null) {
			// Clear previous object errors
			this.clearAllObjectErrors()

			const results = await Promise.allSettled(
				objects.map(async (obj) => {
					try {
						const objectId = obj.id || obj['@self']?.id

						// Use the individual validateObject method
						await this.validateObject(obj)

						// Clear any previous error for this object
						this.clearObjectError(objectId)

						// Call progress callback if provided
						if (onProgress) {
							onProgress(obj, true)
						}

						return { success: true, id: objectId, object: obj }
					} catch (error) {
						const objectId = obj.id || obj['@self']?.id
						const errorMessage = error.message || 'Unknown error'

						console.error(`Failed to validate object ${objectId}:`, error)

						// Store object-specific error
						this.setObjectError(objectId, errorMessage)

						// Call progress callback if provided
						if (onProgress) {
							onProgress(obj, false, errorMessage)
						}

						return {
							success: false,
							id: objectId,
							object: obj,
							error: errorMessage,
						}
					}
				}),
			)

			// Separate successful and failed operations
			const successful = results
				.filter((r) => r.status === 'fulfilled' && r.value.success)
				.map((r) => r.value)
			const failed = results
				.filter(
					(r) =>
						r.status === 'rejected'
            || (r.status === 'fulfilled' && !r.value.success),
				)
				.map((r) => r.value || { success: false, error: 'Unknown error' })

			// Clear selection of successfully processed objects
			if (successful.length > 0) {
				const successfulIds = successful.map((r) => r.id)
				const remainingSelected = this.selectedObjects.filter(
					(id) => !successfulIds.includes(id),
				)
				this.setSelectedObjects(remainingSelected)
			}

			return { successful, failed }
		},

		/**
		 * Mass lock objects
		 * @param {Array<object>} objects - Array of objects to lock
		 * @param {string} process - Process name (optional)
		 * @param {number} duration - Duration in seconds (optional)
		 * @param {Function} onProgress - Callback function called after each lock operation (optional)
		 * @return {Promise<{successful: Array, failed: Array}>} Results of the operation
		 */
		async massLockObjects(
			objects,
			process = null,
			duration = null,
			onProgress = null,
		) {
			// Clear previous object errors
			this.clearAllObjectErrors()

			const results = await Promise.allSettled(
				objects.map(async (obj) => {
					try {
						const objectId = obj.id || obj['@self']?.id

						// Use the individual lockObject method
						await this.lockObject(obj, process, duration)

						// Clear any previous error for this object
						this.clearObjectError(objectId)

						// Call progress callback if provided
						if (onProgress) {
							onProgress(obj, true)
						}

						return { success: true, id: objectId, object: obj }
					} catch (error) {
						const objectId = obj.id || obj['@self']?.id
						const errorMessage = error.message || 'Unknown error'

						console.error(`Failed to lock object ${objectId}:`, error)

						// Store object-specific error
						this.setObjectError(objectId, errorMessage)

						// Call progress callback if provided
						if (onProgress) {
							onProgress(obj, false, errorMessage)
						}

						return {
							success: false,
							id: objectId,
							object: obj,
							error: errorMessage,
						}
					}
				}),
			)

			// Separate successful and failed operations
			const successful = results
				.filter((r) => r.status === 'fulfilled' && r.value.success)
				.map((r) => r.value)
			const failed = results
				.filter(
					(r) =>
						r.status === 'rejected'
            || (r.status === 'fulfilled' && !r.value.success),
				)
				.map((r) => r.value || { success: false, error: 'Unknown error' })

			// Clear selection of successfully processed objects
			if (successful.length > 0) {
				const successfulIds = successful.map((r) => r.id)
				const remainingSelected = this.selectedObjects.filter(
					(id) => !successfulIds.includes(id),
				)
				this.setSelectedObjects(remainingSelected)
			}

			return { successful, failed }
		},

		/**
		 * Mass unlock objects
		 * @param {Array<object>} objects - Array of objects to unlock
		 * @param {Function} onProgress - Callback function called after each unlock operation (optional)
		 * @return {Promise<{successful: Array, failed: Array}>} Results of the operation
		 */
		async massUnlockObjects(objects, onProgress = null) {
			// Clear previous object errors
			this.clearAllObjectErrors()

			const results = await Promise.allSettled(
				objects.map(async (obj) => {
					try {
						const objectId = obj.id || obj['@self']?.id

						// Use the individual unlockObject method
						await this.unlockObject(obj)

						// Clear any previous error for this object
						this.clearObjectError(objectId)

						// Call progress callback if provided
						if (onProgress) {
							onProgress(obj, true)
						}

						return { success: true, id: objectId, object: obj }
					} catch (error) {
						const objectId = obj.id || obj['@self']?.id
						const errorMessage = error.message || 'Unknown error'

						console.error(`Failed to unlock object ${objectId}:`, error)

						// Store object-specific error
						this.setObjectError(objectId, errorMessage)

						// Call progress callback if provided
						if (onProgress) {
							onProgress(obj, false, errorMessage)
						}

						return {
							success: false,
							id: objectId,
							object: obj,
							error: errorMessage,
						}
					}
				}),
			)

			// Separate successful and failed operations
			const successful = results
				.filter((r) => r.status === 'fulfilled' && r.value.success)
				.map((r) => r.value)
			const failed = results
				.filter(
					(r) =>
						r.status === 'rejected'
            || (r.status === 'fulfilled' && !r.value.success),
				)
				.map((r) => r.value || { success: false, error: 'Unknown error' })

			// Clear selection of successfully processed objects
			if (successful.length > 0) {
				const successfulIds = successful.map((r) => r.id)
				const remainingSelected = this.selectedObjects.filter(
					(id) => !successfulIds.includes(id),
				)
				this.setSelectedObjects(remainingSelected)
			}

			return { successful, failed }
		},
	},
})
