/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Compares two snapshots of an architecture view (architecture-views-editor, design D6).
 *
 * Nodes are keyed by `viewNodeId` and connections by `viewRelationshipId`, so a node
 * removed from the middle of the list is one removal, not every later node changed,
 * and a different order alone is no change.
 */

/** What makes a node changed: its name, element, parent, position, size or style. */
const NODE_FIELDS = ['name', 'elementRef', 'parent', 'x', 'y', 'width', 'height', 'style']

/** What makes a connection changed: its ends, its relation, its type or its bendpoints. */
const CONNECTION_FIELDS = ['sourceId', 'targetId', 'modelRelationshipId', 'type', 'bendpoints']

/**
 * A value comparable by content: objects with their keys sorted, so key order is no change.
 *
 * @param {unknown} value The value.
 * @return {string}
 */
function canonical(value) {
	if (Array.isArray(value)) return `[${value.map(canonical).join(',')}]`
	if (value !== null && typeof value === 'object') {
		return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${canonical(value[key])}`).join(',')}}`
	}
	return JSON.stringify(value ?? null)
}

/**
 * The fields of `before` and `after` that differ.
 *
 * @param {object} before The earlier item.
 * @param {object} after The later item.
 * @param {string[]} fields The fields to compare.
 * @return {string[]}
 */
const changedFields = (before, after, fields) => fields.filter((field) => canonical(before[field]) !== canonical(after[field]))

/**
 * Sort two lists of items, keyed by `key`, into added, removed, changed and unchanged.
 *
 * @param {Array<object>} before The earlier list.
 * @param {Array<object>} after The later list.
 * @param {{key: string, fields: string[]}} shape The id field and the compared fields.
 * @return {{added: object[], removed: object[], changed: Array<{id: string, before: object, after: object, fields: string[]}>, unchanged: object[]}}
 */
function compare(before, after, { key, fields }) {
	const earlier = new Map(before.map((item) => [String(item[key]), item]))
	const later = new Map(after.map((item) => [String(item[key]), item]))
	const result = { added: [], removed: [], changed: [], unchanged: [] }
	for (const [id, item] of later) {
		const old = earlier.get(id)
		if (!old) {
			result.added.push(item)
			continue
		}
		const differs = changedFields(old, item, fields)
		if (differs.length) result.changed.push({ id, before: old, after: item, fields: differs })
		else result.unchanged.push(item)
	}
	for (const [id, item] of earlier) {
		if (!later.has(id)) result.removed.push(item)
	}
	const byId = (a, b) => String(a[key] ?? a.id).localeCompare(String(b[key] ?? b.id))
	result.added.sort(byId)
	result.removed.sort(byId)
	result.unchanged.sort(byId)
	result.changed.sort((a, b) => a.id.localeCompare(b.id))
	return result
}

/**
 * Compare two snapshots of a view: `{nodes, connections}` in the import's shape.
 *
 * @param {{nodes?: object[], connections?: object[]}} before The earlier snapshot.
 * @param {{nodes?: object[], connections?: object[]}} after The later snapshot, or the current view.
 * @return {{nodes: object, connections: object, unchanged: boolean}}
 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-005-an-owner-shall-save-named-versions-and-compare-two-of-them
 */
export function diffViews(before = {}, after = {}) {
	const nodes = compare(before.nodes ?? [], after.nodes ?? [], { key: 'viewNodeId', fields: NODE_FIELDS })
	const connections = compare(before.connections ?? [], after.connections ?? [], { key: 'viewRelationshipId', fields: CONNECTION_FIELDS })
	const none = (part) => !part.added.length && !part.removed.length && !part.changed.length
	return { nodes, connections, unchanged: none(nodes) && none(connections) }
}
