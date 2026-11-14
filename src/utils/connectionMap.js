// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The nodes and edges of one application's connection map.
 *
 * The application sits in the centre as the root; every application or
 * national provision it has a connection with sits around it, once, however
 * many connections link them. Each connection is one edge, labelled with its
 * transport type and drawn from the sending end: a bi-directional connection
 * is labelled with a double arrow (design D1, REQ-CDX-001).
 *
 * @spec openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-001-the-application-page-draws-the-applications-connections-as-a-map
 */

import { connectionEnds } from './connectionLayout.js'

/**
 * The map of an application's connections.
 *
 * @param {string} rootId The application's id.
 * @param {string} rootLabel The application's name, used when no connection names it.
 * @param {Array<object>} connections The connections with the application at either end.
 * @return {{nodes: Array<object>, edges: Array<object>}} The graph for CnRelationshipGraph.
 */
export function connectionMap(rootId, rootLabel, connections) {
	const root = { id: rootId, label: rootLabel || rootId, isRoot: true, kind: 'application' }
	const others = new Map()
	const edges = []
	const seen = new Set()

	for (const connection of connections || []) {
		const { a, b } = connectionEnds(connection)
		const id = String(connection?.id ?? connection?.uuid ?? '')
		if (!a || !b || (a.id !== rootId && b.id !== rootId) || seen.has(id)) {
			continue
		}
		seen.add(id)

		const own = a.id === rootId ? a : b
		if (own.name && own.name !== own.id) {
			root.label = own.name
		}
		const other = a.id === rootId ? b : { ...a, provision: false }
		if (!others.has(other.id)) {
			others.set(other.id, { id: other.id, label: other.name, kind: other.provision ? 'provision' : 'application' })
		}

		const direction = String(connection.dataExchangeDirection || 'bi-directional')
		const fromA = direction !== 'BtoA'
		const type = String(connection.type ?? '')
		edges.push({
			id,
			source: fromA ? a.id : b.id,
			target: fromA ? b.id : a.id,
			label: direction === 'AtoB' || direction === 'BtoA' ? `${type} →` : `${type} ↔`,
			dashed: other.provision === true,
		})
	}

	const around = [...others.values()].sort((x, y) => x.label.localeCompare(y.label) || x.id.localeCompare(y.id))
	return { nodes: [root, ...around], edges }
}
