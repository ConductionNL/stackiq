// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The nodes and edges of an application's connection map.
 *
 * @spec openspec/changes/archive/2026-11-25-connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-001-the-application-page-draws-the-applications-connections-as-a-map
 */

import { describe, expect, it } from 'vitest'
import { connectionLinks, connectionMap } from '../../src/utils/connectionMap.js'

const x = { id: 'x', name: 'Zaaksysteem' }
const y = { id: 'y', name: 'DMS' }
const z = { id: 'z', name: 'Archief' }

describe('connectionMap', () => {
	it('puts the application in the centre and every linked end around it once', () => {
		const { nodes } = connectionMap('x', 'fallback', [
			{ id: 'k1', moduleA: x, moduleB: y, type: 'api', dataExchangeDirection: 'AtoB' },
			{ id: 'k2', moduleA: y, moduleB: x, type: 'file transfer', dataExchangeDirection: 'AtoB' },
			{ id: 'k3', moduleA: x, nonMunicipalProvision: { id: 'p', name: 'BRP' }, type: 'file transfer', dataExchangeDirection: 'bi-directional' },
		])

		expect(nodes).toEqual([
			{ id: 'x', label: 'Zaaksysteem', isRoot: true, kind: 'application' },
			{ id: 'p', label: 'BRP', kind: 'provision' },
			{ id: 'y', label: 'DMS', kind: 'application' },
		])
	})

	it('draws one edge per connection, from the sender, labelled with the transport type', () => {
		const { edges } = connectionMap('x', 'Zaaksysteem', [
			{ id: 'k1', moduleA: x, moduleB: y, type: 'api', dataExchangeDirection: 'AtoB' },
			{ id: 'k2', moduleA: x, moduleB: z, type: 'digikoppeling', dataExchangeDirection: 'BtoA' },
			{ id: 'k3', moduleA: x, nonMunicipalProvision: { id: 'p', name: 'BRP' }, type: 'webservices', dataExchangeDirection: 'bi-directional' },
		])

		expect(edges).toEqual([
			{ id: 'k1', source: 'x', target: 'y', label: 'api →', dashed: false },
			{ id: 'k2', source: 'z', target: 'x', label: 'digikoppeling →', dashed: false },
			{ id: 'k3', source: 'x', target: 'p', label: 'webservices ↔', dashed: true },
		])
	})

	it('names the three applications that depend on the one being phased out', () => {
		const { nodes, edges } = connectionMap('x', 'Zaaksysteem', [
			{ id: 'k1', moduleA: y, moduleB: 'x', type: 'api', dataExchangeDirection: 'AtoB' },
			{ id: 'k2', moduleA: z, moduleB: 'x', type: 'api', dataExchangeDirection: 'AtoB' },
			{ id: 'k3', moduleA: { id: 'w', name: 'Balie' }, moduleB: 'x', type: 'api', dataExchangeDirection: 'AtoB' },
		])

		expect(nodes.map((node) => node.label)).toEqual(['Zaaksysteem', 'Archief', 'Balie', 'DMS'])
		expect(edges.map((edge) => edge.id)).toEqual(['k1', 'k2', 'k3'])
	})

	it('leaves out a duplicate row and a connection that does not touch the application', () => {
		const { nodes, edges } = connectionMap('x', 'Zaaksysteem', [
			{ id: 'k1', moduleA: x, moduleB: y, type: 'api' },
			{ id: 'k1', moduleA: x, moduleB: y, type: 'api' },
			{ id: 'k9', moduleA: y, moduleB: z, type: 'api' },
			{ id: 'k8', moduleA: x },
		])

		expect(edges.map((edge) => edge.id)).toEqual(['k1'])
		expect(nodes.map((node) => node.id)).toEqual(['x', 'y'])
	})
})

describe('connectionLinks', () => {
	it('names both ends and the transport type of every connection, from the sender', () => {
		const graph = connectionMap('x', 'Zaaksysteem', [
			{ id: 'k1', moduleA: x, moduleB: y, type: 'api', dataExchangeDirection: 'AtoB' },
			{ id: 'k2', moduleA: x, moduleB: z, type: 'digikoppeling', dataExchangeDirection: 'BtoA' },
			{ id: 'k3', moduleA: x, nonMunicipalProvision: { id: 'p', name: 'BRP' }, type: 'webservices', dataExchangeDirection: 'bi-directional' },
		])

		expect(connectionLinks(graph)).toEqual([
			{ id: 'k1', text: 'Zaaksysteem api → DMS' },
			{ id: 'k2', text: 'Archief digikoppeling → Zaaksysteem' },
			{ id: 'k3', text: 'Zaaksysteem webservices ↔ BRP' },
		])
	})

	it('falls back to the id for an end without a node, and to nothing for no graph', () => {
		expect(connectionLinks({ nodes: [], edges: [{ id: 'k9', source: 'a', target: 'b', label: 'api →' }] }))
			.toEqual([{ id: 'k9', text: 'a api → b' }])
		expect(connectionLinks(null)).toEqual([])
	})
})
