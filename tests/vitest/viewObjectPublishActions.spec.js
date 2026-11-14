// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the object view's publish and depublish actions: the dates
 * the modals open with, the endpoint, method and body each path sends to
 * OpenRegister, what happens to the store on success, the error message on a
 * failed response, and the single-object toolbar actions that hand the object
 * to the mass dialogs.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
	setActiveObject: vi.fn(),
	setSelectedObjects: vi.fn(),
	setDialog: vi.fn(),
	fetchPublications: vi.fn(),
}))

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'tok' }))

vi.mock('../../src/store/store.js', () => ({
	catalogStore: { fetchPublications: mocks.fetchPublications },
	navigationStore: { setDialog: mocks.setDialog },
	objectStore: {
		setActiveObject: mocks.setActiveObject,
		setSelectedObjects: mocks.setSelectedObjects,
	},
}))

const { default: ViewObject } =
	await import('../../src/modals/object/ViewObject.vue')

const methods = ViewObject.methods
const BASE = '/index.php/apps/openregister/api/objects/reg/sch/obj-1'

/**
 * A component context for the publish methods.
 *
 * @param {object} overrides Fields to override.
 * @return {object} The context.
 */
function context(overrides = {}) {
	const ctx = {
		currentObject: { id: 'plain', title: 'T', '@self': { id: 'obj-1' } },
		showPublishModal: false,
		showDepublishModal: false,
		publishDate: null,
		depublishDate: null,
		isPublishing: false,
		isDepublishing: false,
		success: null,
		error: null,
		getRegisterSchemaIds: vi.fn(() => ({ registerId: 'reg', schemaId: 'sch' })),
		rebuildObjectWithSchemaProperties: vi.fn((result) => ({ rebuilt: result })),
		...overrides,
	}
	// Every other component method, bound, so a method under test can call
	// the helpers it delegates to.
	for (const [name, fn] of Object.entries(methods)) {
		if (!(name in ctx)) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

/**
 * A fetch response.
 *
 * @param {boolean} ok Whether the response is ok.
 * @param {object} json The JSON body.
 * @return {object} The response.
 */
function response(ok, json = {}) {
	return {
		ok,
		status: ok ? 200 : 409,
		statusText: ok ? 'OK' : 'Conflict',
		json: vi.fn(async () => json),
		text: vi.fn(async () => 'locked'),
	}
}

beforeEach(() => {
	vi.useFakeTimers()
	vi.spyOn(console, 'error').mockImplementation(() => {})
	Object.values(mocks).forEach((mock) => mock.mockReset())
})

afterEach(() => {
	vi.useRealTimers()
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('ViewObject publish modals', () => {
	it('opens the publish modal with the published date, or none', () => {
		const ctx = context({ currentObject: { '@self': { published: '2026-01-02T03:04:05Z' } } })
		methods.openPublishModal.call(ctx)
		expect(ctx.showPublishModal).toBe(true)
		expect(ctx.publishDate.toISOString()).toBe('2026-01-02T03:04:05.000Z')

		const bare = context({ currentObject: { '@self': {} }, publishDate: new Date() })
		methods.openPublishModal.call(bare)
		expect(bare.publishDate).toBeNull()
	})

	it('opens the depublish modal with the depublished date, or none', () => {
		const ctx = context({ currentObject: { '@self': { depublished: '2026-05-06T07:08:09Z' } } })
		methods.openDepublishModal.call(ctx)
		expect(ctx.showDepublishModal).toBe(true)
		expect(ctx.depublishDate.toISOString()).toBe('2026-05-06T07:08:09.000Z')

		const bare = context({ currentObject: {}, depublishDate: new Date() })
		methods.openDepublishModal.call(bare)
		expect(bare.depublishDate).toBeNull()
	})

	it('resets modal, date and busy flag on close', () => {
		const ctx = context({ showPublishModal: true, publishDate: new Date(), isPublishing: true,
			showDepublishModal: true, depublishDate: new Date(), isDepublishing: true })
		methods.closePublishModal.call(ctx)
		methods.closeDepublishModal.call(ctx)
		expect(ctx).toMatchObject({ showPublishModal: false, publishDate: null, isPublishing: false,
			showDepublishModal: false, depublishDate: null, isDepublishing: false })
	})
})

describe('ViewObject publishObject', () => {
	it('publishes now with a POST to the publish endpoint', async () => {
		const fetch = vi.fn(async () => response(true, { id: 'obj-1' }))
		vi.stubGlobal('fetch', fetch)
		const ctx = context()

		await methods.publishObject.call(ctx)

		expect(fetch).toHaveBeenCalledWith(`${BASE}/publish`, {
			method: 'POST', headers: { requesttoken: 'tok' }, body: undefined,
		})
		expect(ctx.rebuildObjectWithSchemaProperties).toHaveBeenCalledWith({ id: 'obj-1' })
		expect(mocks.setActiveObject).toHaveBeenCalledWith('publication', { rebuilt: { id: 'obj-1' } })
		expect(mocks.fetchPublications).toHaveBeenCalledTimes(1)
		expect(ctx.success).toBe('Object published successfully')
		expect(ctx.isPublishing).toBe(false)
		vi.advanceTimersByTime(3000)
		expect(ctx.success).toBeNull()
	})

	it('publishes on a date with a PUT of the object carrying that date', async () => {
		const fetch = vi.fn(async () => response(true, {}))
		vi.stubGlobal('fetch', fetch)
		const ctx = context({ showPublishModal: true, publishDate: new Date('2026-03-01T00:00:00Z') })

		await methods.publishObject.call(ctx)

		const [url, init] = fetch.mock.calls[0]
		expect(url).toBe(BASE)
		expect(init.method).toBe('PUT')
		expect(init.headers).toEqual({ 'Content-Type': 'application/json', requesttoken: 'tok' })
		expect(JSON.parse(init.body)).toEqual({
			id: 'plain', title: 'T', '@self': { id: 'obj-1', published: '2026-03-01T00:00:00.000Z' },
		})
		expect(ctx.showPublishModal).toBe(false)
	})

	// The thrown message is prefixed again by the catch, so the prefix shows twice.
	it('reports the status and body of a failed publish', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => response(false)))
		const ctx = context()

		await methods.publishObject.call(ctx)

		expect(ctx.error).toBe('Failed to publish object: Failed to publish object: 409 Conflict - locked')
		expect(mocks.setActiveObject).not.toHaveBeenCalled()
		expect(ctx.isPublishing).toBe(false)
		vi.advanceTimersByTime(5000)
		expect(ctx.error).toBeNull()
	})

	it('refuses to publish without an object', async () => {
		const fetch = vi.fn()
		vi.stubGlobal('fetch', fetch)
		const ctx = context({ currentObject: null })

		await methods.publishObject.call(ctx)

		expect(fetch).not.toHaveBeenCalled()
		expect(ctx.error).toBe('Failed to publish object: No object to publish')
	})
})

describe('ViewObject depublishObject', () => {
	it('depublishes now with a POST, falling back to the plain id', async () => {
		const fetch = vi.fn(async () => response(true, { id: 'plain' }))
		vi.stubGlobal('fetch', fetch)
		const ctx = context({ currentObject: { id: 'plain' } })

		await methods.depublishObject.call(ctx)

		expect(fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/reg/sch/plain/depublish', {
			method: 'POST', headers: { requesttoken: 'tok' }, body: undefined,
		})
		expect(mocks.setActiveObject).toHaveBeenCalledWith('publication', { rebuilt: { id: 'plain' } })
		expect(ctx.success).toBe('Object depublished successfully')
		expect(ctx.isDepublishing).toBe(false)
	})

	it('depublishes on a date with a PUT of the object carrying that date', async () => {
		const fetch = vi.fn(async () => response(true, {}))
		vi.stubGlobal('fetch', fetch)
		const ctx = context({ showDepublishModal: true, depublishDate: '2026-04-01' })

		await methods.depublishObject.call(ctx)

		const [url, init] = fetch.mock.calls[0]
		expect(url).toBe(BASE)
		expect(init.method).toBe('PUT')
		expect(JSON.parse(init.body)['@self']).toEqual({ id: 'obj-1', depublished: '2026-04-01' })
		expect(ctx.showDepublishModal).toBe(false)
	})

	it('reports a failed depublish and refuses without an object', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => response(false)))
		const ctx = context()
		await methods.depublishObject.call(ctx)
		expect(ctx.error).toBe('Failed to depublish object: Failed to depublish object: 409 Conflict - locked')

		const none = context({ currentObject: null })
		await methods.depublishObject.call(none)
		expect(none.error).toBe('Failed to depublish object: No object to depublish')
	})
})

