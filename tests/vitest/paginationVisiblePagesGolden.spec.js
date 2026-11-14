/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for which page numbers and ellipses the pagination shows for
 * every current page around every total up to well past the collapse point.
 * Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcSelect: {} }))

const { default: PaginationComponent } =
	await import('../../src/components/PaginationComponent.vue')

const GOLDEN = path.resolve(
	import.meta.dirname,
	'golden/paginationVisiblePages.json',
)

describe('PaginationComponent visiblePages golden', () => {
	it('matches the recorded outcomes', () => {
		const lines = []
		const actual = {}
		for (const total of [0, 1, 2, 6, 7, 7.5, 8, 9, 10, 12, 20]) {
			for (let current = -1; current <= total + 2; current++) {
				const key = 'total ' + total + ' current ' + current
				actual[key] = PaginationComponent.computed.visiblePages.call({
					currentPage: current,
					totalPages: total,
				})
				lines.push(JSON.stringify(key) + ': ' + JSON.stringify(actual[key]))
			}
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, '{\n' + lines.join(',\n') + '\n}\n')
		}
		expect(actual).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
