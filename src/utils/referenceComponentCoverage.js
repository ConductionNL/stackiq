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
 * A view's id, whichever shape the views endpoint gives it in.
 *
 * @param {object} view A view from `GET /api/views`.
 * @return {string} The id, or '' when it has none.
 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-draws-the-gemma-view-the-user-picks-req-gpl-001
 */
export function viewId(view) {
	return String(view?.id ?? view?.['@self']?.id ?? view?.uuid ?? '')
}

/**
 * The GEMMA release a view belongs to, or '' when the import recorded none.
 *
 * @param {object} view A view.
 * @return {string} The release.
 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-draws-the-gemma-view-the-user-picks-req-gpl-001
 */
export function viewRelease(view) {
	return String(view?.release ?? view?.gemmaRelease ?? view?.version ?? '')
}

/**
 * Picker options for the views, by name, each labelled with its release.
 *
 * @param {Array<object>} views Views from `GET /api/views`.
 * @return {Array<{id: string, label: string}>} Options.
 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-draws-the-gemma-view-the-user-picks-req-gpl-001
 */
export function viewOptions(views) {
	return (views || [])
		.map((view) => {
			const id = viewId(view)
			const name = String(view?.name || view?.title || id)
			const release = viewRelease(view)
			return { id, label: release ? `${name} (${release})` : name }
		})
		.filter((option) => option.id !== '')
		.sort((a, b) => a.label.localeCompare(b.label))
}

/**
 * The applications a view node's usage names, in the shape of a coverage row.
 *
 * @param {Array<object>} usage The node's `usage` from `include_gebruik`.
 * @return {Array<{moduleName: string}>} The applications.
 */
function usageApplications(usage) {
	return (usage || []).map((item) => ({
		moduleName: String(
			item?._linked_module?.name
				?? item?.module?.name
				?? item?.moduleName
				?? item?.name
				?? '',
		),
	}))
}

/**
 * The state of a component that has this many applications.
 *
 * @param {number} count The applications.
 * @return {string} gap, covered or overlap.
 */
function stateOf(count) {
	if (count === 0) {
		return 'gap'
	}
	return count === 1 ? 'covered' : 'overlap'
}

/**
 * The plate of one GEMMA view: its reference components in the view's own
 * groups, in the order the view lists them.
 *
 * A node is a group when other nodes sit inside it; a component is a node
 * without children that names a reference component of the coverage (by its
 * element identifier or id) or carries usage. A component keeps its coverage
 * row (state, fillable, applications); one the coverage does not know gets a
 * row from the node's usage. A component sits in the group of its nearest
 * named parent; components outside any group come last under the fallback.
 *
 * @param {object} view A view from `GET /api/views/{viewId}` with `include_gebruik`.
 * @param {Array<object>} components Coverage rows of the organisation.
 * @param {string} fallback The name for components outside a group.
 * @return {Array<{name: string, components: Array<object>}>} Groups.
 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-draws-the-gemma-view-the-user-picks-req-gpl-001
 */
export function viewGroups(view, components, fallback) {
	const nodes = (view?.viewNodes || []).filter(
		(node) => node && node.type !== 'label',
	)
	const byNodeId = new Map(nodes.map((node) => [node.viewNodeId, node]))
	const parents = new Set(nodes.map((node) => node.parent).filter(Boolean))
	const rows = new Map()
	for (const row of components || []) {
		for (const key of [row.identifier, row.id]) {
			if (key && !rows.has(key)) {
				rows.set(key, row)
			}
		}
	}

	const groups = new Map()
	const seen = new Set()
	for (const node of nodes) {
		const ref = node.modelNodeId || node.elementRef
		if (!ref || parents.has(node.viewNodeId) || seen.has(ref)) {
			continue
		}
		const row = rows.get(ref) || usageRow(node, ref)
		if (!row) {
			continue
		}
		seen.add(ref)
		const name = groupName(node, byNodeId)
		if (!groups.has(name)) {
			groups.set(name, [])
		}
		groups.get(name).push(row)
	}

	const named = [...groups.entries()]
		.filter(([name]) => name !== '')
		.map(([name, rowsOfGroup]) => ({ name, components: rowsOfGroup }))
	return groups.has('')
		? [...named, { name: fallback, components: groups.get('') }]
		: named
}

/**
 * A coverage row from a node's usage, or null when the node has none.
 *
 * @param {object} node A view node.
 * @param {string} ref The element it shows.
 * @return {object|null} The row.
 */
function usageRow(node, ref) {
	if (!Array.isArray(node.usage) || node.usage.length === 0) {
		return null
	}
	const applications = usageApplications(node.usage)
	return {
		id: ref,
		identifier: ref,
		name: String(node.name || ref),
		documentation: String(node.documentation || node.description || ''),
		state: stateOf(applications.length),
		fillable: false,
		fillableBy: [],
		applications,
	}
}

/**
 * The name of the nearest named parent of a node, or ''.
 *
 * @param {object} node A view node.
 * @param {Map<string, object>} byNodeId The view's nodes by view node id.
 * @return {string} The group name.
 */
function groupName(node, byNodeId) {
	const visited = new Set()
	let parent = byNodeId.get(node.parent)
	while (parent && !visited.has(parent.viewNodeId)) {
		visited.add(parent.viewNodeId)
		if (parent.name) {
			return String(parent.name)
		}
		parent = byNodeId.get(parent.parent)
	}
	return ''
}

/**
 * The view's components that a filter keeps, groups without any left dropped.
 *
 * @param {Array<{name: string, components: Array<object>}>} groups The view's groups.
 * @param {string} filter all, gap, fillable or overlap.
 * @return {Array<{name: string, components: Array<object>}>} The kept groups.
 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-draws-the-gemma-view-the-user-picks-req-gpl-001
 */
export function filterGroups(groups, filter) {
	return groups
		.map((group) => ({
			name: group.name,
			components: filterCoverage(group.components, filter),
		}))
		.filter((group) => group.components.length > 0)
}
