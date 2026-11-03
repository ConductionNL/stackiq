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
 * The id a selection entry stands for: selections hold plain ids or objects.
 *
 * @param {string|object} entry A selected id or object
 * @return {string|undefined} The id
 */
function selectionId(entry) {
	return typeof entry === 'string' ? entry : entry.id || entry['@self']?.id
}

/**
 * The OpenRegister URL for one kind of related data of an object.
 *
 * @param {object} config Schema config with `register` and `schema`
 * @param {{id: string, dataType: string, params: object}} request The object id, the kind of related data and the query parameters
 * @return {string} The URL
 */
function relatedDataUrl(config, request) {
	const registerId = extractId(config.register)
	const schemaId = extractId(config.schema)
	const actionPath =
		request.dataType === 'logs' ? 'audit-trails' : request.dataType
	return withLanguageParam(
		`/index.php/apps/openregister/api/objects/${registerId}/${schemaId}/${request.id}/${actionPath}${buildQueryString(relatedQuery(request))}`,
	)
}

/**
 * The query parameters for a related-data request: relations get their
 * schema expanded unless the caller asks for another expansion.
 *
 * @param {{dataType: string, params: object}} request The kind of related data and the query parameters
 * @return {object} The query parameters
 */
function relatedQuery(request) {
	const queryParams = { ...request.params }
	if (request.dataType === 'uses' || request.dataType === 'used') {
		queryParams._extend = request.params._extend || '@self.schema'
	}
	return queryParams
}

/**
 * Whether a related-data answer carries pagination fields.
 *
 * @param {object} data The answer
 * @return {boolean} True when it has a total or a page
 */
function hasPagination(data) {
	return data.total !== undefined || data.page !== undefined
}

/**
 * The pagination state for a related-data answer.
 *
 * @param {object} data The answer
 * @param {{dataType: string, params: object}} request The kind of related data and the query parameters
 * @return {object} The pagination state
 */
function relatedPagination(data, request) {
	const limit = relatedPageLimit(data, request)
	return {
		total: data.total || 0,
		page: data.page || 1,
		pages: relatedPageCount(data, limit),
		limit,
		next: data.next || null,
		prev: data.prev || null,
	}
}

/**
 * The page count of a related-data answer: the server's, else the total
 * divided by the page size.
 *
 * @param {object} data The answer
 * @param {number} limit The page size
 * @return {number} The page count
 */
function relatedPageCount(data, limit) {
	return data.pages || Math.ceil((data.total || 0) / limit)
}

/**
 * The page size of a related-data answer: the server's, else the requested
 * one, else 500 for files and 20 for the rest.
 *
 * @param {object} data The answer
 * @param {{dataType: string, params: object}} request The kind of related data and the query parameters
 * @return {number} The page size
 */
function relatedPageLimit(data, request) {
	const requestedLimit = request.params._limit || request.params.limit
	const apiLimit = data.limit ? parseInt(data.limit, 10) : null
	return apiLimit || requestedLimit || (request.dataType === 'files' ? 500 : 20)
}

/**
 * What the store keeps for a related-data answer: the result list for audit
 * logs, the whole answer for the rest.
 *
 * @param {object} data The answer
 * @param {string} dataType The kind of related data
 * @return {object|Array} The stored value
 */
function relatedEntry(data, dataType) {
	return dataType === 'logs' ? data.results || [] : data
}

/**
 * Store a related-data answer: its pagination, when it has any, and the
 * related data itself under the object type.
 *
 * @param {object} store The store
 * @param {{type: string, dataType: string, params: object, data: object}} answer The object type, the kind of related data, the query parameters and the answer
 */
function storeRelatedAnswer(store, answer) {
	const { type, dataType, data } = answer
	if (!store.relatedData[type]) {
		store.relatedData = { ...store.relatedData, [type]: {} }
	}

	if (hasPagination(data)) {
		store.pagination = {
			...store.pagination,
			[`${type}_${dataType}`]: relatedPagination(data, answer),
		}
	}

	const relatedTypeData = { ...store.relatedData[type] }
	relatedTypeData[dataType] = relatedEntry(data, dataType)
	store.relatedData = { ...store.relatedData, [type]: relatedTypeData }
}

