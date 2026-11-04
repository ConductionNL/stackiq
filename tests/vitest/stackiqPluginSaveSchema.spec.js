/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the stackiq store plugin's schema config lookup and object
 * save. Each case records the result or the error, the fetch calls and the
 * store fields the action writes. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-stores/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { afterAll, afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	buildHeaders: () => ({ requesttoken: 'token' }),
	buildQueryString: (params) =>
		Object.keys(params).length ? '?' + JSON.stringify(params) : '',
}))

vi.mock('../../src/composables/orClient.js', async (importOriginal) => ({
	...(await importOriginal()),
	getActiveOrganisationUuid: () => 'org-1',
}))

const { stackiqPlugin } = await import('../../src/store/plugins/stackiqPlugin.js')

const GOLDEN = path.resolve(
	import.meta.dirname,
	'golden/stackiqPluginSaveSchema.json',
)
const recorded = {}
const expected = process.env.RECORD_GOLDEN
	? {}
	: JSON.parse(readFileSync(GOLDEN, 'utf8'))

/**
 * Compare a case with the golden file, or record it.
 *
 * @param {string} key The case name
 * @param {object} value What the case observed
 */
function check(key, value) {
	const plain = JSON.parse(
		JSON.stringify(value, (k, v) => (v === undefined ? '(undefined)' : v)),
	)
	recorded[key] = plain
	if (!process.env.RECORD_GOLDEN) {
		expect(plain).toEqual(expected[key])
	}
}

/**
 * Run a function and record its result or the error it throws.
 *
 * @param {Function} fn The function
 * @return {Promise<object>} `{ result }` or `{ error }`
 */
async function outcome(fn) {
	try {
		return { result: await fn() }
	} catch (error) {
		return { error: `${error.constructor.name}: ${error.message}` }
	}
}

const SCHEMA_CASES = {
	'registered type': {
		ctx: { objectTypeRegistry: { app: { schema: 's1', register: 'r1' } } },
		type: 'app',
	},
	'no registry, no settings': {
		ctx: { objectTypeRegistry: undefined, settings: null },
		type: 'app',
	},
	'registry miss, no settings': {
		ctx: { objectTypeRegistry: { other: {} }, settings: undefined },
		type: 'app',
	},
	'voorzieningen blob': {
		ctx: {
			settings: {
				voorzieningen: { register: 3, module_schema: 9 },
				voorzieningenConfig: { register: 4, module_schema: 8 },
			},
		},
		type: 'module',
	},
	'voorzieningenConfig blob': {
		ctx: {
			settings: { voorzieningenConfig: { register: 4, module_schema: 8 } },
		},
		type: 'module',
	},
	'voorzieningen without the schema falls to amef': {
		ctx: {
			settings: {
				voorzieningen: { register: 3 },
				amef: { register: 5, element_schema: 6 },
			},
		},
		type: 'element',
	},
	'voorzieningen without register falls to amefConfig': {
		ctx: {
			settings: {
				voorzieningen: { element_schema: 1 },
				amefConfig: { register: 7, element_schema: 2 },
			},
		},
		type: 'element',
	},
	'amef blob wins over amefConfig': {
		ctx: {
			settings: {
				amef: { register: 5, element_schema: 6 },
				amefConfig: { register: 7, element_schema: 2 },
			},
		},
		type: 'element',
	},
	'amef without register falls to legacy': {
		ctx: {
			settings: {
				amef: { element_schema: 2 },
				configuration: { element_schema: 'x', element_register: 'y' },
			},
		},
		type: 'element',
	},
	'legacy prefixed keys': {
		ctx: {
			settings: {
				configuration: {
					voorzieningen_gebruik_source: 'internal',
					gebruik_source: 'other',
					voorzieningen_gebruik_schema: 'vs',
					gebruik_schema: 'ps',
					voorzieningen_gebruik_register: 'vr',
					gebruik_register: 'pr',
					voorzieningen_register: 'gr',
				},
			},
		},
		type: 'gebruik',
	},
	'legacy plain keys': {
		ctx: {
			settings: {
				configuration: {
					gebruik_source: 'other',
					gebruik_schema: 'ps',
					gebruik_register: 'pr',
					voorzieningen_register: 'gr',
				},
			},
		},
		type: 'gebruik',
	},
	'legacy shared register and default source': {
		ctx: {
			settings: {
				configuration: {
					gebruik_schema: 'ps',
					voorzieningen_register: 'gr',
				},
			},
		},
		type: 'gebruik',
	},
	'legacy empty schema': {
		ctx: {
			settings: {
				configuration: {
					voorzieningen_gebruik_schema: 0,
					gebruik_schema: '',
					gebruik_register: 'pr',
				},
			},
		},
		type: 'gebruik',
	},
	'legacy missing register': {
		ctx: {
			settings: {
				configuration: { gebruik_schema: 'ps', voorzieningen_register: '' },
			},
		},
		type: 'gebruik',
	},
	'no configuration at all': { ctx: { settings: {} }, type: 'gebruik' },
}

