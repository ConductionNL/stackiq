// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the value/fit plot, mounted: the quadrant labels, one
 * circle per scored usage placed on the scale with its quadrant colour and
 * mismatch ring, the hover title, the accessible label and the not-scored
 * note. The quadrant rectangles are pinned by valueFitQuadrantsGolden.spec.js.
 *
 * @spec openspec/specs/application-value-assessment/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => String(text).replace(/\{(\w+)\}/g, (match, key) => (vars && key in vars ? String(vars[key]) : match)),
	translatePlural: (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count)),
}))

const { default: ValueFitPlot } =
	await import('../../src/components/portfolio/ValueFitPlot.vue')

const rows = [
	{ uuid: 'u-1', moduleName: 'Zaaksysteem', businessValue: 5, technicalFit: 5, annualisedCost: 40000, suggestedTimeClassification: 'Invest', timeClassification: 'Invest' },
	{ uuid: 'u-2', moduleName: 'Oud systeem', businessValue: 1, technicalFit: 1, annualisedCost: 10000, suggestedTimeClassification: 'Eliminate', timeClassification: 'Tolerate' },
	{ uuid: 'u-3', moduleName: 'Nieuw', businessValue: 3, technicalFit: 2, annualisedCost: 0 },
	{ uuid: 'u-4', moduleName: 'Unscored', businessValue: null, technicalFit: 3 },
	{ uuid: 'u-5', moduleName: 'Half', businessValue: 2.5, technicalFit: 3 },
]

describe('ValueFitPlot', () => {
	it('labels the four quadrants and both axes', () => {
		const wrapper = mount(ValueFitPlot, { props: { rows } })
		expect(wrapper.findAll('.value-fit__quadrantLabel').map((l) => l.text())).toEqual(['Migrate', 'Invest', 'Eliminate', 'Tolerate'])
		expect(wrapper.findAll('.value-fit__axis').map((a) => a.text())).toEqual(['Technical fit', 'Business value'])
		expect(wrapper.findAll('.value-fit__tick').map((t) => t.text())).toEqual(['1', '1', '2', '2', '3', '3', '4', '4', '5', '5'])
	})

	it('draws one circle per scored usage at its scaled position', () => {
		const wrapper = mount(ValueFitPlot, { props: { rows } })
		const circles = wrapper.findAll('[data-testid="pr-value-fit-point"]')
		expect(circles).toHaveLength(3)
		// Plot area: x 48..496 (448 wide), y 16..356 (340 high), five units each.
		expect(circles.map((c) => [Number(c.attributes('cx')), Number(c.attributes('cy'))])).toEqual([
			[48 + (4.5 / 5) * 448, 16 + (0.5 / 5) * 340],
			[48 + (0.5 / 5) * 448, 16 + (4.5 / 5) * 340],
			[48 + (1.5 / 5) * 448, 16 + (2.5 / 5) * 340],
		])
		expect(circles.map((c) => Number(c.attributes('r')))).toEqual([22, 6 + Math.sqrt(0.25) * 16, 6])
	})

	it('colours by suggested quadrant and rings a differing recorded class', () => {
		const wrapper = mount(ValueFitPlot, { props: { rows } })
		const circles = wrapper.findAll('[data-testid="pr-value-fit-point"]')
		expect(circles.map((c) => c.attributes('fill'))).toEqual([
			'var(--color-success, #46ba61)',
			'var(--color-error, #e04224)',
			'var(--color-border-dark, #949494)',
		])
		expect(circles.map((c) => c.attributes('stroke'))).toEqual(['none', 'var(--color-main-text)', 'none'])
	})

	it('titles each circle with its scores and cost', () => {
		const wrapper = mount(ValueFitPlot, { props: { rows } })
		const titles = wrapper.findAll('[data-testid="pr-value-fit-point"] title').map((t) => t.text().replace(/\s/g, ' '))
		expect(titles[0]).toMatch(/^Zaaksysteem: business value 5, technical fit 5, annualised cost € 40\.000$/)
		expect(titles[2]).toBe('Nieuw: business value 3, technical fit 2, annualised cost —')
	})

	it('counts the scored and not-scored usages', () => {
		const wrapper = mount(ValueFitPlot, { props: { rows } })
		expect(wrapper.find('svg').attributes('aria-label')).toBe('Business value against technical fit for 3 scored applications in use')
		expect(wrapper.find('[data-testid="pr-value-fit-not-scored"]').text()).toBe('2 applications in use are not scored yet.')
	})

	it('hides the not-scored note and uses the singular when every usage is scored', () => {
		const wrapper = mount(ValueFitPlot, { props: { rows: [rows[0]] } })
		expect(wrapper.find('svg').attributes('aria-label')).toBe('Business value against technical fit for 1 scored application in use')
		expect(wrapper.find('[data-testid="pr-value-fit-not-scored"]').exists()).toBe(false)
	})

	it('falls back to the key for an unknown quadrant label', () => {
		expect(ValueFitPlot.methods.quadrantLabel('Other')).toBe('Other')
	})
})
