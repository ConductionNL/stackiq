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
 */

let currentLanguage = 'en'

export function getLanguage() {
	return currentLanguage
}

export function __setLanguage(lang) {
	currentLanguage = lang
}

export function __resetLanguage() {
	currentLanguage = 'en'
}

export default {
	getLanguage,
}
