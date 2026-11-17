/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The edits the view editor makes to its canvas (architecture-views-editor,
 * design D3 and D4). CnGraphCanvas reports changes and applies none, so the
 * editor owns `nodes` and `edges`; these functions are those edits, kept
 * apart from the component so they can be tested without a DOM.
 */

/** The default size of a placed element, the size ArchiMate tools use. */
export const NODE_SIZE = Object.freeze({ width: 120, height: 55 })

/** The ArchiMate layer of each element type the palette offers. */
const LAYER_OF = {
	BusinessActor: 'business',
	BusinessRole: 'business',
	BusinessProcess: 'business',
	BusinessService: 'business',
	BusinessObject: 'business',
	ApplicationComponent: 'application',
	ApplicationService: 'application',
	ApplicationInterface: 'application',
	ApplicationFunction: 'application',
	DataObject: 'application',
	Node: 'technology',
	SystemSoftware: 'technology',
	TechnologyService: 'technology',
	Grouping: 'other',
}

/**
 * The ArchiMate layer of an element type, by name when it is not in the palette.
 *
 * @param {string} type The ArchiMate type.
 * @return {'business'|'application'|'technology'|'other'}
 */
export function layerOf(type) {
	if (LAYER_OF[type]) return LAYER_OF[type]
	const name = String(type ?? '')
	if (name.startsWith('Business') || name === 'Contract' || name === 'Product') return 'business'
	if (name.startsWith('Application') || name === 'DataObject') return 'application'
	if (name.startsWith('Technology') || ['Node', 'Device', 'SystemSoftware', 'Artifact', 'CommunicationNetwork', 'Path'].includes(name)) return 'technology'
	return 'other'
}

/**
 * A fresh node or connection id, unique on this canvas.
 *
 * @param {string} prefix `n` for a node, `c` for a connection.
 * @param {Array<{id: string}>} taken The ids in use.
 * @return {string}
 */
export function freshId(prefix, taken) {
	const used = new Set(taken.map((item) => String(item.id)))
	let n = taken.length + 1
	while (used.has(`${prefix}-${n}`)) n++
	return `${prefix}-${n}`
}

/**
 * Place an element on the canvas: an existing one by reference
 * (`elementRef` set), or a new one from the palette (`elementRef` null, created
 * on save).
 *
 * @param {Array<object>} nodes The canvas nodes, unchanged.
 * @param {{name: string, type: string, elementRef?: string|null}} element What to place.
 * @param {{x: number, y: number}} [position] Where; next to the last node when absent.
 * @return {Array<object>} The nodes with the new one appended.
 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-001-an-application-owner-shall-draw-and-save-an-architecture-view
 */
export function placeElement(nodes, element, position = null) {
	const last = nodes.filter((node) => !node.parentNode).at(-1)
	const at = position ?? (last ? { x: last.position.x + NODE_SIZE.width + 40, y: last.position.y } : { x: 40, y: 40 })
	return [...nodes, {
		id: freshId('n', nodes),
		type: 'default',
		position: { x: at.x, y: at.y },
		style: { width: `${NODE_SIZE.width}px`, height: `${NODE_SIZE.height}px` },
		data: { name: element.name, type: element.type, elementRef: element.elementRef ?? null, width: NODE_SIZE.width, height: NODE_SIZE.height },
	}]
}

/**
 * Connect two nodes with a relation type. A self-loop or a duplicate of the
 * same type between the same nodes is refused and the edges come back unchanged.
 *
 * @param {Array<object>} edges The canvas edges, unchanged.
 * @param {{source: string, target: string}} connection The two node ids.
 * @param {string} type The ArchiMate relation type.
 * @return {Array<object>}
 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-003-a-drawn-connection-shall-reference-a-relation-object
 */
export function connect(edges, connection, type) {
	const source = String(connection?.source ?? '')
	const target = String(connection?.target ?? '')
	if (!source || !target || source === target) return edges
	if (edges.some((edge) => edge.source === source && edge.target === target && edge.data?.type === type)) return edges
	return [...edges, { id: freshId('c', edges), source, target, label: type, data: { type, modelRelationshipId: null, bendpoints: [] } }]
}

/**
 * Remove a node, its children and every connection that touches one of them.
 *
 * @param {{nodes: Array<object>, edges: Array<object>}} canvas The canvas, unchanged.
 * @param {string} id The node id.
 * @return {{nodes: Array<object>, edges: Array<object>}}
 */
export function removeNode({ nodes, edges }, id) {
	const gone = new Set([String(id)])
	let grew = true
	while (grew) {
		grew = false
		for (const node of nodes) {
			if (node.parentNode && gone.has(String(node.parentNode)) && !gone.has(String(node.id))) {
				gone.add(String(node.id))
				grew = true
			}
		}
	}
	return {
		nodes: nodes.filter((node) => !gone.has(String(node.id))),
		edges: edges.filter((edge) => !gone.has(String(edge.source)) && !gone.has(String(edge.target))),
	}
}

/**
 * Apply CnGraphCanvas's node change stream: moves (the final position of a
 * drag), resizes and removals. Selection changes are no edit.
 *
 * @param {{nodes: Array<object>, edges: Array<object>}} canvas The canvas, unchanged.
 * @param {Array<object>} changes Vue Flow's node changes.
 * @return {{nodes: Array<object>, edges: Array<object>, changed: boolean}}
 */
export function applyNodeChanges(canvas, changes = []) {
	let next = { nodes: canvas.nodes, edges: canvas.edges }
	let changed = false
	for (const change of changes) {
		if (change.type === 'remove') {
			next = removeNode(next, change.id)
			changed = true
			continue
		}
		if (change.type === 'position' && change.position) {
			next = {
				...next,
				nodes: next.nodes.map((node) => (String(node.id) === String(change.id)
					? { ...node, position: { x: Math.round(change.position.x), y: Math.round(change.position.y) } }
					: node)),
			}
			changed = true
			continue
		}
		if (change.type === 'dimensions' && change.resizing && change.dimensions) {
			const width = Math.round(change.dimensions.width)
			const height = Math.round(change.dimensions.height)
			next = {
				...next,
				nodes: next.nodes.map((node) => (String(node.id) === String(change.id)
					? { ...node, style: { ...(node.style ?? {}), width: `${width}px`, height: `${height}px` }, data: { ...node.data, width, height } }
					: node)),
			}
			changed = true
		}
	}
	return { ...next, changed }
}

/**
 * Remove the connections Vue Flow reports removed.
 *
 * @param {Array<object>} edges The canvas edges, unchanged.
 * @param {Array<object>} changes Vue Flow's edge changes.
 * @return {Array<object>}
 */
export function applyEdgeChanges(edges, changes = []) {
	const removed = new Set(changes.filter((change) => change.type === 'remove').map((change) => String(change.id)))
	return removed.size ? edges.filter((edge) => !removed.has(String(edge.id))) : edges
}
