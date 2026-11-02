/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the settings store actions saveConfiguration and
 * exportToArchiMate. For each store state and each kind of answer it records
 * every fetch call, toast, console line and download step, the action's
 * outcome and the store state afterwards. Recorded with RECORD_GOLDEN=1.
 *
 * @vitest-environment jsdom
 * @spec openspec/specs/fe-stores/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSettingsStore } from '../../src/store/modules/settings.js'

const trace = []

vi.mock('@nextcloud/dialogs', () => ({
	showError: (...args) => trace.push(['showError', ...args]),
	showSuccess: (...args) => trace.push(['showSuccess', ...args]),
}))

const GOLDEN = path.resolve(
	import.meta.dirname,
	'golden/settingsStoreSaveExport.json',
)

const SAVE_STATES = {
	defaults: () => {},
	'nothing to save': (store) => {
		store.emailSettings = {}
	},
	'no email object': (store) => {
		store.emailSettings = null
		store.superUserGroups = ['root']
	},
	full: (store) => {
		store.amefRegister = { value: '4' }
		store.voorzieningenRegister = { value: '3' }
		store.configuration = {
			amef_element_schema: { schema: { value: 11 } },
			amef_view_schema: { schema: 12 },
			amef_model_schema: { schema: { value: 0, label: 'zero' } },
			amef_property_definition_schema: null,
			amef_relation_schema: { schema: null },
			voorzieningen_sector_schema: { schema: { value: 21 } },
			voorzieningen_moduleVersie_schema: { schema: '22' },
			voorzieningen_beoordeeling_schema: { schema: { value: 23 } },
			stray: { schema: 99 },
		}
		store.genericUserGroups = ['users', ' ', null, 'b ']
		store.organizationAdminGroups = []
		store.superUserGroups = ['admin']
		store.settings.catalogLocation = ''
		store.settings.syncTimeWindow = 0
	},
	'registers only': (store) => {
		store.amefRegister = { value: 4 }
		store.voorzieningenRegister = { value: '' }
		store.configuration = {
			amef_property_definition_schema: { schema: { value: 31 } },
		}
		store.genericUserGroups = ['', '  ']
		store.settings.catalogLocation = 'https://catalog.example'
	},
}

const SAVE_ANSWERS = {
	'all ok': () => ({
		ok: true,
		status: 200,
		body: { success: true, syncTimeWindow: 0, isAdmin: 0 },
	}),
	'http 500 on user groups': (url) =>
		url.includes('user-groups')
			? { ok: false, status: 500, statusText: 'Server Error', body: {} }
			: { ok: true, status: 200, body: { success: true } },
	'success false with message': (url) =>
		url.includes('/email/') || url.includes('/amef/')
			? { ok: true, status: 200, body: { success: false, message: 'nope' } }
			: { ok: true, status: 200, body: { success: true } },
	'success false without message': () => ({
		ok: true,
		status: 200,
		body: { success: false },
	}),
	'network failure': () => 'reject',
}

const EXPORT_ANSWERS = {
	'quoted filename': {
		status: 200,
		headers: {
			'content-type': 'application/xml',
			'content-disposition': 'attachment; filename="model.xml"',
		},
	},
	'plain filename': {
		status: 200,
		headers: { 'content-disposition': 'attachment; filename=plain.json' },
	},
	'disposition without filename': {
		status: 200,
		headers: { 'content-disposition': 'inline' },
	},
	'no headers': { status: 200, headers: {} },
	'json with message': {
		status: 200,
		headers: { 'content-type': 'application/json; charset=utf-8' },
		body: { message: 'm', error: 'e' },
	},
	'json with error': {
		status: 200,
		headers: { 'content-type': 'application/json' },
		body: { error: 'e' },
	},
	'json empty': {
		status: 200,
		headers: { 'content-type': 'application/json' },
		body: {},
	},
	'http 500 with message': { status: 500, body: { message: 'boom' } },
	'http 500 empty': { status: 500, body: {} },
	'http 404': { status: 404, statusText: 'Not Found', body: {} },
	'network failure': 'reject',
}

const CLEAR_ANSWERS = {
	ok: () => ({
		ok: true,
		status: 200,
		body: { import: { status: 'done' }, export: { status: 'running' } },
	}),
	'http 500': () => ({ ok: false, status: 500, statusText: 'Server Error' }),
	'network failure': () => 'reject',
}

/**
 * Write a running import and export with errors into the store.
 *
 * @param {object} store The settings store.
 */
function runningArchiMate(store) {
	store.archimateStatus = {
		import: { status: 'running', progress: 40 },
		export: { status: 'running', progress: 60 },
	}
	store.isImportRunning = true
	store.isExportRunning = true
	store.importError = 'import broke'
	store.exportError = 'export broke'
}

