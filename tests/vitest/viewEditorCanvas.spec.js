/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The edits the view editor makes to its canvas (architecture-views-editor,
 * task 4): placing elements, connecting them, removing nodes and applying
 * CnGraphCanvas's change stream, which the canvas reports and never applies.
 *
 * @spec openspec/changes/archive/2026-11-26-architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-001-an-application-owner-shall-draw-and-save-an-architecture-view
 */
import { describe, expect, it } from 'vitest'
import { applyEdgeChanges, applyNodeChanges, connect, freshId, layerOf, NODE_SIZE, placeElement, removeNode } from '../../src/utils/viewEditorCanvas.js'
import { fromCanvas } from '../../src/utils/viewGraph.js'

const node = (id, extra = {}) => ({ id, position: { x: 0, y: 0 }, data: { name: id, type: 'ApplicationComponent', elementRef: `el-${id}`, width: 120, height: 55 }, ...extra })

describe('layerOf', () => {
	it('puts palette types and unknown ArchiMate names in their layer', () => {
		expect(layerOf('BusinessActor')).toBe('business')
		expect(layerOf('ApplicationComponent')).toBe('application')
		expect(layerOf('Node')).toBe('technology')
		expect(layerOf('Grouping')).toBe('other')
		expect(layerOf('BusinessEvent')).toBe('business')
		expect(layerOf('ApplicationEvent')).toBe('application')
		expect(layerOf('Device')).toBe('technology')
		expect(layerOf('Goal')).toBe('other')
		expect(layerOf(undefined)).toBe('other')
	})
})

describe('freshId', () => {
	it('skips ids in use', () => {
		expect(freshId('n', [])).toBe('n-1')
		expect(freshId('n', [{ id: 'n-2' }])).toBe('n-3')
		expect(freshId('n', [{ id: 'n-3' }, { id: 'x' }])).toBe('n-4')
		expect(freshId('n', [{ id: 'n-2' }, { id: 'n-3' }])).toBe('n-4')
	})
})

describe('placeElement', () => {
	it('places an existing element by reference and a new one without, side by side', () => {
		const one = placeElement([], { name: 'Zaaksysteem', type: 'ApplicationComponent', elementRef: 'id-el-a' })
		const two = placeElement(one, { name: 'Nieuw', type: 'ApplicationService' })

		expect(one).toHaveLength(1)
		expect(two[0]).toEqual(one[0])
		expect(two.map((n) => [n.id, n.position, n.data.elementRef, n.data.type])).toEqual([
			['n-1', { x: 40, y: 40 }, 'id-el-a', 'ApplicationComponent'],
			['n-2', { x: 40 + NODE_SIZE.width + 40, y: 40 }, null, 'ApplicationService'],
		])
		expect(two[1].style).toEqual({ width: '120px', height: '55px' })
	})

	it('saves as an absolute node with the element and size', () => {
		const nodes = placeElement([], { name: 'A', type: 'Node', elementRef: 'id-a' }, { x: 300, y: 90 })
		expect(fromCanvas(nodes, []).viewNodes).toEqual([
			{ viewNodeId: 'n-1', parent: null, elementRef: 'id-a', x: 300, y: 90, width: 120, height: 55, name: 'A', type: 'Node' },
		])
	})
})

describe('connect', () => {
	it('adds a connection with the relation type and no relation yet', () => {
		const edges = connect([], { source: 'a', target: 'b' }, 'Flow')
		expect(edges).toEqual([{ id: 'c-1', source: 'a', target: 'b', label: 'Flow', data: { type: 'Flow', modelRelationshipId: null, bendpoints: [] } }])
	})

	it('refuses a self-loop, a missing end and a duplicate of the same type', () => {
		const edges = connect([], { source: 'a', target: 'b' }, 'Flow')
		expect(connect(edges, { source: 'a', target: 'a' }, 'Flow')).toBe(edges)
		expect(connect(edges, { source: 'a' }, 'Flow')).toBe(edges)
		expect(connect(edges, { source: 'a', target: 'b' }, 'Flow')).toBe(edges)
		expect(connect(edges, { source: 'a', target: 'b' }, 'Serving')).toHaveLength(2)
	})
})

describe('removeNode', () => {
	it('removes the node, its children and every connection touching them', () => {
		const canvas = {
			nodes: [node('g'), node('a', { parentNode: 'g' }), node('b', { parentNode: 'a' }), node('c')],
			edges: [{ id: 'e1', source: 'b', target: 'c' }, { id: 'e2', source: 'c', target: 'g' }, { id: 'e3', source: 'c', target: 'c2' }],
		}
		const next = removeNode(canvas, 'g')
		expect(next.nodes.map((n) => n.id)).toEqual(['c'])
		expect(next.edges.map((e) => e.id)).toEqual(['e3'])
		expect(canvas.nodes).toHaveLength(4)
	})
})

describe('applyNodeChanges', () => {
	it('moves, resizes and removes, and ignores selection', () => {
		const canvas = { nodes: [node('a'), node('b')], edges: [{ id: 'e', source: 'a', target: 'b' }] }

		const moved = applyNodeChanges(canvas, [{ id: 'a', type: 'position', position: { x: 10.4, y: 20.6 }, dragging: false }])
		expect(moved.changed).toBe(true)
		expect(moved.nodes[0].position).toEqual({ x: 10, y: 21 })

		const resized = applyNodeChanges(canvas, [{ id: 'b', type: 'dimensions', resizing: true, dimensions: { width: 200, height: 80 } }])
		expect(resized.nodes[1].data).toMatchObject({ width: 200, height: 80 })
		expect(resized.nodes[1].style).toEqual({ width: '200px', height: '80px' })

		const removed = applyNodeChanges(canvas, [{ id: 'a', type: 'remove' }])
		expect(removed.nodes.map((n) => n.id)).toEqual(['b'])
		expect(removed.edges).toEqual([])

		const selected = applyNodeChanges(canvas, [{ id: 'a', type: 'select', selected: true }, { id: 'a', type: 'dimensions', dimensions: { width: 1, height: 1 } }])
		expect(selected.changed).toBe(false)
		expect(selected.nodes).toBe(canvas.nodes)
	})
})

describe('applyEdgeChanges', () => {
	it('removes reported removals and returns the same list otherwise', () => {
		const edges = [{ id: 'e1' }, { id: 'e2' }]
		expect(applyEdgeChanges(edges, [{ id: 'e1', type: 'remove' }])).toEqual([{ id: 'e2' }])
		expect(applyEdgeChanges(edges, [{ id: 'e1', type: 'select' }])).toBe(edges)
	})
})
