// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for how the contact person list loads its users' info and the
 * available groups once per session: the guards, the parallel requests, the
 * update with the bulk info, the console lines and the flags afterwards.
 * Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-organizations/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/dialogs', () => ({ showError: () => {}, showSuccess: () => {} }))
vi.mock('../../src/store/modules/organisatie.js', () => ({
	useOrganisatieStore: () => ({}),
}))

const { default: ContactpersonenList } =
	await import('../../src/components/ContactpersonenList.vue')

const GOLDEN = path.resolve(
	import.meta.dirname,
	'golden/contactpersonenUserInfo.json',
)

const CASES = {
	'already loading': { userStatusRefreshInProgress: true },
	'already loaded': { userInfoLoaded: true },
	'no organisation': { organisationData: null },
	'no contact persons': { organisationData: { name: 'x' } },
	'contacts without ids': {
		organisationData: { contactpersonen: [{ id: '' }, { uuid: '  ' }, {}] },
	},
	'contacts with info': {
		organisationData: {
			contactpersonen: [{ id: 'c1' }, { uuid: 'c2' }, { id: '' }],
		},
		bulk: { c1: { uid: 'u1' } },
	},
	'empty bulk info': {
		organisationData: { contactpersonen: [{ id: 'c1' }] },
		bulk: {},
	},
	'null bulk info': {
		organisationData: { contactpersonen: [{ id: 'c1' }] },
		bulk: null,
	},
	'groups fail': {
		organisationData: { contactpersonen: [{ id: 'c1' }] },
		groupsFail: true,
	},
}

/**
 * Load once for a case and record what happened.
 *
 * @param {object} setup The case.
 * @return {Promise<object>} The trace and flags.
 */
async function record(setup) {
	const trace = []
	vi.spyOn(console, 'info').mockImplementation((...args) =>
		trace.push(['console.info', ...structuredClone(args)]),
	)
	vi.spyOn(console, 'error').mockImplementation((...args) =>
		trace.push(['console.error', args[0], String(args[1])]),
	)
	const vm = {
		userStatusRefreshInProgress: false,
		userInfoLoaded: false,
		organisationData: null,
		...structuredClone({ ...setup, bulk: undefined, groupsFail: undefined }),
		organisatieStore: {
			fetchAvailableGroups: async () => {
				trace.push(['fetchAvailableGroups'])
				if (setup.groupsFail) throw new Error('groups down')
				return ['g']
			},
			getBulkUserInfo: async (ids) => {
				trace.push(['getBulkUserInfo', ids])
				return structuredClone(setup.bulk)
			},
		},
	}
	for (const [name, fn] of Object.entries(ContactpersonenList.methods)) {
		vm[name] = fn.bind(vm)
	}
	vm.updateContactpersonenWithUserInfo = (info) =>
		trace.push(['updateContactpersonenWithUserInfo', info])
	await vm.loadUserInfoAndGroups()
	return {
		trace,
		inProgress: vm.userStatusRefreshInProgress,
		loaded: vm.userInfoLoaded,
	}
}

describe('contact person list user info golden', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('loads the same', async () => {
		const out = {}
		for (const [name, setup] of Object.entries(CASES)) {
			out[name] = await record(setup)
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(out, null, '\t') + '\n')
		}
		expect(out).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
