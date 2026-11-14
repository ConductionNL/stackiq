// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the mass object dialogs (publish, depublish, lock, unlock,
 * validate, delete): which store call is made with which objects, the
 * publication refresh, the auto-close timer, the error texts and the dialog
 * state afterwards. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const trace = []
const store = vi.hoisted(() => ({ objectStore: {}, answer: null }))

vi.mock('../../src/store/store.js', () => ({
	catalogStore: {
		fetchPublications: () => trace.push(['catalogStore.fetchPublications']),
	},
	navigationStore: {
		setDialog: (value) => trace.push(['navigationStore.setDialog', value]),
	},
	objectStore: store.objectStore,
}))

const { default: MassPublishObjects } =
	await import('../../src/modals/object/MassPublishObjects.vue')
const { default: MassDepublishObjects } =
	await import('../../src/modals/object/MassDepublishObjects.vue')
const { default: MassLockObjects } =
	await import('../../src/modals/object/MassLockObjects.vue')
const { default: MassUnlockObjects } =
	await import('../../src/modals/object/MassUnlockObjects.vue')
const { default: MassValidateObjects } =
	await import('../../src/modals/object/MassValidateObjects.vue')
const { default: MassDeleteObject } =
	await import('../../src/modals/object/MassDeleteObject.vue')
const { default: MassObjectDialog } =
	await import('../../src/modals/object/MassObjectDialog.vue')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/massObjectActions.json')

const dialogs = {
	publish: [MassPublishObjects, 'massPublishObjects'],
	depublish: [MassDepublishObjects, 'massDepublishObjects'],
	lock: [MassLockObjects, 'massLockObjects'],
	unlock: [MassUnlockObjects, 'massUnlockObjects'],
	validate: [MassValidateObjects, 'massValidateObjects'],
	delete: [MassDeleteObject, 'massDeleteObjects'],
}

const two = [{ id: 'a' }, { id: 'b' }]

const answers = {
	'all succeed': { successful: two, failed: [] },
	'some fail': { successful: [two[0]], failed: [two[1]] },
	'all fail': { successful: [], failed: two },
	'nothing processed': { successful: [], failed: [] },
	'store throws with a message': { throws: 'boom' },
	'store throws without a message': { throws: '' },
}

const selections = {
	'two selected': two,
	'nothing selected': null,
}

/**
 * Run one dialog's action for one store answer: the dialog's massAction,
 * run by the shared MassObjectDialog, against fake components.
 *
 * @param {Array} dialog The component and its store method.
 * @param {object} input The store answer, the selection and the lock fields.
 * @return {Promise<object>} The trace and the dialog state afterwards.
 */
async function run(dialog, input) {
	const [component, storeMethod] = dialog
	trace.length = 0
	const selected = input.selected === null ? null : structuredClone(input.selected)
	store.objectStore.selectedObjects = selected
	store.objectStore[storeMethod] = async (...args) => {
		trace.push(['objectStore.' + storeMethod, ...args])
		if (input.answer.throws !== undefined) {
			throw new Error(input.answer.throws)
		}
		return structuredClone(input.answer)
	}
	const t = (app, text, vars) => `${app}:${text}:${JSON.stringify(vars ?? null)}`
	const parent = {
		process: input.process,
		duration: input.duration,
		objectsToDepublish: selected || [],
		objectsToLock: selected || [],
		objectsToUnlock: selected || [],
		objectsToValidate: selected || [],
		t,
	}
	const self = {
		success: null,
		loading: false,
		error: input.earlierError ?? false,
		closeModalTimeout: null,
		action: component.computed.massAction.call(parent),
		closeDialog: () => trace.push(['closeDialog']),
		t,
	}
	let outcome = 'resolved'
	const pending = MassObjectDialog.methods.runAction.call(self)
	trace.push(['loading while running', self.loading])
	try {
		await pending
	} catch (error) {
		outcome = `rejected: ${error.constructor.name}`
	}
	const state = {
		success: self.success,
		loading: self.loading,
		error: self.error,
		timerSet: self.closeModalTimeout !== null,
	}
	trace.push(['timers pending', vi.getTimerCount()])
	vi.advanceTimersByTime(1999)
	trace.push(['after 1999 ms'])
	vi.advanceTimersByTime(1)
	return JSON.parse(
		JSON.stringify({ outcome, state, trace: [...trace] }, (key, value) =>
			value === undefined ? '(undefined)' : value,
		),
	)
}

const cases = {}
for (const [dialogName, dialog] of Object.entries(dialogs)) {
	for (const [answerName, answer] of Object.entries(answers)) {
		for (const [selectionName, selected] of Object.entries(selections)) {
			cases[`${dialogName}, ${answerName}, ${selectionName}`] = {
				dialog,
				input: { answer, selected, process: 'review', duration: 60 },
			}
		}
	}
	cases[`${dialogName}, all succeed, earlier error kept`] = {
		dialog,
		input: {
			answer: answers['all succeed'],
			selected: two,
			process: 'review',
			duration: 60,
			earlierError: 'earlier',
		},
	}
}
cases['lock, empty process and duration'] = {
	dialog: dialogs.lock,
	input: {
		answer: answers['all succeed'],
		selected: two,
		process: '',
		duration: 0,
	},
}

describe('mass object dialogs', () => {
	beforeEach(() => {
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.clearAllTimers()
		vi.useRealTimers()
	})

	for (const [name, { dialog, input }] of Object.entries(cases)) {
		it(name, async () => {
			const actual = await run(dialog, input)
			if (process.env.RECORD_GOLDEN) {
				const all = { ...readGolden(), [name]: actual }
				writeFileSync(GOLDEN, JSON.stringify(all, null, '\t') + '\n')
			}
			expect(actual).toEqual(readGolden()[name])
		})
	}

	it('covers every case', () => {
		expect(Object.keys(readGolden()).sort()).toEqual(Object.keys(cases).sort())
	})
})

/**
 * The recorded golden, or an empty object while recording the first case.
 *
 * @return {object} Results by case name.
 */
function readGolden() {
	try {
		return JSON.parse(readFileSync(GOLDEN, 'utf8'))
	} catch (error) {
		if (process.env.RECORD_GOLDEN) {
			return {}
		}
		throw error
	}
}
