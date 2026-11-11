// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for how the object view summarises an object: the
 * modal title (name and schema name) and the metadata rows (register and
 * schema titles, lock description and timestamps with their fallbacks).
 * Timestamps are compared with the same toLocaleString the view uses, so the
 * expectations hold in every timezone and locale.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/store.js', () => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: {
		availableRegisters: [{ id: 'r1', title: 'Register One' }, { id: 'r2' }],
		availableSchemas: [
			{ id: 's1', title: 'Schema One' },
			{ id: 's2', name: 'no title' },
		],
	},
}))

const { default: ViewObject } =
	await import('../../src/modals/object/ViewObject.vue')

const STAMP = '2026-01-02T03:04:05Z'
const LOCAL = new Date(STAMP).toLocaleString()

/**
 * The modal title for an object.
 *
 * @param {object|null} currentObject The object shown.
 * @param {boolean} isNewObject Whether the modal creates an object.
 * @return {string} The title.
 */
function title(currentObject, isNewObject = false) {
	return ViewObject.methods.getModalTitle.call({ currentObject, isNewObject })
}

/**
 * The metadata rows for an object.
 *
 * @param {object|null} currentObject The object shown.
 * @return {Array} The rows.
 */
function metadata(currentObject) {
	return ViewObject.computed.metadataProperties.call({ currentObject })
}

describe('ViewObject getModalTitle', () => {
	it('names a new object and a missing object', () => {
		expect(title({ id: 'x' }, true)).toBe('Create Publication')
		expect(title(null)).toBe('View Object')
	})

	it('takes the first name the object carries', () => {
		expect(
			title({ '@self': { name: 'Self' }, name: 'N', title: 'T', id: 'I' }),
		).toBe('Self (Publication)')
		expect(title({ name: 'N', title: 'T', id: 'I' })).toBe('N (Publication)')
		expect(title({ title: 'T', id: 'I' })).toBe('T (Publication)')
		expect(title({ id: 'I' })).toBe('I (Publication)')
		expect(title({})).toBe('Untitled (Publication)')
	})

	it('takes the schema name from the object, then from @self', () => {
		expect(
			title({ id: 'a', schema: { title: 'ST', name: 'SN', id: 'SI' } }),
		).toBe('a (ST)')
		expect(title({ id: 'a', schema: { name: 'SN', id: 'SI' } })).toBe('a (SN)')
		expect(title({ id: 'a', schema: { id: 'SI' } })).toBe('a (SI)')
		expect(title({ id: 'a', schema: {} })).toBe('a (Publication)')
		expect(
			title({ id: 'a', schema: 'plain', '@self': { schema: 'self' } }),
		).toBe('a (self)')
		expect(title({ id: 'a', '@self': { schema: { title: 'Self T' } } })).toBe(
			'a (Self T)',
		)
		expect(title({ id: 'a', '@self': { schema: { name: 'Self N' } } })).toBe(
			'a (Self N)',
		)
		expect(title({ id: 'a', '@self': { schema: { id: 'Self I' } } })).toBe(
			'a (Self I)',
		)
		expect(title({ id: 'a', '@self': { schema: {} } })).toBe('a (Publication)')
		expect(title({ id: 'a', '@self': { schema: '' } })).toBe('a ()')
		expect(title({ id: 'a', '@self': { schema: 7 } })).toBe('a (Publication)')
		expect(title({ id: 'a', schema: null, '@self': { schema: null } })).toBe(
			'a (Publication)',
		)
	})
})

describe('ViewObject metadataProperties', () => {
	it('has no rows without an object', () => {
		expect(metadata(null)).toEqual([])
	})

	it('falls back for an object without metadata', () => {
		expect(metadata({})).toEqual([
			['ID', 'Not set'],
			['Version', 'Not set'],
			['Register', 'Not set'],
			['Schema', 'Not set'],
			['Locked', 'Not locked'],
			['Created', 'Not set'],
			['Updated', 'Not set'],
			['Published', 'Not published'],
			['Depublished', 'Not depublished'],
		])
	})

	it('describes full metadata', () => {
		expect(
			metadata({
				id: 'obj',
				'@self': {
					version: '1.2',
					register: { title: 'RT', name: 'RN', id: 'RI' },
					schema: { name: 'SN', id: 'SI' },
					locked: { lockedBy: 'ann', lockedAt: STAMP, process: 'import' },
					created: STAMP,
					updated: STAMP,
					published: STAMP,
					depublished: STAMP,
				},
			}),
		).toEqual([
			['ID', 'obj'],
			['Version', '1.2'],
			['Register', 'RT'],
			['Schema', 'SN'],
			['Locked', `Locked by ann at ${LOCAL} (import)`],
			['Created', LOCAL],
			['Updated', LOCAL],
			['Published', LOCAL],
			['Depublished', LOCAL],
		])
	})

	it('resolves register and schema ids against the available lists', () => {
		const rows = (self) => metadata({ '@self': self }).slice(2, 5)
		expect(rows({ register: 'r1', schema: 's1' })).toEqual([
			['Register', 'Register One'],
			['Schema', 'Schema One'],
			['Locked', 'Not locked'],
		])
		expect(rows({ register: 'r2', schema: 's2', locked: true })).toEqual([
			['Register', 'r2'],
			['Schema', 's2'],
			['Locked', 'Locked'],
		])
		expect(rows({ register: 'rX', schema: { id: 'SI' }, locked: {} })).toEqual([
			['Register', 'rX'],
			['Schema', 'SI'],
			['Locked', 'Locked by Unknown user at Unknown time'],
		])
		const emptyRef = {}
		expect(
			rows({ register: emptyRef, schema: { id: '' }, locked: 'yes' }),
		).toEqual([
			['Register', emptyRef],
			['Schema', { id: '' }],
			['Locked', 'Locked'],
		])
	})
})
