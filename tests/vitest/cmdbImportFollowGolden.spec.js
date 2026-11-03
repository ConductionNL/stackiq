/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for normaliseError (every status against every answer body)
 * and followInterruptedImport (scripted progress answers: every terminal
 * state, polling, the timeout and the stop request). Recorded with
 * RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-stores/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	followInterruptedImport,
	normaliseError,
} from '../../src/utils/cmdbImport.js'

const GOLDEN = path.resolve(
	import.meta.dirname,
	'golden/cmdbImportFollowGolden.json',
)

const STATUSES = [undefined, 0, 401, 403, 412, 413, 500, 502, 503, 504, '401']
const BODIES = [
	undefined,
	null,
	'text',
	[],
	{},
	{ error: 'SHEET_MISSING', message: 'm', details: { sheet: 'A' } },
	{ error: '', message: 7, details: 'x' },
	{ error: 3, details: null },
	{ message: 'only message', details: [] },
]

const REPORT = { created: 1 }
const FOLLOWS = {
	'completed with report': [
		{ progress: { status: 'completed', statistics: { report: REPORT } } },
	],
	'cancelled with report': [
		{ progress: { status: 'cancelled', statistics: { report: REPORT } } },
	],
	'completed without report': [
		{ progress: { status: 'completed', statistics: {} } },
	],
	'completed with text report': [
		{ progress: { status: 'completed', statistics: { report: 'r' } } },
	],
	failed: [{ progress: { status: 'failed' } }],
	'running then completed': [
		{ progress: { status: 'running' } },
		{ progress: { status: 'running', statistics: { report: REPORT } } },
		{ progress: { status: 'completed', statistics: { report: REPORT } } },
	],
	'other status': [{ progress: { status: 'queued' } }],
	'no progress': [{}],
	'progress is text': [{ progress: 'x' }],
	unreachable: ['throw-no-response'],
	'error with response': ['throw-response'],
	'running until timeout': Array.from({ length: 6 }, () => ({
		progress: { status: 'running' },
	})),
	'running until stopped': Array.from({ length: 6 }, () => ({
		progress: { status: 'running' },
	})),
}

/**
 * Follow one scripted import.
 *
 * @param {string} name The case name
 * @param {Array} answers The scripted answers, in order
 * @return {Promise<object>} The result and every call the doubles saw
 */
async function follow(name, answers) {
	const calls = []
	let i = 0
	const http = {
		get: async (url) => {
			calls.push(['get', url])
			const answer = answers[Math.min(i++, answers.length - 1)]
			if (answer === 'throw-no-response') throw new Error('offline')
			if (answer === 'throw-response') {
				const error = new Error('500')
				error.response = { status: 500 }
				throw error
			}
			return { data: answer }
		},
	}
	let stops = 0
	const result = await followInterruptedImport({
		operationId: 'op-1',
		http,
		onProgress: (p) => calls.push(['progress', p.status]),
		shouldStop: () => name === 'running until stopped' && ++stops > 2,
		wait: async (ms) => calls.push(['wait', ms]),
		intervalMs: 10,
		timeoutMs: name === 'running until timeout' ? 30 : 1000,
	})
	return { result, calls }
}

describe('cmdb import errors and follow-up', () => {
	it('answers the same for every case', async () => {
		const recorded = { errors: {}, follow: {} }
		for (const status of STATUSES) {
			for (const [b, body] of BODIES.entries()) {
				const response =
					status === undefined && body === undefined
						? undefined
						: { status, data: body }
				recorded.errors[`${typeof status} ${String(status)} / ${b}`] = normaliseError({
					response,
				})
			}
		}
		recorded.errors['no error'] = normaliseError(undefined)
		for (const [name, answers] of Object.entries(FOLLOWS)) {
			recorded.follow[name] = await follow(name, answers)
		}
		const defaults = []
		recorded.follow.defaults = await followInterruptedImport({
			operationId: 'op-2',
			http: {
				get: async (url) =>
					defaults.push(url) && {
						data: { progress: { status: 'failed' } },
					},
			},
		})
		recorded.follow.defaultsCalls = defaults
		const value = JSON.parse(
			JSON.stringify(recorded, (k, v) =>
				v === undefined ? '(undefined)' : v,
			),
		)
		if (process.env.RECORD_GOLDEN) {
			writeFileSync(GOLDEN, JSON.stringify(value, null, '\t') + '\n')
			return
		}
		expect(JSON.stringify(value)).toBe(
			JSON.stringify(JSON.parse(readFileSync(GOLDEN, 'utf8'))),
		)
	})
})
