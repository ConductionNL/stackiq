// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The coverage rows a filter keeps.
 *
 * @param {Array<object>} components Coverage rows from the endpoint.
 * @param {string} filter all, gap, fillable or overlap.
 * @return {Array<object>} The kept rows.
 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-002-a-coverage-page-shall-list-the-components-with-filters-for-gaps-and-overlaps
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
 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-002-a-coverage-page-shall-list-the-components-with-filters-for-gaps-and-overlaps
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
	const named = [...groups.entries()].filter(([name]) => name !== '').map(([name, rows]) => ({ name, components: rows }))
	return groups.has('') ? [...named, { name: fallback, components: groups.get('') }] : named
}
