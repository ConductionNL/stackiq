/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What "shared" means for a usage: its participants, the owner and count the
 * Shared column shows, and who only takes part (shared-usage-visible-to-participants task 3).
 *
 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
 */

import { describe, expect, it } from 'vitest'
import { isParticipantOnly, participantsOf, relationId, sharedSummary } from '../../src/utils/sharedUsage.js'

const mozard = {
	id: 'u-mozard',
	consumer: { id: 'org-zd', name: 'Drechtsteden-Zuid' },
	participants: ['org-alb', { id: 'org-pap', name: 'Papendrecht', since: '2025-03-01' }, 'org-alb', ''],
}

describe('sharedUsage', () => {
	it('reads a relation as a string or an object', () => {
		expect(relationId(' org-1 ')).toBe('org-1')
		expect(relationId({ uuid: 'org-2' })).toBe('org-2')
		expect(relationId({ '@self': { id: 'org-3' } })).toBe('org-3')
		expect(relationId(null)).toBe('')
		expect(relationId(7)).toBe('')
	})

	it('lists each participant once with its name and join date', () => {
		expect(participantsOf(mozard)).toEqual([
			{ id: 'org-alb', name: '', since: '' },
			{ id: 'org-pap', name: 'Papendrecht', since: '2025-03-01' },
		])
		expect(participantsOf({})).toEqual([])
		expect(participantsOf(null)).toEqual([])
	})

	it('summarises a shared usage as its owner and three organisations', () => {
		expect(sharedSummary(mozard)).toEqual({ owner: 'org-zd', ownerName: 'Drechtsteden-Zuid', count: 3 })
	})

	it('counts the owner once when it is also listed', () => {
		expect(sharedSummary({ consumer: 'org-zd', participants: ['org-zd', 'org-alb'] }).count).toBe(2)
	})

	it('prefers the gebruik API marker', () => {
		const usage = { ...mozard, _shared: { cooperation: 'org-coop', participantCount: 5 } }
		expect(sharedSummary(usage)).toEqual({ owner: 'org-coop', ownerName: 'Drechtsteden-Zuid', count: 5 })
	})

	it('is not shared without participants', () => {
		expect(sharedSummary({ consumer: 'org-zd', participants: [] })).toBeNull()
		expect(sharedSummary({ consumer: 'org-zd' })).toBeNull()
	})

	it('knows who only takes part', () => {
		expect(isParticipantOnly(mozard, 'org-alb')).toBe(true)
		expect(isParticipantOnly(mozard, 'org-zd')).toBe(false)
		expect(isParticipantOnly(mozard, 'org-oostmaas')).toBe(false)
		expect(isParticipantOnly(mozard, '')).toBe(false)
	})
})
