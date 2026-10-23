/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The GEMMA plate's filters and grouping, and the page as the app builds it.
 *
 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-002-a-coverage-page-shall-list-the-components-with-filters-for-gaps-and-overlaps
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import { buildManifest } from '../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js'
import base from '../../src/manifest.json'
import menuLayout from '../../src/menu-layout.json'
import { filterCoverage, groupCoverage } from '../../src/utils/referenceComponentCoverage.js'

const rows = [
	{ id: 'kb', name: 'Kennisbankcomponent', group: 'Front-office', state: 'gap', fillable: false },
	{ id: 'zrc', name: 'Zaakregistratiecomponent', group: 'Mid-office', state: 'overlap', fillable: false },
	{ id: 'doc', name: 'Documentbeheercomponent', group: 'Mid-office', state: 'gap', fillable: true },
	{ id: 'x', name: 'Ongegroepeerd', group: '', state: 'covered', fillable: false },
]

describe('GEMMA plate filters', () => {
	it('keeps gaps, fillable gaps or overlaps', () => {
		expect(filterCoverage(rows, 'gap').map((r) => r.id)).toEqual(['kb', 'doc'])
		expect(filterCoverage(rows, 'fillable').map((r) => r.id)).toEqual(['doc'])
		expect(filterCoverage(rows, 'overlap').map((r) => r.id)).toEqual(['zrc'])
		expect(filterCoverage(rows, 'all')).toHaveLength(4)
	})

	it('groups by the GEMMA grouping, ungrouped last', () => {
		expect(groupCoverage(rows, 'Other').map((g) => [g.name, g.components.map((c) => c.id)])).toEqual([
			['Front-office', ['kb']],
			['Mid-office', ['zrc', 'doc']],
			['Other', ['x']],
		])
	})
})

describe('GEMMA plate page (board SkGemmaPlaat)', () => {
	const dir = path.resolve(__dirname, '../../src/manifest.d')
	const merged = buildManifest(
		base,
		fs.readdirSync(dir).filter((f) => f.endsWith('.json')).sort()
			.map((f) => JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8'))),
		menuLayout,
	)

	it('is a custom page rendered by GemmaPlateView, under Reports & Compliance', () => {
		expect(merged.pages.find((p) => p.id === 'GemmaPlate')).toMatchObject({ route: '/gemma-plaat', type: 'custom', component: 'GemmaPlateView' })
		const group = merged.menu.find((m) => m.id === 'ReportsCompliance')
		expect((group.children || []).map((c) => c.id)).toContain('GemmaPlate')
		const source = fs.readFileSync(path.resolve(__dirname, '../../src/customComponents.js'), 'utf8')
		expect(source).toMatch(/^\tGemmaPlateView,$/m)
	})
})
