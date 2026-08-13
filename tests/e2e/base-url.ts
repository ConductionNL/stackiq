// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * The ONE place that decides which Nextcloud instance the e2e suite talks to.
 *
 * WHY THIS EXISTS
 * ---------------
 * Every spec used to compute its own
 * `process.env.BASE_URL ?? process.env.NEXTCLOUD_URL ?? 'http://localhost:8080'`.
 * That literal fallback is the SHARED dev container, which bind-mounts real host
 * checkouts — so an unconfigured run quietly pointed a suite that CREATES
 * organisations, contracts and SBOM imports at somebody else's environment, and
 * fired failed logins into it. Two apps in this programme were caught doing
 * exactly that.
 *
 * A second, subtler failure the central helper prevents: with the resolution
 * duplicated per file, absolute and relative navigation inside ONE spec could
 * disagree — a fixture created on the isolated instance and then opened on
 * :8080.
 *
 * THE ENV-VAR NAME IS NOT FREE CHOICE
 * -----------------------------------
 * ⚠️ The shared Conduction quality workflow exports the target as **`BASE_URL`**.
 * A resolver that accepts only `PLAYWRIGHT_BASE_URL` hard-fails every CI run —
 * that regression is live on another app in this fleet right now. So: strict
 * about having a value, liberal about which of the known names carries it.
 *
 * The workflow's Playwright step exports FOUR names for the same value —
 * `BASE_URL`, `NEXTCLOUD_URL`, `NC_BASE_URL` and (in the seed step) nothing at
 * all. `NC_BASE_URL` was missing from this list; it happens to be the last of
 * the three the workflow sets, so the omission was invisible while the other
 * two were present, and would have become a hard failure the moment they were
 * not. All four accepted names are listed here.
 *
 * There is deliberately NO default. An unset target must abort the run loudly
 * rather than silently choose a shared instance.
 */

const CANDIDATES = [
	'PLAYWRIGHT_BASE_URL',
	'BASE_URL',
	'NEXTCLOUD_URL',
	'NC_BASE_URL',
] as const

/**
 * Resolve the base URL of the Nextcloud instance under test.
 *
 * @throws If none of the accepted environment variables is set.
 * @return The base URL, without a trailing slash.
 */
export function resolveBaseUrl(): string {
	for (const name of CANDIDATES) {
		const value = process.env[name]
		if (value && value.trim() !== '') {
			return value.trim().replace(/\/+$/, '')
		}
	}

	throw new Error(
		'No Nextcloud target configured for the e2e suite. Set one of '
			+ CANDIDATES.join(', ')
			+ ' (e.g. PLAYWRIGHT_BASE_URL=http://localhost:8092). There is no default: '
			+ 'defaulting would point this suite at the shared dev container.',
	)
}

export const BASE_URL = resolveBaseUrl()

/**
 * The app's entry path, WITH the `/index.php` front controller.
 *
 * ⚠️ THE `/index.php` PREFIX IS NOT COSMETIC — it is the difference between
 * the suite running and 404-ing.
 *
 * The pretty form `/apps/softwarecatalog` only resolves where a rewrite rule
 * maps every unmatched path onto `index.php` — Apache + `.htaccess` in the
 * docker dev images. The CI runner serves Nextcloud with PHP's built-in
 * server (`php -S 0.0.0.0:8080`, no router script), which has no rewriting at
 * all: it resolves a request against the filesystem first, and
 * `server/apps/softwarecatalog/` IS a real directory with no `index.php`
 * inside it. So the request never reaches Nextcloud and the built-in server
 * answers with its OWN error page:
 *
 *     Not Found — The requested resource /apps/softwarecatalog was not found
 *     on this server.
 *
 * Observed on run 30797297831: 50 of 58 failures were this one cause. Every
 * one of them surfaced as `waiting for locator('.softwarecatalog-app-root')`
 * timing out after 30s — a message that accuses the app of not mounting.
 * The three specs that already used the `/index.php/...` form (the `smoke`
 * project and the admin-settings tests) passed in the same run, which is what
 * isolated it.
 *
 * `/index.php/apps/...` resolves on BOTH kinds of instance, so this is the
 * portable spelling, not a CI workaround. Testing that the rewrite rule is in
 * place is a webserver-config concern, not something these specs assert.
 */
export const APP_PATH = '/index.php/apps/softwarecatalog'