/**
 * The metadata columns GenericObjectTable can show, in order: column, label,
 * object key, description and whether it is shown by default.
 *
 * @type {Array<[string, string, string, string, boolean]>}
 */
const METADATA_COLUMNS = [
	['name', 'Name', 'name', 'Display name of the object', true],
	[
		'description',
		'Description',
		'description',
		'Description of the object',
		false,
	],
	['objectId', 'ID', 'id', 'Unique identifier of the object', false],
	['uri', 'URI', 'uri', 'URI of the object', false],
	['version', 'Version', 'version', 'Version of the object', false],
	['register', 'Register', 'register', 'Register of the object', false],
	['schema', 'Schema', 'schema', 'Schema of the object', false],
	['files', 'Files', 'files', 'Attached files count', true],
	['locked', 'Locked', 'locked', 'Whether the object is locked', false],
	[
		'organization',
		'Organization',
		'organization',
		'Organization owning the object',
		false,
	],
	[
		'validation',
		'Validation',
		'validation',
		'Validation status of the object',
		false,
	],
	['owner', 'Owner', 'owner', 'Owner of the object', false],
	[
		'application',
		'Application',
		'application',
		'Application of the object',
		false,
	],
	['folder', 'Folder', 'folder', 'Folder of the object', false],
	['geo', 'Geo', 'geo', 'Geographic information', false],
	['retention', 'Retention', 'retention', 'Retention policy', false],
	['size', 'Size', 'size', 'Size of the object', false],
	['published', 'Published', 'published', 'Publication date', false],
	['depublished', 'Depublished', 'depublished', 'Depublication date', false],
	['deleted', 'Deleted', 'deleted', 'Deletion date', false],
	['created', 'Created', 'created', 'Creation date and time', false],
	['updated', 'Updated', 'updated', 'Last update date and time', false],
]

/**
 * One metadata column definition, keyed by its column name.
 *
 * @param {Array} row A row of METADATA_COLUMNS
 * @return {[string, object]} The column name and a fresh definition
 */
function metadataEntry(row) {
	const [column, label, key, description, enabled] = row
	return [column, { label, key, description, enabled }]
}

/**
 * Fresh column metadata definitions for a new store state.
 *
 * @return {object} The definitions by column name
 */
function columnMetadata() {
	return Object.fromEntries(METADATA_COLUMNS.map(metadataEntry))
}

/**
 * POST a lifecycle action (publish, depublish) for an object.
 *
 * @param {object} objectItem The object
 * @param {string} action The action
 * @return {Promise<object>} The updated object
 * @throws {Error} When the object lacks ids or the request fails.
 */
async function requestObjectAction(objectItem, action) {
	const url = buildObjectUrl(objectItem, action)
	const response = await fetch(url, { method: 'POST', headers: buildHeaders() })
	if (!response.ok) {
		throw new Error(
			`Failed to ${action} object: ${response.status} ${response.statusText}`,
		)
	}
	return await response.json()
}

/**
 * Run a lifecycle action for an object, flagging `<action>_<id>` in the
 * store's loading map while it runs.
 *
 * @param {object} store The object store
 * @param {{objectItem: object, action: string}} request The object and the action
 * @return {Promise<object>} The updated object
 */
