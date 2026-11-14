// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The read-only step flow of a business process (REQ-BPM-004).

import { describe, expect, it } from 'vitest'
import { buildStepFlow, orderSteps, stepNeedsAttention } from '../../src/utils/processStepFlow.js'

const step = (id, position, extra = {}) => ({ id, name: 'Step ' + id, position, ...extra })

describe('processStepFlow', () => {
	it('runs edges in position order when no step names what it follows', () => {
		const { edges, nodes } = buildStepFlow([step('c', 3), step('a', 1), step('b', 2)])
		expect(edges.map((edge) => [edge.source, edge.target])).toEqual([['a', 'b'], ['b', 'c']])
		expect(nodes.map((node) => node.id)).toEqual(['a', 'b', 'c'])
		const x = nodes.map((node) => node.position.x)
		expect(x[0]).toBeLessThan(x[1])
		expect(x[1]).toBeLessThan(x[2])
	})

	it('a step that follows two steps has two incoming edges', () => {
		const { edges } = buildStepFlow([
			step('intake', 1),
			step('check-a', 2),
			step('check-b', 2, { follows: ['intake'] }),
			step('decide', 3, { follows: ['check-a', 'check-b'] }),
		])
		expect(edges.filter((edge) => edge.target === 'decide').map((edge) => edge.source)).toEqual(['check-a', 'check-b'])
		expect(edges.filter((edge) => edge.target === 'check-a').map((edge) => edge.source)).toEqual(['intake'])
	})

	it('ignores a follows entry that is not a step of this process', () => {
		const { edges } = buildStepFlow([step('a', 1), step('b', 2, { follows: ['elsewhere'] })])
		expect(edges.map((edge) => [edge.source, edge.target])).toEqual([['a', 'b']])
	})

	it('flags a high risk or a failed compliance check, and names the applications', () => {
		const { nodes } = buildStepFlow(
			[
				step('a', 1, { riskLevel: 'high', usages: [{ id: 'u1' }, 'u2'] }),
				step('b', 2, { complianceCheck: 'not compliant' }),
				step('c', 3, { riskLevel: 'medium', complianceCheck: 'compliant' }),
			],
			{ u1: 'TOPdesk', u2: 'Zaaksysteem' },
		)
		expect(nodes.map((node) => node.data.needsAttention)).toEqual([true, true, false])
		expect(nodes[0].data.applications).toEqual(['TOPdesk', 'Zaaksysteem'])
		expect(nodes[2].data).toMatchObject({ stepType: 'task', riskLevel: 'medium', complianceCheck: 'compliant' })
	})

	it('orders steps of the same position by name and drops ones without an id', () => {
		expect(orderSteps([step('b', 1, { name: 'Zeta' }), step('a', 1, { name: 'Alpha' }), { name: 'no id', position: 0 }]).map((s) => s.id)).toEqual(['a', 'b'])
		expect(stepNeedsAttention({ riskLevel: 'low', complianceCheck: 'not checked' })).toBe(false)
		expect(buildStepFlow([])).toEqual({ nodes: [], edges: [] })
	})
})
