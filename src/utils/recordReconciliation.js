/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Record reconciliation in the catalogue: OpenRegister finds and merges
 * duplicates; stackiq leads admins there, leaves merged records out of its
 * lists and points a merged record's page to the survivor.
 *
 * @spec openspec/specs/record-reconciliation/spec.md
 */
import { refId } from './maintenance.js'

/** OpenRegister's duplicate candidates page, relative to the Nextcloud root. */
export const DUPLICATE_CANDIDATES_PATH = '/apps/openregister/duplicates'

/**
 * Whether the signed-in user may open the duplicate candidates: a Nextcloud
 * admin or a functional administrator (display gate; OpenRegister checks rights on merge).
 *
 * @param {{isAdmin: boolean, isFunctionalAdmin: boolean}|null} roles The roles from the settings store.
 * @return {boolean} True when the action shows.
 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-002-the-catalogue-pages-shall-lead-an-administrator-to-openregisters-duplicate-candidates
 */
export function canFindDuplicates(roles) {
	return Boolean(roles?.isAdmin || roles?.isFunctionalAdmin)
}

/**
 * The list filter with merged records left out.
 *
 * @param {object} filter The filter the page already applies.
 * @return {object} The filter plus recordStatus not Merged.
 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-004-merged-applications-and-services-shall-leave-the-lists-and-point-readers-to-the-survivor
 */
export function activeRecordsFilter(filter) {
	return { ...(filter || {}), 'recordStatus[ne]': 'Merged' }
}

/**
 * The record a merged record was merged into.
 *
 * @param {object|null} object The record.
 * @return {string|null} The survivor's id, or null when the record is not merged.
 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-004-merged-applications-and-services-shall-leave-the-lists-and-point-readers-to-the-survivor
 */
export function mergedIntoId(object) {
	if (object?.recordStatus !== 'Merged') {
		return null
	}
	return refId(object.mergedInto) || null
}
