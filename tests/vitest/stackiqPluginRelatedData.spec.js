/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the stackiq store plugin's related-data fetch, the legacy
 * delete's selection clean-up and the pagination getters. Each case records
 * the fetch calls, console errors and the store state afterwards. Recorded
 * with RECORD_GOLDEN=1.
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

const { stackiqPlugin } = await import(
	'../../src/store/plugins/stackiqPlugin.js'
)

const GOLDEN = path.resolve(
	import.meta.dirname,
	'golden/stackiqPluginRelatedData.json',
)
const recorded = {}
const expected = process.env.RECORD_GOLDEN
	? {}
	: JSON.parse(readFileSync(GOLDEN, 'utf8'))

/**
 * A store context: the plugin's state plus the base store fields and the
 * actions fetchRelatedData calls on `this`.
 *
 * @param {object} overrides Fields to set on the context
 * @return {object} The context
 */
function context(overrides) {
	const plugin = stackiqPlugin()
	const ctx = {
		...plugin.state(),
		loading: {},
		pagination: {},
		settings: { objectTypes: [] },
		calls: [],
		...overrides,
	}
	ctx.fetchSettings = async () => {
		ctx.calls.push(['fetchSettings'])
		ctx.settings = { objectTypes: ['loaded'] }
	}
	ctx.getSchemaConfig = (type) => {
		ctx.calls.push(['getSchemaConfig', type])
		return { register: { id: 'reg-' + type }, schema: 'sch-' + type }
	}
	ctx.setSelectedObjects = (objects) => {
		ctx.calls.push(['setSelectedObjects', objects])
		ctx.selectedObjects = objects
	}
	return ctx
}

/**
 * Install a fetch stub that answers every call with the given answer.
 *
 * @param {object} answer The answer: `{ ok, body }`, or `{ throws }`
 * @return {Array} The recorded fetch calls
 */
function stubFetch(answer) {
	const calls = []
	vi.stubGlobal('fetch', async (url, init) => {
		calls.push([url, init])
		if (answer.throws) throw new Error(answer.throws)
		return {
			ok: answer.ok,
			status: answer.ok ? 200 : 500,
			statusText: answer.ok ? 'OK' : 'Server Error',
			json: async () => answer.body,
		}
	})
	return calls
}

/**
 * Compare a case with the golden file, or record it.
 *
 * @param {string} key The case name
 * @param {object} value What the case observed
 */
function check(key, value) {
	const plain = JSON.parse(JSON.stringify(value))
	recorded[key] = plain
	if (!process.env.RECORD_GOLDEN) {
		expect(plain).toEqual(expected[key])
	}
}

const RELATED_CASES = {
	'logs with results and pagination': {
		args: ['application', 'a1', 'logs', { _limit: 5 }],
		answer: { ok: true, body: { results: [{ id: 1 }], total: 12, page: 2 } },
	},
	'logs without results': {
		args: ['application', 'a1', 'logs', {}],
		answer: { ok: true, body: { total: 0 } },
	},
	'uses with default extend and api limit': {
		args: ['application', 'a1', 'uses', { limit: 7 }],
		answer: {
			ok: true,
			body: { results: [1, 2], total: 30, limit: '10', next: 'n', prev: 'p' },
		},
	},
	'used with given extend and pages': {
		args: ['module', 'm1', 'used', { _extend: 'x', _page: 3 }],
		answer: { ok: true, body: { results: [], page: 3, pages: 9 } },
	},
	'used without extend and plain limit': {
		args: ['module', 'm1', 'used', { limit: 4 }],
		answer: { ok: true, body: { results: [], total: 9 } },
	},
	'files default limit': {
		args: ['application', 'a1', 'files'],
		answer: { ok: true, body: { results: [], total: 1001 } },
	},
	'other type default limit': {
		args: ['application', 'a1', 'contracts', {}],
		answer: { ok: true, body: { results: [], total: 41 } },
	},
	'no pagination fields': {
		args: ['application', 'a1', 'uses', {}],
		answer: { ok: true, body: { results: ['r'] } },
	},
	'organisation config given': {
		args: [
			'organization',
			'o1',
			'logs',
			{ _limit: 20 },
			{ source: 'openregister', schema: { id: 9 }, register: 4 },
		],
		answer: { ok: true, body: { results: [], total: 3, page: 1 } },
	},
	'http error': {
		args: ['application', 'a1', 'uses', {}],
		answer: { ok: false, body: {} },
	},
	'network error': {
		args: ['application', 'a1', 'logs', {}],
		answer: { throws: 'offline' },
	},
	'settings not loaded': {
		args: ['application', 'a1', 'logs', {}],
		answer: { ok: true, body: { results: [] } },
		ctx: { settings: null },
	},
	'existing related data kept': {
		args: ['application', 'a1', 'files', { _limit: 2 }],
		answer: { ok: true, body: { results: [], total: 0, page: 0, limit: 0 } },
		ctx: {
			relatedData: { application: { logs: [{ id: 'old' }] } },
			pagination: { other: { page: 1 } },
		},
	},
	'id from object register and missing schema': {
		args: ['thing', 't1', 'logs', {}, { register: { id: 0 }, schema: null }],
		answer: { ok: true, body: { results: [] } },
	},
}

