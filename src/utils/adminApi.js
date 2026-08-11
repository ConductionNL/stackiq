/**
 * Thin fetch helpers for the admin-gated SoftwareCatalog REST endpoints
 * (federation settings + moderation queue).
 *
 * Extracted from the settings section components so the request/response
 * contract — URL shape, JSON body/headers, ok/!ok branching, error-message
 * extraction — is unit-testable offline (vitest) without a Vue/DOM runtime.
 * The admin authorization itself is enforced server-side by
 * `#[AuthorizedAdminSetting(SoftwareCatalogAdmin::class)]`; these helpers add
 * no client-side gate (a client gate would be security theatre).
 *
 * @spec openspec/specs/federated-catalog-sync/spec.md
 * @spec openspec/specs/open-data-publishing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { getRequestToken } from '@nextcloud/auth'

const API_BASE = '/index.php/apps/softwarecatalog/api'

/**
 * Build the absolute API URL for a relative path.
 *
 * @param {string} path - Path under the app API root (leading slash optional).
 * @return {string} The absolute API URL.
 * @spec openspec/specs/federated-catalog-sync/spec.md
 */
export function apiUrl(path) {
	const clean = String(path || '').replace(/^\/+/, '')
	return `${API_BASE}/${clean}`
}

/**
 * Perform a JSON request against an app API endpoint and return the parsed
 * body. Throws an Error carrying the server's `message` (when present) on a
 * non-2xx response, so callers can surface a meaningful toast.
 *
 * @param {string} path             - Path under the app API root.
 * @param {object} [options]        - Options.
 * @param {string} [options.method] - HTTP method (default GET).
 * @param {object} [options.body]   - JSON body (serialised when present).
 * @param {Function} [fetchImpl]    - Injected fetch (defaults to global fetch; for tests).
 * @return {Promise<object>} The parsed JSON response body.
 * @spec openspec/specs/federated-catalog-sync/spec.md
 * @spec openspec/specs/open-data-publishing/spec.md
 */
export async function apiRequest(path, options = {}, fetchImpl = undefined) {
	const doFetch = fetchImpl || (typeof fetch !== 'undefined' ? fetch : null)
	if (doFetch === null) {
		throw new Error('No fetch implementation available')
	}

	// ⚠️ `requesttoken` is NOT optional on a state-changing request.
	//
	// Nextcloud's CSRF middleware rejects any cookie-authenticated request
	// whose method is not GET/HEAD unless the controller declares
	// `#[NoCSRFRequired]` OR the request carries the session's request token.
	// `X-Requested-With: XMLHttpRequest` does NOT satisfy it — that header was
	// dropped as a CSRF signal long ago.
	//
	// Without this header EVERY write that goes through this helper failed
	// with a rendered "CSRF check failed" alert and no server-side effect:
	//   SubmitReviewModal      POST reviews
	//   ModerationQueue        POST moderation/{uuid}/approve|reject
	//   FederationSettings     POST/DELETE federation/peers, POST federation/pull
	//   EolSyncSettings        POST eol-sync/config, POST eol-sync/trigger
	// None of those controllers is `#[NoCSRFRequired]`, so none of them was
	// reachable from the UI. Measured, not inferred: a Playwright run driving
	// the real "Write a review" modal captured the alert text `CSRF check
	// failed` in the dialog, and the same flow passes once this header is sent.
	//
	// Reads are unaffected (GET is exempt), which is why the settings sections
	// rendered correctly and only their WRITE actions were dead — a failure
	// mode that looks like "the button does nothing".
	//
	// `getRequestToken()` reads the token @nextcloud/auth keeps in sync with
	// the `data-requesttoken` head meta, so it stays correct across NC's
	// token rotation. This is the same source `@nextcloud/axios` uses; the
	// sibling store `src/store/modules/facets.js` already goes through axios
	// and was never affected.
	const init = {
		method: options.method || 'GET',
		headers: {
			'Content-Type': 'application/json',
			'X-Requested-With': 'XMLHttpRequest',
			requesttoken: getRequestToken() ?? '',
		},
	}
	if (options.body !== undefined && options.body !== null) {
		init.body = JSON.stringify(options.body)
	}

	const response = await doFetch(apiUrl(path), init)
	let data = {}
	try {
		data = await response.json()
	} catch (e) {
		data = {}
	}

	if (!response.ok) {
		const message = (data && data.message) ? data.message : `HTTP ${response.status}`
		throw new Error(message)
	}

	return data
}

/**
 * Normalise a federation status payload to a stable shape the UI renders,
 * tolerating older `peers: string[]` payloads as well as the enriched
 * `peers: {url, failures, stale, allowed}[]` shape.
 *
 * @param {object} status - The raw `/api/federation/status` response.
 * @return {{available: boolean, enabled: boolean, directoryUrl: string, staleAfter: number, peers: Array<{url: string, failures: number, stale: boolean, allowed: boolean}>, message: string}} Normalised status.
 * @spec openspec/specs/federated-catalog-sync/spec.md
 */
export function normaliseFederationStatus(status) {
	const raw = status || {}
	const peers = Array.isArray(raw.peers) ? raw.peers : []
	return {
		available: raw.available === true,
		enabled: raw.enabled === true,
		directoryUrl: raw.directoryUrl || '',
		staleAfter: typeof raw.staleAfter === 'number' ? raw.staleAfter : 3,
		message: raw.message || '',
		peers: peers.map((peer) => {
			if (typeof peer === 'string') {
				return { url: peer, failures: 0, stale: false, allowed: true }
			}
			return {
				url: peer.url || '',
				failures: typeof peer.failures === 'number' ? peer.failures : 0,
				stale: peer.stale === true,
				allowed: peer.allowed !== false,
			}
		}),
	}
}
