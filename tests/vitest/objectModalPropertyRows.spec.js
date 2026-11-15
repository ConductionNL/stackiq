/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the object view's property rows: schema defaults per type,
 * the split by schema order, and the final row order.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	orderedPropertyRows,
	schemaDefaultValue,
	schemaPropertyRows,
} from '../../src/modals/object/objectPropertyRows.js'

describe('schemaDefaultValue', () => {
	it('gives each type its empty value', () => {
		expect(schemaDefaultValue({ type: 'string' })).toBe('')
		expect(schemaDefaultValue({ type: 'string', const: 'fixed' })).toBe('fixed')
		expect(schemaDefaultValue({ type: 'number' })).toBe(0)
		expect(schemaDefaultValue({ type: 'integer' })).toBe(0)
		expect(schemaDefaultValue({ type: 'boolean' })).toBe(false)
		expect(schemaDefaultValue({ type: 'array' })).toEqual([])
		expect(schemaDefaultValue({ type: 'object' })).toEqual({})
		expect(schemaDefaultValue({ type: 'toString' })).toBe('')
		expect(schemaDefaultValue({})).toBe('')
	})
})

describe('schemaPropertyRows', () => {
	it('uses the object value when present, else the default, and splits on order', () => {
		const rows = schemaPropertyRows(
			{ name: 'srv', count: 0 },
			{
				name: { type: 'string', order: 2 },
				count: { type: 'integer', order: 0 },
				tags: { type: 'array' },
				note: { type: 'string', order: null },
			},
		)

		expect(rows.withOrder).toEqual([
			['name', 'srv', { type: 'string', order: 2 }],
			['count', 0, { type: 'integer', order: 0 }],
		])
		expect(rows.withoutOrder).toEqual([
			['tags', [], { type: 'array' }],
			['note', '', { type: 'string', order: null }],
		])
	})
})

describe('orderedPropertyRows', () => {
	it('orders by schema order then key, then unordered by key, then additional rows by key', () => {
		const rows = orderedPropertyRows(
			{
				withOrder: [
					['b', 1, { order: 2 }],
					['z', 2, { order: 1 }],
					['a', 3, { order: 2 }],
				],
				withoutOrder: [['y', 4, {}], ['c', 5, {}]],
			},
			[['m', 6], ['d', 7]],
		)

		expect(rows).toEqual([['z', 2], ['a', 3], ['b', 1], ['c', 5], ['y', 4], ['d', 7], ['m', 6]])
	})

	it('falls back to the basic rows when there is nothing to show', () => {
		expect(orderedPropertyRows({ withOrder: [], withoutOrder: [] }, [])).toEqual([
			['title', ''],
			['description', ''],
			['omschrijving', ''],
			['category', ''],
			['status', 'draft'],
		])
	})
})
