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
 * There is deliberately NO default. An unset target must abort the run loudly
 * rather than silently choose a shared instance.
 */

const CANDIDATES = ['PLAYWRIGHT_BASE_URL', 'BASE_URL', 'NEXTCLOUD_URL'] as const

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