async function postObjectAction(store, request) {
	const { objectItem, action } = request
	const flag = `${action}_${objectItem.id || objectItem['@self']?.id}`
	store.loading = { ...store.loading, [flag]: true }
	try {
		return await requestObjectAction(objectItem, action)
	} finally {
		store.loading = { ...store.loading, [flag]: false }
	}
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
 * The schema config of a type the object type registry knows, or null.
 *
 * @param {object|undefined} registry The object type registry
 * @param {string} objectType The object type
 * @return {{source: string, schema: string, register: string}|null} The schema config
 */
function registeredSchemaConfig(registry, objectType) {
	const config = registry?.[objectType]
	if (!config) {
		return null
	}
	return {
		source: 'openregister',
		schema: config.schema,
		register: config.register,
	}
}

/**
 * The voorzieningen config blob: the settings endpoint returns it under
 * `voorzieningenConfig`, the focused config fetch stores it under
 * `voorzieningen`. It carries one register and a `<type>_schema` key per
 * catalog object type.
 *
 * @param {object} settings The loaded settings
 * @return {object} The blob, empty when neither is loaded
 */
function voorzieningenBlob(settings) {
	return settings.voorzieningen || settings.voorzieningenConfig || {}
}

/**
 * The AMEF config blob, with one register and a `<type>_schema` key per
 * ArchiMate object type (element, relation, view, model, ...).
 *
 * @param {object} settings The loaded settings
 * @return {object} The blob, empty when neither is loaded
 */
function amefBlob(settings) {
	return settings.amef || settings.amefConfig || {}
}

/**
 * The schema config of an object type from a register-scoped config blob, or
 * null when the blob has no register or no schema for the type.
 *
 * @param {object} blob The config blob
 * @param {string} objectType The object type
 * @return {{source: string, schema: string, register: string}|null} The schema config
 */
function registerBlobSchemaConfig(blob, objectType) {
	const schema = blob[`${objectType}_schema`]
	if (!blob.register || !schema) {
		return null
	}
	return { source: 'openregister', schema, register: blob.register }
}

/**
 * The first truthy value, or the last value when none is truthy (what a
 * chain of `||` gives).
 *
 * @param {Array} values The candidates, in order
 * @return {string|number|undefined} The chosen value
 */
function firstTruthyOrLast(values) {
	return values.find(Boolean) ?? values[values.length - 1]
}

/**
 * The legacy flat config values of one field of an object type: the
 * `voorzieningen_`-prefixed key first, then the plain one.
 *
 * @param {{config: object, objectType: string}} lookup The legacy config and the object type
 * @param {string} field The field: source, schema or register
 * @return {Array} The two values
 */
function legacyField(lookup, field) {
	const { config, objectType } = lookup
	return [
		config[`voorzieningen_${objectType}_${field}`],
		config[`${objectType}_${field}`],
	]
}

/**
 * The schema config of an object type from the legacy flat config keys.
 *
 * @param {object} settings The loaded settings
 * @param {string} objectType The object type
 * @return {{source: string, schema: string, register: string}} The schema config
 * @throws {Error} When the schema or the register is not configured.
 */
function legacySchemaConfig(settings, objectType) {
	const lookup = { config: settings.configuration || {}, objectType }
	const source = firstTruthyOrLast([
		...legacyField(lookup, 'source'),
		'openregister',
	])
	const schema = firstTruthyOrLast(legacyField(lookup, 'schema'))
	const register = firstTruthyOrLast([
		...legacyField(lookup, 'register'),
		lookup.config.voorzieningen_register,
	])
	if (!schema || !register) {
		throw new Error(
			`Invalid configuration for object type: ${lookup.objectType}. Schema: ${schema}, Register: ${register}`,
		)
	}
	return { source, schema, register }
}

/**
 * Put a saved object in the store's object cache when it has an id.
 *
 * @param {object} store The object store
 * @param {{type: string, result: object}} saved The object type and the saved object
 * @return {void}
 */
function cacheSavedObject(store, saved) {
	const { type: objectType, result } = saved
	if (!result.id) {
		return
	}
	if (!store.objects[objectType]) {
		store.objects = { ...store.objects, [objectType]: {} }
	}
	store.objects[objectType][result.id] = result
}

/**
 * Create or update an object of a registered type, and cache the answer.
 *
 * @param {object} store The object store
 * @param {{type: string, objectData: object}} request The object type and the object data
 * @return {Promise<object>} The saved object
 * @throws {Error} When the save fails.
 */
async function saveTypedObject(store, request) {
	const { type: objectType, objectData } = request
	const id = objectData.id || objectData['@self']?.id
	const isUpdate = Boolean(id)
	const response = await fetch(store._buildUrl(objectType, isUpdate ? id : null), {
		method: isUpdate ? 'PUT' : 'POST',
		headers: buildWriteHeaders(buildHeaders(), {
			organisation: getActiveOrganisationUuid(),
		}),
		body: JSON.stringify(objectData),
	})
	if (!response.ok) {
		throw new Error(`Failed to save ${objectType} object`)
	}
	const result = await response.json()
	cacheSavedObject(store, { type: objectType, result })
	return result
}

/**
 * The register and schema of a legacy save, checked to be present.
 *
 * @param {object} objectItem The object item
 * @param {object|undefined} config The `{ register, schema }` config
 * @return {{register: (string|number|object), schema: (string|number|object)}} The register and schema
 * @throws {Error} When the item, the register or the schema is missing.
 */
function requireLegacyConfig(objectItem, config) {
	const { register, schema } = config || {}
	if (!objectItem || !register || !schema) {
		throw new Error('Object item, register and schema are required')
	}
	return { register, schema }
}

/**
 * The OpenRegister endpoint of a legacy save: the collection for a new
 * object, the object itself for an existing one.
 *
 * @param {object} objectItem The object item
 * @param {object|undefined} config The `{ register, schema }` config
 * @return {string} The endpoint
 * @throws {Error} When the register or schema id cannot be found.
 */
function legacyEndpoint(objectItem, config) {
	const { register, schema } = requireLegacyConfig(objectItem, config)
	const registerId = extractId(register)
	const schemaId = extractId(schema)
	if (!registerId || !schemaId) {
		throw new Error('Could not extract register or schema ID')
	}
	const base = `/index.php/apps/openregister/api/objects/${registerId}/${schemaId}`
	const objectId = objectItem['@self']?.id
	return objectId ? `${base}/${objectId}` : base
}

/**
 * Save an object item with @self metadata straight to OpenRegister,
 * stamping its update time.
 *
 * @param {object} objectItem The object item
 * @param {object|undefined} config The `{ register, schema }` config
 * @return {Promise<{response: Response, data: object}>} The answer and its body
 * @throws {Error} When the config is incomplete or the save fails.
 */
async function saveLegacyObject(objectItem, config) {
	const endpoint = legacyEndpoint(objectItem, config)
	const isNewObject = !objectItem['@self']?.id
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
			metadata: columnMetadata(),

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
					? pagination.next !== null || pagination.pages > pagination.page
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
				const registered = registeredSchemaConfig(
					this.objectTypeRegistry,
					objectType,
				)
				if (registered) {
					return registered
				}
				if (!this.settings) {
					throw new Error('Settings not loaded')
				}
				// The voorzieningen blob holds every catalog type, the AMEF blob
				// the ArchiMate types; the legacy flat keys come last.
				return (
					registerBlobSchemaConfig(
						voorzieningenBlob(this.settings),
						objectType,
					)
					|| registerBlobSchemaConfig(amefBlob(this.settings), objectType)
					|| legacySchemaConfig(this.settings, objectType)
				)
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

					const request = { id, dataType, params }
					const url = relatedDataUrl(
						organisatieData || this.getSchemaConfig(type),
						request,
					)

					const response = await fetch(url, { headers: buildHeaders() })
					if (!response.ok)
						throw new Error(`Failed to fetch ${dataType} for ${type}`)

					storeRelatedAnswer(this, {
						...request,
						type,
						data: await response.json(),
					})
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
					return saveTypedObject(this, {
						type: typeOrObject,
						objectData: dataOrConfig,
					})
				}
				return saveLegacyObject(typeOrObject, dataOrConfig)
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
						(obj) => selectionId(obj) === objectId,
					)
					if (isSelected) {
						this.setSelectedObjects(
							this.selectedObjects.filter(
								(obj) => selectionId(obj) !== objectId,
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
				return postObjectAction(this, { objectItem, action: 'publish' })
			},

			/**
			 * Depublish an object.
			 *
			 * @param {object} objectItem Object to depublish
			 * @return {Promise<object>} Updated object
			 * @spec openspec/specs/fe-stores/spec.md
			 */
			async depublishObject(objectItem) {
				return postObjectAction(this, { objectItem, action: 'depublish' })
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
