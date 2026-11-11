// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the input the object view renders per schema
 * property: the HTML input type, the component and the date picker type, for
 * every type and format the view distinguishes and for unknown properties.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/store.js', () => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: {},
}))

const { default: ViewObject } =
	await import('../../src/modals/object/ViewObject.vue')

const SCHEMA = {
	date: { type: 'string', format: 'date' },
	time: { type: 'string', format: 'time' },
	dateTime: { type: 'string', format: 'date-time' },
	email: { type: 'string', format: 'email' },
	url: { type: 'string', format: 'url' },
	uri: { type: 'string', format: 'uri' },
	password: { type: 'string', format: 'password' },
	text: { type: 'string', format: 'text' },
	markdown: { type: 'string', format: 'markdown' },
	otherFormat: { type: 'string', format: 'uuid' },
	plain: { type: 'string' },
	number: { type: 'number' },
	integer: { type: 'integer' },
	boolean: { type: 'boolean' },
	array: { type: 'array' },
	object: { type: 'object' },
	numberAsDate: { type: 'number', format: 'date' },
	booleanAsTime: { type: 'boolean', format: 'time' },
	arrayAsText: { type: 'array', format: 'text' },
	untyped: {},
	untypedDate: { format: 'date' },
}

/**
 * One input method's answer for every schema property and an unknown one.
 *
 * @param {string} method The method name.
 * @return {object} The answer per property.
 */
function answers(method) {
	const vm = { getSchemaProperties: () => SCHEMA }
	const out = {}
	for (const key of [...Object.keys(SCHEMA), 'unknown']) {
		out[key] = ViewObject.methods[method].call(vm, key)
	}
	return out
}

describe('ViewObject input kind', () => {
	it('picks the HTML input type', () => {
		expect(answers('getPropertyInputType')).toEqual({
			date: 'date',
			time: 'time',
			dateTime: 'datetime-local',
			email: 'email',
			url: 'url',
			uri: 'url',
			password: 'password',
			text: 'text',
			markdown: 'text',
			otherFormat: 'text',
			plain: 'text',
			number: 'number',
			integer: 'number',
			boolean: 'checkbox',
			array: 'text',
			object: 'text',
			numberAsDate: 'number',
			booleanAsTime: 'checkbox',
			arrayAsText: 'text',
			untyped: 'text',
			untypedDate: 'text',
			unknown: 'text',
		})
	})

	it('picks the input component', () => {
		expect(answers('getPropertyInputComponent')).toEqual({
			date: 'NcDateTimePicker',
			time: 'NcDateTimePicker',
			dateTime: 'NcDateTimePicker',
			email: 'NcTextField',
			url: 'NcTextField',
			uri: 'NcTextField',
			password: 'NcTextField',
			text: 'NcTextArea',
			markdown: 'Editor',
			otherFormat: 'NcTextField',
			plain: 'NcTextField',
			number: 'NcTextField',
			integer: 'NcTextField',
			boolean: 'NcCheckboxRadioSwitch',
			array: 'NcTextFieldArray',
			object: 'NcTextField',
			numberAsDate: 'NcTextField',
			booleanAsTime: 'NcCheckboxRadioSwitch',
			arrayAsText: 'NcTextFieldArray',
			untyped: 'NcTextField',
			untypedDate: 'NcTextField',
			unknown: 'NcTextField',
		})
	})

	it('picks the date picker type from the format alone', () => {
		expect(answers('getDateTimePickerType')).toEqual({
			date: 'date',
			time: 'time',
			dateTime: 'datetime',
			email: 'datetime',
			url: 'datetime',
			uri: 'datetime',
			password: 'datetime',
			text: 'datetime',
			markdown: 'datetime',
			otherFormat: 'datetime',
			plain: 'datetime',
			number: 'datetime',
			integer: 'datetime',
			boolean: 'datetime',
			array: 'datetime',
			object: 'datetime',
			numberAsDate: 'date',
			booleanAsTime: 'time',
			arrayAsText: 'datetime',
			untyped: 'datetime',
			untypedDate: 'date',
			unknown: 'datetime',
		})
	})
})
