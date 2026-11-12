// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the property rows of the object view: schema properties in
 * their order, then those without an order, then the object's own extra
 * properties, with the default value of each missing schema property by its
 * type, and the basic rows of an empty object. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/store.js', () => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: {},
}))

const { default: ViewObject } =
	await import('../../src/modals/object/ViewObject.vue')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/viewObjectProperties.json')

const SCHEMAS = {
	none: {},
	'every type, no order': {
		zeta: { type: 'string' },
		alpha: { type: 'string', const: 'fixed' },
		count: { type: 'number' },
		amount: { type: 'integer' },
		flag: { type: 'boolean' },
		list: { type: 'array' },
		map: { type: 'object' },
		other: { type: 'file' },
		untyped: {},
	},
	'mixed order': {
		late: { type: 'string', order: 5 },
		early: { type: 'string', order: 1 },
		alsoEarly: { type: 'string', order: 1 },
		zero: { type: 'number', order: 0 },
		nullOrder: { type: 'string', order: null },
		plain: { type: 'string' },
		negative: { type: 'boolean', order: -2 },
	},
}

const OBJECTS = {
	absent: null,
	empty: {},
	'with values and extras': {
		'@self': { id: 'x' },
		id: 'obj-1',
		zeta: 'z',
		late: 'value',
		count: 0,
		extraB: 'b',
		extraA: { nested: true },
		deleted: 'gone',
	},
}

const FORMS = {
	'no edits': {},
	'edits and a deletion': { extraB: 'edited', deleted: undefined },
}

/**
 * The computed property rows for one object, schema and form.
 *
 * @param {object|null} currentObject The object shown.
 * @param {object} schemaProperties The schema properties.
 * @param {object} formData The edits.
 * @return {Array} The rows.
 */
function rows(currentObject, schemaProperties, formData) {
	const vm = {
		currentObject: structuredClone(currentObject),
		formData: { ...formData },
		getSchemaProperties: () => structuredClone(schemaProperties),
	}
	for (const [name, fn] of Object.entries(ViewObject.methods)) {
		if (!(name in vm)) {
			vm[name] = fn.bind(vm)
		}
	}
	return ViewObject.computed.objectProperties.call(vm)
}

describe('ViewObject property rows golden', () => {
	it('builds the same rows', () => {
		const out = {}
		for (const [schemaName, schema] of Object.entries(SCHEMAS)) {
			for (const [objectName, object] of Object.entries(OBJECTS)) {
				for (const [formName, form] of Object.entries(FORMS)) {
					out[`${schemaName} | ${objectName} | ${formName}`] = rows(
						object,
						schema,
						form,
					)
				}
			}
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(out, null, '\t') + '\n')
		}
		expect(out).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