describe('stackiqPlugin related data', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
		vi.restoreAllMocks()
	})

	afterAll(() => {
		if (process.env.RECORD_GOLDEN) {
			writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
		}
	})

	for (const [name, spec] of Object.entries(RELATED_CASES)) {
		it('fetchRelatedData: ' + name, async () => {
			const errors = []
			vi.spyOn(console, 'error').mockImplementation((...args) =>
				errors.push(args.map((a) => String(a))),
			)
			const fetches = stubFetch(spec.answer)
			const ctx = context(spec.ctx || {})
			const result = await stackiqPlugin().actions.fetchRelatedData.call(
				ctx,
				...spec.args,
			)
			check('fetchRelatedData: ' + name, {
				result: result === undefined ? 'undefined' : result,
				fetches,
				errors,
				calls: ctx.calls,
				loading: ctx.loading,
				pagination: ctx.pagination,
				relatedData: ctx.relatedData,
			})
		})
	}

	const DELETE_CASES = {
		'string and object selections': [
			'x1',
			{ id: 'x1' },
			{ '@self': { id: 'x1' } },
			'x2',
			{ id: 'x3', '@self': { id: 'x1' } },
		],
		'not selected': ['x2', { id: 'x3' }, { '@self': { id: 'x4' } }],
		'empty selection': [],
	}

	for (const [name, selected] of Object.entries(DELETE_CASES)) {
		it('deleteObject legacy: ' + name, async () => {
			const fetches = stubFetch({ ok: true, body: {} })
			const ctx = context({ selectedObjects: selected })
			const result = await stackiqPlugin().actions.deleteObject.call(ctx, {
				'@self': { id: 'x1', register: 1, schema: 2 },
			})
			check('deleteObject legacy: ' + name, {
				result,
				fetches,
				calls: ctx.calls,
				loading: ctx.loading,
				selectedObjects: ctx.selectedObjects,
			})
		})
	}

	it('pagination getters', () => {
		const { getters } = stackiqPlugin()
		const states = [
			{},
			{ pagination: {} },
			{ pagination: { t: { next: null, prev: null, page: 1, pages: 1 } } },
			{ pagination: { t: { next: null, prev: null, page: 1, pages: 3 } } },
			{ pagination: { t: { next: null, prev: null, page: 3, pages: 3 } } },
			{ pagination: { t: { next: 'n', prev: 'p', page: 1, pages: 1 } } },
			{ pagination: { t: { next: undefined, prev: undefined } } },
			{ pagination: { t: { next: null, prev: null, page: 2 } } },
			{ pagination: { t: { next: null, prev: null, page: '2', pages: '10' } } },
		]
		check(
			'pagination getters',
			states.map((state) => [
				getters.hasMorePages(state)('t'),
				getters.hasPreviousPages(state)('t'),
			]),
		)
	})
})
