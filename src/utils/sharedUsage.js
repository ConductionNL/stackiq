// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A usage a cooperation shares: who owns it, who takes part, how many
 * organisations share it (shared-usage-visible-to-participants).
 *
 * Pure helpers over a usage record, so the list cell, the detail card and the
 * connections column agree on what "shared" means.
 *
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
 */

/**
 * The uuid a relation holds: a string, or an object with `id`, `uuid` or `@self.id`.
 *
 * @param {string|object|null|undefined} value The relation.
 * @return {string} The uuid, or ''.
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
 */
export function relationId(value) {
	if (value && typeof value === 'object') {
		value = value.id ?? value.uuid ?? value['@self']?.id ?? ''
	}
	return typeof value === 'string' ? value.trim() : ''
}

/**
 * The name a relation carries when it was extended, else ''.
 *
 * @param {string|object|null|undefined} value The relation.
 * @return {string} The name, or ''.
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
 */
export function relationName(value) {
	if (value && typeof value === 'object' && typeof value.name === 'string') {
		return value.name
	}
	return ''
}

/**
 * The participants of a usage: each once, in the order recorded, with the
 * date it joined when the record carries one (`since` or `joinedAt`).
 *
 * @param {object|null} usage The usage record.
 * @return {Array<{id: string, name: string, since: string}>} The participants.
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
 */
export function participantsOf(usage) {
	const list = Array.isArray(usage?.participants) ? usage.participants : []
	const seen = new Set()
	const out = []
	for (const entry of list) {
		const id = relationId(entry)
		if (id === '' || seen.has(id)) {
			continue
		}
		seen.add(id)
		const since = entry && typeof entry === 'object' ? (entry.since ?? entry.joinedAt ?? '') : ''
		out.push({ id, name: relationName(entry), since: typeof since === 'string' ? since : '' })
	}
	return out
}

/**
 * Whether and how a usage is shared: null when it has no participants, else
 * the owner (its consumer) and how many organisations share it, the owner
 * counted once. A `_shared` marker from the gebruik API wins when present.
 *
 * @param {object|null} usage The usage record.
 * @return {{owner: string, ownerName: string, count: number}|null} The summary.
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
 */
export function sharedSummary(usage) {
	const participants = participantsOf(usage)
	if (participants.length === 0) {
		return null
	}
	const owner = relationId(usage?.consumer)
	const ids = new Set(participants.map((p) => p.id))
	if (owner !== '') {
		ids.add(owner)
	}
	const marker = usage?._shared
	return {
		owner: relationId(marker?.cooperation) || owner,
		ownerName: relationName(usage?.consumer),
		count: typeof marker?.participantCount === 'number' ? marker.participantCount : ids.size,
	}
}

/**
 * Whether the signed-in organisation only takes part in the usage (it is
 * listed as participant and is not the owner), so it reads it but may not change it.
 *
 * @param {object|null} usage The usage record.
 * @param {string} organisation The signed-in user's organisation uuid.
 * @return {boolean} True for a participant that is not the owner.
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
 */
export function isParticipantOnly(usage, organisation) {
	if (!organisation || relationId(usage?.consumer) === organisation) {
		return false
	}
	return participantsOf(usage).some((p) => p.id === organisation)
}
