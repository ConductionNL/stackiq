/**
 * Unit tests for the suite-wizard pure helpers (vitest mirror of the
 * co-located Jest suite at src/utils/suiteWizard.spec.js — this project runs
 * two test runners; new pure-JS logic gets a vitest spec per PHASE 3 of the
 * suite-wizard implementation plan).
 *
 * @spec openspec/specs/suite-wizard/spec.md#requirement-the-wizard-must-require-at-least-one-attached-application-before-advancing-past-the-applications-step
 */

import { describe, it, expect } from 'vitest'
import {
	isDetailsStepValid,
	isApplicationsStepValid,
	buildSuitePayload,
	summarizeApplications,
} from '../../src/utils/suiteWizard.js'

describe('suiteWizard.isDetailsStepValid', () => {
	it('requires both naam and beschrijvingKort', () => {
		expect(isDetailsStepValid({ naam: '', beschrijvingKort: '' })).toBe(false)
		expect(isDetailsStepValid({ naam: 'Suite', beschrijvingKort: '' })).toBe(
			false,
		)
		expect(isDetailsStepValid({ naam: '', beschrijvingKort: 'Short' })).toBe(
			false,
		)
		expect(
			isDetailsStepValid({ naam: 'Suite', beschrijvingKort: 'Short' }),
		).toBe(true)
	})
})

describe('suiteWizard.isApplicationsStepValid', () => {
	const translate = (app, message) => message

	it('blocks zero applications and allows one or more', () => {
		expect(isApplicationsStepValid([], translate)).not.toBe(true)
		expect(isApplicationsStepValid([{ id: 'mod-1' }], translate)).toBe(true)
	})
})

describe('suiteWizard.buildSuitePayload', () => {
	it('reduces attached module objects to a plain array of ids', () => {
		const payload = buildSuitePayload({
			naam: 'Centric Leefomgeving',
			beschrijvingKort: 'Bundled product',
			applications: [{ id: 'mod-1' }, { id: 'mod-2' }],
		})
		expect(payload.applicaties).toEqual(['mod-1', 'mod-2'])
		expect(payload.naam).toBe('Centric Leefomgeving')
	})
})

describe('suiteWizard.summarizeApplications', () => {
	it('lists application names for the confirm step', () => {
		expect(
			summarizeApplications([{ naam: 'Module A' }, { id: 'mod-2' }]),
		).toEqual(['Module A', 'mod-2'])
	})
})
