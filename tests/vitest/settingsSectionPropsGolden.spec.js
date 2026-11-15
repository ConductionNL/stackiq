// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the prop contract of the two settings sections: every
 * prop of AlwaysVisibleSection and CollapsibleSection with its type,
 * default and whether it is required. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import AlwaysVisibleSection from '../../src/components/AlwaysVisibleSection.vue'
import CollapsibleSection from '../../src/components/CollapsibleSection.vue'

const GOLDEN = path.resolve(import.meta.dirname, 'golden/settingsSectionProps.json')

/**
 * A component's props as plain data, sorted by name.
 *
 * @param {object} component The component.
 * @return {object} Prop name to its type name, default and required flag.
 */
function contract(component) {
	const props = {}
	for (const name of Object.keys(component.props).sort()) {
		const definition = component.props[name]
		props[name] = {
			type: definition.type.name,
			default: definition.default ?? null,
			required: definition.required === true,
		}
	}
	return props
}

describe('settings section props', () => {
	it('keeps the prop contract of both sections', () => {
		const actual = {
			AlwaysVisibleSection: contract(AlwaysVisibleSection),
			CollapsibleSection: contract(CollapsibleSection),
		}
		if (process.env.RECORD_GOLDEN) {
			writeFileSync(GOLDEN, JSON.stringify(actual, null, '\t') + '\n')
		}
		expect(actual).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
