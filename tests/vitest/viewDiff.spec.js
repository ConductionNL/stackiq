/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * viewDiff compares two snapshots of a view by node and connection id
 * (architecture-views-editor, task 7).
 *
 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-005-an-owner-shall-save-named-versions-and-compare-two-of-them
 */
import { describe, expect, it } from 'vitest'
import { diffViews } from '../../src/utils/viewDiff.js'

const node = (id, extra = {}) => ({ viewNodeId: id, parent: null, elementRef: `el-${id}`, x: 0, y: 0, width: 120, height: 55, name: id, type: 'Element', ...extra })
const link = (id, extra = {}) => ({ viewRelationshipId: id, modelRelationshipId: `rel-${id}`, sourceId: 'a', targetId: 'b', type: 'Relationship', bendpoints: [], ...extra })

describe('diffViews', () => {
	it('sorts nodes and connections into added, removed and changed by id', () => {
		const before = { nodes: [node('a'), node('b'), node('c')], connections: [link('ab'), link('bc')] }
		const after = { nodes: [node('a'), node('c', { x: 40 }), node('d')], connections: [link('ab', { type: 'Flow' }), link('cd')] }

		const diff = diffViews(before, after)

		expect(diff.nodes.added.map((n) => n.viewNodeId)).toEqual(['d'])
		expect(diff.nodes.removed.map((n) => n.viewNodeId)).toEqual(['b'])
		expect(diff.nodes.changed).toEqual([{ id: 'c', before: node('c'), after: node('c', { x: 40 }), fields: ['x'] }])
		expect(diff.nodes.unchanged.map((n) => n.viewNodeId)).toEqual(['a'])
		expect(diff.connections.added.map((c) => c.viewRelationshipId)).toEqual(['cd'])
		expect(diff.connections.removed.map((c) => c.viewRelationshipId)).toEqual(['bc'])
		expect(diff.connections.changed.map((c) => [c.id, c.fields])).toEqual([['ab', ['type']]])
		expect(diff.unchanged).toBe(false)
	})

	it('counts one node removed from the middle as one removal, not every later node changed', () => {
		const before = { nodes: [node('a'), node('b'), node('c'), node('d')] }
		const after = { nodes: [node('a'), node('c'), node('d')] }

		const diff = diffViews(before, after)

		expect(diff.nodes.removed.map((n) => n.viewNodeId)).toEqual(['b'])
		expect(diff.nodes.changed).toEqual([])
	})

	it('reads a different order, or the same fields in another key order, as no change', () => {
		const before = { nodes: [node('a'), node('b', { style: { fill: 'x', line: 'y' } })], connections: [link('ab')] }
		const after = { nodes: [{ ...node('b'), style: { line: 'y', fill: 'x' } }, node('a')], connections: [link('ab')] }

		expect(diffViews(before, after).unchanged).toBe(true)
	})

	it('marks a node changed for its name, element, parent, size or style, and nothing else', () => {
		const before = { nodes: [node('a')] }
		for (const [field, value] of [['name', 'Nieuw'], ['elementRef', 'el-x'], ['parent', 'g'], ['width', 200], ['height', 10], ['y', 5], ['style', { fill: 'red' }]]) {
			expect(diffViews(before, { nodes: [node('a', { [field]: value })] }).nodes.changed[0].fields).toEqual([field])
		}
		expect(diffViews(before, { nodes: [node('a', { documentation: 'ignored' })] }).unchanged).toBe(true)
	})
})
