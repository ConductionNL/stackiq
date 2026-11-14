// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the suite wizard's Step2Applications loadApplications:
 * when the settings are fetched, when and with which register the module
 * type is registered, the collection fetch, the error text, the log line
 * and the loading state. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/suite-wizard/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const trace = []
const store = vi.hoisted(() => ({ objectStore: {} }))

vi.mock('../../src/store/store.js', () => store)
// The component reads Nextcloud's global `t`, which the page provides.
globalThis.t = (app, text) => `${app}:${text}`

const { default: Step2Applications } =
	await import('../../src/dialogs/SuiteWizard/Step2Applications.vue')

const GOLDEN = path.resolve(
	import.meta.dirname,
	'golden/suiteWizardLoadApplications.json',
)

const withRegister = { voorzieningen: { register: 'reg-1' } }

const cases = {
	'settings present, module not registered': { settings: withRegister },
	'settings present, module already registered': {
		settings: withRegister,
		registry: { module: {} },
	},
	'register from voorzieningenConfig': {
		settings: { voorzieningenConfig: { register: 'reg-2' } },
	},
	'voorzieningen wins over voorzieningenConfig': {
		settings: {
			voorzieningen: { register: 'reg-1' },
			voorzieningenConfig: { register: 'reg-2' },
		},
	},
	'empty voorzieningen falls back': {
		settings: {
			voorzieningen: null,
			voorzieningenConfig: { register: 'reg-2' },
		},
	},
	'no register id': { settings: { voorzieningen: {} } },
	'empty settings object': { settings: {} },
	'no settings, fetched': { settings: null, fetchedSettings: withRegister },
	'no settings, nothing fetched': { settings: null, fetchedSettings: null },
	'no settings and no fetchSettings': { settings: null, noFetchSettings: true },
	'no registerObjectType': { settings: withRegister, noRegister: true },
	'no fetchCollection': { settings: withRegister, noFetchCollection: true },
	'registry without module key': {
		settings: withRegister,
		registry: { suite: {} },
	},
	'fetchSettings fails': { settings: null, fetchSettingsThrows: true },
	'fetchCollection fails': { settings: withRegister, fetchThrows: true },
	'registerObjectType fails': { settings: withRegister, registerThrows: true },
}

/**
 * Build the fake object store for one case.
 *
 * @param {object} input The case.
 * @return {object} The fake store.
 */
function fakeStore(input) {
	const objectStore = {
		settings: structuredClone(input.settings),
		objectTypeRegistry: input.registry,
	}
	if (!input.noFetchSettings) {
		objectStore.fetchSettings = async () => {
			trace.push(['fetchSettings'])
			if (input.fetchSettingsThrows) {
				throw new Error('settings failed')
			}
			store.objectStore.settings = structuredClone(input.fetchedSettings)
		}
	}
	if (!input.noRegister) {
		objectStore.registerObjectType = (...args) => {
			trace.push(['registerObjectType', ...args])
			if (input.registerThrows) {
				throw new Error('register failed')
			}
		}
	}
	if (!input.noFetchCollection) {
		objectStore.fetchCollection = async (...args) => {
			trace.push(['fetchCollection', ...args])
			if (input.fetchThrows) {
				throw new Error('fetch failed')
			}
		}
	}
	return objectStore
}

/**
 * Run loadApplications for one case against a fake component.
 *
 * @param {object} input The case.
 * @return {Promise<object>} The trace and the component state afterwards.
 */
async function run(input) {
	trace.length = 0
	for (const key of Object.keys(store.objectStore)) {
		delete store.objectStore[key]
	}
	Object.assign(store.objectStore, fakeStore(input))
	const self = { loading: false, loadError: 'earlier' }
	for (const [name, method] of Object.entries(Step2Applications.methods)) {
		self[name] ??= method.bind(self)
	}
	let outcome = 'resolved'
	const pending = Step2Applications.methods.loadApplications.call(self)
	trace.push(['while running', { loading: self.loading, error: self.loadError }])
	try {
		await pending
	} catch (error) {
		outcome = `rejected: ${error.constructor.name}`
	}
	const state = { loading: self.loading, loadError: self.loadError }
	return JSON.parse(
		JSON.stringify({ outcome, state, trace: [...trace] }, (key, value) =>
			value === undefined ? '(undefined)' : value,
		),
	)
}

describe('Step2Applications loadApplications', () => {
	beforeEach(() => {
		vi.spyOn(console, 'error').mockImplementation((...args) =>
			trace.push(['console.error', ...args.map(String)]),
		)
	})

	for (const [name, input] of Object.entries(cases)) {
		it(name, async () => {
			const actual = await run(input)
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