const EXPORT_CALLS = {
	'default format': [],
	'json format, options off': ['json'],
}

/**
 * A fetch double that answers per URL and records every call.
 *
 * @param {Function} answerFor Maps a URL to a scripted answer.
 * @return {Function} The fetch double.
 */
function fakeFetch(answerFor) {
	return async (url, options) => {
		trace.push(['fetch', url, options ?? '(no options)'])
		const answer = answerFor(url)
		if (answer === 'reject') {
			throw new TypeError('Failed to fetch')
		}
		const headers = answer.headers ?? {}
		return {
			ok: answer.status >= 200 && answer.status < 300,
			status: answer.status,
			statusText: answer.statusText ?? 'OK',
			headers: { get: (name) => headers[name] ?? null },
			json: async () => structuredClone(answer.body ?? {}),
			blob: async () => 'blob-of-' + url,
		}
	}
}

/**
 * JSON-safe copy that keeps undefined and errors visible.
 *
 * @param {*} value Any value.
 * @return {*} The copy.
 */
function plain(value) {
	return JSON.parse(
		JSON.stringify(value, (key, v) => {
			if (v === undefined) {
				return '(undefined)'
			}
			if (v instanceof Error) {
				return `${v.constructor.name}: ${v.message}`
			}
			return v
		}),
	)
}

/**
 * Run one action on a fresh store and collect what happened.
 *
 * @param {Function} prepare Writes the starting state.
 * @param {Function} act Runs the action.
 * @return {Promise<object>} What happened.
 */
async function run(prepare, act) {
	trace.length = 0
	setActivePinia(createPinia())
	const store = useSettingsStore()
	prepare(store)
	let outcome
	try {
		outcome = { returned: await act(store) }
	} catch (error) {
		outcome = { rejected: error }
	}
	for (let i = 0; i < 20; i++) {
		await Promise.resolve()
	}
	await new Promise((resolve) => setTimeout(resolve, 0))
	store.stopStatusPolling()
	return plain({
		outcome,
		trace: [...trace],
		links: document.body.querySelectorAll('a').length,
		state: store.$state,
	})
}

const recorded = {}

/**
 * Compare one case with the golden file, or record it.
 *
 * @param {string} key The case name.
 * @param {object} result What happened.
 */
function check(key, result) {
	recorded[key] = result
	if (process.env.RECORD_GOLDEN) {
		writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
		return
	}
	const expected = JSON.parse(readFileSync(GOLDEN, 'utf8'))
	expect(result).toEqual(expected[key])
}

describe('settings store save and export', () => {
	beforeEach(() => {
		vi.useFakeTimers({ toFake: ['Date'] })
		vi.setSystemTime(new Date('2026-11-02T10:20:30.456Z'))
		document.body.innerHTML = ''
		for (const level of ['info', 'warn', 'error', 'log']) {
			vi.spyOn(console, level).mockImplementation((...args) =>
				trace.push([`console.${level}`, ...args]),
			)
		}
		window.URL.createObjectURL = (blob) => {
			trace.push(['createObjectURL', blob])
			return 'blob:url'
		}
		window.URL.revokeObjectURL = (url) => trace.push(['revokeObjectURL', url])
		vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(
			function () {
				trace.push([
					'click',
					this.getAttribute('href'),
					this.download,
					this.style.display,
					this.parentNode === document.body,
				])
			},
		)
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
	})

	for (const [stateName, prepare] of Object.entries(SAVE_STATES)) {
		for (const [answerName, answerFor] of Object.entries(SAVE_ANSWERS)) {
			const key = `saveConfiguration / ${stateName} / ${answerName}`
			it(key, async () => {
				globalThis.fetch = fakeFetch(answerFor)
				check(key, await run(prepare, (store) => store.saveConfiguration()))
			})
		}
	}

	for (const action of ['clearImportStatus', 'clearExportStatus']) {
		for (const [answerName, answerFor] of Object.entries(CLEAR_ANSWERS)) {
			const key = `${action} / ${answerName}`
			it(key, async () => {
				globalThis.fetch = fakeFetch(answerFor)
				check(key, await run(runningArchiMate, (store) => store[action]()))
			})
		}
	}

	for (const [callName, args] of Object.entries(EXPORT_CALLS)) {
		for (const [answerName, answer] of Object.entries(EXPORT_ANSWERS)) {
			const key = `exportToArchiMate / ${callName} / ${answerName}`
			it(key, async () => {
				globalThis.fetch = fakeFetch(() => answer)
				const prepare = (store) => {
					if (args.length > 0) {
						store.exportOptions = {
							includeRelationships: false,
							includeViews: undefined,
						}
					}
				}
				check(
					key,
					await run(prepare, (store) => store.exportToArchiMate(...args)),
				)
			})
		}
	}
})
