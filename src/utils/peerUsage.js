// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The peer applications per reference component, from the peers endpoint.
 *
 * @param {object|null} peers The answer of `GET /api/reference-component-coverage/peers`.
 * @return {Map<string, Array<object>>} The applications by reference component id.
 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
 */
export function peerIndex(peers) {
	const index = new Map()
	for (const row of peers?.components || []) {
		if (row?.referenceComponent) {
			index.set(String(row.referenceComponent), row.applications || [])
		}
	}
	return index
}

/**
 * The peer applications of one coverage row: by its id, else its identifier.
 *
 * @param {Map<string, Array<object>>} index From peerIndex().
 * @param {object} component A coverage row.
 * @return {Array<object>} The applications, most used first.
 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
 */
export function peerApplicationsOf(index, component) {
	return index.get(String(component?.id ?? '')) || index.get(String(component?.identifier ?? '')) || []
}

/**
 * Whether a peer count is held back because fewer organisations share it.
 *
 * @param {object} application A peer application.
 * @return {boolean} True when the count is shown as "fewer than".
 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
 */
export function isHeldBack(application) {
	return application?.count === null || application?.count === undefined
}
