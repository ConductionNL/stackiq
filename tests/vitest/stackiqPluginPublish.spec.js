/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for publishing and depublishing an object through the stackiq
 * store plugin: the fetch call, the loading flags while it runs and after,
 * and the result or error. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-stores/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { afterAll, afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	buildHeaders: () => ({ requesttoken: 'token' }),
	buildQueryString: () => '',
}))

const { stackiqPlugin } = await import('../../src/store/plugins/stackiqPlugin.js')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/stackiqPluginPublish.json')
const recorded = {}
const expected = process.env.RECORD_GOLDEN
	? {}
	: JSON.parse(readFileSync(GOLDEN, 'utf8'))

const OBJECTS = {
	'top-level id': { id: 'o1', register: 3, schema: { id: 7 } },
	'@self id': { '@self': { id: 'o2', register: { id: 4 }, schema: 8 } },
	'missing schema': { id: 'o3', register: 3 },
}

const ANSWERS = {
	ok: { ok: true, status: 200, statusText: 'OK', body: { id: 'done' } },
	'http 409': { ok: false, status: 409, statusText: 'Conflict', body: {} },
}

describe('stackiq plugin publish and depublish', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	afterAll(() => {
		if (process.env.RECORD_GOLDEN) {
			writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
		}
	})

	for (const action of ['publishObject', 'depublishObject']) {
		for (const [objectName, objectItem] of Object.entries(OBJECTS)) {
			for (const [answerName, answer] of Object.entries(ANSWERS)) {
				const key = `${action} / ${objectName} / ${answerName}`
				it(key, async () => {
					const ctx = { loading: { other: true } }
					const calls = []
					vi.stubGlobal('fetch', async (url, init) => {
						calls.push([url, init, { ...ctx.loading }])
						return {
							ok: answer.ok,
							status: answer.status,
							statusText: answer.statusText,
							json: async () => answer.body,
						}
					})
					let outcome
					try {
						outcome = {
							result: await stackiqPlugin().actions[action].call(
								ctx,
								structuredClone(objectItem),
							),
						}
					} catch (error) {
						outcome = { error: error.message }
					}
					const value = JSON.parse(
						JSON.stringify({ outcome, calls, loading: ctx.loading }),
					)
					recorded[key] = value
					if (!process.env.RECORD_GOLDEN) {
						expect(JSON.stringify(value)).toBe(
							JSON.stringify(expected[key]),
						)
					}
				})
			}
		}
	}
})
