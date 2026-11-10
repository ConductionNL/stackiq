/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the OpenRegister object URLs: orClient's buildObjectUrl
 * for every combination of ids, object id, action and language flag, and
 * the URL each object action of the stackiq store plugin requests (download,
 * delete, lock, unlock, publish), or the error it throws, for objects whose
 * ids sit at the top level, in @self, as objects or are missing. Recorded
 * with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-stores/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	getLanguage: () => 'nl',
	translate: (app, text) => text,
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	buildHeaders: () => ({ requesttoken: 'token' }),
	buildQueryString: () => '',
}))

const { buildObjectUrl } = await import('../../src/composables/orClient.js')
const { stackiqPlugin } = await import('../../src/store/plugins/stackiqPlugin.js')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/objectUrl.json')

const OBJECTS = {
	'top-level ids': { id: 'o1', register: 3, schema: 7 },
	'@self ids': { '@self': { id: 'o2', register: 4, schema: 8 } },
	'ids as objects': { id: 'o3', '@self': { register: { id: 5 }, schema: { uuid: 's-uuid' } } },
	'@self wins over top level': { id: 'o4', register: 1, schema: 2, '@self': { id: 'x', register: 9, schema: 10 } },
	'_id reference': { id: 'o5', register: { _id: 'r_' }, schema: 6 },
	'missing schema': { id: 'o6', register: 3 },
	'missing id': { register: 3, schema: 7 },
	'zero register': { id: 'o7', register: 0, schema: 7 },
}

const ACTIONS = {
	downloadObject: (store, item) => store.downloadObject(item),
	deleteObject: (store, item) => store.deleteObject(item),
	lockObject: (store, item) => store.lockObject(item, 'proc', 60),
	unlockObject: (store, item) => store.unlockObject(item),
	publishObject: (store, item) => store.publishObject(item),
}

/**
 * Every buildObjectUrl call shape and its URL or error.
 *
 * @return {object} The outcomes.
 */
function clientUrls() {
	const out = {}
	const ids = { 'numbers': { register: 7, schema: 21 }, 'zero ids': { register: 0, schema: 0 }, 'no register': { schema: 21 }, 'null schema': { register: 7, schema: null } }
	for (const [idName, idValues] of Object.entries(ids)) {
		for (const uuid of [undefined, '', 'abc']) {
			for (const action of [undefined, '', 'logs', 'lock']) {
				for (const withLang of [undefined, true, false]) {
					const key = `${idName} | uuid ${uuid} | action ${action} | lang ${withLang}`
					try {
						out[key] = buildObjectUrl({ ...idValues, uuid, action, withLang })
					} catch (error) {
						out[key] = `error: ${error.message}`
					}
				}
			}
		}
	}
	return out
}

/**
 * The URL each store action requests for one object, or its error.
 *
 * @param {Function} run The action.
 * @param {object} item The object.
 * @return {Promise<object>} The requests and the error.
 */
async function pluginRequests(run, item) {
	const calls = []
	vi.stubGlobal('fetch', async (url, init) => {
		calls.push([url, init?.method ?? 'GET'])
		return { ok: true, status: 200, statusText: 'OK', json: async () => ({}), blob: async () => 'blob' }
	})
	const store = { loading: {}, ...stackiqPlugin().actions }
	for (const [name, fn] of Object.entries(stackiqPlugin().actions)) {
		store[name] = fn.bind(store)
	}
	let error = null
	try {
		await run(store, structuredClone(item))
	} catch (caught) {
		error = caught.message
	}
	return { calls, error }
}

describe('object URLs golden', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('builds the same URLs and errors', async () => {
		const out = { client: clientUrls(), plugin: {} }
		for (const [actionName, run] of Object.entries(ACTIONS)) {
			for (const [objectName, item] of Object.entries(OBJECTS)) {
				out.plugin[`${actionName} | ${objectName}`] = await pluginRequests(run, item)
			}
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(out, null, '\t') + '\n')
		}
		expect(out).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
