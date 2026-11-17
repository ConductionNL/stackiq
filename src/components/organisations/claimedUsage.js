/**
 * The usages other organisations claim of a supplier's products, and the
 * supplier's answer (supplier-accepts-claimed-usage).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The claims of the active organisation's products, or null when they are
 * not this page's organisation's: only its members see them.
 *
 * @param {string} organisationId The organisation whose page is open.
 * @return {Promise<Array<object>|null>}
 *
 * @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
 */
export async function fetchClaims(organisationId) {
	try {
		const { data } = await axios.get(generateUrl('/apps/stackiq/api/aanbod/claimed'))
		if (!organisationId || data?.organisation !== organisationId) {
			return null
		}
		return Array.isArray(data.results) ? data.results : []
	} catch {
		return null
	}
}

/**
 * Accept or decline a claim.
 *
 * @param {string} uuid The usage.
 * @param {'accept'|'decline'} action The answer.
 * @return {Promise<void>}
 *
 * @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
 */
export async function answerClaim(uuid, action) {
	if (action !== 'accept' && action !== 'decline') {
		throw new Error(`Unknown answer ${action}`)
	}
	await axios.put(generateUrl(`/apps/stackiq/api/aanbod/claimed/${encodeURIComponent(uuid)}/${action}`))
}

/**
 * A claim date as a calendar date, or '' when there is none.
 *
 * @param {string} iso The ISO date-time.
 * @return {string}
 *
 * @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
 */
export function claimDate(iso) {
	return typeof iso === 'string' && /^\d{4}-\d{2}-\d{2}/.test(iso) ? iso.slice(0, 10) : ''
}
