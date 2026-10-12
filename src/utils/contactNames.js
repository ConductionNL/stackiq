// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Display names of contact persons, for the owner column of the usage list.
 *
 * A contact person in OpenRegister carries only a `contactsUid`; its name is
 * in Nextcloud Contacts. `GET /api/contact-names` gives the names the
 * signed-in user can read in their own address books. The cells of one page
 * ask in the same tick, so they are batched into one request, and a name is
 * asked for once per page load.
 *
 * @spec openspec/specs/application-usage-pages/spec.md#requirement-req-uap-003-a-usage-names-a-business-owner-and-a-technical-owner
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The endpoint's own limit (`ContactNamesController::MAX_IDS`). */
export const MAX_IDS_PER_REQUEST = 100

/** uuid -> Promise<string|null>; null when no name could be read. */
const cache = new Map()

/** uuid -> { resolve } for the ids of the batch being collected. */
let pending = new Map()

/**
 * The contact person uuid a cell value refers to.
 *
 * @param {string|object|null} value A uuid, or a related object with `id` or `uuid`.
 * @return {string} The uuid, or ''.
 */
export function contactPersonId(value) {
	if (typeof value === 'string') {
		return value.trim()
	}
	if (value && typeof value === 'object') {
		return String(value.id ?? value.uuid ?? '').trim()
	}
	return ''
}

/**
 * Ask the endpoint for the names of the collected ids, in chunks it accepts.
 *
 * @return {Promise<void>}
 */
async function flush() {
	const batch = pending
	pending = new Map()
	const ids = [...batch.keys()]
	for (let start = 0; start < ids.length; start += MAX_IDS_PER_REQUEST) {
		const chunk = ids.slice(start, start + MAX_IDS_PER_REQUEST)
		let names = {}
		try {
			const { data } = await axios.get(
				generateUrl('/apps/stackiq/api/contact-names'),
				{ params: { ids: chunk } },
			)
			names = data?.names ?? {}
		} catch {
			names = {}
		}
		chunk.forEach((id) => {
			const name = typeof names[id] === 'string' ? names[id] : ''
			batch.get(id).resolve(name !== '' ? name : null)
		})
	}
}

/**
 * The display name of a contact person, or null when the signed-in user
 * cannot read one.
 *
 * @param {string} id The contact person uuid.
 * @return {Promise<string|null>} The name.
 */
export function contactName(id) {
	const key = String(id ?? '').toLowerCase()
	if (key === '') {
		return Promise.resolve(null)
	}
	if (!cache.has(key)) {
		cache.set(
			key,
			new Promise((resolve) => {
				if (pending.size === 0) {
					Promise.resolve().then(flush)
				}
				pending.set(key, { resolve })
			}),
		)
	}
	return cache.get(key)
}

/**
 * Forget every name read so far (tests).
 *
 * @return {void}
 */
export function clearContactNames() {
	cache.clear()
	pending = new Map()
}
