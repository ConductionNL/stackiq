/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the object view's metadata helpers: display and schema
 * names with their fallbacks, reference titles, lock descriptions and the
 * timestamp rows.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { describe, expect, it } from 'vitest'
import { FILE_ACTIONS } from '../../src/modals/object/objectFileActions.js'
import {
	localeTimestamp,
	lockDescription,
	objectDisplayName,
	objectSchemaName,
	referenceTitle,
	timestampRows,
} from '../../src/modals/object/objectMetadata.js'

describe('objectDisplayName', () => {
	it('prefers @self name, then name, title, id', () => {
		expect(objectDisplayName({ '@self': { name: 'Self' }, name: 'N' })).toBe('Self')
		expect(objectDisplayName({ name: 'N', title: 'T' })).toBe('N')
		expect(objectDisplayName({ title: 'T', id: 7 })).toBe('T')
		expect(objectDisplayName({ id: 7 })).toBe(7)
		expect(objectDisplayName({})).toBe('Untitled')
	})
})

describe('objectSchemaName', () => {
	it('reads an object schema, then an @self schema object, then an @self schema string', () => {
		expect(objectSchemaName({ schema: { title: 'Server', name: 'srv' } })).toBe('Server')
		expect(objectSchemaName({ schema: { name: 'srv', id: 3 } })).toBe('srv')
		expect(objectSchemaName({ schema: { id: 3 } })).toBe(3)
		expect(objectSchemaName({ schema: {} })).toBe('Publication')
		expect(objectSchemaName({ '@self': { schema: { name: 'Self' } } })).toBe('Self')
		expect(objectSchemaName({ '@self': { schema: 'slug' } })).toBe('slug')
		expect(objectSchemaName({ schema: 12, '@self': { schema: 12 } })).toBe('Publication')
		expect(objectSchemaName({})).toBe('Publication')
	})
})

describe('referenceTitle', () => {
	const available = [{ id: 'a', title: 'Alpha' }, { id: 'b' }]

	it('says not set for no reference', () => {
		expect(referenceTitle(null, available)).toBe('Not set')
		expect(referenceTitle('', available)).toBe('Not set')
	})

	it('titles an object reference by title, name, id or itself', () => {
		expect(referenceTitle({ title: 'T', name: 'N' }, available)).toBe('T')
		expect(referenceTitle({ name: 'N', id: 1 }, available)).toBe('N')
		expect(referenceTitle({ id: 1 }, available)).toBe(1)
		const bare = {}
		expect(referenceTitle(bare, available)).toBe(bare)
	})

	it('looks an id up in the available list and falls back to the id', () => {
		expect(referenceTitle('a', available)).toBe('Alpha')
		expect(referenceTitle('b', available)).toBe('b')
		expect(referenceTitle('z', available)).toBe('z')
	})
})

describe('lockDescription', () => {
	it('describes no lock and a bare lock flag', () => {
		expect(lockDescription(null)).toBe('Not locked')
		expect(lockDescription(false)).toBe('Not locked')
		expect(lockDescription(true)).toBe('Locked')
	})

	it('names who locked it, when and in which process', () => {
		const at = '2026-03-05T09:07:00Z'
		expect(lockDescription({ lockedBy: 'ann', lockedAt: at, process: 'import' }))
			.toBe(`Locked by ann at ${new Date(at).toLocaleString()} (import)`)
		expect(lockDescription({})).toBe('Locked by Unknown user at Unknown time')
	})
})

describe('timestamps', () => {
	it('falls back when a timestamp is missing', () => {
		expect(localeTimestamp('', 'none')).toBe('none')
		expect(localeTimestamp('2026-01-02T00:00:00Z', 'none')).toBe(new Date('2026-01-02T00:00:00Z').toLocaleString())
	})

	it('lists created, updated, published and depublished with their own fallbacks', () => {
		const created = '2026-01-02T00:00:00Z'
		expect(timestampRows({ created })).toEqual([
			['Created', new Date(created).toLocaleString()],
			['Updated', 'Not set'],
			['Published', 'Not published'],
			['Depublished', 'Not depublished'],
		])
		expect(timestampRows(undefined).map((row) => row[1])).toEqual(['Not set', 'Not set', 'Not published', 'Not depublished'])
	})
})

describe('FILE_ACTIONS', () => {
	it('defines the request, loading list and bulk error per action, frozen', () => {
		expect(FILE_ACTIONS.publish).toMatchObject({ suffix: '/publish', method: 'POST', loading: 'publishLoading' })
		expect(FILE_ACTIONS.depublish).toMatchObject({ suffix: '/depublish', method: 'POST', loading: 'depublishLoading' })
		expect(FILE_ACTIONS.delete).toMatchObject({ suffix: '', method: 'DELETE', loading: 'fileIdsLoading', bulkError: 'Failed to delete selected files:' })
		expect(Object.isFrozen(FILE_ACTIONS)).toBe(true)
		expect(Object.isFrozen(FILE_ACTIONS.publish)).toBe(true)
	})
})
