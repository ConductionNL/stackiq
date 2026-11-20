// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * With whom each connection is shared, read once per page load from
 * `/api/gebruik/shared-connections`: for every connection of a shared usage
 * the signed-in organisation owns or takes part in, the other organisations
 * of those usages (shared-usage-visible-to-participants REQ-DLG-011).
 *
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

let pending = null

/**
 * The shared connections, keyed by connection uuid; null when the lookup is
 * refused or fails (the column then shows nothing rather than "nobody").
 *
 * @return {Promise<Record<string, {sharedWith: string[], usages: string[]}>|null>} The map.
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
 */
export function loadSharedConnections() {
	if (pending === null) {
		pending = axios.get(generateUrl('/apps/stackiq/api/gebruik/shared-connections'))
			.then((response) => {
				const connections = response?.data?.connections
				return connections && typeof connections === 'object' && !Array.isArray(connections) ? connections : {}
			})
			.catch(() => {
				pending = null
				return null
			})
	}
	return pending
}

/**
 * The organisations a connection is shared with, from a loaded map.
 *
 * @param {Record<string, {sharedWith: string[]}>|null} connections The map, or null when unknown.
 * @param {string} id The connection uuid.
 * @return {string[]|null} The organisation uuids, [] for nobody, null when unknown.
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
 */
export function sharedWithOf(connections, id) {
	if (connections === null || typeof connections !== 'object') {
		return null
	}
	const entry = connections[id]
	return Array.isArray(entry?.sharedWith) ? entry.sharedWith.filter((org) => typeof org === 'string' && org !== '') : []
}

/**
 * Forget the loaded map (tests, and after the active organisation changes).
 *
 * @return {void}
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
 */
export function resetSharedConnections() {
	pending = null
}
