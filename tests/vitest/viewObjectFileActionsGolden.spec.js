// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the file actions of the object view: publishing,
 * depublishing and deleting one file or the selected files, and saving a
 * file's labels. It records every request, the files-list refresh, the
 * console lines and the component state afterwards, for success, a refused
 * request and a network failure. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ViewObject from '../../src/modals/object/ViewObject.vue'

const trace = []

vi.mock('../../src/store/store.js', () => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: {
		fetchRelatedData: async (...args) => {
			trace.push(['fetchRelatedData', ...args])
		},
	},
}))

const GOLDEN = path.resolve(import.meta.dirname, 'golden/viewObjectFileActions.json')

const files = [
	{ id: 11, title: 'Plan', name: 'plan.pdf' },
	{ id: 12, name: 'notes.txt' },
	{ id: 13, title: 'Other', name: 'other.txt' },
]

const objects = {
	'ids as values': { id: 'obj-1', '@self': { register: 4, schema: 7 } },
	'ids as objects': {
		id: 'obj-2',
		'@self': { register: { id: null, uuid: 'reg-uuid' }, schema: { id: 9 } },
	},
	'no object': null,
}

const bulk = ['publishSelectedFiles', 'depublishSelectedFiles', 'deleteSelectedFiles']
const single = ['publishFile', 'depublishFile', 'deleteFile']

const answers = {
	ok: () => ({ ok: true, statusText: 'OK' }),
	'refused on the second request': (calls) => (calls === 2 ? { ok: false, statusText: 'Forbidden' } : { ok: true, statusText: 'OK' }),
	refused: () => ({ ok: false, statusText: 'Bad Request' }),
	'network failure': () => 'reject',
}

/**
 * A fetch double answering as the scripted answer says and recording each call.
 *
 * @param {Function} answer The answer per call number.
 * @return {Function} The fetch double.
 */
function fakeFetch(answer) {
	let calls = 0
	return async (url, options) => {
		calls++
		trace.push(['fetch', url, options])
		const reply = answer(calls)
		if (reply === 'reject') {
			throw new TypeError('Failed to fetch')
		}
		return reply
	}
}

/**
 * The component's methods bound to a plain object holding the state the file actions read.
 *
 * @param {object} currentObject The object shown.
 * @return {object} The fake instance.
 */
function instance(currentObject) {
	const vm = {
		currentObject: structuredClone(currentObject),
		paginatedFiles: structuredClone(files),
		selectedAttachments: [11, 12],
		publishLoading: [5],
		depublishLoading: [5],
		fileIdsLoading: [5],
		editingTags: 11,
		editedTags: ['a'],
		success: null,
		error: null,
		filesCurrentPageSize: 20,
	}
	for (const [name, fn] of Object.entries(ViewObject.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

/**
 * The state the file actions change.
 *
 * @param {object} vm The fake instance.
 * @return {object} The state.
 */
function state(vm) {
	return {
		selectedAttachments: vm.selectedAttachments,
		publishLoading: vm.publishLoading,
		depublishLoading: vm.depublishLoading,
		fileIdsLoading: vm.fileIdsLoading,
		editingTags: vm.editingTags,
		editedTags: vm.editedTags,
		success: vm.success,
		error: vm.error,
	}
}

/**
 * Run one method on a fresh instance and record what it did.
 *
 * @param {object} run The method, object, answer and selection.
 * @return {Promise<object>} The trace and the state.
 */
async function record(run) {
	trace.length = 0
	vi.stubGlobal('fetch', fakeFetch(answers[run.answer]))
	const vm = instance(objects[run.object])
	if (run.selection) {
		vm.selectedAttachments = run.selection
	}
	await vm[run.method](...(run.args ?? []))
	return { trace: structuredClone(trace), state: structuredClone(state(vm)) }
}

/**
 * Every case: each method on each object shape with each answer.
 *
 * @return {Array<object>} The runs.
 */
function runs() {
	const out = []
	for (const object of Object.keys(objects)) {
		for (const answer of Object.keys(answers)) {
			for (const method of bulk) {
				out.push({ method, object, answer })
			}
			for (const method of single) {
				out.push({ method, object, answer, args: [files[1]] })
			}
			out.push({ method: 'saveTags', object, answer, args: [files[0], ['x', 'y']] })
		}
		for (const method of bulk) {
			out.push({ method, object, answer: 'ok', selection: [] })
		}
		out.push({ method: 'onFilesPageChanged', object, answer: 'ok', args: [3] })
		out.push({ method: 'onFilesPageSizeChanged', object, answer: 'ok', args: [50] })
	}
	out.push({ method: 'onFilesPageChanged', object: 'no object', answer: 'ok', args: [3] })
	out.push({ method: 'onFilesPageSizeChanged', object: 'no object', answer: 'ok', args: [50] })
	return out
}

describe('ViewObject file actions golden', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.spyOn(console, 'error').mockImplementation((...args) => {
			trace.push(['console.error', ...args.map((arg) => (arg instanceof Error ? `${arg.name}: ${arg.message}` : arg))])
		})
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
		vi.unstubAllGlobals()
	})

	it('records the requests, refreshes, console lines and state of every file action', async () => {
		const out = {}
		for (const run of runs()) {
			const key = `${run.method} | ${run.object} | ${run.answer}${run.selection ? ' | nothing selected' : ''}`
			out[key] = await record(run)
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(out, null, '\t') + '\n')
		}
		expect(out).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
