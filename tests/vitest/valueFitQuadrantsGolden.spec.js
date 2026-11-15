/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the four quadrant rectangles of the value/fit plot,
 * through two different scales. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/application-value-assessment/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
vi.mock('@nextcloud/vue', () => ({ NcNoteCard: {}, NcLoadingIcon: {} }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnIcon: {}, CnWidgetWrapper: {} }))

const { default: ValueFitPlot } =
	await import('../../src/components/portfolio/ValueFitPlot.vue')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/valueFitQuadrants.json')

const SCALES = {
	linear: { scaleX: (v) => 40 + v * 60, scaleY: (v) => 340 - v * 60 },
	odd: { scaleX: (v) => v * v - 1, scaleY: (v) => 7 / (v + 1) },
}

describe('ValueFitPlot quadrants golden', () => {
	it('matches the recorded rectangles', () => {
		const actual = {}
		for (const [name, scale] of Object.entries(SCALES)) {
			actual[name] = ValueFitPlot.computed.quadrants.call(scale)
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(actual, null, '\t') + '\n')
		}
		expect(JSON.stringify(actual)).toBe(
			JSON.stringify(JSON.parse(readFileSync(GOLDEN, 'utf8'))),
		)
	})
})
