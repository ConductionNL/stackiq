/**
 * Stackiq plugin for the @conduction/nextcloud-vue object store.
 *
 * Adds app-specific state, getters, and actions that extend the base CRUD store
 * with stackiq-specific operations: settings management, active object
 * tracking, mass operations, lifecycle actions, and column management.
 *
 * @module stackiqPlugin
 * @author Ruben Linde
 * @copyright 2024
 * @license EUPL-1.2
 *
 * @spec openspec/specs/softwarecatalog-store-migration/spec.md#requirement-plugin-shape-for-app-specific-extensions
 */

import { buildHeaders, buildQueryString } from '@conduction/nextcloud-vue'
import {
	buildWriteHeaders,
	getActiveOrganisationUuid,
	withLanguageParam,
} from '../../composables/orClient.js'

/**
 * Extract an ID from a value that can be either a primitive or an object.
 *
 * @param {string|number|object} value The value to extract ID from
 * @return {string|number|null} The extracted ID
 */
function extractId(value) {
	if (value === null || value === undefined) return value
	if (typeof value === 'object') return value.id || value.uuid || value._id
	return value
}

/**
 * Build an API URL for an object using its @self metadata.
 *
 * Read-side callers (GET) pass `withLang: true` so the user's preferred
 * language variant is served (ADR-025 i18n negotiation). Write/action callers
 * leave it false because the language target is communicated via the
 * `X-Translation-Target-Language` header instead.
 *
 * @param {object} objectItem Object with @self metadata
 * @param {string} [action] Optional action endpoint (publish, lock, etc.). Defaults to null.
 * @param {boolean} [withLang] Whether to append the `_lang` query param. Defaults false.
 * @return {string} The constructed URL
 */
