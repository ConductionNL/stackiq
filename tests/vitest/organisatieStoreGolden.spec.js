/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the organisatie store's API actions: the request each
 * action sends, what it returns or throws, every console line, and the store
 * state afterwards, for a successful answer, an unsuccessful body, an HTTP
 * error with and without a readable body, and a network failure. Recorded
 * with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-stores/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params) => `/index.php${url}` + (params ? `#${JSON.stringify(params)}` : ''),
}))

const { useOrganisatieStore } = await import('../../src/store/modules/organisatie.js')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/organisatieStoreGolden.json')

function readGolden () {
	try {
		return JSON.parse(readFileSync(GOLDEN, 'utf8'))
	} catch (error) {
		if (process.env.RECORD_GOLDEN) {
			return {}
		}
		throw error
	}
}

const answers = {
	success: () => ({
		ok: true,
		status: 200,
		json: async () => ({
			success: true,
			contactpersonen: [{ id: 'c1' }],
			groups: ['g1'],
			availableGroups: ['g2'],
			userInfo: { username: 'u' },
			data: [{ id: 'd1' }],
			organisation: { users: ['alice'] },
			counts: { a: 1 },
		}),
	}),
	'success, empty': () => ({ ok: true, status: 200, json: async () => ({ success: true }) }),
	unsuccessful: () => ({ ok: true, status: 200, json: async () => ({ success: false, message: 'refused' }) }),
	'unsuccessful, no message': () => ({ ok: true, status: 200, json: async () => ({ success: false }) }),
	'http error': () => ({ ok: false, status: 403, json: async () => ({ success: false, message: 'forbidden', error: 'nope' }) }),
	'http error, bare body': () => ({ ok: false, status: 500, json: async () => ({}) }),
	'http error, unreadable body': () => ({ ok: false, status: 502, json: async () => { throw new SyntaxError('bad json') } }),
	'network failure': 'reject',
}

const actions = {
	fetchContactpersonen: (store) => store.fetchContactpersonen('org-1'),
	convertToUser: (store) => store.convertToUser('cp-1'),
	changePassword: (store) => store.changePassword('alice', 's3cret'),
	updateUserGroups: (store) => store.updateUserGroups('alice', ['g1']),
	fetchUserInfo: (store) => store.fetchUserInfo('cp-1'),
	fetchContactPersonsWithUserDetails: (store) => store.fetchContactPersonsWithUserDetails('org-1'),
	fetchAvailableGroups: (store) => store.fetchAvailableGroups(),
	disableUser: (store) => store.disableUser('cp 1'),
	enableUser: (store) => store.enableUser('cp/1'),
	dryRunMerge: (store) => store.dryRunMerge('src-1', 'tgt-1'),
	executeMerge: (store) => store.executeMerge('src-1', 'tgt-1'),
	getBulkUserInfo: (store) => store.getBulkUserInfo(['cp-1', 'cp-2']),
	fetchMembers: (store) => store.fetchMembers('org-1'),
	grantAccess: (store) => store.grantAccess('org-1', 'bob'),
	revokeAccess: (store) => store.revokeAccess('org-1', 'bob'),
}

function plain (value) {
	if (value instanceof Error) {
		return { error: value.constructor.name, message: value.message }
	}
	return value
}

let trace = []

beforeEach(() => {
	setActivePinia(createPinia())
	trace = []
	globalThis.OC = { requestToken: 'token-1' }
	for (const level of ['error', 'info', 'warn', 'log']) {
		vi.spyOn(console, level).mockImplementation((...args) => trace.push([level, ...args.map(plain)]))
	}
})

afterEach(() => {
	vi.restoreAllMocks()
	delete globalThis.fetch
})

describe('organisatie store API actions', () => {
	for (const [action, call] of Object.entries(actions)) {
		for (const [answerName, answer] of Object.entries(answers)) {
			const name = `${action} / ${answerName}`
			it(name, async () => {
				globalThis.fetch = vi.fn(async (...args) => {
					trace.push(['fetch', ...args, Object.keys(args[1] ?? {})])
					if (answer === 'reject') {
						throw new TypeError('Failed to fetch')
					}
					return answer()
				})
				const store = useOrganisatieStore()
				store.contactpersonen = [{ user: { username: 'alice', groups: [] } }]
				store.availableGroups = ['old']
				let outcome
				try {
					outcome = { returned: (await call(store)) ?? null }
				} catch (error) {
					outcome = { thrown: plain(error) }
				}
				const actual = {
					outcome,
					trace,
					state: {
						contactpersonen: store.contactpersonen,
						loading: store.loading,
						error: store.error,
						availableGroups: store.availableGroups,
					},
				}
				const recorded = JSON.parse(JSON.stringify(actual))
				if (process.env.RECORD_GOLDEN) {
					const all = { ...readGolden(), [name]: recorded }
					writeFileSync(GOLDEN, JSON.stringify(all, null, '\t') + '\n')
				}
				expect(recorded).toEqual(readGolden()[name])
			})
		}
	}
})
