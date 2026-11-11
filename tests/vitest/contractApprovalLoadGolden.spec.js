// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for how the contract approval panel loads its contract: the
 * guard, the request, the unwrapping of the reply and the fields set
 * afterwards. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ reply: null, fail: false, calls: [] }))

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
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/dialogs', () => ({ showError: () => {}, showSuccess: () => {} }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnIcon: {},
	CnStatusBadge: {},
	CnWidgetWrapper: {},
}))

const { default: ContractApprovalPanel } =
	await import('../../src/components/contracts/ContractApprovalPanel.vue')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/contractApprovalLoad.json')

const FULL = {
	status: 'active',
	approvalState: 'pending',
	approvalDecisionId: 'd-1',
}

const CASES = {
	'no object id': { objectId: '' },
	'self envelope': { reply: { '@self': {}, ...FULL } },
	'self envelope with object': {
		reply: { '@self': null, object: { status: 'inner' }, status: 'outer' },
	},
	'object wrapper': { reply: { object: FULL } },
	'plain reply': { reply: { status: 'draft' } },
	'empty object wrapper': { reply: { object: null, approvalState: 'approved' } },
	'nothing set': { reply: {} },
	'numeric id': { objectId: 42, reply: FULL },
	'null reply': { reply: null },
	'request fails': { fail: true },
}

/**
 * Load the contract once for a case and record what happened.
 *
 * @param {object} setup The case.
 * @return {Promise<object>} The requests, the error if one escaped, and the fields afterwards.
 */
async function record(setup) {
	http.reply = setup.reply === undefined ? FULL : setup.reply
	http.fail = Boolean(setup.fail)
	http.calls = []
	const vm = {
		...ContractApprovalPanel.data.call({}),
		register: 'reg',
		schema: 'contract',
		objectId: setup.objectId === undefined ? 'uuid-1' : setup.objectId,
	}
	for (const [name, fn] of Object.entries(ContractApprovalPanel.methods)) {
		vm[name] = fn.bind(vm)
	}
	let escaped = null
	try {
		await vm.loadContract()
	} catch (e) {
		escaped = e.constructor.name
	}
	return {
		calls: http.calls,
		escaped,
		status: vm.status,
		approvalState: vm.approvalState,
		decisionId: vm.decisionId,
	}
}

describe('ContractApprovalPanel loadContract golden', () => {
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
