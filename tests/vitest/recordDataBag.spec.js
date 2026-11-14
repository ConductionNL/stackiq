/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for dataOf, the reader of a record's data bag (an OR object
 * envelope with a nested `object`, or plain data) that the src/utils
 * modules share. Pinned through complianceMatrix's export.
 *
 * @spec openspec/specs/module-compliance-assessment/spec.md
 */

import { describe, expect, it } from 'vitest'
import { dataOf } from '../../src/utils/complianceMatrix.js'

const nested = { naam: 'inner' }
const plain = { naam: 'plain', object: 'not an object' }
const envelope = { '@self': { id: 'x' }, object: nested, naam: 'outer' }
const nullObject = { object: null, naam: 'kept' }
const list = ['a']

const cases = [
	['undefined', undefined, {}],
	['null', null, {}],
	['zero', 0, {}],
	['empty string', '', {}],
	['string', 'abc', {}],
	['number', 42, {}],
	['true', true, {}],
	['envelope', envelope, nested],
	['plain data', plain, plain],
	['object null', nullObject, nullObject],
	['array', list, list],
]

describe('dataOf', () => {
	for (const [name, input, expected] of cases) {
		it(name, () => {
			const result = dataOf(input)
			expect(result).toEqual(expected)
			if (input && typeof input === 'object') {
				expect(result).toBe(expected)
			}
		})
	}
})
