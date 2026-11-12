// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The object store plugin's own state, getters and actions, run against a
 * plain store context: schema lookup from the three configuration sources,
 * related data and its pagination, saving, patching, copying, deleting,
 * publishing and locking objects, mass operations, selection and column
 * filters.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	buildHeaders: (type) => ({ kind: type === null ? 'none' : 'json' }),
	buildQueryString: (params) => {
		const entries = Object.entries(params || {})
		return entries.length ? '?' + entries.map(([k, v]) => `${k}=${v}`).join('&') : ''
	},
}))
vi.mock('../../src/composables/orClient.js', () => ({
	OR_API_BASE: '/index.php/apps/openregister/api',
	buildWriteHeaders: (headers, options) => ({ ...headers, ...options }),
	getActiveOrganisationUuid: () => 'org-1',
	objectActionPath: (action) => (action ? `/${action}` : ''),
	withLanguageParam: (url) => `${url}#lang`,
}))

const { stackiqPlugin } = await import('../../src/store/plugins/stackiqPlugin.js')

const OR = '/index.php/apps/openregister/api/objects'

/**
 * A store context with the plugin's state, getters and actions bound to it.
 *
 * @param {object} [extra] State to add or override.
 * @return {object} The context.
 */
function makeStore(extra = {}) {
	const plugin = stackiqPlugin()
	const ctx = {
		...plugin.state(),
		loading: {},
		objects: {},
		collections: {},
		pagination: {},
		errors: {},
		objectTypeRegistry: {},
		_buildUrl: (type, id) => `/u/${type}${id ? '/' + id : ''}`,
		fetchCollection: vi.fn(),
		...extra,
	}
	for (const [name, getter] of Object.entries(plugin.getters)) {
		Object.defineProperty(ctx, name, { get: () => getter(ctx), configurable: true })
	}
	for (const [name, action] of Object.entries(plugin.actions)) {
		ctx[name] = action.bind(ctx)
	}
	return ctx
}

/**
 * A fetch Response double.
 *
 * @param {*} body The JSON body.
 * @param {object} [init] Fields to override.
 * @return {object} The response.
 */
const reply = (body, init = {}) => ({ ok: true, status: 200, statusText: 'OK', json: async () => body, ...init })

let fetchSpy

