/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/architecture-shared-usage-on-views/specs/shared-usage-on-views/spec.md#requirement-req-suv-002-a-shared-application-must-be-drawn-apart-from-an-own-application-without-relying-on-colour
 */

import { describe, expect, it } from 'vitest'
import { overlayRows, USAGE_ROW_HEIGHT, usageOverlayNodes } from '../../src/utils/usageOverlay.js'

const t = (app, text, vars = {}) => text.replace(/{(\w+)}/g, (_, key) => String(vars[key] ?? ''))

const component = {
	id: 'ref-1',
	usage: [{ '@self': { id: 'u-1' }, module: 'm-1', moduleName: 'Zaaksysteem' }],
	deelnamesGebruik: [{ '@self': { id: 'u-2' }, module: 'm-2', moduleName: 'Belastingen', _sourceOrganization: 'Gemeente Noord' }],
}
const both = { own: true, shared: true }

describe('usageOverlayNodes', () => {
	it('nests one own and one shared node inside the component', () => {
		const nodes = usageOverlayNodes(component, both, { width: 200, height: 120, t })
		expect(nodes).toHaveLength(2)
		for (const node of nodes) {
			expect(node.parentNode).toBe('ref-1')
			expect(node.extent).toBe('parent')
		}
		expect(nodes.map((node) => node.id)).toEqual(['ref-1::usage::u-1', 'ref-1::usage::u-2'])
	})

	it('marks a shared node by class, badge and accessible name, not by colour', () => {
		const [own, shared] = usageOverlayNodes(component, both, { width: 200, height: 120, t })
		expect(shared.class).toContain('usage-node--shared')
		expect(shared.data.badge).toBe('Shared')
		expect(shared.data.ariaLabel).toBe('Belastingen, shared by Gemeente Noord')
		expect(shared.data.partner).toBe('Gemeente Noord')
		expect(own.class).toContain('usage-node--own')
		expect(own.data.badge).toBeNull()
		expect(own.data.ariaLabel).toBe('Zaaksysteem')
	})

	it('draws an application both owned and shared once, as owned', () => {
		const node = { ...component, deelnamesGebruik: [{ module: 'm-1', moduleName: 'Zaaksysteem', _sourceOrganization: 'X' }] }
		const nodes = usageOverlayNodes(node, both, { width: 200, height: 120, t })
		expect(nodes).toHaveLength(1)
		expect(nodes[0].data.shared).toBe(false)
	})

	it('ends with "+N more" when the usages do not fit the component', () => {
		const many = { id: 'ref-2', usage: Array.from({ length: 6 }, (_, i) => ({ id: 'u' + i, module: 'm' + i, moduleName: 'App ' + i })) }
		const height = 32 + 6 + (3 * USAGE_ROW_HEIGHT)
		const nodes = usageOverlayNodes(many, both, { width: 200, height, t })
		expect(nodes).toHaveLength(3)
		expect(nodes[2].data.label).toBe('+4 more')
		expect(nodes[2].data.more).toBe(4)
		expect(nodes.map((node) => node.position.y)).toEqual([height - 6 - 84, height - 6 - 56, height - 6 - 28])
	})

	it('follows the switches, and draws nothing without a component id or usages', () => {
		expect(usageOverlayNodes(component, { own: true, shared: false }, { width: 200, height: 120, t }).map((node) => node.data.label)).toEqual(['Zaaksysteem'])
		expect(usageOverlayNodes(component, { own: false, shared: true }, { width: 200, height: 120, t }).map((node) => node.data.label)).toEqual(['Belastingen'])
		expect(usageOverlayNodes({ ...component, id: '' }, both, { width: 200, height: 120, t })).toEqual([])
		expect(usageOverlayNodes({ id: 'x' }, both, { width: 200, height: 120, t })).toEqual([])
	})
})

describe('overlayRows', () => {
	it('lists own first, then shared', () => {
		expect(overlayRows(component, both).map((row) => row.shared)).toEqual([false, true])
		expect(overlayRows(component, { own: false, shared: false })).toEqual([])
	})
})
