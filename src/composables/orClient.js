/**
 * orClient — centralised OpenRegister object-API client for SoftwareCatalog.
 *
 * Provides a single place where the OpenRegister object URL is built and the
 * fleet-wide i18n conventions (ADR-025) are applied:
 *
 *  - Every GET (fetch) appends `?_lang={language}` so OpenRegister can serve
 *    the user's preferred language variant of translatable properties.
 *  - Every write (POST/PUT/PATCH) may carry an
 *    `X-Translation-Target-Language` header when the caller is editing a
 *    specific (non-default) language variant, so OpenRegister's
 *    source-of-truth tracking knows which language slot to write.
 *  - Writes may carry `X-OpenRegister-Organisation` when a tenant is active
 *    (multi-tenancy support; the tenant value is supplied by the caller and is
 *    only stamped when non-null, matching the gated nc-vue `useTenantContext`
 *    contract).
 *
 * The helpers are intentionally framework-light: they return URL strings and
 * header objects so the existing Pinia plugin can keep using `fetch` /
 * `buildHeaders` exactly as before. This keeps the migration additive and
 * avoids changing the store's transport.
 *
 * @module composables/orClient
 * @author Ruben Linde
 * @copyright 2026 Conduction B.V.
 * @license AGPL-3.0-or-later
 *
 * @spec openspec/changes/softwarecatalog-adopt-or-abstractions/tasks.md#3.1
 */

import { getLanguage } from '@nextcloud/l10n'

/**
 * Base path for the OpenRegister object API.
 *
 * @type {string}
 */
export const OR_API_BASE = '/index.php/apps/openregister/api'

/**
 * Resolve the active user's language code (BCP-47 region tag stripped).
 *
 * `@nextcloud/l10n`'s `getLanguage()` already returns the short language
 * code (e.g. `en`, `nl`) for most Nextcloud locales, but some locales are
 * returned in `xx_YY` form. We defensively strip any region suffix so the
 * `_lang` query parameter is always a bare language code.
 *
 * @return {string} The two/three-letter language code (e.g. `nl`, `en`).
 */
export function resolveLanguage() {
	let lang = ''
	try {
		lang = getLanguage() || ''
	} catch (e) {
		lang = ''
	}

	// Fall back to the global OC locale if l10n returned nothing.
	if (!lang && typeof OC !== 'undefined' && typeof OC.getLocale === 'function') {
		lang = OC.getLocale() || ''
	}

	// Strip a region tag: `en_GB` -> `en`, `nl-NL` -> `nl`.
	lang = lang.split(/[_-]/)[0].toLowerCase()

	return lang || 'en'
}

/**
 * Append (or merge) the `_lang` query parameter onto an OpenRegister URL.
 *
 * Preserves any query string already present on the URL and never overwrites
 * an explicit `_lang` the caller may have set.
 *
 * @param {string} url  The URL to decorate.
 * @param {string} [lang] Explicit language; defaults to {@link resolveLanguage}.
 * @return {string} The URL with a `_lang` query parameter.
 */
export function withLanguageParam(url, lang = resolveLanguage()) {
	if (!url) return url
	if (/[?&]_lang=/.test(url)) return url

	const separator = url.includes('?') ? '&' : '?'
	return `${url}${separator}_lang=${encodeURIComponent(lang)}`
}

/**
 * Build the request headers for an OpenRegister write that participates in
 * i18n source-of-truth / multi-tenancy conventions.
 *
 * The caller passes the base headers (typically the result of nc-vue's
 * `buildHeaders()`); this helper layers the optional i18n / tenant headers on
 * top. Both are only added when their value is non-null/non-empty so that the
 * single-tenant, default-language path is byte-for-byte unchanged.
 *
 * @param {object} baseHeaders                Starting headers object.
 * @param {object} [options]                  Stamping options.
 * @param {string|null} [options.targetLang]  Target translation language.
 * @param {string|null} [options.organisation] Active tenant organisation UUID.
 * @return {object} A new headers object.
 */
export function buildWriteHeaders(baseHeaders = {}, { targetLang = null, organisation = null } = {}) {
	const headers = { ...baseHeaders }

	if (targetLang) {
		headers['X-Translation-Target-Language'] = targetLang
	}

	if (organisation) {
		headers['X-OpenRegister-Organisation'] = organisation
	}

	return headers
}

/**
 * Build a fully-qualified OpenRegister object URL.
 *
 * @param {object} params               URL parameters.
 * @param {string|number} params.register Register ID.
 * @param {string|number} params.schema   Schema ID.
 * @param {string} [params.uuid]          Object UUID/ID. Omit for collection ops.
 * @param {string} [params.action]        Optional action sub-path (publish, lock…).
 * @param {boolean} [params.withLang]     Whether to append `_lang`. Defaults true.
 * @return {string} The constructed URL.
 */
export function buildObjectUrl({ register, schema, uuid = null, action = null, withLang = true }) {
	if (register === undefined || register === null || schema === undefined || schema === null) {
		throw new Error('register and schema are required to build an OpenRegister URL')
	}

	let url = `${OR_API_BASE}/objects/${register}/${schema}`
	if (uuid) {
		url += `/${uuid}`
	}
	if (action) {
		url += action === 'logs' ? '/audit-trails' : `/${action}`
	}

	return withLang ? withLanguageParam(url) : url
}
