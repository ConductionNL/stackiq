/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the compliance matrix helpers over seeded random compliancy
 * records, modules, columns and usages, for both column sources. Recorded
 * with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-stores/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	buildComplianceMatrix,
	buildOrganisationCoverage,
	COLUMN_SOURCE,
	columnLabel,
	hasEvidence,
	partitionCompliancy,
	standardLabel,
} from '../../src/utils/complianceMatrix.js'

const GOLDEN = path.resolve(
	import.meta.dirname,
	'golden/complianceMatrixGolden.json',
)

/**
 * A small seeded generator, so every run builds the same data.
 *
 * @param {number} seed The seed
 * @return {Function} A function returning the next number in [0, 1)
 */
function generator(seed) {
	let state = seed
	return () => {
		state = (state * 1103515245 + 12345) % 2147483648
		return state / 2147483648
	}
}

const EVIDENCE = ['', '  ', 'pdf', {}, { a: 1 }, null, 0, 5, true, []]
const REFS = ['', ' ', 'REF-1', null, 3, {}]
const URLS = ['', ' ', 'https://x', null, 4]

/**
 * One random data set.
 *
 * @param {number} seed The seed
 * @return {object} The data
 */
function dataSet(seed) {
	const rnd = generator(seed)
	const pick = (list) => list[Math.floor(rnd() * list.length)]
	const compliancy = Array.from({ length: 8 }, (_, i) => {
		const data = {
			module: pick(['m1', 'm2', { id: 'm3' }, '', 'm1']),
			standard_version: pick(['s1', 's2', { uuid: 's1' }, '', '', null]),
			bioMeasure: pick(['b1', 'b2', '', '', null]),
			standardGemma: pick(['GEMMA', '  ', '', 7, undefined]),
			evidence: pick(EVIDENCE),
			evidenceReference: pick(REFS),
			url: pick(URLS),
		}
		return pick([() => data, () => ({ id: 'r' + i, object: data })])()
	})
	const columns = [
		pick([{ uuid: 's1', name: 'S one' }, { id: 's1', titel: 'S1' }, 's1']),
		pick([
			{ uuid: 's2', title: 'S two' },
			{ id: 's2', label: 'L2' },
			{ uuid: 's2' },
		]),
		pick([{ uuid: 'b1', name: '' }, { id: 'b1' }, 'b1']),
		{ uuid: 'b2', name: 'B two' },
	]
	const modules = [{ uuid: 'm1' }, { id: 'm2' }, 'm3', { uuid: 'mx' }]
	const usages = Array.from({ length: 4 }, () =>
		pick([
			{ module: 'm1' },
			{ object: { module: 'm2' } },
			{ module: { id: 'm3' } },
			{},
			null,
		]),
	)
	return { compliancy, columns, modules, usages }
}

describe('compliance matrix golden', () => {
	it('answers the same for every seeded data set', () => {
		const recorded = {}
		for (let seed = 1; seed <= 30; seed++) {
			const { compliancy, columns, modules, usages } = dataSet(seed)
			const out = {}
			for (const source of Object.values(COLUMN_SOURCE)) {
				out[source] = {
					partition: partitionCompliancy(compliancy, source),
					matrix: buildComplianceMatrix({
						modules,
						columns,
						compliancy,
						columnSource: source,
					}),
					coverage: buildOrganisationCoverage({
						usages,
						columnUuid:
							source === COLUMN_SOURCE.BIO_MAATREGEL
								? 'b1'
								: undefined,
						standaardversieUuid: 's1',
						compliancy,
						columnSource: source,
						moduleIndex: { m1: { name: 'M1' } },
					}),
				}
			}
			out.defaults = {
				partition: partitionCompliancy(compliancy),
				matrix: buildComplianceMatrix({
					modules,
					standardVersions: columns,
					compliancy,
				}),
			}
			out.labels = columns.map((c) => [columnLabel(c), standardLabel(c)])
			out.evidence = compliancy.map((c) => hasEvidence(c?.object ?? c))
			recorded[seed] = JSON.parse(
				JSON.stringify(out, (k, v) => (v === undefined ? '(undefined)' : v)),
			)
		}
		recorded.edges = JSON.parse(
			JSON.stringify(
				{
					emptyMatrix: buildComplianceMatrix(),
					noColumns: buildComplianceMatrix({ modules: ['m1'] }),
					bothColumnSets: buildComplianceMatrix({
						modules: ['m1'],
						columns: ['c1'],
						standardVersions: ['s1'],
					}),
					namedTwice: columnLabel({ name: 'N', titel: 'T', title: 'X' }),
					emptyCoverage: buildOrganisationCoverage(),
					nullPartition: partitionCompliancy(null),
					labels: [
						null,
						undefined,
						0,
						'x',
						5,
						{},
						{ uuid: 'u' },
						{ id: 7 },
					].map((c) => [columnLabel(c), standardLabel(c)]),
					evidence: [null, 'x', 3, { evidence: 'e' }, { url: ' ' }].map(
						hasEvidence,
					),
				},
				(k, v) => (v === undefined ? '(undefined)' : v),
			),
		)
		if (process.env.RECORD_GOLDEN) {
			writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
			return
		}
		expect(JSON.stringify(recorded)).toBe(
			JSON.stringify(JSON.parse(readFileSync(GOLDEN, 'utf8'))),
		)
	})
})
