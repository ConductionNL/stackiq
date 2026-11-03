/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for getThemeVariables: which CSS variables it reads, in which
 * order, and under which keys it returns them. Recorded with RECORD_GOLDEN=1.
 *
 * @vitest-environment jsdom
 * @spec openspec/specs/fe-stores/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { getThemeVariables } from '../../src/services/getTheme.js'

const GOLDEN = path.resolve(import.meta.dirname, 'golden/themeVariables.json')

describe('getThemeVariables', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('reads every theme variable of the document root', () => {
		const reads = []
		vi.spyOn(window, 'getComputedStyle').mockImplementation((element) => ({
			getPropertyValue: (name) => {
				reads.push(name)
				return `value of ${name} on ${element.tagName}`
			},
		}))
		const result = { variables: getThemeVariables(), reads }
		if (process.env.RECORD_GOLDEN) {
			writeFileSync(GOLDEN, JSON.stringify(result, null, '\t') + '\n')
			return
		}
		expect(JSON.stringify(result)).toBe(
			JSON.stringify(JSON.parse(readFileSync(GOLDEN, 'utf8'))),
		)
	})
})
