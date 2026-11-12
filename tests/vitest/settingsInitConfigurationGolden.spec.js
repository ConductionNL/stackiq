// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for how the settings view initialises its configuration from
 * the stored settings: the empty entry per object type, the register it
 * selects from the first configured type, the schema options it asks for,
 * and the schema it selects per type. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-settings-ui/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/store.js', () => ({
	navigationStore: {},
	objectStore: {},
	settingsStore: {},
}))

const { default: Settings } = await import('../../src/views/settings/Settings.vue')

const GOLDEN = path.resolve(
	import.meta.dirname,
	'golden/settingsInitConfiguration.json',
)

const REGISTERS = [
	{
		id: 1,
		title: 'One',
		schemas: [
			{ id: 10, title: 'Ten' },
			{ id: 11, title: 'Eleven' },
		],
	},
	{ id: 2, title: 'Two', schemas: 'not a list' },
	{ id: 3, title: 'Three' },
]

const CONFIGURATIONS = {
	empty: {},
	'register and schemas': {
		module_register: '1',
		module_schema: '10',
		usage_register: '2',
		usage_schema: '11',
		other_schema: '11',
	},
	'unknown register': { module_register: '9', module_schema: '10' },
	'register without schema list': {
		module_register: '2',
		module_schema: '10',
		usage_schema: '10',
	},
	'register without schemas': { module_register: '3', module_schema: '10' },
	'schema only': { module_schema: '10' },
	'unknown schema': {
		module_register: '1',
		module_schema: '99',
		usage_schema: '11',
	},
}

const PRESELECTED = {
	none: null,
	two: { label: 'Two', value: '2' },
	one: { label: 'One', value: '1' },
}

/**
 * Initialise once and record the state and the schema option requests.
 *
 * @param {object} configuration The stored configuration.
 * @param {object|null} selectedRegister The register selected before.
 * @return {object} The outcome.
 */
function record(configuration, selectedRegister) {
	const calls = []
	const vm = {
		settings: {
			objectTypes: ['module', 'usage', 'other'],
			configuration,
			availableRegisters: structuredClone(REGISTERS),
		},
		configuration: { existing: { schema: 'kept' } },
		selectedRegister: structuredClone(selectedRegister),
	}
	for (const [name, fn] of Object.entries(Settings.methods)) {
		vm[name] = fn.bind(vm)
	}
	vm.updateSchemaOptions = (id) => calls.push(['updateSchemaOptions', id])
	vm.initializeConfiguration()
	return {
		calls,
		configuration: vm.configuration,
		selectedRegister: vm.selectedRegister,
	}
}

describe('settings configuration initialisation golden', () => {
	it('builds the same configuration and selections', () => {
		const out = {}
		for (const [configName, configuration] of Object.entries(CONFIGURATIONS)) {
			for (const [selectedName, selected] of Object.entries(PRESELECTED)) {
				out[`${configName} | selected ${selectedName}`] = record(
					configuration,
					selected,
				)
			}
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(out, null, '\t') + '\n')
		}
		expect(out).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
