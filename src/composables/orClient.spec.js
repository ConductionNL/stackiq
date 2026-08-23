/**
 * Unit tests for the orClient composable (i18n + tenant URL/header helpers).
 *
 * @spec openspec/changes/softwarecatalog-adopt-or-abstractions/tasks.md#3.2
 */

import { getLanguage } from '@nextcloud/l10n'
import {
	buildObjectUrl,
	buildWriteHeaders,
	getActiveOrganisationUuid,
	OR_API_BASE,
	resolveLanguage,
	setActiveOrganisationUuid,
	withLanguageParam,
} from './orClient.js'

jest.mock('@nextcloud/l10n', () => ({
	getLanguage: jest.fn(() => 'nl_NL'),
}))

describe('orClient.resolveLanguage', () => {
	afterEach(() => {
		getLanguage.mockReset()
	})

	it('strips the region tag from an underscore locale', () => {
		getLanguage.mockReturnValue('en_GB')
		expect(resolveLanguage()).toBe('en')
	})

	it('strips the region tag from a hyphen locale', () => {
		getLanguage.mockReturnValue('nl-NL')
		expect(resolveLanguage()).toBe('nl')
	})

	it('lower-cases the language code', () => {
		getLanguage.mockReturnValue('NL')
		expect(resolveLanguage()).toBe('nl')
	})

	it('falls back to en when no locale is resolvable', () => {
		getLanguage.mockReturnValue('')
		expect(resolveLanguage()).toBe('en')
	})
})

describe('orClient.withLanguageParam', () => {
	it('appends _lang with a ? when no query string present', () => {
		expect(withLanguageParam('/objects/7/21/x', 'en')).toBe(
			'/objects/7/21/x?_lang=en',
		)
	})

	it('appends _lang with an & when a query string is present', () => {
		expect(withLanguageParam('/objects/7/21/x?_limit=20', 'nl')).toBe(
			'/objects/7/21/x?_limit=20&_lang=nl',
		)
	})

	it('does not duplicate an existing _lang param', () => {
		expect(withLanguageParam('/objects/7/21/x?_lang=de', 'en')).toBe(
			'/objects/7/21/x?_lang=de',
		)
	})
})

describe('orClient.buildWriteHeaders', () => {
	it('returns the base headers unchanged when no options given', () => {
		expect(buildWriteHeaders({ 'Content-Type': 'application/json' })).toEqual({
			'Content-Type': 'application/json',
		})
	})

	it('stamps X-Translation-Target-Language when targetLang is set', () => {
		const headers = buildWriteHeaders({}, { targetLang: 'en' })
		expect(headers['X-Translation-Target-Language']).toBe('en')
	})

	it('does NOT stamp the translation header when targetLang is null', () => {
		const headers = buildWriteHeaders({}, { targetLang: null })
		expect(headers).not.toHaveProperty('X-Translation-Target-Language')
	})

	it('stamps X-OpenRegister-Organisation when organisation is set', () => {
		const headers = buildWriteHeaders({}, { organisation: 'tenant-b-uuid' })
		expect(headers['X-OpenRegister-Organisation']).toBe('tenant-b-uuid')
	})

	it('does NOT stamp the organisation header when organisation is null', () => {
		const headers = buildWriteHeaders({}, { organisation: null })
		expect(headers).not.toHaveProperty('X-OpenRegister-Organisation')
	})

	it('does not mutate the base headers object', () => {
		const base = {}
		buildWriteHeaders(base, { targetLang: 'en' })
		expect(base).toEqual({})
	})
})

describe('orClient.buildObjectUrl', () => {
	beforeEach(() => {
		getLanguage.mockReturnValue('en_GB')
	})

	it('builds a language-stamped object URL by default', () => {
		expect(buildObjectUrl({ register: 7, schema: 21, uuid: 'xyz' })).toBe(
			`${OR_API_BASE}/objects/7/21/xyz?_lang=en`,
		)
	})

	it('omits _lang when withLang is false', () => {
		expect(
			buildObjectUrl({
				register: 7,
				schema: 21,
				uuid: 'xyz',
				withLang: false,
			}),
		).toBe(`${OR_API_BASE}/objects/7/21/xyz`)
	})

	it('appends an action sub-path', () => {
		expect(
			buildObjectUrl({
				register: 7,
				schema: 21,
				uuid: 'xyz',
				action: 'publish',
				withLang: false,
			}),
		).toBe(`${OR_API_BASE}/objects/7/21/xyz/publish`)
	})

	it('maps the logs action to audit-trails', () => {
		expect(
			buildObjectUrl({
				register: 7,
				schema: 21,
				uuid: 'xyz',
				action: 'logs',
				withLang: false,
			}),
		).toBe(`${OR_API_BASE}/objects/7/21/xyz/audit-trails`)
	})

	it('throws when register or schema is missing', () => {
		expect(() => buildObjectUrl({ schema: 21, uuid: 'x' })).toThrow()
		expect(() => buildObjectUrl({ register: 7, uuid: 'x' })).toThrow()
	})
})

describe('orClient.setActiveOrganisationUuid / getActiveOrganisationUuid (multi-org-membership)', () => {
	afterEach(() => {
		setActiveOrganisationUuid(null)
	})

	it('starts unset', () => {
		expect(getActiveOrganisationUuid()).toBeNull()
	})

	it('stores and returns the active organisation uuid', () => {
		setActiveOrganisationUuid('org-a')
		expect(getActiveOrganisationUuid()).toBe('org-a')
	})

	it('normalises an empty string to null', () => {
		setActiveOrganisationUuid('org-a')
		setActiveOrganisationUuid('')
		expect(getActiveOrganisationUuid()).toBeNull()
	})

	it('normalises a non-string value to null', () => {
		setActiveOrganisationUuid(42)
		expect(getActiveOrganisationUuid()).toBeNull()
	})

	it('feeds buildWriteHeaders so a write stamps the active organisation', () => {
		setActiveOrganisationUuid('org-b')
		const headers = buildWriteHeaders(
			{},
			{ organisation: getActiveOrganisationUuid() },
		)
		expect(headers).toHaveProperty('X-OpenRegister-Organisation', 'org-b')
	})
})
