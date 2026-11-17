/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Merged catalogue records: whether a record was merged into another, and
 * where OpenRegister lists the duplicate candidates.
 *
 * @module utils/mergedRecord
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * @spec openspec/changes/operations-record-reconciliation
 */

/** The `recordStatus` of a record merged into another. */
export const MERGED_STATUS = 'Merged'

/**
 * The uuid of the record a merged record was merged into, or null when the
 * record is not merged or names no survivor.
 *
 * @param {?object} record The record.
 * @return {?string} The survivor's uuid.
 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-004-merged-applications-and-services-shall-leave-the-lists-and-point-readers-to-the-survivor
 */
export function survivorOf(record) {
	if (!record || record.recordStatus !== MERGED_STATUS) {
		return null
	}
	const survivor = record.mergedInto
	if (survivor && typeof survivor === 'object') {
		return survivor.id || survivor.uuid || null
	}
	return typeof survivor === 'string' && survivor !== '' ? survivor : null
}

/**
 * OpenRegister's Duplicate candidates page.
 *
 * @param {Function} generateUrl `@nextcloud/router`'s generateUrl.
 * @return {string} The URL.
 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-002-the-catalogue-pages-shall-lead-an-administrator-to-openregisters-duplicate-candidates
 */
export function duplicateCandidatesUrl(generateUrl) {
	return generateUrl('/apps/openregister/duplicates')
}
