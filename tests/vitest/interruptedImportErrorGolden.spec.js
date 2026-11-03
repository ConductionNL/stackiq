/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for interruptedImportError over every follow-up outcome and
 * error status. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-stores/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { interruptedImportError } from '../../src/utils/cmdbImport.js'

const GOLDEN = path.resolve(
	import.meta.dirname,
	'golden/interruptedImportErrorGolden.json',
)

const OUTCOMES = [
	undefined,
	null,
	{},
	{ state: 'finished' },
	{ state: 'failed' },
	{ state: 'unreachable' },
	{ state: 'unknown' },
	{ state: 'other' },
]
const ERRORS = [
	undefined,
	null,
	{},
	{ status: 0 },
	{ status: 502 },
	{ status: '0' },
	{ status: null },
]

describe('interrupted import error golden', () => {
	it('answers the same for every outcome and error', () => {
		const value = JSON.parse(
			JSON.stringify(
				OUTCOMES.map((o) => ERRORS.map((e) => interruptedImportError(o, e))),
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
