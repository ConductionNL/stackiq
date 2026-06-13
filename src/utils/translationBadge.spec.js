/* eslint-disable no-console */
/**
 * Unit tests for the translation-badge utility.
 *
 * @spec openspec/changes/softwarecatalog-adopt-or-abstractions/tasks.md#3.5
 */

import {
	languageName,
	getSourceLanguage,
	shouldShowTranslationBadge,
	translationBadge,
} from './translationBadge.js'

describe('translationBadge.languageName', () => {
	it('maps known codes to English names', () => {
		expect(languageName('nl')).toBe('Dutch')
		expect(languageName('en')).toBe('English')
	})

	it('normalises region tags before mapping', () => {
		expect(languageName('nl_NL')).toBe('Dutch')
		expect(languageName('en-GB')).toBe('English')
	})

	it('falls back to the upper-cased code for unknown languages', () => {
		expect(languageName('xx')).toBe('XX')
	})

	it('returns an empty string for empty input', () => {
		expect(languageName('')).toBe('')
	})
})

describe('translationBadge.getSourceLanguage', () => {
	it('reads a top-level sourceLanguage', () => {
		expect(getSourceLanguage({ sourceLanguage: 'NL' })).toBe('nl')
	})

	it('reads sourceLanguage from the @self envelope', () => {
		expect(getSourceLanguage({ '@self': { sourceLanguage: 'en_US' } })).toBe('en')
	})

	it('reads the snake_case @self variant', () => {
		expect(getSourceLanguage({ '@self': { source_language: 'de' } })).toBe('de')
	})

	it('returns empty string when no source language is present', () => {
		expect(getSourceLanguage({})).toBe('')
		expect(getSourceLanguage(null)).toBe('')
	})
})

describe('translationBadge.shouldShowTranslationBadge', () => {
	it('is true when source differs from served', () => {
		expect(shouldShowTranslationBadge({ sourceLanguage: 'nl' }, 'en')).toBe(true)
	})

	it('is false when source equals served', () => {
		expect(shouldShowTranslationBadge({ sourceLanguage: 'nl' }, 'nl_NL')).toBe(false)
	})

	it('is false when the source language is unknown', () => {
		expect(shouldShowTranslationBadge({}, 'en')).toBe(false)
	})

	it('is false when the served language is unknown', () => {
		expect(shouldShowTranslationBadge({ sourceLanguage: 'nl' }, '')).toBe(false)
	})
})

describe('translationBadge.translationBadge', () => {
	it('returns null when no badge is warranted', () => {
		expect(translationBadge({ sourceLanguage: 'nl' }, 'nl')).toBeNull()
	})

	it('returns a descriptor with source language details for a translated object', () => {
		const badge = translationBadge({ sourceLanguage: 'nl' }, 'en')
		expect(badge).not.toBeNull()
		expect(badge.sourceLanguage).toBe('nl')
		expect(badge.sourceLanguageName).toBe('Dutch')
	})

	it('produces a fallback label without a translate function', () => {
		const badge = translationBadge({ sourceLanguage: 'nl' }, 'en')
		expect(badge.label()).toBe('(translated from Dutch)')
	})

	it('uses the provided translate function and interpolates the language', () => {
		const t = jest.fn((app, text, vars) => text.replace('{language}', vars.language))
		const badge = translationBadge({ sourceLanguage: 'nl' }, 'en')
		expect(badge.label(t)).toBe('(translated from Dutch)')
		expect(t).toHaveBeenCalledWith('softwarecatalog', '(translated from {language})', { language: 'Dutch' })
	})
})
