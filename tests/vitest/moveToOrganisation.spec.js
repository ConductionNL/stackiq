/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Move to organisation helpers: the bulk-action handlers open the dialog
 * with their schema and the selection, the request body names every entry,
 * the target list leaves the source out, and the dry run splits into
 * moving, skipped and staying behind.
 *
 * @spec openspec/changes/landscape-move-between-organisations
 */

import { describe, expect, it, vi } from 'vitest'
import {
	createMoveHandlers,
	entryLabel,
	MOVE_DIALOG,
	MOVE_HANDLERS,
	splitPlan,
	targetOptions,
	transferBody,
} from '../../src/services/moveToOrganisation.js'

describe('createMoveHandlers', () => {
	it('opens the dialog with the schema of the handler and the selected ids as strings', () => {
		const setDialog = vi.fn()
		const handlers = createMoveHandlers(setDialog)

		handlers.moveUsagesToOrganisation({ actionId: 'move-to-organisation', selectedIds: ['u-1', 2, 'u-3'], count: 3 })

		expect(setDialog).toHaveBeenCalledTimes(1)
		expect(setDialog).toHaveBeenCalledWith(MOVE_DIALOG, { schema: 'usage', ids: ['u-1', '2', 'u-3'] })
	})

	it('has one handler per movable schema', () => {
		const handlers = createMoveHandlers(vi.fn())

		expect(Object.keys(handlers).sort()).toEqual(Object.keys(MOVE_HANDLERS).sort())
		expect(Object.values(MOVE_HANDLERS).sort()).toEqual(['catalogContract', 'catalogService', 'connection', 'module', 'usage'])
	})

	it('opens nothing for an empty or missing selection', () => {
		const setDialog = vi.fn()
		const handlers = createMoveHandlers(setDialog)

		handlers.moveModulesToOrganisation({ selectedIds: [] })
		handlers.moveModulesToOrganisation(undefined)

		expect(setDialog).not.toHaveBeenCalled()
	})
})

describe('transferBody', () => {
	it('names every entry with its schema and both organisations', () => {
		expect(transferBody('module', ['m-1', 7], 'org-a', 'org-b')).toEqual({
			objects: [{ schema: 'module', id: 'm-1' }, { schema: 'module', id: '7' }],
			sourceOrganisation: 'org-a',
			targetOrganisation: 'org-b',
		})
	})
})

describe('targetOptions', () => {
	it('lists the other organisations by name, without the source and entries without a uuid', () => {
		const organisations = [
			{ uuid: 'org-a', name: 'Gemeente Tilburg' },
			{ uuid: 'org-c', name: 'Gemeente Utrecht' },
			{ uuid: 'org-b', name: 'Gemeente Breda' },
			{ uuid: 'org-d' },
			{ name: 'No uuid' },
			null,
		]

		expect(targetOptions(organisations, 'org-a')).toEqual([
			{ value: 'org-b', label: 'Gemeente Breda' },
			{ value: 'org-c', label: 'Gemeente Utrecht' },
			{ value: 'org-d', label: 'org-d' },
		])
	})

	it('answers an empty list for no organisations', () => {
		expect(targetOptions(undefined, 'org-a')).toEqual([])
	})
})

describe('splitPlan', () => {
	it('splits the items into moving and skipped and keeps what stays behind', () => {
		const plan = {
			items: [
				{ schema: 'usage', id: 'u-1', name: 'Zaaksysteem', action: 'move', reason: null },
				{ schema: 'usage', id: 'u-2', name: 'DMS', action: 'skip', reason: 'Not owned by the source organisation' },
				{ schema: 'usage', id: 'u-3', name: 'Burgerzaken', action: 'move', reason: null },
			],
			staysBehind: [{ schema: 'connection', id: 'c-1', name: 'StUF koppeling', organisation: 'org-a', linkedTo: 'u-1' }],
		}

		const split = splitPlan(plan)

		expect(split.moving.map((i) => i.id)).toEqual(['u-1', 'u-3'])
		expect(split.skipped.map((i) => i.id)).toEqual(['u-2'])
		expect(split.staysBehind).toEqual(plan.staysBehind)
	})

	it('answers empty lists for no plan', () => {
		expect(splitPlan(null)).toEqual({ moving: [], skipped: [], staysBehind: [] })
	})
})

describe('entryLabel', () => {
	it('is the name, else the id', () => {
		expect(entryLabel({ id: 'u-1', name: 'Zaaksysteem' })).toBe('Zaaksysteem')
		expect(entryLabel({ id: 'u-1', name: '' })).toBe('u-1')
	})
})
