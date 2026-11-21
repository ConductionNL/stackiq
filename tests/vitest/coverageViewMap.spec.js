// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the coverage map (REQ-RCC-003): a GEMMA view as canvas nodes
 * with each reference component's coverage on its node, the state in text as
 * well as in a CSS-variable border colour, and no state on other elements.
 *
 * With "Show products" on, the view is fetched with include_products=true and
 * a node lists its linked products.
 *
 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-003-the-coverage-shall-be-drawn-on-a-gemma-view-as-a-map
 * @spec openspec/changes/view-products-enrichment/specs/view-enrichment-api/spec.md#requirement-product-filter-enrichment-must-return-real-product-data
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { coverageMapGraph, viewOptions } from '../../src/utils/referenceComponentCoverage.js'

const http = vi.hoisted(() => ({ get: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => `/index.php${path.replace(/\{(\w+)\}/g, (m, k) => params[k] ?? m)}`,
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m)),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnGraphCanvas: {
		props: ['nodes', 'edges', 'readOnly'],
		template: '<div class="canvas" :data-readonly="String(readOnly)"><div v-for="node in nodes" :key="node.id" class="cell" :data-id="node.id"><slot name="node" :node="node" /></div></div>',
	},
	CnStatusBadge: { props: ['label', 'variant'], template: '<span class="badge" :data-variant="variant">{{ label }}</span>' },
}))

const { default: CoverageViewMap } = await import('../../src/views/organisaties/CoverageViewMap.vue')

const components = [
	{ id: 'u-zrc', identifier: 'id-zrc', name: 'Zaakregistratiecomponent', state: 'overlap', applications: [{ moduleName: 'Mozard' }, { moduleName: 'OpenZaak' }] },
	{ id: 'u-doc', identifier: 'doc', name: 'Documentbeheercomponent', state: 'gap', applications: [] },
	{ id: 'u-fin', identifier: 'id-fin', name: 'Financiële component', state: 'covered', applications: [{ moduleName: 'Key2Financien' }] },
	{ id: 'u-none', identifier: '', name: 'No identifier', state: 'gap', applications: [] },
]

const viewNodes = [
	{ viewNodeId: 'n-child', parent: 'n-group', elementRef: 'zrc', name: 'Zaken', x: 150, y: 260, width: 120, height: 55 },
	{ viewNodeId: 'n-group', parent: null, elementRef: 'id-grp', name: 'Mid-office', x: 100, y: 200, width: 400, height: 300 },
	{ viewNodeId: 'n-doc', elementRef: 'id-doc', name: null, x: '10', y: 'x', width: 80, height: 40 },
	{ viewNodeId: 'n-fin', elementRef: 'id-fin', name: 'Financiën', x: 600, y: 10, width: 120, height: 55 },
	{ viewNodeId: 'n-label', elementRef: null, name: 'Legenda', x: 0, y: 0, width: 50, height: 20 },
	{ name: 'no id' },
]

const viewRelationships = [
	{ viewRelationshipId: 'r1', sourceId: 'n-child', targetId: 'n-doc' },
	{ viewRelationshipId: 'r2', sourceId: 'n-child', targetId: 'n-gone' },
]

