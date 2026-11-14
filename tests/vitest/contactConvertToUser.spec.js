// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for ContactpersonenList's convertToUser: which store call is
 * made for which contact person, the loading flag around it, how the row is
 * replaced with the API answer, the refresh, every toast and log line, and
 * the error paths. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-organizations/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ContactpersonenList from '../../src/components/ContactpersonenList.vue'

const trace = []

vi.mock('@nextcloud/dialogs', () => ({
	showError: (...args) => trace.push(['showError', ...args]),
	showSuccess: (...args) => trace.push(['showSuccess', ...args]),
}))
vi.mock('../../src/store/modules/organisatie.js', () => ({
	useOrganisatieStore: () => ({}),
}))

const GOLDEN = path.resolve(import.meta.dirname, 'golden/contactConvertToUser.json')

const apiPerson = {
	id: 'new-1',
	voornaam: 'Ada',
	achternaam: 'Lovelace',
	'e-mailadres': 'ada@example.org',
	name: 'Ada Lovelace',
	organization: 'org-1',
	username: 'ada',
	groups: ['gebruik-raadpleger'],
	extra: 'dropped',
}

const cases = {
	'not found': {
		rows: [{ id: 'a' }],
		person: { id: 'zz' },
		store: 'result',
		result: {},
	},
	'row is not an object': {
		rows: ['plain'],
		person: {},
		store: 'result',
		result: {},
	},
	'row is null': {
		rows: [null],
		person: { id: 'a' },
		store: 'result',
		result: {},
	},
	'replaced with the API person': {
		rows: [{ id: 'x' }, { id: 'a', data: { voornaam: 'old' } }, { id: 'y' }],
		person: { id: 'a' },
		store: 'result',
		result: { contactPerson: apiPerson },
	},
	'matched on uuid, id from @self, no groups': {
		rows: [{ uuid: 'u1' }],
		person: { id: 'u1' },
		store: 'result',
		result: {
			contactPerson: { '@self': { id: 'self-1' }, voornaam: 'B' },
		},
	},
	'id wins over @self': {
		rows: [{ id: 'a' }],
		person: { id: 'a' },
		store: 'result',
		result: { contactPerson: { id: 'own', '@self': { id: 'self' } } },
	},
	'no contactPerson in the answer': {
		rows: [{ id: 'a', loading: false }],
		person: { id: 'a' },
		store: 'result',
		result: { message: 'ok' },
	},
	'store call fails': {
		rows: [{ id: 'a' }],
		person: { id: 'a' },
		store: 'throw',
		result: null,
	},
	'refresh fails after the row was replaced': {
		rows: [{ id: 'a' }],
		person: { id: 'a' },
		store: 'result',
		result: { contactPerson: apiPerson },
		refreshThrows: true,
	},
	'store call fails on a uuid row': {
		rows: [{ id: '', uuid: 'a' }, { id: 'b' }],
		person: { id: 'a' },
		store: 'throw',
		result: null,
	},
}

/**
 * Run convertToUser for one case against a fake component.
 *
 * @param {object} input The case.
 * @return {Promise<object>} The trace and the rows afterwards.
 */
async function run(input) {
	trace.length = 0
	const rows = structuredClone(input.rows)
	const self = {
		organisationData: { contactpersonen: rows },
		organisatieStore: {
			convertToUser: async (id) => {
				trace.push([
					'store.convertToUser',
					id,
					rows.map((row) => row?.loading ?? '(no flag)'),
				])
				if (input.store === 'throw') {
					throw new Error('boom')
				}
				return structuredClone(input.result)
			},
		},
		refreshUserData: async () => {
			trace.push(['refreshUserData'])
			if (input.refreshThrows) {
				throw new Error('refresh failed')
			}
		},
		t: (app, text, vars) => `${app}:${text}:${JSON.stringify(vars ?? null)}`,
	}
	let outcome = 'resolved'
	try {
		await ContactpersonenList.methods.convertToUser.call(
			self,
			structuredClone(input.person),
		)
	} catch (error) {
		outcome = `rejected: ${error.constructor.name}`
	}
	return JSON.parse(
		JSON.stringify({ outcome, trace: [...trace], rows }, (key, value) =>
			value === undefined ? '(undefined)' : value,
		),
	)
}

describe('ContactpersonenList convertToUser', () => {
	const actual = {}

	beforeEach(() => {
		vi.spyOn(console, 'info').mockImplementation((...args) =>
			trace.push(['console.info', ...args]),
		)
		vi.spyOn(console, 'error').mockImplementation((...args) =>
			trace.push(['console.error', ...args.map(String)]),
		)
	})

	for (const [name, input] of Object.entries(cases)) {
		it(name, async () => {
			actual[name] = await run(input)
			if (process.env.RECORD_GOLDEN) {
				const all = { ...readGolden(), [name]: actual[name] }
				writeFileSync(GOLDEN, JSON.stringify(all, null, '\t') + '\n')
			}
			expect(actual[name]).toEqual(readGolden()[name])
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
