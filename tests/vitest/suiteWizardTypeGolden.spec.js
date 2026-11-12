// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for how the suite wizard registers the `suite` object type:
 * reading missing settings, finding the voorzieningen register and
 * registering the type only when it can and must. Recorded with
 * RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/suite-wizard/spec.md#requirement-the-system-shall-register-the-suite-and-module-object-types-by-schema-slug
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => {
	globalThis.t = (app, text) => text
	globalThis.n = (app, one, many, count) => (count === 1 ? one : many)
	return { objectStore: {} }
})

vi.mock('../../src/store/store.js', () => store)
vi.mock('@conduction/nextcloud-vue', () => ({ CnWizardDialog: {} }))
vi.mock('../../src/dialogs/SuiteWizard/Step1Details.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/SuiteWizard/Step2Applications.vue', () => ({
	default: {},
}))
vi.mock('../../src/dialogs/SuiteWizard/Step3Confirm.vue', () => ({ default: {} }))

const { default: SuiteWizardDialog } =
	await import('../../src/dialogs/SuiteWizardDialog.vue')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/suiteWizardType.json')

const VZ = { voorzieningen: { register: 7 } }

const STORES = {
	'settings present': { settings: VZ },
	'settings fetched': { settings: null, fetched: VZ },
	'settings missing, no fetch': { settings: null, noFetch: true },
	'config fallback': { settings: { voorzieningenConfig: { register: 'vz' } } },
	'voorzieningen wins': {
		settings: {
			voorzieningen: { register: 1 },
			voorzieningenConfig: { register: 2 },
		},
	},
	'empty voorzieningen falls back': {
		settings: { voorzieningen: {}, voorzieningenConfig: { register: 2 } },
	},
	'no register': { settings: { voorzieningen: { register: 0 } } },
	'already registered': { settings: VZ, registry: { suite: true } },
	'other types registered': { settings: VZ, registry: { module: true } },
	'store cannot register': { settings: VZ, noRegister: true },
	'empty settings object': { settings: {} },
}

/**
 * Run the registration against one store and record the calls.
 *
 * @param {object} setup The store state.
 * @return {Promise<Array>} The calls.
 */
async function record(setup) {
	const trace = []
	const fake = { settings: setup.settings, objectTypeRegistry: setup.registry }
	if (!setup.noFetch) {
		fake.fetchSettings = async () => {
			trace.push(['fetchSettings'])
			fake.settings = setup.fetched ?? null
		}
	}
	if (!setup.noRegister) {
		fake.registerObjectType = (...args) =>
			trace.push(['registerObjectType', ...args])
	}
	store.objectStore = fake
	await SuiteWizardDialog.methods.ensureSuiteTypeRegistered.call({})
	return trace
}

describe('SuiteWizardDialog ensureSuiteTypeRegistered golden', () => {
	it('matches the recorded outcomes', async () => {
		const actual = {}
		for (const [name, setup] of Object.entries(STORES)) {
			actual[name] = await record(setup)
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(actual, null, '\t') + '\n')
		}
		expect(actual).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
