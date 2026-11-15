/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for how a pack's field mappings become rows of text: the
 * source and target columns, the required flag and the transform text for
 * string, typed and lookup transforms. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/changes/cmdb-import-mapping-view/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { packFieldRows } from '../../src/utils/cmdbImport.js'

const GOLDEN = path.resolve(import.meta.dirname, 'golden/cmdbPackFieldRows.json')

const TRANSFORMS = [
	undefined,
	null,
	'',
	'trim',
	0,
	true,
	[],
	['lookup'],
	{},
	{ type: null },
	{ type: 'date' },
	{ type: 7 },
	{ type: 'lookup' },
	{ type: 'lookup', map: null },
	{ type: 'lookup', map: 'x' },
	{ type: 'lookup', map: {} },
	{ type: 'lookup', map: { a: 1, b: 2 } },
	{ type: 'lookup', map: ['a', 'b', 'c'] },
	{ type: 'Lookup', map: { a: 1 } },
	{ type: 'date', map: { a: 1 } },
]

const PACKS = {
	'no pack': undefined,
	'null pack': null,
	'no mappings': {},
	'mappings not a list': { fieldMappings: { a: 1 } },
	'empty mappings': { fieldMappings: [] },
	'odd mappings': {
		fieldMappings: [
			null,
			'x',
			{},
			{ source: 3, target: null, required: 'true' },
			{ source: 'a', target: 'b', required: true },
		],
	},
	transforms: {
		fieldMappings: TRANSFORMS.map((transform, i) => ({
			source: 'col' + i,
			target: 'field' + i,
			required: i % 2 === 0,
			transform,
		})),
	},
}

describe('packFieldRows golden', () => {
	it('matches the recorded outcomes', () => {
		const actual = {}
		for (const [name, pack] of Object.entries(PACKS)) {
			actual[name] = packFieldRows(pack)
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(actual, null, '\t') + '\n')
		}
		expect(actual).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
