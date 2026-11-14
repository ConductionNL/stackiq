// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * Publish and withdraw catalogue entries through stackiq's guarded
 * publication endpoint (`lib/Controller/PublicationController.php`):
 * `PUT /api/publication/{objectType}/{uuid}/publish` and
 * `DELETE /api/publication/{objectType}/{uuid}/depublish`.
 *
 * One request per entry, so the endpoint's ownership guard runs for each one,
 * and every run reports which entries succeeded and which were refused with
 * the endpoint's own message (REQ-ODP-010).
 *
 * @module services/publicationActions
 * @spec openspec/changes/publish-and-lock-from-the-list/tasks.md#task-1
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** Object types the publication endpoint accepts (PublicationService::PUBLISHABLE_TYPES). */
export const PUBLISHABLE_TYPES = Object.freeze([
	'catalogService',
	'module',
	'connection',
	'organization',
])

/**
 * The object type the open mass publish / withdraw dialog acts on. Set by the
 * selection bar of the list that opened it; the dialogs read it when they run.
 */
let target = { objectType: '' }

/**
 * Name the object type the next mass publish / withdraw acts on.
 *
 * @param {{objectType: string}} next The list's object type.
 * @return {void}
 * @spec openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-selected-entries-are-published-withdrawn-or-locked-together-from-the-list-req-odp-010
 */
export function setPublicationTarget(next) {
	target = { objectType: String((next && next.objectType) || '') }
}

/**
 * The object type the mass publish / withdraw dialogs act on.
 *
 * @return {{objectType: string}} The target.
 * @spec openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-selected-entries-are-published-withdrawn-or-locked-together-from-the-list-req-odp-010
 */
export function publicationTarget() {
	return { ...target }
}

/**
 * Whether the publication endpoint accepts an object type.
 *
 * @param {string} objectType The catalogue object type (schema slug).
 * @return {boolean}
 * @spec openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-an-entrys-page-publishes-or-withdraws-that-entry-req-odp-011
 */
export function isPublishableType(objectType) {
	return PUBLISHABLE_TYPES.includes(objectType)
}

/**
 * The uuid of a catalogue entry: its top-level `id`, else `@self.id`.
 *
 * @param {object} entry A catalogue object.
 * @return {string} The uuid, or ''.
 */
export function entryId(entry) {
	if (!entry || typeof entry !== 'object') {
		return ''
	}
	return String(entry.id || (entry['@self'] && entry['@self'].id) || '')
}

/**
 * The endpoint URL for one entry.
 *
 * @param {string} objectType The catalogue object type.
 * @param {string} uuid The entry uuid.
 * @param {'publish'|'depublish'} action The endpoint action.
 * @return {string} The URL.
 */
function endpointUrl(objectType, uuid, action) {
	return generateUrl(
		`/apps/stackiq/api/publication/${encodeURIComponent(objectType)}/${encodeURIComponent(uuid)}/${action}`,
	)
}

/**
 * The message the endpoint gave for a refusal, else the error's own message.
 *
 * @param {object} error The request error.
 * @return {string} The message to show.
 * @spec openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-selected-entries-are-published-withdrawn-or-locked-together-from-the-list-req-odp-010
 */
export function refusalMessage(error) {
	const data = error && error.response && error.response.data
	if (data && typeof data.message === 'string' && data.message !== '') {
		return data.message
	}
	return (error && error.message) || 'Unknown error'
}

/**
 * Publish one entry: now, or at `when` (an ISO-8601 moment).
 *
 * @param {string} objectType The catalogue object type.
 * @param {string} uuid The entry uuid.
 * @param {object} [options] Options.
 * @param {?string} [options.when] The publication moment; now when empty.
 * @param {object} [options.client] An axios-like client (tests).
 * @return {Promise<object>} The endpoint's result (`publicationDate`).
 * @spec openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-an-entrys-page-publishes-or-withdraws-that-entry-req-odp-011
 */
export async function publishEntry(objectType, uuid, options = {}) {
	const client = options.client || axios
	const body = options.when ? { when: options.when } : {}
	const response = await client.put(
		endpointUrl(objectType, uuid, 'publish'),
		body,
	)
	return (response && response.data) || {}
}

/**
 * Withdraw one entry (set its depublication date to now).
 *
 * @param {string} objectType The catalogue object type.
 * @param {string} uuid The entry uuid.
 * @param {object} [options] Options.
 * @param {object} [options.client] An axios-like client (tests).
 * @return {Promise<object>} The endpoint's result.
 * @spec openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-an-entrys-page-publishes-or-withdraws-that-entry-req-odp-011
 */
export async function withdrawEntry(objectType, uuid, options = {}) {
	const client = options.client || axios
	const response = await client.delete(
		endpointUrl(objectType, uuid, 'depublish'),
	)
	return (response && response.data) || {}
}

/**
 * Publish or withdraw each entry, one request per entry, in order.
 *
 * @param {'publish'|'withdraw'} kind What to do.
 * @param {string} objectType The catalogue object type.
 * @param {Array<object>} entries The selected entries.
 * @param {object} [options] Options passed to publishEntry / withdrawEntry.
 * @return {Promise<{successful: Array<object>, failed: Array<object>}>} The
 *   entries that succeeded, and the refused ones each with a `message`.
 * @spec openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-selected-entries-are-published-withdrawn-or-locked-together-from-the-list-req-odp-010
 */
export async function runPublication(kind, objectType, entries, options = {}) {
	const successful = []
	const failed = []
	const run = kind === 'withdraw' ? withdrawEntry : publishEntry
	for (const entry of entries || []) {
		const uuid = entryId(entry)
		if (!isPublishableType(objectType) || uuid === '') {
			failed.push({ entry, message: 'This entry cannot be published.' })
			continue
		}
		try {
			await run(objectType, uuid, options)
			successful.push(entry)
		} catch (error) {
			failed.push({ entry, message: refusalMessage(error) })
		}
	}
	return { successful, failed }
}

/**
 * An entry's publication state from its dates: `published` when its
 * publication date has passed and no depublication date has, `scheduled`
 * when the publication date is still ahead, else `unpublished`.
 *
 * @param {object} entry A catalogue object (`publicationDate`, `depublicationDate`).
 * @param {Date} [now] The moment to judge at.
 * @return {'published'|'scheduled'|'unpublished'} The state.
 * @spec openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-an-entrys-page-publishes-or-withdraws-that-entry-req-odp-011
 */
export function publicationState(entry, now = new Date()) {
	const published = Date.parse((entry && entry.publicationDate) || '')
	const withdrawn = Date.parse((entry && entry.depublicationDate) || '')
	if (Number.isNaN(published)) {
		return 'unpublished'
	}
	if (!Number.isNaN(withdrawn) && withdrawn <= now.getTime()) {
		return 'unpublished'
	}
	return published <= now.getTime() ? 'published' : 'scheduled'
}

/**
 * The selected objects of a list in selection order: the loaded object for
 * each selected id, or a bare `{ id }` when the list does not hold it.
 *
 * @param {Array<string>} ids The selected ids.
 * @param {Array<object>} objects The objects the list holds.
 * @return {Array<object>} The selected objects.
 * @spec openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-selected-entries-are-published-withdrawn-or-locked-together-from-the-list-req-odp-010
 */
export function selectedEntries(ids, objects) {
	const byId = new Map((objects || []).map((o) => [entryId(o), o]))
	return (ids || []).map((id) => byId.get(String(id)) || { id: String(id) })
}
