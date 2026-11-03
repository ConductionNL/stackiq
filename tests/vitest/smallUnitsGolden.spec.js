/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for outcomeLabel, projectOpenData and apiRequest: every
 * outcome, every envelope shape and every answer kind. Recorded with
 * RECORD_GOLDEN=1.
 *
 * @vitest-environment jsdom
 *
 * @spec openspec/specs/fe-stores/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { apiRequest } from '../../src/utils/adminApi.js'
import { outcomeLabel } from '../../src/utils/cmdbImport.js'
import { projectOpenData } from '../../src/utils/openDataProjection.js'

const GOLDEN = path.resolve(import.meta.dirname, 'golden/smallUnitsGolden.json')

const OUTCOMES = [
	'created',
	'updated',
	'unchanged',
	'skipped',
	'failed',
	'unarchived',
	'restored',
	'archived',
	'deleted',
	'other',
	'',
	null,
	undefined,
	3,
	'toString',
	'constructor',
]

const ENTRIES = [
	{
		object: { slug: 'ds', uuid: 'du' },
		'@self': { uuid: 'su', id: 'si', slug: 'ss' },
	},
	{
		name: 'n',
		'@self': { uuid: 'u1', slug: 's1', updated: 't1' },
		owner: 'x',
		'@type': 'y',
	},
	{
		object: { name: 'n', uuid: 'u2', slug: 's2', updated: 't2' },
		'@self': { id: 'i2', modified: 'm2' },
	},
	{ name: 'n', uuid: 'u3', updated: 't3' },
	{ name: 'n' },
	{},
	null,
]

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
	['reviews', { method: 'DELETE', body: null }],
	['reviews', { body: 0 }],
	['reviews', { method: '' }],
]

describe('small unit golden', () => {
	it('answers the same for every case', async () => {
		const recorded = {
			outcomes: OUTCOMES.map((o) => outcomeLabel(o)),
			projections: ENTRIES.flatMap((e) => {
				try {
					return [
						projectOpenData(e),
						projectOpenData(e, { license: 'MIT', publisherName: 'P' }),
						projectOpenData(e, {}),
					]
				} catch (error) {
					return [`${error.constructor.name}: ${error.message}`]
				}
			}),
			requests: {},
		}
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
				recorded.requests[`${answerName} / ${i}`] = { outcome, calls }
			}
		}
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
