/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * The four cell states of the compliance matrix (bio-measures-shipped-and-not-met
 * task 4): verified, claimed, does not meet and unknown, the summary counts, and
 * the compliance list's Result column.
 *
 * @spec openspec/changes/bio-measures-shipped-and-not-met/specs/bio-compliance-assessment/spec.md#requirement-a-compliance-claim-says-whether-the-application-meets-the-measure-req-bio-011
 */

import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'
import {
	buildComplianceMatrix,
	buildOrganisationCoverage,
	CELL,
	COLUMN_SOURCE,
	countCellStates,
	entryState,
} from '../../src/utils/complianceMatrix.js'

const modules = [
	{ uuid: 'mA', name: 'Zaaksysteem' },
	{ uuid: 'mB', name: 'Archief' },
	{ uuid: 'mC', name: 'Portaal' },
]
const measures = [
	{ uuid: 'b941', code: '9.4.1', name: 'Toegang' },
	{ uuid: 'b1011', code: '10.1.1', name: 'Versleuteling' },
]

function matrixOf(compliancy) {
	return buildComplianceMatrix({ modules, columns: measures, compliancy, columnSource: COLUMN_SOURCE.BIO_MAATREGEL })
}

describe('compliance matrix states', () => {
	it('shows verified, claimed, does not meet and unknown apart', () => {
		const { rows } = matrixOf([
			{ module: 'mA', bioMeasure: 'b941', url: 'https://example.org/pentest', result: 'meets' },
			{ module: 'mA', bioMeasure: 'b1011', result: 'does-not-meet' },
			{ module: 'mB', bioMeasure: 'b941' },
		])

		expect(rows[0].cells.b941.state).toBe(CELL.VERIFIED)
		expect(rows[0].cells.b1011.state).toBe(CELL.NOT_MET)
		expect(rows[0].cells.b1011.record).not.toBeNull()
		expect(rows[1].cells.b941.state).toBe(CELL.CLAIMED)
		// Not assessed stays apart: no claim is unknown, never "does not meet".
		expect(rows[1].cells.b1011.state).toBe(CELL.NONE)
		expect(rows[2].cells.b941.state).toBe(CELL.NONE)
	})

	it('reads a claim without a result as meets, so existing claims keep their state', () => {
		expect(entryState({ evidenced: true, meets: true })).toBe(CELL.VERIFIED)
		expect(entryState({ evidenced: false, meets: true })).toBe(CELL.CLAIMED)
		expect(entryState({ evidenced: true, meets: false })).toBe(CELL.NOT_MET)

		const { rows } = matrixOf([
			{ module: 'mA', bioMeasure: 'b941', url: 'https://example.org/report' },
			{ module: 'mB', bioMeasure: 'b941' },
		])
		expect(rows[0].cells.b941.state).toBe(CELL.VERIFIED)
		expect(rows[1].cells.b941.state).toBe(CELL.CLAIMED)
	})

	it('lets a claim that meets outrank one that does not for the same pair', () => {
		const { rows } = matrixOf([
			{ module: 'mA', bioMeasure: 'b941', result: 'does-not-meet' },
			{ module: 'mA', bioMeasure: 'b941' },
			{ module: 'mB', bioMeasure: 'b941', result: 'does-not-meet' },
			{ module: 'mB', bioMeasure: 'b941', result: 'does-not-meet', url: 'https://example.org/finding' },
		])
		expect(rows[0].cells.b941.state).toBe(CELL.CLAIMED)
		expect(rows[1].cells.b941.state).toBe(CELL.NOT_MET)
	})

	it('counts every state for the summary, does not meet included', () => {
		const matrix = matrixOf([
			{ module: 'mA', bioMeasure: 'b941', url: 'https://example.org/pentest' },
			{ module: 'mA', bioMeasure: 'b1011', result: 'does-not-meet' },
			{ module: 'mB', bioMeasure: 'b941' },
			{ module: 'mC', bioMeasure: 'b1011', result: 'does-not-meet' },
		])
		expect(countCellStates(matrix)).toEqual({ verified: 1, claimed: 1, notMet: 2, none: 2 })
		expect(countCellStates(undefined)).toEqual({ verified: 0, claimed: 0, notMet: 0, none: 0 })
	})

	it('carries does not meet into an organisation coverage row', () => {
		const coverage = buildOrganisationCoverage({
			usages: [{ module: 'mA' }, { module: 'mB' }],
			columnUuid: 'b1011',
			compliancy: [{ module: 'mA', bioMeasure: 'b1011', result: 'does-not-meet' }],
			columnSource: COLUMN_SOURCE.BIO_MAATREGEL,
		})
		expect(coverage.map((row) => row.state)).toEqual([CELL.NOT_MET, CELL.NONE])
	})

	it('reads the BIO measure under bioMeasure and lists the result on an application', () => {
		expect(COLUMN_SOURCE.BIO_MAATREGEL).toBe('bioMeasure')
		const widgets = manifest.pages.flatMap((page) => page.config?.widgets || [])
		const list = widgets.find((widget) => widget.id === 'md-compliance')
		expect(list.content.columns.map((column) => column.key)).toEqual(['standardVersion', 'bioMeasure', 'result', 'url'])
		expect(JSON.stringify(manifest)).not.toContain('"bioMaatregel"')
	})
})
