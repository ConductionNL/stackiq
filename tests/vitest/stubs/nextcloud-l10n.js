/**
 * SPDX-FileCopyrightText: 2026 Conduction / SoftwareCatalog Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest stub for `@nextcloud/l10n`.
 *
 * `orClient.resolveLanguage()` calls `getLanguage()` to discover the active
 * user's locale. The OFFLINE vitest suite has no Nextcloud runtime, so we
 * expose a settable language plus the `getLanguage` export the real package
 * provides.
 *
 * Tests mutate `__setLanguage('nl')` between cases; the stub returns the
 * current value from each `getLanguage()` call.
 *
 * `translate()` is the other export components pull in (`import { translate as
 * t }`). Without it `t` is `undefined` and any component computed that builds a
 * label throws a TypeError — which is NOT the failure mode under test, so the
 * stub returns the source string with `{placeholder}` substitution, exactly the
 * shape the real package produces for an untranslated (English) string.
 */

let currentLanguage = 'en'

export function getLanguage() {
	return currentLanguage
}

export function translate(app, text, vars) {
	if (!vars || typeof vars !== 'object') {
		return String(text)
	}
	return String(text).replace(/\{(\w+)\}/g, (match, key) =>
		Object.hasOwn(vars, key) ? String(vars[key]) : match,
	)
}

/**
 * `translatePlural()` for components that call `n()` directly: picks the
 * singular for a count of one, else the plural, fills `%n` with the count and
 * `{placeholder}`s from `vars`, as the real package does for an untranslated
 * (English) string.
 *
 * @param {string} app - the app id.
 * @param {string} singular - the singular source.
 * @param {string} plural - the plural source.
 * @param {number} count - the count.
 * @param {object} [vars] - placeholder values.
 * @return {string}
 */
export function translatePlural(app, singular, plural, count, vars) {
	const text = String(count === 1 ? singular : plural).replace(/%n/g, String(count))
	return translate(app, text, vars)
}

export function __setLanguage(lang) {
	currentLanguage = lang
}

export function __resetLanguage() {
	currentLanguage = 'en'
}

export default {
	getLanguage,
	translate,
	translatePlural,
}
