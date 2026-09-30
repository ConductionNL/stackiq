/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The value assessment: risk signals on the usage page (the version's end of
 * support and the application's vulnerabilities), the value against fit plot
 * and the mismatch filter of the portfolio report, and the page wiring.
 *
 * @spec openspec/specs/application-value-assessment/spec.md
 */
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import { buildManifest } from '../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js'
import base from '../../src/manifest.json'
import menuLayout from '../../src/menu-layout.json'
import {
	mismatchRows,
	riskSignals,
	valueFitPoints,
} from '../../src/utils/valueAssessment.js'

const now = new Date('2026-10-01T09:00:00Z')

describe('riskSignals', () => {
	it('shows end of support passed and two vulnerabilities of the application', () => {
		const version = { dateEndSupport: '2026-06-30' }
		const vulnerabilities = [
			{ id: 'v1', modules: ['app-x'] },
			{ id: 'v2', modules: [{ id: 'app-x' }, 'app-y'] },
			{ id: 'v3', modules: ['app-y'] },
		]
		expect(riskSignals(version, vulnerabilities, 'app-x', now)).toEqual({
			endOfSupportPassed: true,
			endOfSupportDate: '2026-06-30',
			withdrawn: false,
			vulnerabilityCount: 2,
		})
	})

	it('reads a version that is still supported and an application without vulnerabilities', () => {
		const signals = riskSignals(
			{ object: { dateEndSupport: '2027-12-31' } },
			[],
			'app-x',
			now,
		)
		expect(signals.endOfSupportPassed).toBe(false)
		expect(signals.vulnerabilityCount).toBe(0)
	})

	it('does not throw without a version', () => {
		expect(riskSignals(null, null, 'app-x', now)).toEqual({
			endOfSupportPassed: false,
			endOfSupportDate: null,
			withdrawn: false,
			vulnerabilityCount: 0,
		})
	})
})

const rows = [
	{ uuid: 'a', moduleName: 'A', businessValue: 1, technicalFit: 2, annualisedCost: 1000, timeClassification: 'Tolerate', suggestedTimeClassification: 'Eliminate', timeMismatch: true },
	{ uuid: 'b', moduleName: 'B', businessValue: 5, technicalFit: 4, annualisedCost: 50000, timeClassification: 'Invest', suggestedTimeClassification: 'Invest', timeMismatch: false },
	{ uuid: 'c', moduleName: 'C', businessValue: 4, technicalFit: 4, annualisedCost: 0, timeClassification: null, suggestedTimeClassification: 'Invest', timeMismatch: false },
	{ uuid: 'd', moduleName: 'D', businessValue: null, technicalFit: 3, annualisedCost: 200, timeClassification: 'Tolerate', suggestedTimeClassification: null, timeMismatch: false },
]

describe('valueFitPoints', () => {
	it('gives one point per scored usage and counts the rest as not scored', () => {
		const { points, notScored } = valueFitPoints(rows)
		expect(points.map((p) => p.uuid)).toEqual(['a', 'b', 'c'])
		expect(notScored).toBe(1)
		expect(points[0]).toMatchObject({ fit: 2, value: 1, label: 'A' })
	})

	it('sizes the points by annualised cost, largest cost largest point', () => {
		const { points } = valueFitPoints(rows)
		const radius = Object.fromEntries(points.map((p) => [p.uuid, p.radius]))
		expect(radius.b).toBeGreaterThan(radius.a)
		expect(radius.a).toBeGreaterThan(radius.c)
		expect(radius.c).toBeGreaterThan(0)
	})

	it('spreads points that share a cell so each stays visible', () => {
		const { points } = valueFitPoints([
			{ uuid: 'x', businessValue: 3, technicalFit: 3, annualisedCost: 0 },
			{ uuid: 'y', businessValue: 3, technicalFit: 3, annualisedCost: 0 },
		])
		expect(points[0].offset).not.toEqual(points[1].offset)
	})
})

describe('mismatchRows', () => {
	it('keeps only the usages whose recorded class differs from the scores', () => {
		expect(mismatchRows(rows).map((r) => r.uuid)).toEqual(['a'])
		expect(mismatchRows(null)).toEqual([])
	})
})

describe('the usage page', () => {
	const dir = path.resolve(__dirname, '../../src/manifest.d')
	const merged = buildManifest(
		base,
		fs
			.readdirSync(dir)
			.filter((f) => f.endsWith('.json'))
			.sort()
			.map((f) => JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8'))),
		menuLayout,
	)
	const page = merged.pages.find((p) => p.id === 'GebruikDetail')

	it('shows the scores and the suggested class in their own section', () => {
		const assessment = page.config.widgets.find((w) => w.id === 'gb-assessment')
		expect(assessment.content.include).toEqual(
			expect.arrayContaining([
				'businessValue',
				'technicalFit',
				'riskScore',
				'scoredOn',
				'timeClassification',
				'suggestedTimeClassification',
			]),
		)
		expect(page.config.layout.some((l) => l.widgetId === 'gb-assessment')).toBe(true)
	})

	it('places the risk signals after the data and registers the component', () => {
		const signals = page.config.bodyWidgets.find(
			(w) => w.component === 'UsageRiskSignals',
		)
		expect(signals.props).toEqual({ objectId: '@objectId' })
		expect(signals.placement).toBe('after-data')
		const registry = fs.readFileSync(
			path.resolve(__dirname, '../../src/customComponents.js'),
			'utf8',
		)
		expect(registry).toMatch(/^\s*UsageRiskSignals,$/m)
	})
})
