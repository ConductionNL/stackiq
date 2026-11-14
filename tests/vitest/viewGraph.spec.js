/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * viewGraph maps an imported GEMMA view to CnGraphCanvas nodes and back without moving
 * a node, and a child keeps its parent (architecture-views-editor, task 3).
 *
 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-001-an-application-owner-shall-draw-and-save-an-architecture-view
 */
import { describe, expect, it } from 'vitest'
import { fromCanvas, toCanvas } from '../../src/utils/viewGraph.js'

// The import's shape: absolute positions, a grouping with a nested child and a grandchild.
const VIEW_NODES = [
	{ viewNodeId: 'n-child', parent: 'n-group', elementRef: 'id-zaaksysteem', x: 140, y: 160, width: 120, height: 55, name: 'Zaaksysteem', type: 'Element' },
	{ viewNodeId: 'n-group', parent: null, elementRef: 'id-domein', x: 100, y: 100, width: 400, height: 300, name: 'Zaakgericht werken', type: 'Element' },
	{ viewNodeId: 'n-grandchild', parent: 'n-child', elementRef: 'id-module', x: 150, y: 190, width: 80, height: 20, name: 'Module', type: 'Element' },
	{ viewNodeId: 'n-dms', parent: null, elementRef: 'id-dms', x: 600, y: 120, width: 120, height: 55, name: 'Documentbeheer', type: 'Element' },
]
const RELATIONS = [
	{ viewRelationshipId: 'c-1', modelRelationshipId: 'rel-1', sourceId: 'n-child', targetId: 'n-dms', type: 'Relationship', bendpoints: [{ x: 400, y: 190 }] },
]

describe('viewGraph', () => {
	it('draws a child relative to its parent, parents first', () => {
		const { nodes } = toCanvas(VIEW_NODES, RELATIONS)
		const byId = Object.fromEntries(nodes.map((node) => [node.id, node]))

		expect(nodes.findIndex((node) => node.id === 'n-group')).toBeLessThan(nodes.findIndex((node) => node.id === 'n-child'))
		expect(nodes.findIndex((node) => node.id === 'n-child')).toBeLessThan(nodes.findIndex((node) => node.id === 'n-grandchild'))
		expect(byId['n-group'].position).toEqual({ x: 100, y: 100 })
		expect(byId['n-group'].parentNode).toBeUndefined()
		expect(byId['n-child']).toMatchObject({ parentNode: 'n-group', position: { x: 40, y: 60 } })
		expect(byId['n-grandchild']).toMatchObject({ parentNode: 'n-child', position: { x: 10, y: 30 } })
		expect(byId['n-child'].style).toEqual({ width: '120px', height: '55px' })
	})

	it('round-trips an imported view without moving, resizing or re-parenting a node', () => {
		const { nodes, edges } = toCanvas(VIEW_NODES, RELATIONS)
		const back = fromCanvas(nodes, edges)
		const sortById = (list) => [...list].sort((a, b) => a.viewNodeId.localeCompare(b.viewNodeId))

		expect(sortById(back.viewNodes)).toEqual(sortById(VIEW_NODES))
		expect(back.viewRelationships).toEqual(RELATIONS)
	})

	it('saves a child placed on the canvas with its parent\'s viewNodeId and an absolute position', () => {
		const nodes = [
			{ id: 'g', position: { x: 50, y: 50 }, data: { name: 'Groep', type: 'Element', elementRef: 'id-g', width: 300, height: 200 } },
			{ id: 'c', parentNode: 'g', position: { x: 20, y: 30 }, data: { name: 'Nieuw', type: 'Element', elementRef: 'id-c', width: 120, height: 55 } },
		]

		const { viewNodes } = fromCanvas(nodes, [])

		expect(viewNodes.find((node) => node.viewNodeId === 'c')).toEqual({
			viewNodeId: 'c', parent: 'g', elementRef: 'id-c', x: 70, y: 80, width: 120, height: 55, name: 'Nieuw', type: 'Element',
		})
	})
})
