/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for computeExposure, parseCvss, cmdbProgressView and
 * packVersionText over grids of inputs. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-stores/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { cmdbProgressView, packVersionText } from '../../src/utils/cmdbImport.js'
import { computeExposure } from '../../src/utils/vulnerabilityExposure.js'
import { parseCvss } from '../../src/utils/vulnerabilitySeverity.js'

const GOLDEN = path.resolve(
	import.meta.dirname,
	'golden/exposureProgressGolden.json',
)

const USAGES = [
	{
		id: 'g1',
		module: 'm1',
		consumer: 'o1',
		moduleVersion: 'v1',
		startDateInProduction: '2025-01-01',
	},
	{
		'@self': { id: 'g2' },
		object: {
			module: { id: 'm2' },
			consumer: { id: 'o2' },
			startDateInProduction: '2025-01-01',
		},
	},
	{ object: { id: 'g3', module: 'm1', startDateInProduction: '2025-01-01' } },
	{ module: 'm1', startDateInProduction: '2025-01-01' },
	{
		id: 'g5',
		module: 'm1',
		startDateInProduction: '2025-01-01',
		startDateOutPhased: '2025-02-01',
	},
	{ id: 'g6', module: 'm3', startDateInProduction: '2025-01-01' },
	{ id: 'g7', module: '', startDateInProduction: '2025-01-01' },
	{ id: 'g8', module: 'm2' },
]
const VULNS = [
	{ modules: [''] },
	{ modules: ['m1', 'm2'] },
	{ modules: [{ id: 'm2' }] },
	{ modules: [] },
	{},
	null,
]
const CVSS = [
	7.5,
	0,
	-1,
	11,
	'5,5',
	' 6.1 ',
	'',
	'  ',
	'x',
	NaN,
	Infinity,
	null,
	undefined,
	true,
	'10.0',
	'-0',
	-0,
]
const PROGRESS = [
	null,
	{},
	{ processed_items: 5, total_items: 10 },
	{ processed_items: '12', total_items: '10' },
	{ processed_items: 3, total_items: 0, percentage: 40 },
	{ percentage: 'x' },
	{ processed_items: 5, total_items: 10, phase: 'reconciling' },
	{ processed_items: 1, total_items: 3 },
]
const PACKS = [
	{ shippedVersion: '2.0', version: '3.0' },
	null,
	{},
	{ source: 'openregister', storedVersion: '1.2', shippedVersion: '1.3' },
	{ source: 'openregister', storedVersion: '1.2', shippedVersion: '1.2' },
	{ source: 'openregister', storedVersion: '1.2', version: '1.4' },
	{ source: 'openregister', storedVersion: '1.2' },
	{ source: 'openregister', storedVersion: '', version: '1.5' },
	{ source: 'file', storedVersion: '1.2', version: '1.6' },
	{ shippedVersion: 0 },
	{ version: '' },
]

describe('exposure, cvss and progress golden', () => {
	it('answers the same for every input', () => {
		const value = JSON.parse(
			JSON.stringify(
				{
					exposure: VULNS.map((v) => [
						computeExposure(v, USAGES),
						computeExposure(v, null),
					]),
					cvss: CVSS.map((c) => [
						parseCvss(c),
						Object.is(parseCvss(c), -0),
					]),
					progress: PROGRESS.map((p) => cmdbProgressView(p)),
					packs: PACKS.map((p) => packVersionText(p)),
				},
				(k, v) => (v === undefined ? '(undefined)' : v),
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