beforeEach(() => {
	fetchSpy = vi.fn(async () => reply({}))
	vi.stubGlobal('fetch', fetchSpy)
	vi.spyOn(console, 'error').mockImplementation(() => {})
	vi.spyOn(console, 'warn').mockImplementation(() => {})
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

const item = { id: 'o1', '@self': { id: 'o1', register: { id: 'r1' }, schema: 's1', name: 'Thing' } }

describe('getters', () => {
	it('lists object types, registers and their schemas', () => {
		const store = makeStore({ settings: { objectTypes: ['app'], availableRegisters: [{ id: 'r', title: 'R', schemas: [{ id: 's' }] }] } })
		expect(store.objectTypes).toEqual(['app'])
		expect(store.availableSchemas).toEqual([{ id: 's', registerId: 'r', registerTitle: 'R' }])
		const empty = makeStore()
		expect([empty.objectTypes, empty.availableRegisters, empty.availableSchemas]).toEqual([[], [], []])
	})

	it('reads active objects, related data, audit trails and the state per type', () => {
		const store = makeStore({ activeObjects: { app: { id: 1 } }, relatedData: { app: { logs: [1], files: [2] } }, success: { app: true }, errors: { app: 'x' } })
		expect([store.getActiveObject('app'), store.getActiveObject('none')]).toEqual([{ id: 1 }, null])
		expect([store.getRelatedData('app', 'files'), store.getRelatedData('app', 'uses'), store.getAuditTrails('app'), store.getAuditTrails('none')]).toEqual([[2], null, [1], []])
		expect([store.getState('app'), store.getState('none')]).toEqual([{ success: true, error: 'x' }, { success: null, error: null }])
	})

	it('knows whether there are more or previous pages', () => {
		const store = makeStore({ pagination: { a: { next: null, pages: 3, page: 1, prev: null }, b: { next: null, pages: 1, page: 1, prev: 'p' } } })
		expect([store.hasMorePages('a'), store.hasPreviousPages('a'), store.hasMorePages('b'), store.hasPreviousPages('b'), store.hasMorePages('c'), store.hasPreviousPages('c')])
			.toEqual([true, false, false, true, false, false])
	})

	it('lists the enabled metadata and properties as columns', () => {
		const store = makeStore({ properties: { size: { label: 'Size', enabled: true }, hidden: { label: 'H', enabled: false } } })
		expect(store.enabledMetadata.map((m) => m.id)).toEqual(['meta_name', 'meta_files'])
		expect(store.enabledProperties).toEqual([{ id: 'prop_size', key: 'size', label: 'Size', enabled: true }])
		expect(store.enabledColumns.map((c) => c.id)).toEqual(['meta_name', 'meta_files', 'prop_size'])
	})

	it('reads a collection from a list or an envelope, empty when missing', () => {
		const store = makeStore({ collections: { a: [1], b: { results: [2], total: 1 } } })
		expect([store.getCollection('a'), store.getCollection('b'), store.getCollection('c')]).toEqual([{ results: [1] }, { results: [2], total: 1 }, { results: [] }])
	})
})

describe('getSchemaConfig', () => {
	it('prefers the registered object type', () => {
		const store = makeStore({ objectTypeRegistry: { app: { schema: 's', register: 'r' } } })
		expect(store.getSchemaConfig('app')).toEqual({ source: 'openregister', schema: 's', register: 'r' })
	})

	it('falls back to the voorzieningen blob, then the AMEF blob, then the legacy keys', () => {
		const store = makeStore({ settings: { voorzieningen: { register: 'vr', app_schema: 'vs' }, amefConfig: { register: 'ar', element_schema: 'as' }, configuration: { voorzieningen_register: 'lr', contact_schema: 'cs', contact_source: '' } } })
		expect(store.getSchemaConfig('app')).toEqual({ source: 'openregister', schema: 'vs', register: 'vr' })
		expect(store.getSchemaConfig('element')).toEqual({ source: 'openregister', schema: 'as', register: 'ar' })
		expect(store.getSchemaConfig('contact')).toEqual({ source: 'openregister', schema: 'cs', register: 'lr' })
	})

	it('refuses without settings or without a schema and register', () => {
		expect(() => makeStore().getSchemaConfig('app')).toThrow('Settings not loaded')
		expect(() => makeStore({ settings: {} }).getSchemaConfig('app')).toThrow('Invalid configuration for object type: app')
	})
})

describe('settings', () => {
	it('loads the settings, the voorzieningen config, and registers the app schemas', async () => {
		fetchSpy.mockImplementation(async (url) => {
			if (url.endsWith('/api/settings')) {
				return reply({ availableRegisters: [{ id: 'r1', slug: 'stackiq', schemas: [{ slug: 'app', id: 's1' }, { slug: 'org', id: 's2' }] }, { slug: 'other' }] })
			}
			return reply({ success: true, config: { register: 'r1' } })
		})
		const store = makeStore({ registerObjectType: vi.fn() })
		await store.fetchSettings()
		expect(store.settings.voorzieningen).toEqual({ register: 'r1' })
		expect(store.registerObjectType.mock.calls).toEqual([['app', 's1', 'r1'], ['org', 's2', 'r1']])
	})

	it('keeps going without the voorzieningen config, and fails when the settings cannot load', async () => {
		fetchSpy.mockImplementation(async (url) => (url.endsWith('/api/settings') ? reply({}) : reply({}, { ok: false })))
		const store = makeStore({ registerObjectType: vi.fn() })
		await store.fetchSettings()
		expect(store.settings).toEqual({})
		expect(store.registerObjectType).not.toHaveBeenCalled()
		fetchSpy.mockImplementation(async () => reply({}, { ok: false }))
		await expect(store.fetchSettings()).rejects.toThrow('Failed to fetch settings')
	})
})

describe('related data', () => {
	it('fetches the audit trail with its pagination and stores the results', async () => {
		fetchSpy.mockResolvedValueOnce(reply({ results: [{ id: 'l' }], total: 45, page: 2, limit: '20', next: 'n' }))
		const store = makeStore({ settings: {}, objectTypeRegistry: { app: { schema: { id: 's1' }, register: 'r1' } } })
		await store.fetchRelatedData('app', 'o1', 'logs', { _limit: 20 })
		expect(fetchSpy.mock.calls[0][0]).toBe(`${OR}/r1/s1/o1/audit-trails?_limit=20#lang`)
		expect(store.relatedData.app.logs).toEqual([{ id: 'l' }])
		expect(store.pagination.app_logs).toEqual({ total: 45, page: 2, pages: 3, limit: 20, next: 'n', prev: null })
		expect(store.loading.app_o1_logs).toBe(false)
	})

	it('extends uses and used with the schema, and defaults the file page size', async () => {
		fetchSpy.mockResolvedValueOnce(reply([{ id: 'u' }])).mockResolvedValueOnce(reply({ total: 3 }))
		const store = makeStore({ settings: {} })
		const org = { source: 'openregister', schema: 's', register: 'r' }
		await store.fetchRelatedData('organization', 'o1', 'uses', {}, org)
		await store.fetchRelatedData('organization', 'o1', 'files', {}, org)
		expect(fetchSpy.mock.calls[0][0]).toBe(`${OR}/r/s/o1/uses?_extend=@self.schema#lang`)
		expect(store.relatedData.organization).toEqual({ uses: [{ id: 'u' }], files: { total: 3 } })
		expect(store.pagination.organization_files).toMatchObject({ limit: 500, pages: 1 })
	})

	it('logs a failed read and clears the loading flag', async () => {
		fetchSpy.mockResolvedValueOnce(reply({}, { ok: false }))
		const store = makeStore({ settings: {} })
		await store.fetchRelatedData('organization', 'o1', 'logs', {}, { schema: 's', register: 'r' })
		expect(console.error).toHaveBeenCalled()
		expect([store.relatedData, store.loading.organization_o1_logs]).toEqual([{}, false])
	})

	it('loads all four kinds of related data for an active object, and clears them again', async () => {
		const store = makeStore({ settings: {} })
		store.fetchRelatedData = vi.fn()
		await store.setActiveObject('organization', { id: 'o1', '@self': { schema: 's', register: 'r' } })
		expect(store.fetchRelatedData.mock.calls.map((c) => [c[2], c[3]._limit, c[4]])).toEqual([
			['logs', 20, { source: 'openregister', schema: 's', register: 'r' }],
			['uses', 20, { source: 'openregister', schema: 's', register: 'r' }],
			['used', 20, { source: 'openregister', schema: 's', register: 'r' }],
			['files', 500, { source: 'openregister', schema: 's', register: 'r' }],
		])
		await store.setActiveObject('app', null)
		expect(store.fetchRelatedData).toHaveBeenCalledTimes(4)
		store.clearActiveObject('organization')
		expect([store.activeObjects.organization, store.relatedData.organization]).toEqual([null, { logs: null, uses: null, used: null, files: null }])
		store.setObjectItem({ id: 'x' })
		expect(store.objectItem).toEqual({ id: 'x' })
	})
})

describe('saving, patching and copying', () => {
	it('creates a typed object with the organisation header and caches it, and updates by id', async () => {
		fetchSpy.mockResolvedValueOnce(reply({ id: 'n1', name: 'New' })).mockResolvedValueOnce(reply({ id: 'n1', name: 'Renamed' }))
		const store = makeStore()
		await store.saveObject('app', { name: 'New' })
		expect(fetchSpy.mock.calls[0][0]).toBe('/u/app')
		expect(fetchSpy.mock.calls[0][1]).toMatchObject({ method: 'POST', headers: { kind: 'json', organisation: 'org-1' } })
		await store.saveObject('app', { '@self': { id: 'n1' }, name: 'Renamed' })
		expect(fetchSpy.mock.calls[1][0]).toBe('/u/app/n1')
		expect(fetchSpy.mock.calls[1][1].method).toBe('PUT')
		expect(store.objects.app.n1).toEqual({ id: 'n1', name: 'Renamed' })
		fetchSpy.mockResolvedValueOnce(reply({ name: 'no id' }))
		await store.saveObject('other', {})
		expect(store.objects.other).toBeUndefined()
		fetchSpy.mockResolvedValueOnce(reply({}, { ok: false }))
		await expect(store.saveObject('app', {})).rejects.toThrow('Failed to save app object')
	})

	it('saves an object item through its own register and schema, stamping the update', async () => {
		fetchSpy.mockResolvedValueOnce(reply({ ok: 1 }))
		const store = makeStore()
		const fresh = { title: 'T' }
		const { data } = await store.saveObject(fresh, { register: { id: 'r' }, schema: 's' })
		expect(data).toEqual({ ok: 1 })
		expect(fetchSpy.mock.calls[0][0]).toBe(`${OR}/r/s`)
		expect(fetchSpy.mock.calls[0][1].method).toBe('POST')
		expect(fresh['@self'].updated).toMatch(/^\d{4}-/)
		fetchSpy.mockResolvedValueOnce(reply({}))
		await store.saveObject({ '@self': { id: 'o1' } }, { register: 'r', schema: 's' })
		expect(fetchSpy.mock.calls[1]).toEqual([`${OR}/r/s/o1`, expect.objectContaining({ method: 'PUT' })])
		await expect(store.saveObject(null, { register: 'r', schema: 's' })).rejects.toThrow('Object item, register and schema are required')
		await expect(store.saveObject({}, { register: {}, schema: 's' })).rejects.toThrow('Could not extract register or schema ID')
		fetchSpy.mockResolvedValueOnce(reply({}, { ok: false, status: 422, statusText: 'Invalid' }))
		await expect(store.saveObject({}, { register: 'r', schema: 's' })).rejects.toThrow('Failed to save object: 422 Invalid')
	})

	it('patches an object in the target language and refreshes the active copy', async () => {
		fetchSpy.mockResolvedValueOnce(reply({ id: 'o1', name: 'Nieuw' }))
		const store = makeStore({ settings: {}, objectTypeRegistry: { app: { schema: 's', register: { id: 'r' } } }, activeObjects: { app: { id: 'o1' } } })
		expect(await store.patchObject('app', 'o1', { name: 'Nieuw' }, 'nl')).toEqual({ id: 'o1', name: 'Nieuw' })
		expect(fetchSpy.mock.calls[0][0]).toBe(`${OR}/r/s/o1`)
		expect(fetchSpy.mock.calls[0][1]).toMatchObject({ method: 'PATCH', headers: { targetLang: 'nl', organisation: 'org-1' } })
		expect([store.objects.app.o1, store.activeObjects.app, store.success.app, store.errors.app]).toEqual([{ id: 'o1', name: 'Nieuw' }, { id: 'o1', name: 'Nieuw' }, true, null])
		fetchSpy.mockResolvedValueOnce(reply({}, { ok: false }))
		await expect(store.patchObject('app', 'o2', {})).rejects.toThrow('Failed to patch app object')
		expect([store.success.app, store.errors.app, store.loading.app_o2]).toEqual([false, 'Failed to patch app object', false])
	})

	it('copies a cached object under a new title or name', async () => {
		const store = makeStore({ objects: { app: { a: { id: 'a', title: 'T' }, b: { id: 'b', name: 'N' }, c: { id: 'c' } } } })
		store.saveObject = vi.fn(async (type, data) => data)
		expect(await store.copyObject('app', 'a')).toEqual({ title: 'Kopie van T' })
		expect(await store.copyObject('app', 'b')).toEqual({ name: 'Kopie van N' })
		expect(await store.copyObject('app', 'c')).toEqual({})
		await expect(store.copyObject('app', 'zz')).rejects.toThrow('Object zz of type app not found')
	})
})

describe('deleting, publishing and locking', () => {
	it('deletes by type and id, or an item that it also deselects', async () => {
		const store = makeStore({ selectedObjects: ['o1', { id: 'o2' }] })
		expect(await store.deleteObject('app', 'x')).toBe(true)
		expect(fetchSpy.mock.calls[0]).toEqual(['/u/app/x', { method: 'DELETE', headers: { kind: 'json' } }])
		expect(await store.deleteObject(item)).toBe(true)
		expect(fetchSpy.mock.calls[1][0]).toBe(`${OR}/r1/s1/o1`)
		expect([store.selectedObjects, store.loading.delete_o1]).toEqual([[{ id: 'o2' }], false])
		fetchSpy.mockResolvedValueOnce(reply({}, { ok: false }))
		await expect(store.deleteObject('app', 'x')).rejects.toThrow('Failed to delete app object')
		fetchSpy.mockResolvedValueOnce(reply({}, { ok: false, status: 403, statusText: 'Forbidden' }))
		await expect(store.deleteObject(item)).rejects.toThrow('Failed to delete object: 403 Forbidden')
		await expect(store.deleteObject({ id: 'x' })).rejects.toThrow('Object must have id, register, and schema information')
	})

	it('publishes and depublishes on the action route', async () => {
		fetchSpy.mockResolvedValue(reply({ published: true }))
		const store = makeStore()
		expect(await store.publishObject(item)).toEqual({ published: true })
		await store.depublishObject(item)
		expect(fetchSpy.mock.calls.map((c) => c[0])).toEqual([`${OR}/r1/s1/o1/publish`, `${OR}/r1/s1/o1/depublish`])
		expect(store.loading).toEqual({ publish_o1: false, depublish_o1: false })
		fetchSpy.mockResolvedValueOnce(reply({}, { ok: false, status: 409, statusText: 'Conflict' }))
		await expect(store.publishObject(item)).rejects.toThrow('Failed to publish object: 409 Conflict')
	})

	it('locks with a process and duration only when given, and unlocks', async () => {
		fetchSpy.mockResolvedValue(reply({ locked: true }))
		const store = makeStore()
		await store.lockObject(item, 'review', 3600)
		await store.lockObject(item)
		await store.unlockObject(item)
		expect(fetchSpy.mock.calls[0][1]).toEqual({ method: 'POST', headers: { kind: 'json' }, body: JSON.stringify({ process: 'review', duration: 3600 }) })
		expect(fetchSpy.mock.calls[1][1]).toEqual({ method: 'POST', headers: { kind: 'none' }, body: undefined })
		expect(fetchSpy.mock.calls[2][0]).toBe(`${OR}/r1/s1/o1/unlock`)
		fetchSpy.mockResolvedValue(reply({}, { ok: false, status: 423, statusText: 'Locked' }))
		await expect(store.lockObject(item)).rejects.toThrow('Failed to lock object: 423 Locked')
		await expect(store.unlockObject(item)).rejects.toThrow('Failed to unlock object: 423 Locked')
		expect([store.loading.lock_o1, store.loading.unlock_o1]).toEqual([false, false])
	})

	it('validates an object by saving it through its own register and schema', async () => {
		const store = makeStore()
		store.saveObject = vi.fn(async () => ({ data: { valid: true } }))
		expect(await store.validateObject(item)).toEqual({ valid: true })
		expect(store.saveObject).toHaveBeenCalledWith(item, { register: 'r1', schema: 's1' })
	})

	it('downloads an object as JSON named after it', async () => {
		URL.createObjectURL = vi.fn(() => 'blob:x')
		URL.revokeObjectURL = vi.fn()
		let name = ''
		vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () { name = this.download })
		fetchSpy.mockResolvedValueOnce(reply({ a: 1 }))
		const store = makeStore()
		expect(await store.downloadObject(item)).toEqual({ ok: true })
		expect(fetchSpy.mock.calls[0][0]).toBe(`${OR}/r1/s1/o1#lang`)
		expect(name).toBe('Thing.json')
		await expect(store.downloadObject({})).rejects.toThrow('Object ID is required for download')
		fetchSpy.mockResolvedValueOnce(reply({}, { ok: false, statusText: 'Gone' }))
		await expect(store.downloadObject(item)).rejects.toThrow('Failed to download object: Gone')
	})
})

describe('mass operations', () => {
	it('runs an operation on every object, keeps the errors per object and deselects the successes', async () => {
		const store = makeStore({ selectedObjects: ['a', 'b', 'c'], objectErrors: { old: 'x' } })
		store.publishObject = vi.fn(async (obj) => {
			if (obj.id === 'b') {
				throw new Error('Refused')
			}
		})
		const progress = []
		const { successful, failed } = await store.massPublishObjects([{ id: 'a' }, { id: 'b' }, { '@self': { id: 'c' } }], (obj, ok, error) => progress.push([obj.id || obj['@self'].id, ok, error]))
		expect(successful.map((r) => r.id)).toEqual(['a', 'c'])
		expect(failed).toEqual([{ success: false, id: 'b', object: { id: 'b' }, error: 'Refused' }])
		expect(store.selectedObjects).toEqual(['b'])
		expect(store.objectErrors).toEqual({ b: 'Refused' })
		expect(store.getObjectError('b')).toBe('Refused')
		expect(store.getObjectError('a')).toBeNull()
		expect(progress).toEqual([['a', true, undefined], ['b', false, 'Refused'], ['c', true, undefined]])
	})

	it('routes each mass action to its single action', async () => {
		const store = makeStore()
		for (const name of ['depublishObject', 'deleteObject', 'lockObject', 'unlockObject', 'validateObject']) {
			store[name] = vi.fn(async () => {})
		}
		await store.massDepublishObjects([{ id: 'a' }])
		await store.massDeleteObjects([{ id: 'a' }])
		await store.massLockObjects([{ id: 'a' }], 'p', 60)
		await store.massUnlockObjects([{ id: 'a' }])
		await store.massValidateObjects([{ id: 'a' }])
		expect(store.lockObject).toHaveBeenCalledWith({ id: 'a' }, 'p', 60)
		for (const name of ['depublishObject', 'deleteObject', 'unlockObject', 'validateObject']) {
			expect(store[name]).toHaveBeenCalledWith({ id: 'a' })
		}
	})
})

describe('selection, errors and columns', () => {
	it('selects all organisations, and clears the selection when all were selected', () => {
		const store = makeStore({ collections: { organization: { results: [{ '@self': { id: 'a' } }, { id: 'b' }] } } })
		expect(store.isAllSelected).toBe(false)
		store.toggleSelectAllObjects()
		expect([store.selectedObjects, store.isAllSelected]).toEqual([['a', 'b'], true])
		store.toggleSelectAllObjects()
		expect(store.selectedObjects).toEqual([])
		const none = makeStore()
		none.toggleSelectAllObjects()
		expect([none.selectedObjects, none.isAllSelected]).toEqual([[], false])
	})

	it('keeps and clears object errors', () => {
		const store = makeStore()
		store.setObjectError('a', 'x')
		store.setObjectError('b', 'y')
		store.clearObjectError('a')
		expect(store.objectErrors).toEqual({ b: 'y' })
		store.clearAllObjectErrors()
		expect(store.objectErrors).toEqual({})
	})

	it('builds properties from a schema and toggles metadata and property columns', () => {
		const store = makeStore()
		store.initializeProperties({ properties: { size: { title: 'Size' }, tag: {} } })
		expect(store.properties).toEqual({
			size: { label: 'Size', key: 'size', description: 'Property: size', enabled: false },
			tag: { label: 'tag', key: 'tag', description: 'Property: tag', enabled: false },
		})
		store.updateColumnFilter('prop_size', true)
		store.updateColumnFilter('meta_name', false)
		store.updateColumnFilter('meta_unknown', true)
		store.updateColumnFilter('prop_unknown', true)
		store.updateColumnFilter('other', true)
		expect([store.properties.size.enabled, store.metadata.name.enabled]).toEqual([true, false])
		store.initializeColumnFilters()
		expect(store.columnFilters).toMatchObject({ meta_name: false, meta_files: true, prop_size: true, prop_tag: false })
		store.initializeProperties(null)
		expect(store.properties).toEqual({})
	})

	it('refreshes every registered collection, keeps state per type, and clears everything', () => {
		const store = makeStore({ objectTypeRegistry: { app: {}, org: {} } })
		store.refreshObjectList()
		expect(store.fetchCollection.mock.calls).toEqual([['app'], ['org']])
		store.setState('app', { success: true })
		store.setState('app', { error: 'x' })
		expect([store.success.app, store.errors.app]).toEqual([true, 'x'])
		Object.assign(store, { objectItem: 1, activeObjects: { a: 1 }, relatedData: { a: 1 }, selectedObjects: [1], objectErrors: { a: 1 } })
		store.clearSoftwarecatalog()
		expect([store.objectItem, store.activeObjects, store.relatedData, store.selectedObjects, store.objectErrors]).toEqual([null, {}, {}, [], {}])
	})
})

describe('merge and mappings', () => {
	it('merges two objects and reads the mappings', async () => {
		fetchSpy.mockResolvedValueOnce(reply({ merged: true })).mockResolvedValueOnce(reply([{ id: 'm' }]))
		const store = makeStore()
		expect(await store.mergeObjects({ register: 'r', schema: 's', sourceObjectId: 'a', target: 'b', object: {}, fileAction: 'move', relationAction: 'keep' })).toEqual({ data: { merged: true } })
		expect(fetchSpy.mock.calls[0][0]).toBe(`${OR}/r/s/a/merge`)
		expect(JSON.parse(fetchSpy.mock.calls[0][1].body)).toEqual({ target: 'b', object: {}, fileAction: 'move', relationAction: 'keep' })
		expect(await store.getMappings()).toEqual({ data: [{ id: 'm' }] })
		fetchSpy.mockResolvedValue(reply({}, { ok: false, statusText: 'Bad' }))
		await expect(store.mergeObjects({})).rejects.toThrow('Failed to merge objects: Bad')
		await expect(store.getMappings()).rejects.toThrow('Failed to fetch mappings')
	})
})
