/**
 * Unit tests for the suite-wizard pure helpers.
 *
 * @spec openspec/specs/suite-wizard/spec.md#requirement-the-wizard-must-require-at-least-one-attached-application-before-advancing-past-the-applications-step
 */

import {
	isDetailsStepValid,
	isApplicationsStepValid,
	buildSuitePayload,
	summarizeApplications,
} from './suiteWizard.js'

describe('suiteWizard.isDetailsStepValid', () => {
	it('is invalid when naam is blank', () => {
		expect(isDetailsStepValid({ naam: '', beschrijvingKort: 'Short' })).toBe(false)
	})

	it('is invalid when beschrijvingKort is blank', () => {
		expect(isDetailsStepValid({ naam: 'Centric Leefomgeving', beschrijvingKort: '  ' })).toBe(false)
	})

	it('is valid when both required fields are filled in', () => {
		expect(isDetailsStepValid({ naam: 'Centric Leefomgeving', beschrijvingKort: 'Bundled product' })).toBe(true)
	})

	it('handles missing stepData gracefully', () => {
		expect(isDetailsStepValid(undefined)).toBe(false)
	})
})

describe('suiteWizard.isApplicationsStepValid', () => {
	const translate = (app, message) => message

	it('blocks advancing with zero applications', () => {
		expect(isApplicationsStepValid([], translate))
			.toBe('Attach at least one existing application before continuing.')
	})

	it('blocks advancing when applications is not an array', () => {
		expect(isApplicationsStepValid(undefined, translate))
			.toBe('Attach at least one existing application before continuing.')
	})

	it('allows advancing with one application', () => {
		expect(isApplicationsStepValid([{ id: 'mod-1' }], translate)).toBe(true)
	})

	it('allows advancing with several applications', () => {
		expect(isApplicationsStepValid([{ id: 'mod-1' }, { id: 'mod-2' }], translate)).toBe(true)
	})
})

describe('suiteWizard.buildSuitePayload', () => {
	it('builds the suite payload with a plain array of module ids', () => {
		const payload = buildSuitePayload({
			naam: '  Centric Leefomgeving  ',
			beschrijvingKort: 'Bundled leefomgeving product',
			beschrijvingLang: 'Long description',
			website: 'https://example.nl/leefomgeving',
			applications: [{ id: 'mod-1', naam: 'Module 1' }, { id: 'mod-2', naam: 'Module 2' }],
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
		const payload = buildSuitePayload({ naam: 'Suite', beschrijvingKort: 'Short' })
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
		expect(summarizeApplications([{ naam: 'Module A' }, { naam: 'Module B' }]))
			.toEqual(['Module A', 'Module B'])
	})

	it('falls back to id when naam is missing', () => {
		expect(summarizeApplications([{ id: 'mod-1' }])).toEqual(['mod-1'])
	})

	it('returns an empty array for non-array input', () => {
		expect(summarizeApplications(undefined)).toEqual([])
	})
})