const SAVE_CASES = {
	'typed create': {
		args: ['app', { name: 'n' }],
		answer: { ok: true, body: { id: 'a1', name: 'n' } },
	},
	'typed update by id': {
		args: ['app', { id: 'a1', name: 'n' }],
		answer: { ok: true, body: { id: 'a1' } },
		objects: { app: { a0: {} } },
	},
	'typed update by @self id': {
		args: ['app', { '@self': { id: 'a2' } }],
		answer: { ok: true, body: { id: 'a2' } },
	},
	'typed answer without id': {
		args: ['app', { name: 'n' }],
		answer: { ok: true, body: { name: 'n' } },
	},
	'typed create next to another cached type': {
		args: ['app', { name: 'n' }],
		answer: { ok: true, body: { id: 'a3' } },
		objects: { module: { m1: { id: 'm1' } } },
	},
	'legacy without an item': {
		args: [undefined, { register: 3, schema: 7 }],
		answer: { ok: true, body: {} },
	},
	'typed failure': {
		args: ['app', { id: 'a1' }],
		answer: { ok: false, body: {} },
	},
	'legacy create': {
		args: [{ name: 'n' }, { register: { id: 3 }, schema: 7 }],
		answer: { ok: true, body: { id: 'x' } },
	},
	'legacy update': {
		args: [
			{ '@self': { id: 'o1', updated: 'old' } },
			{ register: 3, schema: { uuid: 'su' } },
		],
		answer: { ok: true, body: { id: 'o1' } },
	},
	'legacy self without id': {
		args: [{ '@self': {} }, { register: 3, schema: 7 }],
		answer: { ok: true, body: {} },
	},
	'legacy failure': {
		args: [{ name: 'n' }, { register: 3, schema: 7 }],
		answer: { ok: false, body: {} },
	},
	'legacy missing config': {
		args: [{ name: 'n' }, undefined],
		answer: { ok: true, body: {} },
	},
	'legacy missing item': {
		args: [null, { register: 3, schema: 7 }],
		answer: { ok: true, body: {} },
	},
	'legacy missing schema': {
		args: [{ name: 'n' }, { register: 3 }],
		answer: { ok: true, body: {} },
	},
	'legacy register without id': {
		args: [{ name: 'n' }, { register: { title: 'r' }, schema: 7 }],
		answer: { ok: true, body: {} },
	},
	'legacy schema id zero': {
		args: [{ name: 'n' }, { register: 3, schema: { id: 0, _id: 0 } }],
		answer: { ok: true, body: {} },
	},
}

describe('stackiq plugin schema config and save', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
		vi.useRealTimers()
	})

	afterAll(() => {
		if (process.env.RECORD_GOLDEN) {
			writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
		}
	})

	for (const [name, { ctx, type }] of Object.entries(SCHEMA_CASES)) {
		it('getSchemaConfig: ' + name, async () => {
			const context = { ...stackiqPlugin().state(), ...structuredClone(ctx) }
			check(
				'getSchemaConfig: ' + name,
				await outcome(() =>
					stackiqPlugin().actions.getSchemaConfig.call(context, type),
				),
			)
		})
	}

	for (const [name, c] of Object.entries(SAVE_CASES)) {
		it('saveObject: ' + name, async () => {
			vi.useFakeTimers({ toFake: ['Date'] })
			vi.setSystemTime(new Date('2026-11-02T10:20:30.000Z'))
			const calls = []
			vi.stubGlobal('fetch', async (url, init) => {
				calls.push([url, init])
				return {
					ok: c.answer.ok,
					status: c.answer.ok ? 200 : 500,
					statusText: c.answer.ok ? 'OK' : 'Server Error',
					json: async () => c.answer.body,
				}
			})
			const ctx = {
				...stackiqPlugin().state(),
				objects: structuredClone(c.objects ?? {}),
				calls: [],
			}
			ctx._buildUrl = (t, id) => {
				ctx.calls.push(['_buildUrl', t, id])
				return `/url/${t}/${id}`
			}
			const args = structuredClone(c.args)
			const result = await outcome(() =>
				stackiqPlugin().actions.saveObject.call(ctx, ...args),
			)
			if (result.result && result.result.response) {
				result.result = {
					status: result.result.response.status,
					data: result.result.data,
				}
			}
			check('saveObject: ' + name, {
				...result,
				fetch: calls,
				args,
				objects: ctx.objects,
				calls: ctx.calls,
			})
		})
	}
})
