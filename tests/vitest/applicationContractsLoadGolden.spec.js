/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for loading the contracts behind an application: which lists
 * are fetched with which filters, and which contracts come back once each.
 * Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/application-page/spec.md#requirement-req-apg-003-the-application-page-lists-the-contracts-behind-the-application
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { loadApplicationContracts } from '../../src/utils/applicationContracts.js'

const GOLDEN = path.resolve(
	import.meta.dirname,
	'golden/applicationContractsLoad.json',
)

const CASES = {
	nothing: {},
	'null lists': { usage: null, catalogService: null },
	'usages only': {
		usage: [{ id: 'u1' }, { '@self': { id: 'u2' } }],
		byUsage: [{ id: 'c1' }, { id: 'c2' }],
	},
	'services only': { catalogService: [{ uuid: 's1' }], byService: [{ id: 'c3' }] },
	'both, overlapping': {
		usage: [{ id: 'u1' }],
		catalogService: [{ id: 's1' }, { id: 's2' }],
		byUsage: [{ id: 'c1', from: 'usage' }, { id: 'c2' }],
		byService: [
			{ id: 'c1', from: 'service' },
			{ '@self': { id: 'c4' } },
			{ name: 'no id' },
		],
	},
	'rows without ids': { usage: [{ name: 'x' }, null], catalogService: [{}] },
	'contract list null': { usage: [{ id: 'u1' }], byUsage: null },
}

describe('loadApplicationContracts golden', () => {
	it('matches the recorded outcomes', async () => {
		const actual = {}
		for (const [name, lists] of Object.entries(CASES)) {
			const calls = []
			const fetchList = async (type, params) => {
				calls.push([type, params])
				if (type === 'catalogContract') {
					return params.usage ? lists.byUsage : lists.byService
				}
				return lists[type]
			}
			let result
			try {
				result = await loadApplicationContracts('app-1', fetchList)
			} catch (e) {
				result = 'threw ' + e.constructor.name
			}
			actual[name] = { calls, result }
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(actual, null, '\t') + '\n')
		}
		expect(actual).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
