/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for populateSchemaSelectionsFromFocused: for each kind of raw
 * register config (missing, singular keys, plural keys, unknown ids, numeric
 * ids, every alternative key) it records the configuration the action leaves
 * behind, or the error it throws. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-stores/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { createPinia, setActivePinia } from 'pinia'
import { afterAll, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/dialogs', () => ({
	showError: () => {},
	showSuccess: () => {},
}))

const { useSettingsStore } = await import('../../src/store/modules/settings.js')

const GOLDEN = path.resolve(
	import.meta.dirname,
	'golden/settingsSchemaSelections.json',
)
const recorded = {}
const expected = process.env.RECORD_GOLDEN
	? {}
	: JSON.parse(readFileSync(GOLDEN, 'utf8'))

const SCHEMAS = [
	{ id: 1, title: 'Sector' },
	{ id: 2, name: 'Suite' },
	{ id: 3 },
	{ id: 4, title: 'Element' },
	{ id: 5, title: 'Relation' },
	{ id: 6, title: 'View' },
	{ id: 7, title: 'Model' },
	{ id: 8, title: 'Property definition' },
	{ id: 9, title: 'Organisation' },
]

const VOORZIENINGEN_KEYS = [
	'sector_schema',
	'suite_schema',
	'dienst_schema',
	'kwetsbaarheid_schema',
	'contactpersoon_schema',
	'organisatie_schema',
	'gebruik_schema',
	'contract_schema',
	'koppeling_schema',
	'beoordeeling_schema',
	'module_schema',
	'compliancy_schema',
	'moduleVersie_schema',
]

const allVoorzieningen = Object.fromEntries(
	VOORZIENINGEN_KEYS.map((key, i) => [key, (i % 9) + 1]),
)

const CASES = {
	'no raw config': {},
	'empty raw config': { voorzieningenRawConfig: {}, amefRawConfig: {} },
	'every voorzieningen key': { voorzieningenRawConfig: allVoorzieningen },
	'voorzieningen string ids and unknown ids': {
		voorzieningenRawConfig: {
			sector_schema: '2',
			suite_schema: 99,
			dienst_schema: 0,
			kwetsbaarheid_schema: '',
			module_schema: '9',
			unknown_schema: 1,
		},
	},
	'voorzieningen without options': {
		voorzieningenRawConfig: allVoorzieningen,
		voorzieningenSchemas: null,
	},
	'amef singular keys': {
		amefRawConfig: {
			organization_schema: 9,
			element_schema: 4,
			relation_schema: 5,
			view_schema: 6,
			model_schema: 7,
			property_definition_schema: 8,
		},
	},
	'amef plural keys': {
		amefRawConfig: {
			organizations_schema: 9,
			elements_schema: 4,
			relationships_schema: 5,
			views_schema: 6,
			models_schema: 7,
			property_definitions_schema: '8',
		},
	},
	'amef singular wins over plural': {
		amefRawConfig: {
			organization_schema: 1,
			organizations_schema: 9,
			element_schema: 99,
			elements_schema: 4,
			'property-definition_schema': 3,
			property_definitions_schema: 8,
		},
	},
	'amef dashed property key': {
		amefRawConfig: { 'property-definition_schema': 2 },
	},
	'amef falsy singular falls back to plural': {
		amefRawConfig: {
			view_schema: 0,
			views_schema: 6,
			model_schema: '',
			models_schema: 7,
			property_definition_schema: null,
			'property-definition_schema': 0,
			property_definitions_schema: 8,
		},
	},
	'amef without options': {
		amefRawConfig: { element_schema: 4 },
		amefSchemas: 'none',
	},
	'options with empty values': {
		voorzieningenRawConfig: { sector_schema: 1 },
		amefRawConfig: { element_schema: 4 },
		voorzieningenSchemas: [{ id: '' }, { id: 1, title: 'One' }],
		amefSchemas: [{ id: 0 }, { id: 4 }],
	},
	'unknown ids keep the current selection': {
		voorzieningenRawConfig: { sector_schema: 99, suite_schema: 0 },
		amefRawConfig: { element_schema: 98 },
		configuration: {
			voorzieningen_sector_schema: { schema: { value: 'kept' } },
			voorzieningen_suite_schema: { schema: { value: 'kept' } },
			amef_element_schema: { schema: { value: 'kept' } },
		},
	},
	'raw config with an undefined key': {
		voorzieningenRawConfig: { undefined: 3 },
		amefRawConfig: { undefined: 4 },
	},
	'missing configuration entry': {
		voorzieningenRawConfig: { sector_schema: 1 },
		configuration: { voorzieningen_sector_schema: undefined },
	},
	'missing amef configuration entry': {
		amefRawConfig: { element_schema: 4 },
		configuration: { amef_element_schema: undefined },
	},
}

describe('populateSchemaSelectionsFromFocused', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterAll(() => {
		if (process.env.RECORD_GOLDEN) {
			writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
		}
	})

	for (const [name, patch] of Object.entries(CASES)) {
		it(name, () => {
			const store = useSettingsStore()
			store.voorzieningenSchemas = SCHEMAS
			store.amefSchemas = SCHEMAS
			const { configuration, ...rest } = patch
			Object.assign(store, rest)
			if (configuration) {
				store.configuration = { ...store.configuration, ...configuration }
			}
			let error = null
			try {
				store.populateSchemaSelectionsFromFocused()
			} catch (e) {
				error = e.constructor.name
			}
			const out = JSON.parse(
				JSON.stringify({ error, configuration: store.configuration }),
			)
			recorded[name] = out
			if (!process.env.RECORD_GOLDEN) {
				expect(out).toEqual(expected[name])
			}
		})
	}
})
