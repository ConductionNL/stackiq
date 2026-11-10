// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for two helpers of the ArchiMate settings section: the missing
 * configuration items read from an import error (the current and the legacy
 * message format), and the organisation options loaded for the export (the
 * requests, the options, and the console lines when the config or a request
 * fails). Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-settings-ui/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/store.js', () => ({ settingsStore: {} }))

const { default: ArchiMateImportExport } =
	await import('../../src/views/settings/sections/ArchiMateImportExport.vue')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/archiMateConfig.json')

const MESSAGES = {
	empty: '',
	none: null,
	'schema not configured':
		"Schema ID for element 'ApplicationComponent' is not configured in settings",
	'register not configured': 'The AMEF register ID is not configured',
	'other not configured': 'Something is not configured',
	'legacy list':
		'Import failed.\nMissing configuration:\n- Register ID\n  -  Schema ID  \n\n- Third\nPlease configure them.\n- after end',
	'legacy list repeated header':
		'Missing configuration:\n- One\nMissing configuration:\n- Two',
	'legacy without list': 'Import failed for another reason\n- not an item',
	'legacy list at end': 'x\nMissing configuration:\n-A\n-   B',
}

const CONFIG = { config: { register: 'voorz', organisatie_schema: 'org' } }

const LOADS = {
	'config request fails': [{ ok: false }],
	'config without register': [
		{ ok: true, body: { config: { organisatie_schema: 'org' } } },
	],
	'config without schema': [{ ok: true, body: { config: { register: 'voorz' } } }],
	'no config': [{ ok: true, body: null }],
	'organisations request fails': [{ ok: true, body: CONFIG }, { ok: false }],
	'organisations as results': [
		{ ok: true, body: CONFIG },
		{
			ok: true,
			body: {
				results: [
					{ id: 'b', '@self': { name: 'Bravo' } },
					{ '@self': { id: 'a', name: 'alpha' } },
					{ id: 'c', name: 'Charlie' },
					{ id: 'd', contactsUid: 'delta-uid' },
					{ id: 'e' },
					{ '@self': { name: 'No id' } },
				],
			},
		},
	],
	'organisations as a list': [
		{ ok: true, body: CONFIG },
		{ ok: true, body: [{ id: 'x', name: 'Xray' }] },
	],
	'organisations empty': [
		{ ok: true, body: CONFIG },
		{ ok: true, body: { results: [] } },
	],
	'organisations not a list': [
		{ ok: true, body: CONFIG },
		{ ok: true, body: { results: 'none' } },
	],
	'network failure': ['reject'],
}

/**
 * The organisation options a load leaves, with its requests and console lines.
 *
 * @param {Array} answers The fetch answers in order.
 * @return {Promise<object>} The outcome.
 */
async function load(answers) {
	const trace = []
	const queue = structuredClone(answers)
	vi.stubGlobal('OC', { requestToken: 'token' })
	vi.stubGlobal('fetch', async (url, init) => {
		trace.push(['fetch', url, init])
		const answer = queue.shift()
		if (answer === 'reject') {
			throw new TypeError('Failed to fetch')
		}
		return { ok: answer.ok, json: async () => structuredClone(answer.body) }
	})
	vi.spyOn(console, 'debug').mockImplementation((...args) =>
		trace.push(['console.debug', ...args]),
	)
	vi.spyOn(console, 'warn').mockImplementation((...args) =>
		trace.push(['console.warn', ...args]),
	)
	const vm = { organizationOptions: [{ label: 'Generic', value: null }] }
	await ArchiMateImportExport.methods.loadOrganizations.call(vm)
	return { trace, organizationOptions: vm.organizationOptions }
}

describe('ArchiMate settings helpers golden', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
		vi.restoreAllMocks()
	})

	it('reads the same missing items and loads the same organisation options', async () => {
		const out = { missing: {}, organisations: {} }
		for (const [name, message] of Object.entries(MESSAGES)) {
			out.missing[name] =
				ArchiMateImportExport.methods.getMissingConfigItems.call({}, message)
		}
		for (const [name, answers] of Object.entries(LOADS)) {
			out.organisations[name] = await load(answers)
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(out, null, '\t') + '\n')
		}
		expect(out).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
