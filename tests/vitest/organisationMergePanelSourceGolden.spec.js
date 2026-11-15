// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for how the organisation merge panel loads its source
 * organisation: the guard, the request, the unwrapping of the reply, the
 * fields set afterwards and the fallback on failure. Recorded with
 * RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/organisation-merge/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

const http = { reply: null, fail: false, calls: [] }

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: async (url) => {
			http.calls.push(['get', url])
			if (http.fail) throw new Error('down')
			return { data: structuredClone(http.reply) }
		},
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params) => url + ' ' + JSON.stringify(params),
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => '[' + text + ']' }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: () => {} }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
	NcSelect: {},
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnIcon: {}, CnWidgetWrapper: {} }))
vi.mock('vue-material-design-icons/ArrowRight.vue', () => ({ default: {} }))
vi.mock('vue-material-design-icons/Eye.vue', () => ({ default: {} }))
vi.mock('../../src/modals/object/MergeOrganisationConfirmDialog.vue', () => ({
	default: {},
}))
vi.mock('../../src/store/store.js', () => ({
	organisatieStore: {},
	settingsStore: {},
}))

const { default: OrganisationMergePanel } =
	await import('../../src/components/organisations/OrganisationMergePanel.vue')

const GOLDEN = path.resolve(
	import.meta.dirname,
	'golden/organisationMergePanelSource.json',
)

const FULL = { name: 'Gemeente A', status: 'merged', mergedInto: 'org-b' }

const CASES = {
	'no object id': { objectId: '' },
	'zero object id': { objectId: 0 },
	'self envelope': { reply: { '@self': {}, ...FULL } },
	'self envelope with object': {
		reply: { '@self': null, object: { name: 'inner' }, name: 'outer' },
	},
	'object wrapper': { reply: { object: FULL } },
	'plain reply': { reply: { name: 'Plain' } },
	'empty object wrapper': { reply: { object: null, name: 'Fallback' } },
	'no name': { reply: { status: 'active' } },
	'null reply': { reply: null },
	'request fails': { fail: true },
	'numeric id': { objectId: 42, reply: FULL },
}

/**
 * Load the source once for a case and record what happened.
 *
 * @param {object} setup The case.
 * @return {Promise<object>} The requests and the panel state afterwards.
 */
async function record(setup) {
	http.reply = setup.reply === undefined ? FULL : setup.reply
	http.fail = Boolean(setup.fail)
	http.calls = []
	const vm = {
		...OrganisationMergePanel.data.call({}),
		register: 'reg',
		schema: 'org',
		objectId: setup.objectId === undefined ? 'uuid-1' : setup.objectId,
	}
	for (const [name, fn] of Object.entries(OrganisationMergePanel.methods)) {
		vm[name] = fn.bind(vm)
	}
	await vm.loadSource()
	return {
		calls: http.calls,
		loading: vm.loading,
		sourceName: vm.sourceName,
		status: vm.status,
		mergedInto: vm.mergedInto,
	}
}

describe('OrganisationMergePanel loadSource golden', () => {
	it('matches the recorded outcomes', async () => {
		const actual = {}
		for (const [name, setup] of Object.entries(CASES)) {
			actual[name] = await record(setup)
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(actual, null, '\t') + '\n')
		}
		expect(actual).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
