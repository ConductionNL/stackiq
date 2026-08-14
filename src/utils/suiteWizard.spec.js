/**
 * Unit tests for the suite-wizard pure helpers.
 *
 * @spec openspec/specs/suite-wizard/spec.md#requirement-the-wizard-must-require-at-least-one-attached-application-before-advancing-past-the-applications-step
 */

import {
	buildSuitePayload,
	isApplicationsStepValid,
	isDetailsStepValid,
	mapApplicationOptions,
	summarizeApplications,
} from './suiteWizard.js'

describe('suiteWizard.isDetailsStepValid', () => {
	it('is invalid when naam is blank', () => {
		expect(isDetailsStepValid({ naam: '', beschrijvingKort: 'Short' })).toBe(
			false,
		)
	})

	it('is invalid when beschrijvingKort is blank', () => {
		expect(
			isDetailsStepValid({
				naam: 'Centric Leefomgeving',
				beschrijvingKort: '  ',
			}),
		).toBe(false)
	})

	it('is valid when both required fields are filled in', () => {
		expect(
			isDetailsStepValid({
				naam: 'Centric Leefomgeving',
				beschrijvingKort: 'Bundled product',
			}),
		).toBe(true)
	})

	it('handles missing stepData gracefully', () => {
		expect(isDetailsStepValid(undefined)).toBe(false)
	})
})

describe('suiteWizard.isApplicationsStepValid', () => {
	const translate = (app, message) => message

	it('blocks advancing with zero applications', () => {
		expect(isApplicationsStepValid([], translate)).toBe(
			'Attach at least one existing application before continuing.',
		)
	})

	it('blocks advancing when applications is not an array', () => {
		expect(isApplicationsStepValid(undefined, translate)).toBe(
			'Attach at least one existing application before continuing.',
		)
	})

	it('allows advancing with one application', () => {
		expect(isApplicationsStepValid([{ id: 'mod-1' }], translate)).toBe(true)
	})

	it('allows advancing with several applications', () => {
		expect(
			isApplicationsStepValid([{ id: 'mod-1' }, { id: 'mod-2' }], translate),
		).toBe(true)
	})
})

describe('suiteWizard.buildSuitePayload', () => {
	it('builds the suite payload with a plain array of module ids', () => {
		const payload = buildSuitePayload({
			naam: '  Centric Leefomgeving  ',
			beschrijvingKort: 'Bundled leefomgeving product',
			beschrijvingLang: 'Long description',
			website: 'https://example.nl/leefomgeving',
			applications: [
				{ id: 'mod-1', naam: 'Module 1' },
				{ id: 'mod-2', naam: 'Module 2' },
			],
		})

		expect(payload).toEqual({
			naam: 'Centric Leefomgeving',
			beschrijvingKort: 'Bundled leefomgeving product',
			beschrijvingLang: 'Long description',
			website: 'https://example.nl/leefomgeving',
			applicaties: ['mod-1', 'mod-2'],
		})
	})

	it('accepts plain id strings as well as module objects', () => {
		const payload = buildSuitePayload({
			naam: 'Suite',
			beschrijvingKort: 'Short',
			applications: ['mod-1', 'mod-2'],
		})
		expect(payload.applicaties).toEqual(['mod-1', 'mod-2'])
	})

	it('defaults optional fields to empty strings and applicaties to an empty array', () => {
		const payload = buildSuitePayload({
			naam: 'Suite',
			beschrijvingKort: 'Short',
		})
		expect(payload.beschrijvingLang).toBe('')
		expect(payload.website).toBe('')
		expect(payload.applicaties).toEqual([])
	})

	it('handles missing stepData gracefully', () => {
		const payload = buildSuitePayload(undefined)
		expect(payload).toEqual({
			naam: '',
			beschrijvingKort: '',
			beschrijvingLang: '',
			website: '',
			applicaties: [],
		})
	})
})

describe('suiteWizard.summarizeApplications', () => {
	it('returns the naam of each attached application', () => {
		expect(
			summarizeApplications([{ naam: 'Module A' }, { naam: 'Module B' }]),
		).toEqual(['Module A', 'Module B'])
	})

	it('falls back to id when naam is missing', () => {
		expect(summarizeApplications([{ id: 'mod-1' }])).toEqual(['mod-1'])
	})

	it('returns an empty array for non-array input', () => {
		expect(summarizeApplications(undefined)).toEqual([])
	})
})

describe('suiteWizard.mapApplicationOptions', () => {
	// Regression: `objectStore.getCollection()` returns the paginated ENVELOPE,
	// not a bare array. The component computed mapped it as an array, which threw
	// "(intermediate value).map is not a function" at runtime and left the
	// wizard's Applications step blank — no application could be attached to a
	// suite. Found only by running the wizard in a browser (2026-07-24); no test
	// covered the computed. These cases pin BOTH shapes.
	it('maps the paginated envelope shape returned by getCollection()', () => {
		const envelope = {
			results: [{ uuid: 'u1', naam: 'Zaaksysteem' }],
			total: 1,
			page: 1,
		}

		expect(mapApplicationOptions(envelope)).toEqual([
			{
				uuid: 'u1',
				label: 'Zaaksysteem',
				raw: { uuid: 'u1', naam: 'Zaaksysteem' },
			},
		])
	})

	it('still maps a bare array', () => {
		expect(mapApplicationOptions([{ uuid: 'u2', naam: 'DMS' }])).toEqual([
			{ uuid: 'u2', label: 'DMS', raw: { uuid: 'u2', naam: 'DMS' } },
		])
	})

	it('returns an empty list while the collection is still loading', () => {
		expect(mapApplicationOptions(null)).toEqual([])
		expect(mapApplicationOptions(undefined)).toEqual([])
		expect(mapApplicationOptions({})).toEqual([])
	})

	it('falls back to id and @self.id for the identifier, and to the uuid for the label', () => {
		const envelope = { results: [{ id: 'i1' }, { '@self': { id: 's1' } }] }

		expect(
			mapApplicationOptions(envelope).map((o) => [o.uuid, o.label]),
		).toEqual([
			['i1', 'i1'],
			['s1', 's1'],
		])
	})
})
