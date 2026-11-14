// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A stable, layered layout for the connections diagram.
 *
 * Applications that only send sit in the left column, those that only receive
 * in the right column, and the rest (both, or bi-directional) in the middle;
 * each column is sorted by name, then id. The same rows always give the same
 * positions, so a re-render never moves a node, which a force layout would
 * (design D2).
 *
 * @spec openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-002-the-connections-list-can-be-shown-as-a-diagram-of-the-filtered-rows
 */

/** Horizontal distance between columns, in px. */
export const COLUMN_GAP = 320

/** Vertical distance between nodes in one column, in px. */
export const ROW_GAP = 90

/**
 * The id and name of one end of a connection.
 *
 * @param {string|object|null} value A uuid, or a related object with `id`/`uuid` and `name`.
 * @return {{id: string, name: string}|null} The end, or null when empty.
 */
export function connectionEnd(value) {
	if (typeof value === 'string') {
		const id = value.trim()
		return id ? { id, name: id } : null
	}
	if (value && typeof value === 'object') {
		const id = String(value.id ?? value.uuid ?? '').trim()
		if (!id) {
			return null
		}
		return { id, name: String(value.name ?? value.title ?? id) }
	}
	return null
}

/**
 * The two ends of a connection: application A, and application B or the
 * national provision it links to.
 *
 * @param {object} connection The connection object.
 * @return {{a: ({id: string, name: string}|null), b: ({id: string, name: string, provision: boolean}|null)}} The ends.
 */
export function connectionEnds(connection) {
	const a = connectionEnd(connection?.moduleA)
	const module = connectionEnd(connection?.moduleB)
	if (module) {
		return { a, b: { ...module, provision: false } }
	}
	const provision = connectionEnd(connection?.nonMunicipalProvision)
	return { a, b: provision ? { ...provision, provision: true } : null }
}

/**
 * Which end sends: the sender and the receiver ids, or both ids as senders and
 * receivers for a bi-directional connection.
 *
 * @param {string} direction `AtoB`, `BtoA` or `bi-directional`.
 * @param {string} a Id of end A.
 * @param {string} b Id of end B.
 * @return {{senders: string[], receivers: string[]}} The roles.
 */
function roles(direction, a, b) {
	if (direction === 'AtoB') {
		return { senders: [a], receivers: [b] }
	}
	if (direction === 'BtoA') {
		return { senders: [b], receivers: [a] }
	}
	return { senders: [a, b], receivers: [a, b] }
}

/**
 * Nodes with positions and edges for the given connections.
 *
 * @param {Array<object>} connections The rows the list fetched.
 * @return {{nodes: Array<{id: string, label: string, provision: boolean, position: {x: number, y: number}}>, edges: Array<{id: string, source: string, target: string, label: string, bidirectional: boolean}>}} The diagram.
 * @spec openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-002-the-connections-list-can-be-shown-as-a-diagram-of-the-filtered-rows
 */
export function layoutConnections(connections) {
	const nodes = new Map()
	const sends = new Set()
	const receives = new Set()
	const edges = []

	for (const connection of connections || []) {
		const { a, b } = connectionEnds(connection)
		if (!a || !b) {
			continue
		}
		nodes.set(a.id, nodes.get(a.id) || { id: a.id, label: a.name, provision: false })
		nodes.set(b.id, nodes.get(b.id) || { id: b.id, label: b.name, provision: b.provision })

		const direction = String(connection.dataExchangeDirection || 'bi-directional')
		const { senders, receivers } = roles(direction, a.id, b.id)
		senders.forEach((id) => sends.add(id))
		receivers.forEach((id) => receives.add(id))

		const reversed = direction === 'BtoA'
		edges.push({
			id: String(connection.id ?? connection.uuid ?? `${a.id}-${b.id}-${edges.length}`),
			source: reversed ? b.id : a.id,
			target: reversed ? a.id : b.id,
			label: String(connection.type ?? ''),
			bidirectional: direction !== 'AtoB' && direction !== 'BtoA',
		})
	}

	const columns = [[], [], []]
	for (const node of nodes.values()) {
		const sender = sends.has(node.id)
		const receiver = receives.has(node.id)
		let column = 1
		if (sender && !receiver) {
			column = 0
		} else if (receiver && !sender) {
			column = 2
		}
		columns[column].push(node)
	}

	const byName = (x, y) => x.label.localeCompare(y.label) || x.id.localeCompare(y.id)
	const placed = []
	columns.forEach((column, index) => {
		column.sort(byName).forEach((node, row) => {
			placed.push({ ...node, position: { x: index * COLUMN_GAP, y: row * ROW_GAP } })
		})
	})

	return { nodes: placed, edges }
}
