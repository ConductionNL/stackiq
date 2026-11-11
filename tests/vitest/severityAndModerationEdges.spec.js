/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the edges of the severity helpers (every band
 * boundary, non-numbers, the sort order of every band) and of the moderation
 * item title and subtitle (blank and non-string fields, trimming, the id
 * fallbacks, a subtitle equal to the title).
 *
 * @spec openspec/specs/module-vulnerability-tracking/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	moderationItemSubtitle,
	moderationItemTitle,
} from '../../src/utils/moderationItem.js'
import {
	parseCvss,
	SEVERITY,
	severityFromScore,
	severityOrder,
} from '../../src/utils/vulnerabilitySeverity.js'

describe('severity edges', () => {
	it('parses CVSS values', () => {
		expect(
			[
				7.5,
				'9.8',
				' 4,2 ',
				'',
				'  ',
				'abc',
				Infinity,
				NaN,
				null,
				undefined,
				{},
				'12',
				'-1',
			].map(parseCvss),
		).toEqual([
			7.5,
			9.8,
			4.2,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			10,
			0,
		])
	})

	it('bands scores at every boundary', () => {
		expect(
			[10, 9, 8.99, 7, 6.99, 4, 3.99, 0, -1, NaN, '9', null].map(
				severityFromScore,
			),
		).toEqual([
			SEVERITY.CRITICAL,
			SEVERITY.CRITICAL,
			SEVERITY.HIGH,
			SEVERITY.HIGH,
			SEVERITY.MEDIUM,
			SEVERITY.MEDIUM,
			SEVERITY.LOW,
			SEVERITY.LOW,
			SEVERITY.LOW,
			SEVERITY.UNKNOWN,
			SEVERITY.UNKNOWN,
			SEVERITY.UNKNOWN,
		])
	})

	it('orders every band', () => {
		expect(
			[
				SEVERITY.CRITICAL,
				SEVERITY.HIGH,
				SEVERITY.MEDIUM,
				SEVERITY.LOW,
				SEVERITY.UNKNOWN,
				'All',
				undefined,
			].map(severityOrder),
		).toEqual([0, 1, 2, 3, 4, 4, 4])
	})
})

describe('moderation item edges', () => {
	it('picks the first filled title, else the id, else a placeholder', () => {
		expect(moderationItemTitle({ name: '  ', title: ' Spaced ' })).toBe('Spaced')
		expect(moderationItemTitle({ name: 5, id: 7 })).toBe('7')
		expect(moderationItemTitle({ uuid: 'u-1' })).toBe('u-1')
		expect(moderationItemTitle({})).toBe('Untitled registration')
		expect(moderationItemTitle(null)).toBe('Untitled registration')
	})

	it('picks the first filled subtitle that differs from the title', () => {
		expect(
			moderationItemSubtitle({
				name: 'Acme',
				email: ' Acme ',
				url: ' https://a.example ',
			}),
		).toBe('https://a.example')
		expect(
			moderationItemSubtitle({
				name: 'Acme',
				email: '',
				contactEmail: 3,
				website: 'w',
			}),
		).toBe('w')
		expect(moderationItemSubtitle({ id: 9, description: '9' })).toBe('')
		expect(moderationItemSubtitle({ id: 9, description: 'About' })).toBe('About')
		expect(moderationItemSubtitle(null)).toBe('')
		expect(moderationItemSubtitle({})).toBe('')
	})
})
