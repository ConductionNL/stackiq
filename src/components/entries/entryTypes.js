/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * landscape-change-entry-type: the entry types and their allowed changes, as
 * EntryTypeService defines them (TRANSITIONS, MODULE_TYPE), plus the API calls
 * of the type-change routes.
 *
 * @spec openspec/changes/archive/2026-11-25-landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-002-the-user-sees-what-carries-over-before-confirming
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The changes each type may make (mirrors EntryTypeService::TRANSITIONS). */
export const TRANSITIONS = {
	application: ['service', 'systemSoftware'],
	service: ['application'],
	systemSoftware: ['application'],
}

/**
 * The entry type of a module from its `type` field.
 *
 * @param {object|null} module The module object.
 * @return {string} application or systemSoftware.
 */
export function moduleEntryType(module) {
	return module?.type === 'System software' ? 'systemSoftware' : 'application'
}

/**
 * The types an entry of this type may change into.
 *
 * @param {string} currentType The entry's type.
 * @return {Array<string>} The target types.
 */
export function targetsOf(currentType) {
	return TRANSITIONS[currentType] || []
}

/**
 * The dropped fields of a preview as rows with a readable value.
 *
 * @param {object} dropped The preview's dropped map (field → value).
 * @return {Array<{field: string, value: string}>} The rows.
 */
export function droppedRows(dropped) {
	return Object.entries(dropped || {}).map(([field, value]) => ({
		field,
		value: typeof value === 'string' ? value : JSON.stringify(value),
	}))
}

/**
 * The URL of an entry's type-change route.
 *
 * @param {string} uuid The entry uuid.
 * @return {string} The URL.
 */
function typeChangeUrl(uuid) {
	return generateUrl('/apps/stackiq/api/entries/{uuid}/type-change', { uuid })
}

/**
 * The preview of a type change: carried, dropped, blockers and service types.
 *
 * @param {string} uuid The entry uuid.
 * @param {string} target The target type.
 * @return {Promise<object>} The preview.
 */
export async function previewTypeChange(uuid, target) {
	const { data } = await axios.get(typeChangeUrl(uuid), { params: { target } })
	return data
}

/**
 * Change an entry's type.
 *
 * @param {string} uuid The entry uuid.
 * @param {string} target The target type.
 * @param {Array<string>} serviceTypes The service types, when the target is a service.
 * @return {Promise<object>} The outcome ({uuid, targetType, moved, errors}).
 */
export async function changeType(uuid, target, serviceTypes = []) {
	const { data } = await axios.post(typeChangeUrl(uuid), { target, serviceTypes })
	return data
}