describe('ViewObject single-object actions', () => {
	it('offers publish for an unpublished object and depublish for a published one', () => {
		expect(methods.shouldShowPublishAction({ '@self': { published: null } })).toBe(true)
		expect(methods.shouldShowPublishAction({ '@self': {} })).toBe(true)
		expect(methods.shouldShowPublishAction({ '@self': { published: '2026-01-01' } })).toBe(false)
		expect(methods.shouldShowPublishAction({ id: 'x' })).toBe(false)
		expect(methods.shouldShowPublishAction(null)).toBe(false)

		expect(methods.shouldShowDepublishAction({ '@self': { published: '2026-01-01' } })).toBe(true)
		expect(methods.shouldShowDepublishAction({ '@self': { published: null } })).toBe(false)
		expect(methods.shouldShowDepublishAction({ '@self': {} })).toBe(false)
		expect(methods.shouldShowDepublishAction(undefined)).toBe(false)
	})

	it.each([
		['singlePublishObject', 'massPublishObjects'],
		['singleDepublishObject', 'massDepublishObjects'],
		['singleDeleteObject', 'massDeleteObject'],
	])('%s selects the object under its @self id and opens %s', (method, dialog) => {
		methods[method].call({ currentObject: { id: 'plain', title: 'T', '@self': { id: 'uuid' } } })
		expect(mocks.setSelectedObjects).toHaveBeenCalledWith([{ id: 'uuid', title: 'T', '@self': { id: 'uuid' } }])
		expect(mocks.setDialog).toHaveBeenCalledWith(dialog)
	})

	it('selects the plain id without @self and does nothing without an object', () => {
		methods.singlePublishObject.call({ currentObject: { id: 'plain' } })
		expect(mocks.setSelectedObjects).toHaveBeenCalledWith([{ id: 'plain' }])

		mocks.setSelectedObjects.mockReset()
		mocks.setDialog.mockReset()
		for (const method of ['singlePublishObject', 'singleDepublishObject', 'singleDeleteObject']) {
			methods[method].call({ currentObject: null })
		}
		expect(mocks.setSelectedObjects).not.toHaveBeenCalled()
		expect(mocks.setDialog).not.toHaveBeenCalled()
	})

	it('opens the upload dialog and edits and cancels file labels', () => {
		methods.uploadFiles.call({})
		expect(mocks.setDialog).toHaveBeenCalledWith('uploadFiles')

		const ctx = { editingTags: null, editedTags: [] }
		methods.editFileLabels.call(ctx, { id: 'f1', labels: ['a'] })
		expect(ctx).toEqual({ editingTags: 'f1', editedTags: ['a'] })
		methods.editFileLabels.call(ctx, { id: 'f2' })
		expect(ctx).toEqual({ editingTags: 'f2', editedTags: [] })
		methods.cancelFileLabelEditing.call(ctx)
		expect(ctx).toEqual({ editingTags: null, editedTags: [] })
	})
})