describe('coverageMapGraph', () => {
	const graph = coverageMapGraph(viewNodes, viewRelationships, components)
	const byId = Object.fromEntries(graph.nodes.map((node) => [node.id, node]))

	it('lists a parent before its children and drops a node without an id', () => {
		expect(graph.nodes.map((node) => node.id)).toEqual(['n-group', 'n-doc', 'n-fin', 'n-label', 'n-child'])
	})

	it('draws a child relative to its parent and a top-level node where it is', () => {
		expect(byId['n-child'].position).toEqual({ x: 50, y: 60 })
		expect(byId['n-child'].parentNode).toBe('n-group')
		expect(byId['n-fin'].position).toEqual({ x: 600, y: 10 })
		expect(byId['n-fin']).not.toHaveProperty('parentNode')
		expect(byId['n-doc'].position).toEqual({ x: 10, y: 0 })
		expect(byId['n-fin'].style).toEqual({ width: '120px', height: '55px' })
	})

	it('finds a component by its identifier with or without the id- prefix', () => {
		expect(byId['n-child'].data.component.id).toBe('u-zrc')
		expect(byId['n-doc'].data.component.id).toBe('u-doc')
		expect(byId['n-fin'].data.component.state).toBe('covered')
	})

	it('names a node after the component when the view gives it no name', () => {
		expect(byId['n-doc'].data.name).toBe('Documentbeheercomponent')
		expect(byId['n-child'].data.name).toBe('Zaken')
	})

	it('gives a node that is no reference component no coverage', () => {
		expect(byId['n-group'].data.component).toBeNull()
		expect(byId['n-label'].data.component).toBeNull()
	})

	it('keeps only the connections between nodes it draws', () => {
		expect(graph.edges).toEqual([{ id: 'r1', source: 'n-child', target: 'n-doc' }])
	})

	it('carries a node\'s products only when the view was fetched with them', () => {
		expect(byId['n-child'].data.products).toBeNull()

		const enriched = coverageMapGraph([
			{ viewNodeId: 'p1', elementRef: 'zrc', products: [{ name: 'Zaakdienst' }] },
			{ viewNodeId: 'p2', elementRef: 'doc', products: [] },
			{ viewNodeId: 'p3', elementRef: 'fin', products: 'not a list' },
		], [], components)
		expect(enriched.nodes.map((node) => node.data.products)).toEqual([[{ name: 'Zaakdienst' }], [], null])
	})

	it('answers an empty graph for a view without nodes', () => {
		expect(coverageMapGraph(undefined, null, components)).toEqual({ nodes: [], edges: [] })
	})
})

describe('viewOptions', () => {
	it('offers each view with an id by name', () => {
		expect(viewOptions([{ id: 'v2', name: 'Zaakgericht werken' }, { '@self': { id: 'v1' }, title: 'Basis' }, { uuid: 'v3' }, { name: 'no id' }])).toEqual([
			{ id: 'v1', label: 'Basis' },
			{ id: 'v3', label: 'v3' },
			{ id: 'v2', label: 'Zaakgericht werken' },
		])
		expect(viewOptions('not a list')).toEqual([])
	})
})

