/**
 * translationBadge — compute the "(translated from X)" badge for list rows.
 *
 * OpenRegister serves translatable object properties in the language the
 * frontend requests via `?_lang=` (see {@link module:composables/orClient}).
 * Each object carries `sourceLanguage` metadata describing the language its
 * content was authored in (ADR-025 source-of-truth). When the served language
 * differs from the source language, the user is looking at a (machine or
 * human) translation and the UI MUST surface that with a small badge.
 *
 * This module is pure and framework-light so it can be unit-tested and reused
 * across every index view (Apps, Components, Organisations, Catalogs).
 *
 * @module utils/translationBadge
 * @author Ruben Linde
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * @spec openspec/changes/softwarecatalog-adopt-or-abstractions/tasks.md#3.5
 */

/**
 * Map of language codes to their human-readable English display names.
 * Kept intentionally small — covers the languages Stackiq content is
 * authored in. Unknown codes fall back to the upper-cased code itself.
 *
 * @type {{[key: string]: string}}
 */
const LANGUAGE_NAMES = {
	nl: 'Dutch',
	en: 'English',
	de: 'German',
	fr: 'French',
}

/**
 * Normalise a language value to a bare, lower-case language code.
 *
 * @param {string} value A language value (`en`, `en_GB`, `NL-nl`, …).
 * @return {string} The normalised code, or '' when not resolvable.
 */
function normaliseLang(value) {
	if (!value || typeof value !== 'string') return ''
	return value.split(/[_-]/)[0].toLowerCase()
}

/**
 * Resolve the human-readable name for a language code.
 *
 * @param {string} code A language code.
 * @return {string} The display name (e.g. `Dutch`) or the upper-cased code.
 */
export function languageName(code) {
	const norm = normaliseLang(code)
	return LANGUAGE_NAMES[norm] || (norm ? norm.toUpperCase() : '')
}

/**
 * Read the `sourceLanguage` metadata off an OpenRegister object.
 *
 * Tolerates both the top-level `sourceLanguage` property and the `@self`
 * metadata envelope OpenRegister returns.
 *
 * @param {object} object An OpenRegister object.
 * @return {string} The normalised source language code, or '' when absent.
 */
export function getSourceLanguage(object) {
	if (!object || typeof object !== 'object') return ''
	const raw =
		object.sourceLanguage
		?? object['@self']?.sourceLanguage
		?? object['@self']?.source_language
		?? ''
	return normaliseLang(raw)
}

/**
 * Decide whether a translated-from badge should be shown for an object.
 *
 * The badge appears only when BOTH a source language is known AND it differs
 * from the language currently being served. When the source language is
 * unknown (legacy objects) no badge is shown — we never guess.
 *
 * @param {object} object        An OpenRegister object.
 * @param {string} servedLang    The language currently served (e.g. `en`).
 * @return {boolean} True when a badge should be rendered.
 */
export function shouldShowTranslationBadge(object, servedLang) {
	const source = getSourceLanguage(object)
	const served = normaliseLang(servedLang)
	if (!source || !served) return false
	return source !== served
}

/**
 * Build the translation-badge descriptor for an object.
 *
 * Returns `null` when no badge is warranted. Otherwise returns an object with
 * the source language code, its display name, and a `translate` helper that
 * the caller passes a Nextcloud `t()` function to produce the localised label
 * (keeps this module free of a hard `@nextcloud/l10n` dependency for testing).
 *
 * @param {object} object     An OpenRegister object.
 * @param {string} servedLang The language currently served.
 * @return {?{sourceLanguage: string, sourceLanguageName: string, label: Function}}
 *
 * @spec openspec/specs/softwarecatalog-adopt-or-abstractions/spec.md
 */
export function translationBadge(object, servedLang) {
	if (!shouldShowTranslationBadge(object, servedLang)) return null

	const sourceLanguage = getSourceLanguage(object)
	const sourceLanguageName = languageName(sourceLanguage)

	return {
		sourceLanguage,
		sourceLanguageName,
		/**
		 * Produce the localised badge label.
		 *
		 * @param {function(string, object=): string} t Nextcloud translate fn.
		 * @return {string} The localised "(translated from X)" label.
		 *
		 * @spec openspec/specs/softwarecatalog-adopt-or-abstractions/spec.md
		 */
		label(t) {
			if (typeof t === 'function') {
				return t('stackiq', '(translated from {language})', {
					language: sourceLanguageName,
				})
			}
			return `(translated from ${sourceLanguageName})`
		},
	}
}
