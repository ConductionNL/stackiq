// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The layered layout of the connections diagram.
 *
 * @spec openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-002-the-connections-list-can-be-shown-as-a-diagram-of-the-filtered-rows
 */

import { describe, expect, it } from 'vitest'
import { COLUMN_GAP, connectionEnds, layoutConnections, ROW_GAP } from '../../src/utils/connectionLayout.js'

const zaak = { id: 'z', name: 'Zaaksysteem' }
const dms = { id: 'd', name: 'DMS' }
const crm = { id: 'c', name: 'CRM' }
const bal = { id: 'b', name: 'Balie' }

const ROWS = [
	{ id: 'k1', moduleA: zaak, moduleB: dms, dataExchangeDirection: 'AtoB', type: 'api' },
	{ id: 'k2', moduleA: crm, moduleB: zaak, dataExchangeDirection: 'BtoA', type: 'digikoppeling' },
	{ id: 'k3', moduleA: bal, moduleB: crm, dataExchangeDirection: 'AtoB', type: 'api' },
	{ id: 'k4', moduleA: bal, nonMunicipalProvision: { id: 'p', name: 'BRP' }, dataExchangeDirection: 'bi-directional', type: 'webservices' },
]

describe('layoutConnections', () => {
	it('puts senders left, receivers right and both in the middle, by name', () => {
		const { nodes } = layoutConnections(ROWS)
		const at = Object.fromEntries(nodes.map((n) => [n.id, n.position]))

		// Zaaksysteem sends to DMS and to CRM (k2 is BtoA): a pure sender.
		expect(at.z).toEqual({ x: 0, y: 0 })
		// CRM and DMS only receive: the right column, CRM first by name.
		expect(at.c).toEqual({ x: 2 * COLUMN_GAP, y: 0 })
		expect(at.d).toEqual({ x: 2 * COLUMN_GAP, y: ROW_GAP })
		// Balie sends to CRM and exchanges both ways with BRP: the middle.
		expect(at.b).toEqual({ x: COLUMN_GAP, y: 0 })
		expect(at.p).toEqual({ x: COLUMN_GAP, y: ROW_GAP })
		expect(nodes.find((n) => n.id === 'p').provision).toBe(true)
	})

	it('draws each edge from the sender, labelled with the transport type', () => {
		const { edges } = layoutConnections(ROWS)
		expect(edges).toEqual([
			{ id: 'k1', source: 'z', target: 'd', label: 'api', bidirectional: false },
			{ id: 'k2', source: 'z', target: 'c', label: 'digikoppeling', bidirectional: false },
			{ id: 'k3', source: 'b', target: 'c', label: 'api', bidirectional: false },
			{ id: 'k4', source: 'b', target: 'p', label: 'webservices', bidirectional: true },
		])
	})

	it('gives the same positions on every render, whatever the row order', () => {
		const first = layoutConnections(ROWS).nodes
		const again = layoutConnections([...ROWS].reverse()).nodes
		const pos = (nodes) => Object.fromEntries(nodes.map((n) => [n.id, n.position]))
		expect(pos(again)).toEqual(pos(first))
	})

	it('draws only the rows it is given, and skips a row without two ends', () => {
		const apiOnly = ROWS.filter((r) => r.type === 'api')
		const { nodes, edges } = layoutConnections([...apiOnly, { id: 'k9', moduleA: zaak }])
		expect(edges.map((e) => e.id)).toEqual(['k1', 'k3'])
		expect(nodes.map((n) => n.id).sort()).toEqual(['b', 'c', 'd', 'z'])
		expect(layoutConnections(undefined)).toEqual({ nodes: [], edges: [] })
	})

	it('reads an end given as a plain uuid', () => {
		expect(connectionEnds({ moduleA: ' a-1 ', moduleB: 'b-1' })).toEqual({
			a: { id: 'a-1', name: 'a-1' },
			b: { id: 'b-1', name: 'b-1', provision: false },
		})
		expect(connectionEnds({ moduleA: { uuid: '' } })).toEqual({ a: null, b: null })
	})
})
