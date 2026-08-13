/**
 * Unit tests for the annualised-cost contract utility.
 *
 * @spec openspec/changes/contract-administration/specs/contract-administration/spec.md
 */

import { describe, it, expect } from 'vitest'
import {
	PERIOD,
	parseAmount,
	annualisedCost,
	totalAnnualisedCost,
	isOneOff,
} from '../../src/utils/contractCost.js'

describe('contractCost.parseAmount', () => {
	it('reads numbers and numeric strings', () => {
		expect(parseAmount(1000)).toBe(1000)
		expect(parseAmount('1000')).toBe(1000)
		expect(parseAmount('1000,50')).toBe(1000.5)
	})
	it('returns null for non-numeric', () => {
		expect(parseAmount('')).toBeNull()
		expect(parseAmount(null)).toBeNull()
		expect(parseAmount('abc')).toBeNull()
	})
})

describe('contractCost.annualisedCost', () => {
	it('annualises a monthly contract ×12', () => {
		expect(
			annualisedCost({ kosten: 1000, kostenPeriode: PERIOD.MONTHLY }),
		).toEqual({ annual: 12000, oneOff: 0 })
	})
	it('passes a yearly contract through ×1', () => {
		expect(
			annualisedCost({ kosten: 6000, kostenPeriode: PERIOD.YEARLY }),
		).toEqual({ annual: 6000, oneOff: 0 })
	})
	it('excludes a one-off from the annual figure', () => {
		expect(
			annualisedCost({ kosten: 5000, kostenPeriode: PERIOD.ONEOFF }),
		).toEqual({ annual: 0, oneOff: 5000 })
	})
	it('yields zeros for unknown period or unparseable amount', () => {
		expect(annualisedCost({ kosten: 100, kostenPeriode: 'Wekelijks' })).toEqual({
			annual: 0,
			oneOff: 0,
		})
		expect(
			annualisedCost({ kosten: 'x', kostenPeriode: PERIOD.MONTHLY }),
		).toEqual({ annual: 0, oneOff: 0 })
		expect(annualisedCost({})).toEqual({ annual: 0, oneOff: 0 })
	})
	it('reads OR object envelopes', () => {
		expect(
			annualisedCost({
				object: { kosten: 1000, kostenPeriode: PERIOD.MONTHLY },
			}),
		).toEqual({ annual: 12000, oneOff: 0 })
	})
})

describe('contractCost.totalAnnualisedCost', () => {
	it('sums annual and one-off separately across contracts', () => {
		const contracts = [
			{ kosten: 1000, kostenPeriode: PERIOD.MONTHLY }, // 12000 annual
			{ kosten: 6000, kostenPeriode: PERIOD.YEARLY }, // 6000 annual
			{ kosten: 5000, kostenPeriode: PERIOD.ONEOFF }, // 5000 one-off
		]
		expect(totalAnnualisedCost(contracts)).toEqual({
			annual: 18000,
			oneOff: 5000,
		})
	})
	it('handles an empty list', () => {
		expect(totalAnnualisedCost([])).toEqual({ annual: 0, oneOff: 0 })
		expect(totalAnnualisedCost(undefined)).toEqual({ annual: 0, oneOff: 0 })
	})
})

describe('contractCost.isOneOff', () => {
	it('detects Eenmalig', () => {
		expect(isOneOff({ kostenPeriode: PERIOD.ONEOFF })).toBe(true)
		expect(isOneOff({ kostenPeriode: PERIOD.MONTHLY })).toBe(false)
	})
})
