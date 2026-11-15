// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the schema configuration rows of the OpenRegister
 * integration settings: the voorzieningen and AMEF lists (key, title,
 * description, in order) that the two schema tabs render one select for.
 * Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-settings-ui/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import OpenRegisterIntegration from '../../src/views/settings/sections/OpenRegisterIntegration.vue'

vi.mock('../../src/store/store.js', () => ({ settingsStore: {} }))

const GOLDEN = path.resolve(
	import.meta.dirname,
	'golden/openRegisterIntegrationItems.json',
)

const actual = {
	voorzieningenItems: OpenRegisterIntegration.computed.voorzieningenItems.call({}),
	amefItems: OpenRegisterIntegration.computed.amefItems.call({}),
}

if (process.env.RECORD_GOLDEN) {
	writeFileSync(GOLDEN, JSON.stringify(actual, null, '\t') + '\n')
}

const expected = JSON.parse(readFileSync(GOLDEN, 'utf8'))

describe('OpenRegisterIntegration schema rows', () => {
	for (const list of Object.keys(expected)) {
		it(`${list} matches the golden`, () => {
			expect(actual[list]).toEqual(expected[list])
		})
	}

	it('covers both lists', () => {
		expect(Object.keys(actual)).toEqual(Object.keys(expected))
	})
})
