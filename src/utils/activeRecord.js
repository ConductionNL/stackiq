/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The record a detail panel inspects: the object store's active object of a
 * type, a collection's results, and the record with a given UUID.
 *
 * @spec openspec/specs/sbom-import/spec.md
 * @spec openspec/specs/module-vulnerability-tracking/spec.md
 */

import { recordUuid } from './lifecyclePhase.js'

/**
 * The store's active object of a type, or null when the store has none.
 *
 * @param {object} store The object store.
 * @param {string} type The object type slug.
 * @return {object|null} The active object.
 */
export function activeObjectOf(store, type) {
	return typeof store.getActiveObject === 'function'
		? store.getActiveObject(type)
		: null
}

/**
 * The results of a collection in the store, or none.
 *
 * @param {object} store The object store.
 * @param {string} type The object type slug.
 * @return {Array<object>} The records.
 */
export function collectionResults(store, type) {
	return store.getCollection(type)?.results || []
}

/**
 * The first record with a UUID.
 *
 * @param {Array<object>} records The records.
 * @param {string} uuid The UUID.
 * @return {object|undefined} The record.
 */
export function findByUuid(records, uuid) {
	return records.find((record) => recordUuid(record) === uuid)
}
