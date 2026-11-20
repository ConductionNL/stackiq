// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The coverage rows a filter keeps.
 *
 * @param {Array<object>} components Coverage rows from the endpoint.
 * @param {string} filter all, gap, fillable or overlap.
 * @return {Array<object>} The kept rows.
 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-002-a-gemma-plate-shall-show-the-components-with-their-applications-with-filters-for-gaps-and-overlaps
 */
export function filterCoverage(components, filter) {
	if (filter === 'gap' || filter === 'overlap') {
		return components.filter((component) => component.state === filter)
	}
	if (filter === 'fillable') {
		return components.filter((component) => component.fillable)
	}
	return components
}

/**
 * Coverage rows in their GEMMA groups, in the order the rows come, with
 * ungrouped rows last under one fallback name.
 *
 * @param {Array<object>} components Coverage rows.
 * @param {string} fallback The name for rows without a group.
 * @return {Array<{name: string, components: Array<object>}>} Groups.
 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-002-a-gemma-plate-shall-show-the-components-with-their-applications-with-filters-for-gaps-and-overlaps
 */
export function groupCoverage(components, fallback) {
	const groups = new Map()
	for (const component of components) {
		const name = component.group || ''
		if (!groups.has(name)) {
			groups.set(name, [])
		}
		groups.get(name).push(component)
	}
	const named = [...groups.entries()]
		.filter(([name]) => name !== '')
		.map(([name, rows]) => ({ name, components: rows }))
	return groups.has('')
		? [...named, { name: fallback, components: groups.get('') }]
		: named
}

/**
 * A number, or 0.
 *
 * @param {number|string|undefined|null} value The value.
 * @return {number} The number.
 */
const toNumber = (value) => (Number.isFinite(Number(value)) ? Number(value) : 0)

/**
 * An ArchiMate identifier without its `id-` prefix, so `id-x` and `x` match.
 *
 * @param {string|null|undefined} identifier The identifier.
 * @return {string} The bare identifier, or ''.
 */
const bareIdentifier = (identifier) => String(identifier ?? '').trim().replace(/^id-/, '')

/**
 * A GEMMA view as read-only CnGraphCanvas nodes and edges, every node of a
 * reference component carrying that component's coverage row.
 *
 * The import stores ABSOLUTE positions and a `parent` id; Vue Flow draws a
 * child RELATIVE to its `parentNode`, and wants a parent listed before its
 * children. A node finds its component by `elementRef` against the
 * component's ArchiMate `identifier`; a node of any other element carries no
 * coverage. A node of a view fetched with `include_products=true` also carries
 * its `products` list; otherwise `products` is null.
 *
 * @param {Array<object>} viewNodes The view's nodes (`viewNodes`).
 * @param {Array<object>} viewRelationships The view's connections (`viewRelationships`).
 * @param {Array<object>} components Coverage rows from the endpoint.
 * @return {{nodes: Array<object>, edges: Array<object>}} The graph.
 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-003-the-coverage-shall-be-drawn-on-a-gemma-view-as-a-map
 */
export function coverageMapGraph(viewNodes = [], viewRelationships = [], components = []) {
	const byIdentifier = new Map()
	for (const component of components) {
		const identifier = bareIdentifier(component.identifier)
		if (identifier !== '') {
			byIdentifier.set(identifier, component)
		}
	}

	const listed = (Array.isArray(viewNodes) ? viewNodes : []).filter(
		(node) => node && node.viewNodeId !== undefined && node.viewNodeId !== null,
	)
	const byId = new Map(listed.map((node) => [String(node.viewNodeId), node]))
	const depth = (node, seen = new Set()) => {
		const parent = node.parent ? byId.get(String(node.parent)) : null
		if (!parent || seen.has(String(node.viewNodeId))) {
			return 0
		}
		seen.add(String(node.viewNodeId))
		return 1 + depth(parent, seen)
	}

	const nodes = [...listed]
		.sort((a, b) => depth(a) - depth(b))
		.map((node) => {
			const parent = node.parent && byId.has(String(node.parent)) ? byId.get(String(node.parent)) : null
			const width = toNumber(node.width)
			const height = toNumber(node.height)
			const component = byIdentifier.get(bareIdentifier(node.elementRef)) ?? null
			return {
				id: String(node.viewNodeId),
				type: 'default',
				position: {
					x: toNumber(node.x) - (parent ? toNumber(parent.x) : 0),
					y: toNumber(node.y) - (parent ? toNumber(parent.y) : 0),
				},
				...(parent && { parentNode: String(parent.viewNodeId) }),
				style: { width: `${width}px`, height: `${height}px` },
				data: {
					name: node.name ?? component?.name ?? '',
					component,
					// Only a view fetched with include_products carries the list.
					products: Array.isArray(node.products) ? node.products : null,
				},
			}
		})

	const edges = (Array.isArray(viewRelationships) ? viewRelationships : [])
		.filter((relation) => relation && byId.has(String(relation.sourceId)) && byId.has(String(relation.targetId)))
		.map((relation) => ({
			id: String(relation.viewRelationshipId),
			source: String(relation.sourceId),
			target: String(relation.targetId),
		}))

	return { nodes, edges }
}

/**
 * The GEMMA views a picker offers: id and name, by name.
 *
 * @param {Array<object>} views The views from `GET /api/views`.
 * @return {Array<{id: string, label: string}>} The options.
 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-003-the-coverage-shall-be-drawn-on-a-gemma-view-as-a-map
 */
export function viewOptions(views = []) {
	return (Array.isArray(views) ? views : [])
		.map((view) => {
			const id = String(view?.id ?? view?.uuid ?? view?.['@self']?.id ?? '')
			return { id, label: String(view?.name || view?.title || id) }
		})
		.filter((option) => option.id !== '')
		.sort((a, b) => a.label.localeCompare(b.label))
}
