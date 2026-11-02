/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the settings store action that selects the voorzieningen
 * and AMEF registers from the focused config answers. Each case records the
 * register selections and schema lists afterwards. Recorded with
 * RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-stores/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { createPinia, setActivePinia } from 'pinia'
import { afterAll, describe, expect, it } from 'vitest'
import { useSettingsStore } from '../../src/store/modules/settings.js'

const GOLDEN = path.resolve(
	import.meta.dirname,
	'golden/settingsRegisterSelections.json',
)
const recorded = {}
const expected = process.env.RECORD_GOLDEN
	? {}
	: JSON.parse(readFileSync(GOLDEN, 'utf8'))

const REGISTERS = [
	{ id: 3, title: 'Voorzieningen', schemas: [{ id: 30 }] },
	{ id: 4, name: 'Amef' },
	{ id: '5', schemas: [] },
]

const CASES = {
	'no raw configs': {},
	'null raw configs': { voorzieningenRawConfig: null, amefRawConfig: null },
	'raw configs without register': {
		voorzieningenRawConfig: { register: '' },
		amefRawConfig: { register: 0, register_id: '' },
	},
	'numeric register ids': {
		voorzieningenRawConfig: { register: 3 },
		amefRawConfig: { register: 4 },
	},
	'string ids and register_id fallback': {
		voorzieningenRawConfig: { register: '5' },
		amefRawConfig: { register_id: '3' },
	},
	'register wins over register_id': {
		amefRawConfig: { register: '5', register_id: '3' },
	},
	'unknown registers keep the old selection': {
		voorzieningenRawConfig: { register: 9 },
		amefRawConfig: { register_id: 8 },
		voorzieningenRegister: { label: 'old', value: '1' },
		amefSchemas: [{ id: 'old' }],
	},
}

describe('settings store register selections', () => {
	afterAll(() => {
		if (process.env.RECORD_GOLDEN) {
			writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
		}
	})

	for (const [name, patch] of Object.entries(CASES)) {
		it(name, () => {
			setActivePinia(createPinia())
			const store = useSettingsStore()
			store.settings.availableRegisters = structuredClone(REGISTERS)
			Object.assign(store, structuredClone(patch))
			store.populateRegisterSelectionsFromFocused()
			const result = JSON.parse(
				JSON.stringify({
					voorzieningenRegister: store.voorzieningenRegister,
					voorzieningenSchemas: store.voorzieningenSchemas,
					amefRegister: store.amefRegister,
					amefSchemas: store.amefSchemas,
				}),
			)
			recorded[name] = result
			if (!process.env.RECORD_GOLDEN) {
				expect(result).toEqual(expected[name])
			}
		})
	}
})
