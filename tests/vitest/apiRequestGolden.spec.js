/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for apiRequest: every request shape against every answer kind,
 * recording the URL, method, headers (including the CSRF request token) and
 * body sent, and the result or error. Recorded with RECORD_GOLDEN=1.
 *
 * @vitest-environment jsdom
 * @spec openspec/specs/fe-stores/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

const token = { value: 'csrf-token' }

vi.mock('@nextcloud/auth', () => ({
	getRequestToken: () => token.value,
}))

const { apiRequest } = await import('../../src/utils/adminApi.js')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/apiRequestGolden.json')

const ANSWERS = {
	ok: { ok: true, status: 200, json: { a: 1 } },
	'ok bad json': { ok: true, status: 200, json: 'throw' },
	'error with message': { ok: false, status: 400, json: { message: 'bad' } },
	'error without message': { ok: false, status: 500, json: { message: '' } },
	'error bad json': { ok: false, status: 502, json: 'throw' },
	'error null json': { ok: false, status: 503, json: null },
}

const REQUESTS = [
	['reviews', undefined],
	['reviews', { method: 'POST', body: { r: 1 } }],
	['federation/peers/1', { method: 'DELETE', body: null }],
	['reviews', { body: 0 }],
	['reviews', { method: '' }],
	['reviews', { method: 'PUT', body: 'text' }],
]

describe('apiRequest golden', () => {
	it('sends and answers the same for every case', async () => {
		const recorded = {}
		for (const [answerName, answer] of Object.entries(ANSWERS)) {
			for (const [i, [p, options]] of REQUESTS.entries()) {
				const calls = []
				const fetchImpl = async (url, init) => {
					calls.push([url, init])
					return {
						ok: answer.ok,
						status: answer.status,
						json: async () => {
							if (answer.json === 'throw')
								throw new SyntaxError('bad json')
							return answer.json
						},
					}
				}
				let outcome
				try {
					outcome = { result: await apiRequest(p, options, fetchImpl) }
				} catch (error) {
					outcome = { error: error.message }
				}
				recorded[`${answerName} / ${i}`] = { outcome, calls }
			}
		}
		let noFetch
		const saved = globalThis.fetch
		try {
			globalThis.fetch = undefined
			await apiRequest('x', {}, undefined)
		} catch (error) {
			noFetch = error.message
		} finally {
			globalThis.fetch = saved
		}
		recorded['no fetch'] = noFetch
		token.value = null
		const tokenless = []
		await apiRequest('reviews', { method: 'POST' }, async (url, init) => {
			tokenless.push([url, init])
			return { ok: true, status: 200, json: async () => ({}) }
		})
		token.value = 'csrf-token'
		recorded['no token'] = tokenless
		const value = JSON.parse(JSON.stringify(recorded))
		if (process.env.RECORD_GOLDEN) {
			writeFileSync(GOLDEN, JSON.stringify(value, null, '\t') + '\n')
			return
		}
		expect(JSON.stringify(value)).toBe(
			JSON.stringify(JSON.parse(readFileSync(GOLDEN, 'utf8'))),
		)
	})
})
