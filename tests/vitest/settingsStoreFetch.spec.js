/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the settings store actions that call the app's API, plus
 * the two that rebuild the configuration and the whole state: for each action and each kind of answer (a full answer, success false, an empty
 * body, HTTP 500, a network failure) it records every fetch call, toast and
 * console line, the action's return value or rejection, and the store state
 * afterwards. Recorded with RECORD_GOLDEN=1.
 *
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

const GOLDEN = path.resolve(import.meta.dirname, 'golden/settingsStoreFetch.json')

const FULL = {
	success: true,
	message: 'done',
	error: 'none',
	openRegisters: true,
	isAdmin: true,
	availableRegisters: [{ id: 1, title: 'R' }],
	catalogLocation: 'https://catalog.example',
	syncTimeWindow: 20,
	versionInfo: { appVersion: '1.2.3' },
	isFullyConfigured: true,
	configurationStatus: { ok: true },
	emailSettings: { host: 'smtp.example' },
	emailTemplates: { welcome: 'Hi' },
	config: {
		catalogLocation: 'https://config.example',
		syncTimeWindow: '15',
		superUser: 'admin',
		organizationAdmin: 'org-admins',
		generic: 'users',
		allGroups: ['admin', 'users'],
		register: 7,
		voorzieningen_register: 3,
		amef_register: 4,
	},
	counts: {
		voorzieningen: { organisatie: 2 },
		amef: { element: 5 },
		timestamp: '2026-11-02T00:00:00Z',
	},
	status: {
		import: { status: 'done' },
		export: { status: 'running' },
	},
}

const ANSWERS = {
	full: { ok: true, status: 200, statusText: 'OK', body: FULL },
	'success false': {
		ok: true,
		status: 200,
		statusText: 'OK',
		body: { success: false, error: 'not allowed', message: 'no' },
	},
	'empty body': { ok: true, status: 200, statusText: 'OK', body: {} },
	'http 500': { ok: false, status: 500, statusText: 'Server Error', body: {} },
	'network failure': 'reject',
}

const ACTIONS = [
	['loadStatistics'],
	['loadSettings'],
	['loadOpenRegisterEssentials'],
	['loadVoorzieningenConfigFocused'],
	['loadAmefConfigFocused'],
	['loadGeneralConfig'],
	['loadSyncConfig'],
	['loadVersionInfo'],
	['loadArchiMateStatus'],
	['loadObjectCounts'],
	['loadEmailConfig'],
	['loadUserGroupsConfig'],
	['loadUserGroupsOnly'],
	['loadAmefConfig'],
	['loadVoorzieningenConfig'],
	['refreshArchiMateStatus'],
	['consolidatedAutoConfigure'],
	['resetAutoConfig'],
	['forceUpdate'],
	['testEmailConnection'],
	['sendTestEmail', 'test@example.org'],
	['initializeConfiguration'],
	['reset'],
]

// State written before the actions that rebuild state, so the golden shows what they reset.
const DIRTY = {
	configuration: { amef_element_schema: { schema: 9 }, stray: { schema: 1 } },
	statistics: { timestamp: 'old', voorzieningen: { configured: true } },
	settings: { catalogLocation: 'old' },
	isImportRunning: true,
	superUserGroups: ['x'],
}
const REBUILDS = new Set(['initializeConfiguration', 'reset'])

/**
 * A fetch double that answers every call the same way and records it.
 *
 * @param {object|string} answer The scripted answer, or 'reject'.
 * @return {Function} The fetch double.
 */
function fakeFetch(answer) {
	return async (url, options) => {
		trace.push(['fetch', url, options ?? '(no options)'])
		if (answer === 'reject') {
			throw new TypeError('Failed to fetch')
		}
		return {
			ok: answer.ok,
			status: answer.status,
			statusText: answer.statusText,
			json: async () => structuredClone(answer.body),
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
 * Run one action against one answer.
 *
 * @param {Array} action The action name and its arguments.
 * @param {object|string} answer The scripted answer.
 * @return {Promise<object>} What happened.
 */
async function run([name, ...args], answer) {
	trace.length = 0
	setActivePinia(createPinia())
	const store = useSettingsStore()
	if (REBUILDS.has(name)) {
		store.$patch(structuredClone(DIRTY))
	}
	globalThis.fetch = fakeFetch(answer)
	const stopStatusPolling = store.stopStatusPolling
	store.stopStatusPolling = () => {
		trace.push(['stopStatusPolling'])
		return stopStatusPolling()
	}
	let outcome
	try {
		outcome = { returned: await store[name](...args) }
	} catch (error) {
		outcome = { rejected: error }
	}
	// Let the fire-and-forget loads that some actions start settle too.
	for (let i = 0; i < 20; i++) {
		await Promise.resolve()
	}
	await new Promise((resolve) => setTimeout(resolve, 0))
	stopStatusPolling()
	return plain({
		outcome,
		trace: [...trace],
		state: store.$state,
		allGroups: store.allGroups,
	})
}

const recorded = {}

describe('settings store API actions', () => {
	beforeEach(() => {
		for (const level of ['info', 'warn', 'error', 'log']) {
			vi.spyOn(console, level).mockImplementation((...args) =>
				trace.push([`console.${level}`, ...args]),
			)
		}
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	for (const action of ACTIONS) {
		for (const [answerName, answer] of Object.entries(ANSWERS)) {
			const key = `${action[0]} / ${answerName}`
			it(key, async () => {
				recorded[key] = await run(action, answer)
				if (process.env.RECORD_GOLDEN) {
					writeFileSync(
						GOLDEN,
						JSON.stringify(recorded, null, '\t') + '\n',
					)
					return
				}
				const expected = JSON.parse(readFileSync(GOLDEN, 'utf8'))
				expect(recorded[key]).toEqual(expected[key])
			})
		}
	}
})
