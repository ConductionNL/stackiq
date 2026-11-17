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
import { describeDiff, diffViews, statusesFor } from '../../src/utils/viewDiff.js'

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

describe('statusesFor and describeDiff', () => {
	const before = { nodes: [node('a'), node('b'), node('c')], connections: [link('ab')] }
	const after = { nodes: [node('a'), node('c', { name: 'Zaaksysteem', x: 40 }), node('d', { name: 'DMS' })], connections: [link('ab', { type: 'Flow' })] }

	it('marks removed and changed on the earlier side, added and changed on the later side', () => {
		const diff = diffViews(before, after)
		const earlier = statusesFor(diff, 'before')
		const later = statusesFor(diff, 'after')

		expect([...earlier.nodes]).toEqual([['b', 'removed'], ['c', 'changed']])
		expect([...later.nodes]).toEqual([['d', 'added'], ['c', 'changed']])
		expect([...earlier.connections]).toEqual([['ab', 'changed']])
		expect(later.nodes.has('a')).toBe(false)
	})

	it('lists every change once, nodes first, with the changed fields', () => {
		expect(describeDiff(diffViews(before, after))).toEqual([
			{ kind: 'node', change: 'added', id: 'd', name: 'DMS', fields: [] },
			{ kind: 'node', change: 'removed', id: 'b', name: 'b', fields: [] },
			{ kind: 'node', change: 'changed', id: 'c', name: 'Zaaksysteem', fields: ['name', 'x'] },
			{ kind: 'connection', change: 'changed', id: 'ab', name: 'Flow', fields: ['type'] },
		])
	})

	it('lists nothing for two equal snapshots in a different order', () => {
		const diff = diffViews(before, { nodes: [...before.nodes].reverse(), connections: before.connections })
		expect(describeDiff(diff)).toEqual([])
		expect([...statusesFor(diff, 'after').nodes]).toEqual([])
	})
})
