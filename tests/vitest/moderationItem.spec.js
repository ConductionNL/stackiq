/**
 * Unit tests for the moderation-item display helpers.
 *
 * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
 */

import { describe, it, expect } from 'vitest'
import {
	moderationItemTitle,
	moderationItemSubtitle,
} from '../../src/utils/moderationItem.js'

describe('moderationItemTitle', () => {
	it('prefers naam, then name/titel, etc.', () => {
		expect(moderationItemTitle({ name: 'Acme BV' })).toBe('Acme BV')
		expect(moderationItemTitle({ title: 'A standard' })).toBe('A standard')
		expect(moderationItemTitle({ organisatie: 'Org X' })).toBe('Org X')
	})

	it('falls back to the uuid, then a default, never blank', () => {
		expect(moderationItemTitle({ id: 'uuid-123' })).toBe('uuid-123')
		expect(moderationItemTitle({ uuid: 'uuid-456' })).toBe('uuid-456')
		expect(moderationItemTitle({})).toBe('Untitled registration')
		expect(moderationItemTitle(null)).toBe('Untitled registration')
	})

	it('ignores blank/whitespace title fields', () => {
		expect(moderationItemTitle({ name: '   ', name: 'Real' })).toBe('Real')
	})
})

describe('moderationItemSubtitle', () => {
	it('picks a contact/url/description distinct from the title', () => {
		expect(
			moderationItemSubtitle({ name: 'Acme', email: 'info@acme.org' }),
		).toBe('info@acme.org')
		expect(moderationItemSubtitle({ name: 'X', url: 'https://x.org' })).toBe(
			'https://x.org',
		)
	})

	it('never duplicates the title and returns empty when nothing fits', () => {
		expect(moderationItemSubtitle({ name: 'Acme', email: 'Acme' })).toBe('')
		expect(moderationItemSubtitle({ name: 'Acme' })).toBe('')
		expect(moderationItemSubtitle(null)).toBe('')
	})
})