function buildObjectUrl(objectItem, action = null, withLang = false) {
	const objectId = objectItem.id || objectItem['@self']?.id
	const register = objectItem['@self']?.register || objectItem.register
	const schema = objectItem['@self']?.schema || objectItem.schema

	const registerId = extractId(register)
	const schemaId = extractId(schema)

	if (!objectId || !registerId || !schemaId) {
		throw new Error('Object must have id, register, and schema information')
	}

	let url = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${objectId}`
	if (action) {
		url += action === 'logs' ? '/audit-trails' : `/${action}`
	}
	return withLang ? withLanguageParam(url) : url
}

/**
 * Separate Promise.allSettled results into successful and failed arrays.
 *
 * @param {Array} results Promise.allSettled results
 * @return {{successful: Array, failed: Array}} Separated results
 */
function separateResults(results) {
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
	return { successful, failed }
}

/**
 * Stackiq plugin factory.
 *
 * @return {object} Plugin definition for createObjectStore
 * @spec exclude Pinia plugin installer factory — store bootstrap plumbing
 */
export function stackiqPlugin() {
	return {
		name: 'Softwarecatalog',

		state: () => ({
			// App settings from /api/settings
			settings: null,

			// Legacy single-object focus (used by modals: DeleteObject, LockObject, DownloadObject, MergeObject)
			objectItem: null,

			// Active object per type (for editing/viewing)
			activeObjects: {},

			// Related data per type: { logs, uses, used, files }
			relatedData: {},

			// Selected object IDs for mass operations
			selectedObjects: [],

			// Per-type success tracking
			success: {},

			// Per-object error messages
			objectErrors: {},

			// Column metadata definitions for GenericObjectTable
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

			// Schema-derived property columns
			properties: {},

			// Column visibility filters
			columnFilters: {},
		}),

		getters: {
			// -- Settings getters --

			objectTypes: (state) => state.settings?.objectTypes || [],

			availableRegisters: (state) => state.settings?.availableRegisters || [],

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

			// -- Active object getters --

			getActiveObject: (state) => (type) => state.activeObjects[type] || null,

			getRelatedData: (state) => (type, dataType) =>
				state.relatedData[type]?.[dataType] || null,

			getAuditTrails: (state) => (type) => state.relatedData[type]?.logs || [],

			// -- State getters --

			getState: (state) => (type) => ({
				success: state.success[type] || null,
				error: state.errors?.[type] || null,
			}),

			hasMorePages: (state) => (type) => {
				const pagination = state.pagination?.[type]
				return pagination
					? pagination.next !== null || pagination.page < pagination.pages
					: false
			},

			hasPreviousPages: (state) => (type) => {
				const pagination = state.pagination?.[type]
				return pagination
					? pagination.prev !== null || pagination.page > 1
					: false
			},

			// -- Column getters --

			enabledMetadata: (state) => {
				return Object.entries(state.metadata)
					.filter(([_, meta]) => meta.enabled)
					.map(([id, meta]) => ({ id: `meta_${id}`, ...meta }))
			},

			enabledProperties: (state) => {
				return Object.entries(state.properties)
					.filter(([_, prop]) => prop.enabled)
					.map(([key, prop]) => ({ id: `prop_${key}`, key, ...prop }))
			},

			enabledColumns: (state) => {
				const metadata = Object.entries(state.metadata)
					.filter(([_, meta]) => meta.enabled)
					.map(([id, meta]) => ({ id: `meta_${id}`, ...meta }))
				const properties = Object.entries(state.properties)
					.filter(([_, prop]) => prop.enabled)
					.map(([key, prop]) => ({ id: `prop_${key}`, key, ...prop }))
				return [...metadata, ...properties]
			},

			// -- Selection getters --

			isAllSelected: (state) => {
				const organisatieCollection = state.collections?.organization
				const results = Array.isArray(organisatieCollection)
					? organisatieCollection
					: organisatieCollection?.results
				if (!results?.length) return false
				return results.every((org) =>
					state.selectedObjects.includes(org['@self']?.id || org.id),
				)
			},

			// -- Collection getter override --
			// The base store stores collections as plain arrays.
			// Consumers expect { results: [] } format. This getter handles both.

			getCollection: (state) => (type) => {
				const collection = state.collections?.[type]
				if (!collection) return { results: [] }
				if (Array.isArray(collection)) return { results: collection }
				return collection
			},
		},

		actions: {
			// ==========================================
			// Settings Management
			// ==========================================

			/**
			 * Fetch app settings from the stackiq API.
			 *
			 * @return {Promise<void>}
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async fetchSettings() {
				try {
					const settingsResponse = await fetch(
						'/index.php/apps/stackiq/api/settings',
						{ headers: buildHeaders() },
					)
					if (!settingsResponse.ok)
						throw new Error('Failed to fetch settings')
					this.settings = await settingsResponse.json()

					// Fetch voorzieningen-specific configuration
					try {
						const voorzieningenResponse = await fetch(
							'/index.php/apps/stackiq/api/voorzieningen/config',
							{ headers: buildHeaders() },
						)
						if (voorzieningenResponse.ok) {
							const voorzieningenData =
								await voorzieningenResponse.json()
							if (
								voorzieningenData.success
								&& voorzieningenData.config
							) {
								this.settings.voorzieningen =
									voorzieningenData.config
							}
						}
					} catch (error) {
						console.warn('Failed to fetch voorzieningen config:', error)
					}

					// Initialize object types from settings
					await this.initializeVoorzieningenObjectTypes()
				} catch (error) {
					console.error('Error fetching settings:', error)
					throw error
				}
			},

			/**
			 * Initialize voorzieningen object types from settings.
			 * Registers each schema from the voorzieningen register.
			 *
			 * @return {Promise<void>}
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async initializeVoorzieningenObjectTypes() {
				try {
					if (!this.settings?.availableRegisters) return

					const voorzieningenRegister =
						this.settings.availableRegisters.find(
							(register) => register.slug === 'stackiq',
						)

					if (!voorzieningenRegister?.schemas) return

					for (const schema of voorzieningenRegister.schemas) {
						await this.registerObjectType(
							schema.slug,
							schema.id,
							voorzieningenRegister.id,
						)
					}
				} catch (error) {
					console.warn(
						'Failed to initialize voorzieningen object types:',
						error,
					)
				}
			},

			/**
			 * Get schema configuration for an object type.
			 * Checks objectTypeRegistry first, then falls back to settings.
			 *
			 * @param {string} objectType Type of object
			 * @return {{source: string, schema: string, register: string}} Schema config
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			getSchemaConfig(objectType) {
				// Check registered types first
				const objectTypeConfig = this.objectTypeRegistry?.[objectType]
				if (objectTypeConfig) {
					return {
						source: 'openregister',
						schema: objectTypeConfig.schema,
						register: objectTypeConfig.register,
					}
				}

				// Fall back to settings
				if (!this.settings) {
					throw new Error('Settings not loaded')
				}

				// Check for voorzieningen-specific configuration. The settings
				// endpoint returns the blob under `voorzieningenConfig`; the
				// secondary `/api/voorzieningen/config` fetch (when it resolves)
				// stores it under `voorzieningen`. Accept either so the lookup
				// works whichever populated first.
				//
				// The blob carries a single `register` plus a `<type>_schema` key
				// per object type (organisatie_schema, module_schema,
				// compliancy_schema, …). Resolve ANY configured type from it — not
				// just `organisatie` — so the dashboard/settings/compliance
				// surfaces register their stores even when the settings
				// `availableRegisters` list does not include the voorzieningen
				// register (the slug-based auto-registration then finds nothing).
				const voorzieningenConfig =
					this.settings.voorzieningen
					|| this.settings.voorzieningenConfig
					|| {}
				if (
					voorzieningenConfig.register
					&& voorzieningenConfig[`${objectType}_schema`]
				) {
					return {
						source: 'openregister',
						schema: voorzieningenConfig[`${objectType}_schema`],
						register: voorzieningenConfig.register,
					}
				}

				// AMEF / ArchiMate types (element, relation, view, model,
				// property_definition, organization) live in their own register
				// with a `<type>_schema` key under `amefConfig`. The compliance
				// matrix and ArchiMate surfaces fetch `element`, which is not part
				// of the voorzieningen blob.
				const amefConfig =
					this.settings.amef || this.settings.amefConfig || {}
				if (amefConfig.register && amefConfig[`${objectType}_schema`]) {
					return {
						source: 'openregister',
						schema: amefConfig[`${objectType}_schema`],
						register: amefConfig.register,
					}
				}

				// Check legacy settings format
				const config = this.settings.configuration || {}
				const source =
					config[`voorzieningen_${objectType}_source`]
					|| config[`${objectType}_source`]
					|| 'openregister'
				const schema =
					config[`voorzieningen_${objectType}_schema`]
					|| config[`${objectType}_schema`]
				const register =
					config[`voorzieningen_${objectType}_register`]
					|| config[`${objectType}_register`]
					|| config.voorzieningen_register

				if (!schema || !register) {
					throw new Error(
						`Invalid configuration for object type: ${objectType}. Schema: ${schema}, Register: ${register}`,
					)
				}

				return { source, schema, register }
			},

			// ==========================================
			// Active Object Management
			// ==========================================

			/**
			 * Set active object for type and fetch related data.
			 *
			 * @param {string} type Object type
			 * @param {object} object Object to set as active
			 * @return {Promise<void>}
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async setActiveObject(type, object) {
				this.activeObjects = { ...this.activeObjects, [type]: object }
				this.relatedData = {
					...this.relatedData,
					[type]: { logs: null, uses: null, used: null, files: null },
				}

				if (object?.id) {
					let organisatieData = null
					if (type === 'organization' && object['@self']) {
						organisatieData = {
							source: 'openregister',
							schema: object['@self'].schema,
							register: object['@self'].register,
						}
					}

					const dataTypes = ['logs', 'uses', 'used', 'files']
					const fetchPromises = dataTypes.map((dataType) => {
						const defaultLimit = dataType === 'files' ? 500 : 20
						return this.fetchRelatedData(
							type,
							object.id,
							dataType,
							{ _limit: defaultLimit, _page: 1 },
							organisatieData,
						)
					})
					await Promise.all(fetchPromises)
				}
			},

			/**
			 * Clear active object for type.
			 *
			 * @param {string} type Object type
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			clearActiveObject(type) {
				this.activeObjects = { ...this.activeObjects, [type]: null }
				this.relatedData = {
					...this.relatedData,
					[type]: { logs: null, uses: null, used: null, files: null },
				}
			},

			/**
			 * Set the legacy objectItem (single focused object for modals).
			 *
			 * @param {object|null} object The object to focus
			 */
			setObjectItem(object) {
				this.objectItem = object
			},

			/**
			 * Download an object as JSON file.
			 *
			 * @param {object} objectItem Object with @self metadata
			 * @return {Promise<{ok: boolean}>} Response-like object for backward compat
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async downloadObject(objectItem) {
				const objectId = objectItem.id || objectItem['@self']?.id
				if (!objectId) throw new Error('Object ID is required for download')

				const endpoint = buildObjectUrl(objectItem, null, true)
				const response = await fetch(endpoint, {
					headers: buildHeaders(),
				})

				if (!response.ok)
					throw new Error(
						`Failed to download object: ${response.statusText}`,
					)

				const data = await response.json()
				const blob = new Blob([JSON.stringify(data, null, 2)], {
					type: 'application/json',
				})
				const url = URL.createObjectURL(blob)
				const link = document.createElement('a')
				link.href = url
				link.download = `${objectItem['@self']?.name || objectItem.name || objectId}.json`
				link.click()
				URL.revokeObjectURL(url)

				return { ok: true }
			},

			/**
			 * Fetch related data for an object (logs, uses, used, files).
			 *
			 * @param {string} type Object type
			 * @param {string} id Object ID
			 * @param {string} dataType Type of related data
			 * @param {object} params Query parameters
			 * @param {object|null} organisatieData Optional org-specific config
			 * @return {Promise<void>}
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async fetchRelatedData(
				type,
				id,
				dataType,
				params = {},
				organisatieData = null,
			) {
				const loadingKey = `${type}_${id}_${dataType}`
				this.loading = { ...this.loading, [loadingKey]: true }

				try {
					if (!this.settings) await this.fetchSettings()

					let config = organisatieData
					if (!config) {
						config = this.getSchemaConfig(type)
					}

					const registerId = extractId(config.register)
					const schemaId = extractId(config.schema)

					// Build the URL
					let actionPath = dataType
					if (dataType === 'logs') actionPath = 'audit-trails'

					const queryParams = { ...params }
					if (dataType === 'uses' || dataType === 'used') {
						queryParams._extend = params._extend || '@self.schema'
					}

					const url = withLanguageParam(
						`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${id}/${actionPath}${buildQueryString(queryParams)}`,
					)

					const response = await fetch(url, { headers: buildHeaders() })
					if (!response.ok)
						throw new Error(`Failed to fetch ${dataType} for ${type}`)

					const data = await response.json()

					if (!this.relatedData[type]) {
						this.relatedData = { ...this.relatedData, [type]: {} }
					}

					// Update pagination for related data
					if (data.total !== undefined || data.page !== undefined) {
						const paginationKey = `${type}_${dataType}`
						const requestedLimit = params._limit || params.limit
						const apiLimit = data.limit ? parseInt(data.limit, 10) : null
						const actualLimit =
							apiLimit
							|| requestedLimit
							|| (dataType === 'files' ? 500 : 20)
						this.pagination = {
							...this.pagination,
							[paginationKey]: {
								total: data.total || 0,
								page: data.page || 1,
								pages:
									data.pages
									|| Math.ceil((data.total || 0) / actualLimit),
								limit: actualLimit,
								next: data.next || null,
								prev: data.prev || null,
							},
						}
					}

					// Store the data
					const relatedTypeData = { ...this.relatedData[type] }
					relatedTypeData[dataType] =
						dataType === 'logs' ? data.results || [] : data
					this.relatedData = {
						...this.relatedData,
						[type]: relatedTypeData,
					}
				} catch (error) {
					console.error(`Error fetching ${dataType} for ${type}:`, error)
				} finally {
					this.loading = { ...this.loading, [loadingKey]: false }
				}
			},

			// ==========================================
			// CRUD Operations (backward-compatible overrides)
			// ==========================================

			/**
			 * Save an object. Supports both legacy and new call signatures.
			 *
			 * Legacy: saveObject(objectItem, { register, schema })
			 * New: saveObject(type, objectData)
			 *
			 * @param {string|object} typeOrObject Type slug or object item
			 * @param {object} dataOrConfig Object data or { register, schema } config
			 * @return {Promise<object>} Saved object
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async saveObject(typeOrObject, dataOrConfig) {
				if (typeof typeOrObject === 'string') {
					// New signature: saveObject(type, objectData)
					const type = typeOrObject
					const objectData = dataOrConfig
					const isUpdate = !!objectData.id || !!objectData['@self']?.id
					const id = objectData.id || objectData['@self']?.id

					const url = this._buildUrl(type, isUpdate ? id : null)
					const response = await fetch(url, {
						method: isUpdate ? 'PUT' : 'POST',
						headers: buildWriteHeaders(buildHeaders(), {
							organisation: getActiveOrganisationUuid(),
						}),
						body: JSON.stringify(objectData),
					})

					if (!response.ok)
						throw new Error(`Failed to save ${type} object`)
					const result = await response.json()

					// Update cache
					if (result.id) {
						if (!this.objects[type])
							this.objects = { ...this.objects, [type]: {} }
						this.objects[type][result.id] = result
					}

					return result
				}

				// Legacy signature: saveObject(objectItem, { register, schema })
				const objectItem = typeOrObject
				const { register, schema } = dataOrConfig || {}

				if (!objectItem || !register || !schema) {
					throw new Error('Object item, register and schema are required')
				}

				const registerId = extractId(register)
				const schemaId = extractId(schema)
				if (!registerId || !schemaId)
					throw new Error('Could not extract register or schema ID')

				const isNewObject = !objectItem['@self']?.id
				const objectId = objectItem['@self']?.id

				let endpoint = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}`
				if (!isNewObject && objectId) {
					endpoint += `/${objectId}`
				}

				if (!objectItem['@self']) objectItem['@self'] = {}
				objectItem['@self'].updated = new Date().toISOString()

				const response = await fetch(endpoint, {
					method: isNewObject ? 'POST' : 'PUT',
					headers: buildHeaders(),
					body: JSON.stringify(objectItem),
				})

				if (!response.ok) {
					throw new Error(
						`Failed to save object: ${response.status} ${response.statusText}`,
					)
				}

				const data = await response.json()
				return { response, data }
			},

			/**
			 * Delete an object. Supports both legacy and new call signatures.
			 *
			 * Legacy: deleteObject(objectItem) — object with @self metadata
			 * New: deleteObject(type, id)
			 *
			 * @param {string|object} typeOrObject Type slug or full object
			 * @param {string} [id] Object ID (only for new signature)
			 * @return {Promise<boolean>} Success
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async deleteObject(typeOrObject, id) {
				if (typeof typeOrObject === 'string' && id) {
					// New signature: deleteObject(type, id)
					const url = this._buildUrl(typeOrObject, id)
					const response = await fetch(url, {
						method: 'DELETE',
						headers: buildHeaders(),
					})
					if (!response.ok)
						throw new Error(`Failed to delete ${typeOrObject} object`)
					return true
				}

				// Legacy signature: deleteObject(objectItem)
				const objectItem = typeOrObject
				const endpoint = buildObjectUrl(objectItem)

				const objectId = objectItem.id || objectItem['@self']?.id
				this.loading = { ...this.loading, [`delete_${objectId}`]: true }

				try {
					const response = await fetch(endpoint, {
						method: 'DELETE',
						headers: buildHeaders(),
					})

					if (!response.ok) {
						throw new Error(
							`Failed to delete object: ${response.status} ${response.statusText}`,
						)
					}

					// Remove from selection if selected
					const isSelected = this.selectedObjects.some(
						(obj) =>
							(typeof obj === 'string'
								? obj
								: obj.id || obj['@self']?.id) === objectId,
					)
					if (isSelected) {
						this.setSelectedObjects(
							this.selectedObjects.filter(
								(obj) =>
									(typeof obj === 'string'
										? obj
										: obj.id || obj['@self']?.id) !== objectId,
							),
						)
					}

					return true
				} finally {
					this.loading = { ...this.loading, [`delete_${objectId}`]: false }
				}
			},

			/**
			 * Patch existing object (partial update).
			 *
			 * When the caller is editing a specific (non-default) language
			 * variant of a translatable property, it passes `targetLang` so the
			 * request carries `X-Translation-Target-Language` and OpenRegister
			 * writes into the correct language slot (ADR-025).
			 *
			 * @param {string} type Object type
			 * @param {string} id Object ID
			 * @param {object} changes Object with changed properties
			 * @param {string|null} [targetLang] Target translation language. Defaults to null.
			 * @return {Promise<object>} Updated object
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async patchObject(type, id, changes, targetLang = null) {
				this.loading = { ...this.loading, [`${type}_${id}`]: true }

				try {
					if (!this.settings) await this.fetchSettings()

					const config = this.getSchemaConfig(type)
					const registerId = extractId(config.register)
					const schemaId = extractId(config.schema)

					const response = await fetch(
						`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${id}`,
						{
							method: 'PATCH',
							headers: buildWriteHeaders(buildHeaders(), {
								targetLang,
								organisation: getActiveOrganisationUuid(),
							}),
							body: JSON.stringify(changes),
						},
					)
					if (!response.ok)
						throw new Error(`Failed to patch ${type} object`)

					const updatedObject = await response.json()

					// Update cache
					if (!this.objects[type])
						this.objects = { ...this.objects, [type]: {} }
					this.objects[type][id] = updatedObject

					// Update active object if it matches
					if (this.activeObjects[type]?.id === id) {
						this.activeObjects = {
							...this.activeObjects,
							[type]: updatedObject,
						}
					}

					this.setState(type, { success: true, error: null })
					return updatedObject
				} catch (error) {
					console.error(`Error patching ${type} object:`, error)
					this.setState(type, { success: false, error: error.message })
					throw error
				} finally {
					this.loading = { ...this.loading, [`${type}_${id}`]: false }
				}
			},

			/**
			 * Copy an existing object.
			 *
			 * @param {string} type Object type
			 * @param {string} id Object ID to copy
			 * @return {Promise<object>} The newly created copy
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async copyObject(type, id) {
				const originalObject = this.objects?.[type]?.[id]
				if (!originalObject)
					throw new Error(`Object ${id} of type ${type} not found`)

				const { id: _, ...objectData } = originalObject
				if (objectData.title)
					objectData.title = `Kopie van ${objectData.title}`
				else if (objectData.name)
					objectData.name = `Kopie van ${objectData.name}`

				return this.saveObject(type, objectData)
			},

			// ==========================================
			// Lifecycle Operations
			// ==========================================

			/**
			 * Publish an object.
			 *
			 * @param {object} objectItem Object to publish
			 * @return {Promise<object>} Updated object
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async publishObject(objectItem) {
				const objectId = objectItem.id || objectItem['@self']?.id
				this.loading = { ...this.loading, [`publish_${objectId}`]: true }

				try {
					const url = buildObjectUrl(objectItem, 'publish')
					const response = await fetch(url, {
						method: 'POST',
						headers: buildHeaders(),
					})

					if (!response.ok) {
						throw new Error(
							`Failed to publish object: ${response.status} ${response.statusText}`,
						)
					}

					return await response.json()
				} finally {
					this.loading = {
						...this.loading,
						[`publish_${objectId}`]: false,
					}
				}
			},

			/**
			 * Depublish an object.
			 *
			 * @param {object} objectItem Object to depublish
			 * @return {Promise<object>} Updated object
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async depublishObject(objectItem) {
				const objectId = objectItem.id || objectItem['@self']?.id
				this.loading = { ...this.loading, [`depublish_${objectId}`]: true }

				try {
					const url = buildObjectUrl(objectItem, 'depublish')
					const response = await fetch(url, {
						method: 'POST',
						headers: buildHeaders(),
					})

					if (!response.ok) {
						throw new Error(
							`Failed to depublish object: ${response.status} ${response.statusText}`,
						)
					}

					return await response.json()
				} finally {
					this.loading = {
						...this.loading,
						[`depublish_${objectId}`]: false,
					}
				}
			},

			/**
			 * Lock an object.
			 *
			 * @param {object} objectItem Object to lock
			 * @param {string} [process] Process name. Defaults to null.
			 * @param {number} [duration] Duration in seconds. Defaults to null.
			 * @return {Promise<object>} Updated object
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async lockObject(objectItem, process = null, duration = null) {
				const objectId = objectItem.id || objectItem['@self']?.id
				this.loading = { ...this.loading, [`lock_${objectId}`]: true }

				try {
					const url = buildObjectUrl(objectItem, 'lock')
					const body = {}
					if (process) body.process = process
					if (duration) body.duration = duration

					const hasBody = Object.keys(body).length > 0
					const response = await fetch(url, {
						method: 'POST',
						headers: hasBody ? buildHeaders() : buildHeaders(null),
						body: hasBody ? JSON.stringify(body) : undefined,
					})

					if (!response.ok) {
						throw new Error(
							`Failed to lock object: ${response.status} ${response.statusText}`,
						)
					}

					return await response.json()
				} finally {
					this.loading = { ...this.loading, [`lock_${objectId}`]: false }
				}
			},

			/**
			 * Unlock an object.
			 *
			 * @param {object} objectItem Object to unlock
			 * @return {Promise<object>} Updated object
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async unlockObject(objectItem) {
				const objectId = objectItem.id || objectItem['@self']?.id
				this.loading = { ...this.loading, [`unlock_${objectId}`]: true }

				try {
					const url = buildObjectUrl(objectItem, 'unlock')
					const response = await fetch(url, {
						method: 'POST',
						headers: buildHeaders(),
					})

					if (!response.ok) {
						throw new Error(
							`Failed to unlock object: ${response.status} ${response.statusText}`,
						)
					}

					return await response.json()
				} finally {
					this.loading = { ...this.loading, [`unlock_${objectId}`]: false }
				}
			},

			/**
			 * Validate an object by re-saving it.
			 *
			 * @param {object} objectItem Object to validate
			 * @return {Promise<object>} Validated object
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async validateObject(objectItem) {
				const objectId = objectItem.id || objectItem['@self']?.id
				const register = objectItem['@self']?.register || objectItem.register
				const schema = objectItem['@self']?.schema || objectItem.schema

				this.loading = { ...this.loading, [`validate_${objectId}`]: true }

				try {
					const result = await this.saveObject(objectItem, {
						register: extractId(register),
						schema: extractId(schema),
					})

					return result.data
				} finally {
					this.loading = {
						...this.loading,
						[`validate_${objectId}`]: false,
					}
				}
			},

			// ==========================================
			// Mass Operations
			// ==========================================

			/**
			 * Run a mass operation on an array of objects.
			 *
			 * @param {Array<object>} objects Objects to process
			 * @param {Function} operation Per-object operation function
			 * @param {Function} [onProgress] Progress callback. Defaults to null.
			 * @return {Promise<{successful: Array, failed: Array}>} Results
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async _runMassOperation(objects, operation, onProgress = null) {
				this.clearAllObjectErrors()

				const results = await Promise.allSettled(
					objects.map(async (obj) => {
						try {
							const objectId = obj.id || obj['@self']?.id
							await operation(obj)
							this.clearObjectError(objectId)
							if (onProgress) onProgress(obj, true)
							return { success: true, id: objectId, object: obj }
						} catch (error) {
							const objectId = obj.id || obj['@self']?.id
							const errorMessage = error.message || 'Unknown error'
							this.setObjectError(objectId, errorMessage)
							if (onProgress) onProgress(obj, false, errorMessage)
							return {
								success: false,
								id: objectId,
								object: obj,
								error: errorMessage,
							}
						}
					}),
				)

				const { successful, failed } = separateResults(results)

				// Clear selection of successfully processed objects
				if (successful.length > 0) {
					const successfulIds = successful.map((r) => r.id)
					this.setSelectedObjects(
						this.selectedObjects.filter(
							(id) => !successfulIds.includes(id),
						),
					)
				}

				return { successful, failed }
			},

			/**
			 * @param objects
			 * @param onProgress
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async massPublishObjects(objects, onProgress = null) {
				return this._runMassOperation(
					objects,
					(obj) => this.publishObject(obj),
					onProgress,
				)
			},

			/**
			 * @param objects
			 * @param onProgress
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async massDepublishObjects(objects, onProgress = null) {
				return this._runMassOperation(
					objects,
					(obj) => this.depublishObject(obj),
					onProgress,
				)
			},

			/**
			 * @param objects
			 * @param onProgress
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async massDeleteObjects(objects, onProgress = null) {
				return this._runMassOperation(
					objects,
					(obj) => this.deleteObject(obj),
					onProgress,
				)
			},

			/**
			 * @param objects
			 * @param process
			 * @param duration
			 * @param onProgress
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async massLockObjects(
				objects,
				process = null,
				duration = null,
				onProgress = null,
			) {
				return this._runMassOperation(
					objects,
					(obj) => this.lockObject(obj, process, duration),
					onProgress,
				)
			},

			/**
			 * @param objects
			 * @param onProgress
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async massUnlockObjects(objects, onProgress = null) {
				return this._runMassOperation(
					objects,
					(obj) => this.unlockObject(obj),
					onProgress,
				)
			},

			/**
			 * @param objects
			 * @param onProgress
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async massValidateObjects(objects, onProgress = null) {
				return this._runMassOperation(
					objects,
					(obj) => this.validateObject(obj),
					onProgress,
				)
			},

			// ==========================================
			// Selection Management
			// ==========================================

			setSelectedObjects(objects) {
				this.selectedObjects = objects
			},

			/**
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			toggleSelectAllObjects() {
				const organisatieCollection = this.collections?.organization
				const results = Array.isArray(organisatieCollection)
					? organisatieCollection
					: organisatieCollection?.results
				if (!results?.length) return

				if (this.isAllSelected) {
					this.selectedObjects = []
				} else {
					this.selectedObjects = results.map(
						(org) => org['@self']?.id || org.id,
					)
				}
			},

			// ==========================================
			// Object Error Management
			// ==========================================

			setObjectError(objectId, error) {
				this.objectErrors = { ...this.objectErrors, [objectId]: error }
			},

			/**
			 * @param objectId
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			clearObjectError(objectId) {
				const { [objectId]: _, ...rest } = this.objectErrors
				this.objectErrors = rest
			},

			/**
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			clearAllObjectErrors() {
				this.objectErrors = {}
			},

			getObjectError(objectId) {
				return this.objectErrors[objectId] || null
			},

			// ==========================================
			// Column Management
			// ==========================================

			/**
			 * @param id
			 * @param enabled
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			updateColumnFilter(id, enabled) {
				this.columnFilters = { ...this.columnFilters, [id]: enabled }

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
			 * @param schema
			 * @spec openspec/specs/fe-stores/spec.md
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
						enabled: false,
					}
				})
				this.properties = properties
			},

			/**
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			initializeColumnFilters() {
				const filters = {}
				Object.keys(this.metadata).forEach((key) => {
					filters[`meta_${key}`] = this.metadata[key].enabled
				})
				Object.keys(this.properties).forEach((key) => {
					filters[`prop_${key}`] = this.properties[key].enabled
				})
				this.columnFilters = filters
			},

			// ==========================================
			// Merge & Migration Operations
			// ==========================================

			/**
			 * Merge two objects via the OpenRegister merge API.
			 *
			 * @param {object} params Merge parameters
			 * @param {string} params.register Register ID
			 * @param {string} params.schema Schema ID
			 * @param {string} params.sourceObjectId Source object ID
			 * @param {string} params.target Target object ID
			 * @param {object} params.object Merged data
			 * @param {string} params.fileAction What to do with files
			 * @param {string} params.relationAction What to do with relations
			 * @return {Promise<object>} Merge result
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async mergeObjects({
				register,
				schema,
				sourceObjectId,
				target,
				object,
				fileAction,
				relationAction,
			}) {
				const response = await fetch(
					`/index.php/apps/openregister/api/objects/${register}/${schema}/${sourceObjectId}/merge`,
					{
						method: 'POST',
						headers: buildHeaders(),
						body: JSON.stringify({
							target,
							object,
							fileAction,
							relationAction,
						}),
					},
				)

				if (!response.ok)
					throw new Error(
						`Failed to merge objects: ${response.statusText}`,
					)
				return { data: await response.json() }
			},

			/**
			 * Fetch available mappings from the OpenRegister API.
			 *
			 * @return {Promise<{data: Array}>} Mappings result
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async getMappings() {
				const response = await fetch(
					'/index.php/apps/openregister/api/mappings',
					{ headers: buildHeaders() },
				)
				if (!response.ok) throw new Error('Failed to fetch mappings')
				return { data: await response.json() }
			},

			/**
			 * Refresh the current object list by refetching all registered types.
			 *
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			refreshObjectList() {
				const registeredTypes = Object.keys(this.objectTypeRegistry || {})
				registeredTypes.forEach((type) => {
					this.fetchCollection(type)
				})
			},

			// ==========================================
			// State Management
			// ==========================================

			/**
			 * @param type
			 * @param root0
			 * @param root0.success
			 * @param root0.error
			 * @spec exclude generic Pinia $patch passthrough — store bootstrap plumbing
			 */
			setState(type, { success, error }) {
				if (success !== undefined) {
					this.success = { ...this.success, [type]: success }
				}
				if (error !== undefined) {
					this.errors = { ...this.errors, [type]: error }
				}
			},

			/**
			 * Clear the stackiq sub-resources (called by base clearAllSubResources).
			 *
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			clearSoftwarecatalog() {
				this.objectItem = null
				this.activeObjects = {}
				this.relatedData = {}
				this.selectedObjects = []
				this.objectErrors = {}
			},
		},
	}
}
