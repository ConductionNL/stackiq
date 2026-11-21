/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The comparison answer as rows: each difference with its change and source in
 * words, the coverage changes, the two landscape columns, and the notice for a
 * failed apply.
 *
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
 */
import { describe, expect, it } from 'vitest'
import {
	applicationRows,
	applyFailureNotice,
	changeLabel,
	comparisonParams,
	coverageRows,
	landscapeNames,
	organisationPickerOptions,
	sourceLabel,
} from '../../src/utils/landscapeComparison.js'

const REPORT = {
	today: '2026-11-21',
	date: '2027-06-30',
	landscapes: {
		today: [{ module: 'm1', moduleName: 'Zaaksysteem A' }, { module: 'm2', moduleName: 'DMS' }],
		future: [{ module: 'm3', moduleName: 'Zaaksysteem B' }, { module: 'm2', moduleName: 'DMS' }],
	},
	applications: [
		{ usage: 'u1', module: 'm1', moduleName: 'Zaaksysteem A', change: 'replaced', replacedBy: 'Zaaksysteem B', source: 'plan', sources: ['plan'], date: '2027-03-01' },
		{ usage: 'u2', module: 'm2', moduleName: 'DMS', change: 'unchanged', source: null, sources: [], date: null },
		{ usage: null, module: 'm4', moduleName: 'Servicedesk', change: 'added', source: 'scenario', sources: ['scenario'], date: '2027-03-01' },
		{ usage: 'u5', module: 'm5', moduleName: 'Schuldhulp', change: 'removed', source: 'scenario', sources: ['scenario', 'plan'], date: '2027-06-30' },
	],
	coverage: [
		{ componentId: 'rc1', component: 'Zaakregistratiecomponent', today: 'overlap', future: 'covered', change: 'overlap resolved' },
		{ componentId: 'rc2', component: 'Klantinteractie', today: 'gap', future: 'covered', change: 'gap closed' },
	],
	notDated: 2,
}

describe('comparisonParams', () => {
	it('sends the organisation and the date for the plan', () => {
		expect(comparisonParams({ organisation: 'o1', date: '2027-06-30' })).toEqual({ organisation: 'o1', date: '2027-06-30' })
	})

	it('sends the scenario, leaving the date to it', () => {
		expect(comparisonParams({ organisation: 'o1', scenario: 's1' })).toEqual({ organisation: 'o1', scenario: 's1' })
	})
})

describe('applicationRows', () => {
	it('lists every difference in words and leaves the unchanged ones out', () => {
		expect(applicationRows(REPORT)).toEqual([
			{ id: 'u1:0', application: 'Zaaksysteem A', change: 'Replaced by Zaaksysteem B', source: 'Plan', date: '2027-03-01' },
			{ id: 'm4:1', application: 'Servicedesk', change: 'Added', source: 'Scenario', date: '2027-03-01' },
			{ id: 'u5:2', application: 'Schuldhulp', change: 'Removed', source: 'Scenario, Plan', date: '2027-06-30' },
		])
	})

	it('reads an empty answer as no rows', () => {
		expect(applicationRows(null)).toEqual([])
	})
})

describe('changeLabel and sourceLabel', () => {
	it('falls back to Unchanged and to the single source', () => {
		expect(changeLabel({ change: 'unchanged' })).toBe('Unchanged')
		expect(sourceLabel({ source: 'plan' })).toBe('Plan')
		expect(sourceLabel({})).toBe('')
	})
})

describe('coverageRows', () => {
	it('names both states and the change in words', () => {
		expect(coverageRows(REPORT)).toEqual([
			{ id: 'rc1', component: 'Zaakregistratiecomponent', today: 'Overlap', future: 'Covered', change: 'Overlap resolved' },
			{ id: 'rc2', component: 'Klantinteractie', today: 'Gap', future: 'Covered', change: 'Gap closed' },
		])
	})
})

describe('landscapeNames', () => {
	it('lists the applications of each column', () => {
		expect(landscapeNames(REPORT, 'today')).toEqual(['Zaaksysteem A', 'DMS'])
		expect(landscapeNames(REPORT, 'future')).toEqual(['Zaaksysteem B', 'DMS'])
		expect(landscapeNames({}, 'today')).toEqual([])
	})
})

describe('applyFailureNotice', () => {
	it('names the change the apply stopped at', () => {
		const text = applyFailureNotice({ change: { action: 'phase out', note: 'Schuldhulp gaat naar de regio.' }, message: 'The application in use could not be written.' })
		expect(text).toContain('Schuldhulp gaat naar de regio.')
		expect(text).toContain('could not be written')
	})

	it('says when only the scenario could not be marked', () => {
		expect(applyFailureNotice({ change: null, message: 'no' })).toBe('The scenario was not marked as applied: no')
	})
})

describe('organisationPickerOptions', () => {
	it('labels each organisation by name, falling back to its uuid', () => {
		expect(organisationPickerOptions([
			{ uuid: 'o1', name: 'Gemeente A' },
			{ '@self': { id: 'o2' }, object: { title: 'Samenwerking B' } },
			{ id: 'o3' },
		])).toEqual([
			{ uuid: 'o1', label: 'Gemeente A' },
			{ uuid: 'o2', label: 'Samenwerking B' },
			{ uuid: 'o3', label: 'o3' },
		])
		expect(organisationPickerOptions(null)).toEqual([])
	})
})
