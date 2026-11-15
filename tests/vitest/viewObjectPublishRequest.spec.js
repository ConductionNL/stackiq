// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for publishing and depublishing from the object
 * view: the request for a dated action from the modal (PUT of the object
 * with the date), the modal without a date and the direct action, the
 * stored result, the modal state, the messages and their timers, and the
 * failures (no object, refused response, failed request).
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { getRequestToken } from '@nextcloud/auth'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const stores = vi.hoisted(() => ({
	catalogStore: { fetchPublications: vi.fn() },
	navigationStore: {},
	objectStore: { setActiveObject: vi.fn(), availableSchemas: [] },
}))

vi.mock('../../src/store/store.js', () => stores)

const { default: ViewObject } =
	await import('../../src/modals/object/ViewObject.vue')

const OBJECT = {
	id: 'o1',
	title: 'T',
	'@self': { id: 's1', register: 'r', schema: 'sc', published: null },
}

/**
 * A stand-in for the component with the given state and every method bound.
 *
 * @param {object} state The component state.
 * @return {object} The stand-in.
 */
function viewModel(state) {
	const vm = {
		currentObject: OBJECT,
		formData: {},
		success: null,
		error: null,
		isPublishing: false,
		isDepublishing: false,
		showPublishModal: false,
		showDepublishModal: false,
		publishDate: null,
		depublishDate: null,
		getSchemaProperties: () => ({ extra: { type: 'string' } }),
		...state,
	}
	for (const [name, fn] of Object.entries(ViewObject.methods)) {
		if (!(name in vm)) {
			vm[name] = fn.bind(vm)
		}
	}
	return vm
}

let fetch

beforeEach(() => {
	vi.useFakeTimers()
	stores.objectStore.setActiveObject.mockReset()
	stores.catalogStore.fetchPublications.mockReset()
	fetch = vi.fn(async () => ({
		ok: true,
		json: async () => ({ id: 'o1', '@self': { published: 'now' } }),
	}))
	vi.stubGlobal('fetch', fetch)
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.useRealTimers()
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

/**
 * Runs one action and records what happened.
 *
 * @param {string} method publishObject or depublishObject.
 * @param {object} state The component state.
 * @return {Promise<object>} The request, store calls and component state.
 */
async function run(method, state = {}) {
	const vm = viewModel(state)
	await vm[method]()
	const out = {
		request: fetch.mock.calls[0] ?? null,
		active: stores.objectStore.setActiveObject.mock.calls,
		refreshed: stores.catalogStore.fetchPublications.mock.calls.length,
		success: vm.success,
		error: vm.error,
		busy: [vm.isPublishing, vm.isDepublishing],
		modals: [
			vm.showPublishModal,
			vm.showDepublishModal,
			vm.publishDate,
			vm.depublishDate,
		],
	}
	vi.advanceTimersByTime(3000)
	out.successAfter3s = vm.success
	out.errorAfter3s = vm.error
	vi.advanceTimersByTime(2000)
	out.errorAfter5s = vm.error
	return out
}

const BASE = '/index.php/apps/openregister/api/objects/r/sc/s1'

describe('ViewObject publishObject and depublishObject', () => {
	it('publishes and depublishes now', async () => {
		const published = await run('publishObject')
		expect(published.request).toEqual([
			BASE + '/publish',
			{ method: 'POST', headers: { requesttoken: getRequestToken() }, body: undefined },
		])
		expect(published.active).toEqual([
			[
				'publication',
				{
					id: 'o1',
					title: 'T',
					'@self': {
						id: 's1',
						register: 'r',
						schema: 'sc',
						published: 'now',
					},
					extra: '',
				},
			],
		])
		expect(published).toMatchObject({
			refreshed: 1,
			success: 'Object published successfully',
			error: null,
			busy: [false, false],
			successAfter3s: null,
		})
		fetch.mockClear()
		const depublished = await run('depublishObject')
		expect(depublished.request).toEqual([
			BASE + '/depublish',
			{ method: 'POST', headers: { requesttoken: getRequestToken() }, body: undefined },
		])
		expect(depublished.success).toBe('Object depublished successfully')
	})

	it('sends the dated object from the modal and closes it', async () => {
		const date = new Date(Date.UTC(2026, 0, 2))
		const published = await run('publishObject', {
			showPublishModal: true,
			publishDate: date,
		})
		expect(published.request[0]).toBe(BASE)
		expect(published.request[1].method).toBe('PUT')
		expect(published.request[1].headers).toEqual({
			'Content-Type': 'application/json',
			requesttoken: getRequestToken(),
		})
		expect(JSON.parse(published.request[1].body)).toEqual({
			...OBJECT,
			'@self': { ...OBJECT['@self'], published: '2026-01-02T00:00:00.000Z' },
		})
		expect(published.modals).toEqual([false, false, null, null])
		fetch.mockClear()
		const depublished = await run('depublishObject', {
			showDepublishModal: true,
			depublishDate: '2026-05-06',
		})
		expect(depublished.request[0]).toBe(BASE)
		expect(JSON.parse(depublished.request[1].body)['@self'].depublished).toBe(
			'2026-05-06',
		)
		expect(depublished.modals).toEqual([false, false, null, null])
	})

	it('puts an empty body when the modal has no date', async () => {
		const published = await run('publishObject', { showPublishModal: true })
		expect(published.request).toEqual([
			BASE + '/publish',
			{
				method: 'PUT',
				headers: { 'Content-Type': 'application/json', requesttoken: getRequestToken() },
				body: '{}',
			},
		])
	})

	it('reports failures and clears the error after five seconds', async () => {
		const missing = await run('publishObject', { currentObject: null })
		expect(missing).toMatchObject({
			request: null,
			error: 'Failed to publish object: No object to publish',
			errorAfter3s: 'Failed to publish object: No object to publish',
			errorAfter5s: null,
			busy: [false, false],
		})
		fetch.mockImplementation(async () => ({
			ok: false,
			status: 409,
			statusText: 'Conflict',
			text: async () => 'locked',
		}))
		const refused = await run('depublishObject', { showDepublishModal: true })
		expect(refused.error).toBe(
			'Failed to depublish object: Failed to depublish object: 409 Conflict - locked',
		)
		expect(refused.modals[1]).toBe(true)
		expect(refused.active).toEqual([])
		fetch.mockImplementation(async () => {
			throw new Error('offline')
		})
		expect((await run('publishObject')).error).toBe(
			'Failed to publish object: offline',
		)
		expect(console.error).toHaveBeenCalledTimes(3)
		expect(console.error.mock.calls.map((c) => c[0])).toEqual([
			'Failed to publish object:',
			'Failed to depublish object:',
			'Failed to publish object:',
		])
	})
})
