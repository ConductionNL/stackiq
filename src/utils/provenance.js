/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Where a mirrored entry came from. A federation pull stamps every mirror
 * with `_source` (the peer's address as `instance`, its catalogue name as
 * `organisation`, the entry's id at the peer as `peerEntryId`); a record
 * without an `instance` is this catalogue's own.
 *
 * @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-mirrored-entries-show-where-they-came-from-req-fed-011
 */

/**
 * The path of a peer's public listing below its address, as the pull reads
 * it (lib/Service/Federation/PeerCatalogClient.php LISTING_PATH).
 */
export const PEER_LISTING_PATH = '/index.php/apps/opencatalogi/api/publications'

/**
 * The provenance of a record: the peer catalogue's name and the link to the
 * entry at the peer, or null for a record of this catalogue.
 *
 * @param {object|null} object The record (its data, with `_source`).
 * @return {{name: string, url: string|null}|null} The provenance, or null.
 * @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-mirrored-entries-show-where-they-came-from-req-fed-011
 */
export function provenanceOf(object) {
	const source = object && typeof object === 'object' ? object._source : null
	const instance = source && typeof source === 'object' && typeof source.instance === 'string' ? source.instance.trim() : ''
	if (instance === '') {
		return null
	}
	const name = typeof source.organisation === 'string' && source.organisation.trim() !== '' && source.organisation.trim() !== instance
		? source.organisation.trim()
		: hostOf(instance)
	return { name, url: originalUrl(instance, source.peerEntryId) }
}

/**
 * The entry's address at the peer, only for an http(s) peer and an entry id.
 *
 * @param {string} instance The peer's address.
 * @param {unknown} peerEntryId The entry's id at the peer.
 * @return {string|null} The link, or null.
 */
function originalUrl(instance, peerEntryId) {
	const id = typeof peerEntryId === 'number' ? String(peerEntryId) : peerEntryId
	if (!/^https?:\/\//i.test(instance) || typeof id !== 'string' || id.trim() === '') {
		return null
	}
	const base = instance.replace(/\/+$/, '')
	const listing = base.endsWith(PEER_LISTING_PATH) ? base : base + PEER_LISTING_PATH
	return listing + '/' + encodeURIComponent(id.trim())
}

/**
 * The host of a peer address, or the address itself when it has none.
 *
 * @param {string} instance The peer's address.
 * @return {string} The host.
 */
function hostOf(instance) {
	try {
		return new URL(instance).host || instance
	} catch {
		return instance
	}
}
