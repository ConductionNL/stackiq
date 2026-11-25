/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The scenario pages in the merged manifest (architecture-future-state-scenarios,
 * task 4): Scenarios under the Architecture group, the scenario page with its
 * changes and comparison, and the comparison page the roadmap opens.
 *
 * @spec openspec/specs/future-state-scenarios/spec.md#requirement-req-fss-003-an-information-manager-shall-write-a-scenario-of-additions-phase-outs-and-replacements
 */
import addFormats from 'ajv-formats'
import Ajv2020 from 'ajv/dist/2020.js'
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import manifestSchema from '../../node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json'
import { buildManifest } from '../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js'
import base from '../../src/manifest.json'
import menuLayout from '../../src/menu-layout.json'

const dir = path.resolve(__dirname, '../../src/manifest.d')
const fragments = fs.readdirSync(dir)
	.filter((f) => f.endsWith('.json'))
	.sort()
	.map((f) => JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8')))
const merged = buildManifest(base, fragments, menuLayout)
const page = (id) => merged.pages.find((p) => p.id === id)

/**
 * Every menu entry, top level and nested, with its parent id.
 *
 * @param {Array<object>} entries The menu.
 * @param {string|null} parent The parent id.
 * @return {Array<{id: string, parent: (string|null)}>} The entries.
 */
function flatten(entries, parent = null) {
	return (entries || []).flatMap((entry) => [{ id: entry.id, parent }, ...flatten(entry.children, entry.id)])
}

describe('the scenario pages', () => {
	it('keeps the merged manifest valid', () => {
		const ajv = new Ajv2020({ allErrors: true, strict: false })
		addFormats(ajv)
		const validate = ajv.compile(manifestSchema)
		validate(merged)
		expect(validate.errors ?? []).toEqual([])
	})

	it('lists scenarios at /scenarios with the lifecycle quick filters', () => {
		const scenarios = page('Scenarios')
		expect(scenarios).toMatchObject({ type: 'index', route: '/scenarios' })
		expect(scenarios.config.schema).toBe('scenario')
		expect(scenarios.config.columns).toEqual(['name', 'organisation', 'targetDate', 'status'])
		expect(scenarios.config.quickFilters.map((q) => [q.label, q.filter])).toEqual([
			['All', {}],
			['Draft', { status: 'draft' }],
			['Proposed', { status: 'proposed' }],
			['Adopted', { status: 'adopted' }],
		])
		expect(scenarios.config.rowRoute).toBe('ScenarioDetail')
	})

	it('puts Scenarios under the Architecture group, not at the top level', () => {
		const entry = flatten(merged.menu).find((e) => e.id === 'Scenarios')
		expect(entry).toEqual({ id: 'Scenarios', parent: 'Architecture' })
	})

	it('shows a scenario with its data, its changes, the comparison and the lifecycle actions', () => {
		const detail = page('ScenarioDetail')
		expect(detail).toMatchObject({ type: 'detail', route: '/scenarios/:id' })
		expect(detail.config.lifecycleActions).toEqual({ field: 'status' })

		const changes = detail.config.widgets.find((w) => w.id === 'sc-changes')
		expect(changes.type).toBe('object-list')
		expect(changes.content.schema).toBe('scenarioChange')
		expect(changes.content.filter).toEqual({ scenario: '@objectId' })
		expect(changes.content.columns).toEqual(['action', 'usage', 'module', 'effectiveDate'])

		expect(detail.config.bodyWidgets).toEqual([
			expect.objectContaining({ component: 'LandscapeComparisonView', props: { objectId: '@objectId' } }),
		])
		expect(detail.config.sidebar.tabs.map((tab) => tab.id)).toContain('audit')
	})

	it('offers the comparison page the roadmap opens, over a registered widget', () => {
		const comparison = page('LandscapeComparison')
		expect(comparison).toMatchObject({ type: 'dashboard', route: '/landscape-comparison' })
		expect(comparison.config.widgets.map((w) => w.type)).toEqual(['landscape-comparison'])
		const main = fs.readFileSync(path.resolve(__dirname, '../../src/main.js'), 'utf8')
		expect(main).toMatch(/registerDashboardWidget\('landscape-comparison', \{\n\trenderer: LandscapeComparisonView,/)
		const registry = fs.readFileSync(path.resolve(__dirname, '../../src/customComponents.js'), 'utf8')
		expect(registry).toMatch(/^\tLandscapeComparisonView,$/m)
	})
})