describe('CoverageViewMap', () => {
	beforeEach(() => {
		http.get.mockReset()
	})

	const stubs = {
		NcEmptyContent: { props: ['name'], template: '<div class="empty">{{ name }}</div>' },
		NcLoadingIcon: { template: '<i class="loading" />' },
		NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
		NcSelect: true,
		NcCheckboxRadioSwitch: {
			props: ['modelValue', 'disabled'],
			emits: ['update:modelValue'],
			template: '<button class="switch" :disabled="disabled" @click="$emit(\'update:modelValue\', !modelValue)"><slot /></button>',
		},
	}

	it('draws the picked view read-only with every component state in text', async () => {
		http.get.mockImplementation((url) => Promise.resolve(url.endsWith('/api/views')
			? { data: { views: [{ id: 'v1', name: 'Mid-office' }] } }
			: { data: { success: true, view: { viewNodes, viewRelationships } } }))

		const wrapper = mount(CoverageViewMap, { props: { components }, global: { stubs } })
		await flushPromises()
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/stackiq/api/views')
		expect(wrapper.find('.empty').text()).toBe('Select a view')

		wrapper.vm.selectedView = { id: 'v1', label: 'Mid-office' }
		await wrapper.vm.loadView()
		await flushPromises()

		expect(http.get).toHaveBeenLastCalledWith('/index.php/apps/stackiq/api/views/v1', { params: {} })
		expect(wrapper.find('.canvas').attributes('data-readonly')).toBe('true')
		expect(wrapper.find('[data-testid="coverage-view-map-node-products"]').exists()).toBe(false)

		const zrc = wrapper.find('[data-id="n-child"]')
		expect(zrc.find('[data-state]').attributes('data-state')).toBe('overlap')
		expect(zrc.find('.badge').text()).toBe('Overlap')
		expect(zrc.text()).toContain('Applications (2): Mozard, OpenZaak')

		const doc = wrapper.find('[data-id="n-doc"]')
		expect(doc.find('.badge').text()).toBe('Gap')
		expect(doc.find('.badge').attributes('data-variant')).toBe('error')
		expect(doc.text()).toContain('No application')

		const fin = wrapper.find('[data-id="n-fin"]')
		expect(fin.find('.badge').text()).toBe('Covered')
		expect(fin.find('.coverage-view-map__node--covered').exists()).toBe(true)

		const group = wrapper.find('[data-id="n-group"]')
		expect(group.find('.badge').exists()).toBe(false)
		expect(group.find('[data-state]').exists()).toBe(false)
	})

	it('fetches the view with include_products when Show products is on, and lists the products per node', async () => {
		const productNodes = [
			{ viewNodeId: 'n-zrc', elementRef: 'id-zrc', modelNodeId: 'id-zrc', name: 'Zaken', products: [{ name: 'Zaakdienst' }, { title: 'Archiefdienst' }] },
			{ viewNodeId: 'n-doc', elementRef: 'id-doc', modelNodeId: 'id-doc', name: 'Documenten', products: [] },
			{ viewNodeId: 'n-label', elementRef: null, name: 'Legenda' },
		]
		http.get.mockImplementation((url, config) => Promise.resolve(url.endsWith('/api/views')
			? { data: { views: [{ id: 'v1', name: 'Mid-office' }] } }
			: { data: { success: true, view: { viewNodes: config?.params?.include_products ? productNodes : viewNodes, viewRelationships: [] } } }))

		const wrapper = mount(CoverageViewMap, { props: { components }, global: { stubs } })
		await flushPromises()
		expect(wrapper.find('.switch').attributes('disabled')).toBeDefined()

		wrapper.vm.selectedView = { id: 'v1', label: 'Mid-office' }
		await flushPromises()
		await wrapper.find('.switch').trigger('click')
		await flushPromises()

		expect(http.get).toHaveBeenLastCalledWith('/index.php/apps/stackiq/api/views/v1', { params: { include_products: true } })
		const products = wrapper.findAll('[data-testid="coverage-view-map-node-products"]')
		expect(products.map((node) => node.text())).toEqual(['Products (2): Zaakdienst, Archiefdienst', 'No product'])
		expect(wrapper.find('[data-id="n-label"]').text()).toBe('Legenda')

		await wrapper.find('.switch').trigger('click')
		await flushPromises()
		expect(http.get).toHaveBeenLastCalledWith('/index.php/apps/stackiq/api/views/v1', { params: {} })
		expect(wrapper.find('[data-testid="coverage-view-map-node-products"]').exists()).toBe(false)
	})

	it('says so when the view cannot be loaded', async () => {
		http.get.mockImplementation((url) => (url.endsWith('/api/views')
			? Promise.resolve({ data: { views: [] } })
			: Promise.reject({ response: { data: { error: "View with ID 'v9' not found" } } })))

		const wrapper = mount(CoverageViewMap, { props: { components }, global: { stubs } })
		wrapper.vm.selectedView = { id: 'v9', label: 'Gone' }
		await wrapper.vm.loadView()
		await flushPromises()

		expect(wrapper.find('.note').text()).toBe("View with ID 'v9' not found")
		expect(wrapper.find('.canvas').exists()).toBe(false)
	})

	it('colours the states with the theme variables only', () => {
		const here = dirname(fileURLToPath(import.meta.url))
		const source = readFileSync(resolve(here, '../../src/views/organisaties/CoverageViewMap.vue'), 'utf8')
		const style = source.slice(source.indexOf('<style'))

		expect(style).toMatch(/__node--gap\s*\{\s*border-color: var\(--color-error\);/)
		expect(style).toMatch(/__node--covered\s*\{\s*border-color: var\(--color-success\);/)
		expect(style).toMatch(/__node--overlap\s*\{\s*border-color: var\(--color-warning\);/)
		expect(style).not.toMatch(/#[0-9a-fA-F]{3,6}\b|rgb\(/)
	})
})
