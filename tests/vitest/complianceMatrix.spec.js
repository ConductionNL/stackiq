/**
 * Unit tests for the compliance-matrix data mapper.
 *
 * Covers the three cell states (verified / claimed / none), evidence
 * detection across all three evidence carriers, unresolved-relation
 * partitioning, deduplication of multiple records for one pair, and the
 * organisation-coverage join.
 *
 * @spec openspec/changes/module-compliance-assessment/specs/module-compliance-assessment/spec.md
 */

import { describe, it, expect } from 'vitest'
import {
	CELL,
	resolveUuid,
	hasEvidence,
	partitionCompliancy,
	buildComplianceMatrix,
	buildOrganisationCoverage,
	standardLabel,
} from '../../src/utils/complianceMatrix.js'

describe('complianceMatrix.resolveUuid', () => {
	it('reads bare string UUIDs', () => {
		expect(resolveUuid('abc')).toBe('abc')
		expect(resolveUuid('  abc  ')).toBe('abc')
	})
	it('reads object uuid/id/@self.id', () => {
		expect(resolveUuid({ uuid: 'u1' })).toBe('u1')
		expect(resolveUuid({ id: 'i1' })).toBe('i1')
		expect(resolveUuid({ '@self': { id: 's1' } })).toBe('s1')
	})
	it('returns empty for null/undefined', () => {
		expect(resolveUuid(null)).toBe('')
		expect(resolveUuid(undefined)).toBe('')
	})
})

describe('complianceMatrix.hasEvidence', () => {
	it('detects a base64 bewijs file', () => {
		expect(hasEvidence({ bewijs: 'data:application/pdf;base64,AAA' })).toBe(true)
		expect(hasEvidence({ bewijs: { id: 1 } })).toBe(true)
	})
	it('detects a bewijsReferentie NC Files link', () => {
		expect(hasEvidence({ bewijsReferentie: '/Files/proof.pdf' })).toBe(true)
	})
	it('detects a url', () => {
		expect(hasEvidence({ url: 'https://example.org/proof' })).toBe(true)
	})
	it('returns false with no evidence', () => {
		expect(hasEvidence({})).toBe(false)
		expect(hasEvidence({ bewijs: '', bewijsReferentie: '  ', url: '' })).toBe(false)
		expect(hasEvidence(null)).toBe(false)
	})
})

describe('complianceMatrix.partitionCompliancy', () => {
	it('separates resolved relations from unresolved standaardGemma-only records', () => {
		const { resolved, unresolved } = partitionCompliancy([
			{ module: 'm1', standaardversie: 's1', url: 'https://e' },
			{ module: 'm1', standaardGemma: 'GEMMA-ZGW' },
			{ module: 'm2', standaardversie: { uuid: 's2' } },
			{ module: 'm3' },
		])
		expect(resolved).toHaveLength(2)
		expect(unresolved).toHaveLength(1)
		expect(unresolved[0].standaardGemma).toBe('GEMMA-ZGW')
		expect(resolved[0].evidenced).toBe(true)
		expect(resolved[1].evidenced).toBe(false)
	})
})

describe('complianceMatrix.buildComplianceMatrix', () => {
	const modules = [
		{ uuid: 'mA', naam: 'App A' },
		{ uuid: 'mB', naam: 'App B' },
	]
	const standaardversies = [
		{ uuid: 's1', naam: 'ZGW API' },
		{ uuid: 's2', naam: 'Haal Centraal' },
	]

	it('renders the three cell states correctly', () => {
		const compliancy = [
			{ module: 'mA', standaardversie: 's1', url: 'https://proof' },
			{ module: 'mA', standaardversie: 's2' },
			{ module: 'mB', standaardversie: 's1' },
		]
		const { rows, columns } = buildComplianceMatrix({ modules, standaardversies, compliancy })
		expect(columns.map((c) => c.label)).toEqual(['ZGW API', 'Haal Centraal'])
		expect(rows[0].cells.s1.state).toBe(CELL.VERIFIED)
		expect(rows[0].cells.s2.state).toBe(CELL.CLAIMED)
		expect(rows[1].cells.s1.state).toBe(CELL.CLAIMED)
		expect(rows[1].cells.s2.state).toBe(CELL.NONE)
		expect(rows[1].cells.s2.record).toBeNull()
	})

	it('only produces selected standard columns (filter-first, no cartesian wall)', () => {
		const { columns } = buildComplianceMatrix({
			modules,
			standaardversies: [{ uuid: 's1', naam: 'ZGW API' }],
			compliancy: [],
		})
		expect(columns).toHaveLength(1)
	})

	it('keeps the strongest state when two records cover one pair', () => {
		const compliancy = [
			{ module: 'mA', standaardversie: 's1' },
			{ module: 'mA', standaardversie: 's1', url: 'https://proof' },
		]
		const { rows } = buildComplianceMatrix({ modules, standaardversies, compliancy })
		expect(rows[0].cells.s1.state).toBe(CELL.VERIFIED)
		expect(rows[0].cells.s1.record).toBeTruthy()
	})

	it('surfaces unresolved standaardGemma-only records separately', () => {
		const { unresolved } = buildComplianceMatrix({
			modules,
			standaardversies,
			compliancy: [{ module: 'mA', standaardGemma: 'GEMMA-X' }],
		})
		expect(unresolved).toHaveLength(1)
		expect(unresolved[0].standaardGemma).toBe('GEMMA-X')
	})
})

describe('complianceMatrix.standardLabel', () => {
	it('falls back through name fields', () => {
		expect(standardLabel({ naam: 'A' })).toBe('A')
		expect(standardLabel({ titel: 'B' })).toBe('B')
		expect(standardLabel({ uuid: 'u' })).toBe('u')
	})
})

describe('complianceMatrix.buildOrganisationCoverage', () => {
	it('lists every gebruik including applications with no compliance data', () => {
		const gebruiken = [
			{ module: 'mA' },
			{ module: 'mB' },
			{ module: 'mC' },
		]
		const compliancy = [
			{ module: 'mA', standaardversie: 's1', url: 'https://e' },
			{ module: 'mB', standaardversie: 's1' },
		]
		const coverage = buildOrganisationCoverage({
			gebruiken,
			standaardversieUuid: 's1',
			compliancy,
		})
		expect(coverage).toHaveLength(3)
		expect(coverage[0].state).toBe(CELL.VERIFIED)
		expect(coverage[1].state).toBe(CELL.CLAIMED)
		expect(coverage[2].state).toBe(CELL.NONE)
	})

	it('ignores compliancy for other standards', () => {
		const coverage = buildOrganisationCoverage({
			gebruiken: [{ module: 'mA' }],
			standaardversieUuid: 's1',
			compliancy: [{ module: 'mA', standaardversie: 's2', url: 'https://e' }],
		})
		expect(coverage[0].state).toBe(CELL.NONE)
	})
})
