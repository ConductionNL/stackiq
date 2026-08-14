/**
 * Unit tests for OrganisationSwitcher's pure helpers.
 *
 * @spec openspec/specs/multi-org-membership/spec.md#requirement-switching-the-active-organisation-must-be-verified-against-server-side-membership-never-a-client-supplied-claim-req-001
 */

import {
	resolveActiveOrganisationName,
	resolveOtherOrganisations,
	resolveSwitchError,
} from './organisationSwitcher.js'

describe('organisationSwitcher.resolveActiveOrganisationName', () => {
	const organisations = [
		{ uuid: 'org-a', name: 'Gemeente A' },
		{ uuid: 'org-b', name: 'Gemeente B' },
	]

	it('resolves the active organisation name', () => {
		expect(
			resolveActiveOrganisationName(organisations, 'org-b', 'fallback'),
		).toBe('Gemeente B')
	})

	it('falls back when no organisation matches', () => {
		expect(
			resolveActiveOrganisationName(organisations, 'org-c', 'fallback'),
		).toBe('fallback')
	})

	it('falls back when the active uuid is null', () => {
		expect(resolveActiveOrganisationName(organisations, null, 'fallback')).toBe(
			'fallback',
		)
	})

	it('handles an empty/undefined organisations list gracefully', () => {
		expect(resolveActiveOrganisationName(undefined, 'org-a', 'fallback')).toBe(
			'fallback',
		)
		expect(resolveActiveOrganisationName([], 'org-a', 'fallback')).toBe(
			'fallback',
		)
	})
})

describe('organisationSwitcher.resolveOtherOrganisations', () => {
	const organisations = [
		{ uuid: 'org-a', name: 'Gemeente A' },
		{ uuid: 'org-b', name: 'Gemeente B' },
	]

	it('excludes the active organisation', () => {
		expect(resolveOtherOrganisations(organisations, 'org-a')).toEqual([
			{ uuid: 'org-b', name: 'Gemeente B' },
		])
	})

	it('returns every organisation when the active uuid is null', () => {
		expect(resolveOtherOrganisations(organisations, null)).toEqual(organisations)
	})

	it('returns an empty array when the user has only the active organisation', () => {
		const single = [{ uuid: 'org-a', name: 'Gemeente A' }]
		expect(resolveOtherOrganisations(single, 'org-a')).toEqual([])
	})
})

describe('organisationSwitcher.resolveSwitchError — server-side membership verification (REQ-001)', () => {
	it('returns null when the switch succeeded', () => {
		expect(resolveSwitchError(true, null, 'fallback')).toBeNull()
	})

	it('surfaces the server error message when the switch is refused', () => {
		expect(
			resolveSwitchError(
				false,
				{ error: 'User does not belong to this organisation' },
				'fallback',
			),
		).toBe('User does not belong to this organisation')
	})

	it('falls back to a generic message when the refused response carries no error field', () => {
		expect(resolveSwitchError(false, {}, 'fallback')).toBe('fallback')
	})

	it('falls back to a generic message when the refused response body is null', () => {
		expect(resolveSwitchError(false, null, 'fallback')).toBe('fallback')
	})

	it('never treats a non-ok response as a success, even with a truthy-looking body', () => {
		// Guards against a client trusting a forged/malformed body over the
		// actual HTTP status — REQ-001's "forged organisation id" scenario.
		expect(
			resolveSwitchError(
				false,
				{ activeOrganisation: { uuid: 'org-c' } },
				'fallback',
			),
		).toBe('fallback')
	})
})
