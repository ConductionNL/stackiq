/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Maps an architecture view between the shape the GEMMA import stores and the
 * shape CnGraphCanvas (Vue Flow) draws (architecture-views-editor, design D3).
 *
 * The import keeps a flat node list with ABSOLUTE positions and a `parent` id;
 * Vue Flow draws a child node RELATIVE to its `parentNode`. Both directions
 * convert, so a view round-trips without moving a node, and a grouping keeps
 * its children.
 */

/**
 * A number, or 0.
 *
 * @param {number|string|undefined|null} value The value.
 * @return {number}
 */
const num = (value) => (Number.isFinite(Number(value)) ? Number(value) : 0)

/**
 * The absolute position of every node, by `viewNodeId`, following `parent` up the chain.
 *
 * @param {Array<object>} viewNodes The import's nodes.
 * @return {Map<string, {x: number, y: number}>}
 */
function absolutePositions(viewNodes) {
	const positions = new Map()
	for (const node of viewNodes) {
		positions.set(String(node.viewNodeId), { x: num(node.x), y: num(node.y) })
	}
	return positions
}

/**
 * The import's nodes and connections as CnGraphCanvas nodes and edges.
 *
 * A parent is listed before its children, as Vue Flow requires.
 *
 * @param {Array<object>} viewNodes The import's `xml.viewNodes`.
 * @param {Array<object>} viewRelationships The import's `xml.viewRelationships`.
 * @return {{nodes: Array<object>, edges: Array<object>}}
 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-001-an-application-owner-shall-draw-and-save-an-architecture-view
 */
export function toCanvas(viewNodes = [], viewRelationships = []) {
	const absolute = absolutePositions(viewNodes)
	const byId = new Map(viewNodes.map((node) => [String(node.viewNodeId), node]))
	const depth = (node, seen = new Set()) => {
		const parent = node.parent ? byId.get(String(node.parent)) : null
		if (!parent || seen.has(String(node.viewNodeId))) return 0
		seen.add(String(node.viewNodeId))
		return 1 + depth(parent, seen)
	}
	const ordered = [...viewNodes].sort((a, b) => depth(a) - depth(b))

	const nodes = ordered.map((node) => {
		const id = String(node.viewNodeId)
		const own = absolute.get(id)
		const parent = node.parent && byId.has(String(node.parent)) ? String(node.parent) : null
		const origin = parent ? absolute.get(parent) : { x: 0, y: 0 }
		const width = num(node.width)
		const height = num(node.height)
		return {
			id,
			type: 'default',
			position: { x: own.x - origin.x, y: own.y - origin.y },
			...(parent && { parentNode: parent }),
			style: { width: `${width}px`, height: `${height}px` },
			data: { name: node.name ?? '', type: node.type ?? '', elementRef: node.elementRef ?? null, width, height },
		}
	})

	const edges = viewRelationships.map((relation) => ({
		id: String(relation.viewRelationshipId),
		source: String(relation.sourceId),
		target: String(relation.targetId),
		data: { type: relation.type ?? '', modelRelationshipId: relation.modelRelationshipId ?? null, bendpoints: relation.bendpoints ?? [] },
	}))

	return { nodes, edges }
}

/**
 * CnGraphCanvas nodes and edges back in the shape the import stores, with absolute positions.
 *
 * @param {Array<object>} nodes The canvas nodes.
 * @param {Array<object>} edges The canvas edges.
 * @return {{viewNodes: Array<object>, viewRelationships: Array<object>}}
 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-001-an-application-owner-shall-draw-and-save-an-architecture-view
 */
export function fromCanvas(nodes = [], edges = []) {
	const byId = new Map(nodes.map((node) => [String(node.id), node]))
	const absolute = (node, seen = new Set()) => {
		const own = { x: num(node.position?.x), y: num(node.position?.y) }
		const parent = node.parentNode ? byId.get(String(node.parentNode)) : null
		if (!parent || seen.has(String(node.id))) return own
		seen.add(String(node.id))
		const base = absolute(parent, seen)
		return { x: own.x + base.x, y: own.y + base.y }
	}

	const viewNodes = nodes.map((node) => {
		const { x, y } = absolute(node)
		return {
			viewNodeId: String(node.id),
			parent: node.parentNode ? String(node.parentNode) : null,
			elementRef: node.data?.elementRef ?? null,
			x,
			y,
			width: num(node.data?.width),
			height: num(node.data?.height),
			name: node.data?.name ?? '',
			type: node.data?.type ?? '',
		}
	})

	const viewRelationships = edges.map((edge) => ({
		viewRelationshipId: String(edge.id),
		modelRelationshipId: edge.data?.modelRelationshipId ?? null,
		sourceId: String(edge.source),
		targetId: String(edge.target),
		type: edge.data?.type ?? '',
		bendpoints: edge.data?.bendpoints ?? [],
	}))

	return { viewNodes, viewRelationships }
}
