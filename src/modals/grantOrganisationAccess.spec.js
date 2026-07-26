/**
 * Unit tests for GrantOrganisationAccessModal's pure helpers.
 *
 * @spec openspec/specs/multi-org-membership/spec.md#requirement-granting-access-must-only-target-an-existing-nextcloud-user-req-005
 */

import { extractUserId, removeMember } from './grantOrganisationAccess.js'

describe('grantOrganisationAccess.extractUserId', () => {
	it('extracts the id from an { id } shaped selection', () => {
		expect(extractUserId({ id: 'j.devries' })).toBe('j.devries')
	})

	it('extracts the id from a { user } shaped selection', () => {
		expect(extractUserId({ user: 'j.devries' })).toBe('j.devries')
	})

	it('prefers .id over .user when both are present', () => {
		expect(extractUserId({ id: 'preferred', user: 'other' })).toBe('preferred')
	})

	it('passes through a bare string selection', () => {
		expect(extractUserId('j.devries')).toBe('j.devries')
	})

	it('returns null for a null/undefined selection', () => {
		expect(extractUserId(null)).toBeNull()
		expect(extractUserId(undefined)).toBeNull()
	})

	it('returns null when neither .id nor .user is present', () => {
		expect(extractUserId({ displayName: 'Jan de Vries' })).toBeNull()
	})
})

describe('grantOrganisationAccess.removeMember', () => {
	it('removes the given user id from the member list', () => {
		expect(removeMember(['a', 'b', 'c'], 'b')).toEqual(['a', 'c'])
	})

	it('is a no-op when the user id is not present', () => {
		expect(removeMember(['a', 'b'], 'z')).toEqual(['a', 'b'])
	})

	it('handles an empty/undefined member list gracefully', () => {
		expect(removeMember([], 'a')).toEqual([])
		expect(removeMember(undefined, 'a')).toEqual([])
	})

	it('does not mutate the input array', () => {
		const members = ['a', 'b']
		removeMember(members, 'a')
		expect(members).toEqual(['a', 'b'])
	})
})
