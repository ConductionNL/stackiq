// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the contract panels' requests: the approval
 * panel's submit for approval or renewal (state kept, toasts, fail-closed
 * error with the server's message or the default) and the seats panel's
 * contract load (envelope unwrapping, no id, failure).
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ reply: null, fail: null, calls: [] }))
const toasts = vi.hoisted(() => ({ success: [], error: [] }))

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: async (url) => {
			http.calls.push(['get', url])
			if (http.fail) throw http.fail
			return { data: structuredClone(http.reply) }
		},
		post: async (url) => {
			http.calls.push(['post', url])
			if (http.fail) throw http.fail
			return { data: structuredClone(http.reply) }
		},
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params) => url + ' ' + JSON.stringify(params),
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => '[' + text + ']' }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (text) => toasts.error.push(text),
	showSuccess: (text) => toasts.success.push(text),
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnIcon: {},
	CnProgressBar: {},
	CnStatusBadge: {},
	CnWidgetWrapper: {},
}))

const { default: ContractApprovalPanel } =
	await import('../../src/components/contracts/ContractApprovalPanel.vue')
const { default: ContractSeatsPanel } =
	await import('../../src/components/contracts/ContractSeatsPanel.vue')

/**
 * A stand-in for a panel with its data, the given state and every method bound.
 *
 * @param {object} component The panel.
 * @param {object} state The panel state.
 * @return {object} The stand-in.
 */
function viewModel(component, state) {
	const vm = {
		...component.data.call({}),
		register: 'reg',
		schema: 'con',
		objectId: 12,
		...state,
	}
	for (const [name, fn] of Object.entries(component.methods)) {
		if (typeof fn === 'function') {
			vm[name] = fn.bind(vm)
		}
	}
	return vm
}

beforeEach(() => {
	http.reply = null
	http.fail = null
	http.calls = []
	toasts.success = []
	toasts.error = []
})

describe('ContractApprovalPanel submit', () => {
	const run = async (isRenewal, state = {}) => {
		const vm = viewModel(ContractApprovalPanel, {
			decisionId: 'old-d',
			error: 'old',
			...state,
		})
		await vm.submit(isRenewal)
		return {
			calls: http.calls,
			busy: vm.busy,
			error: vm.error,
			approvalState: vm.approvalState,
			decisionId: vm.decisionId,
			toasts: structuredClone(toasts),
		}
	}

	it('submits for approval and keeps the decision', async () => {
		http.reply = { approvalState: 'requested', decisionId: 'd-9' }
		expect(await run(false)).toEqual({
			calls: [
				[
					'post',
					'/apps/stackiq/api/contracts/{id}/approval/{path} {"id":"12","path":"submit"}',
				],
			],
			busy: false,
			error: '',
			approvalState: 'requested',
			decisionId: 'd-9',
			toasts: {
				success: ['[Contract submitted to decidesk for a decision.]'],
				error: [],
			},
		})
	})

	it('submits a renewal and falls back to pending and the old decision', async () => {
		http.reply = {}
		const out = await run(true)
		expect(out.calls[0][1]).toContain('"path":"renewal"')
		expect(out.approvalState).toBe('pending')
		expect(out.decisionId).toBe('old-d')
	})

	it('fails closed with the server message or the default', async () => {
		http.fail = Object.assign(new Error('x'), {
			response: { data: { message: 'Not allowed' } },
		})
		const refused = await run(false, { approvalState: 'none' })
		expect(refused.error).toBe('Not allowed')
		expect(refused.toasts).toEqual({ success: [], error: ['Not allowed'] })
		expect(refused.approvalState).toBe('none')
		expect(refused.busy).toBe(false)
		toasts.error = []
		http.fail = new Error('down')
		const failed = await run(false)
		expect(failed.error).toBe(
			'[Submitting the contract failed; it remains in negotiation.]',
		)
		http.fail = null
		http.reply = null
		const empty = await run(false)
		expect(empty.error).toBe(
			'[Submitting the contract failed; it remains in negotiation.]',
		)
	})
})

describe('ContractSeatsPanel load', () => {
	const load = async (reply, state = {}) => {
		http.reply = reply
		const vm = viewModel(ContractSeatsPanel, state)
		await vm.load()
		return {
			calls: http.calls,
			loading: vm.loading,
			error: vm.error,
			contract: vm.contract,
		}
	}

	it('unwraps the contract from its envelope', async () => {
		expect(await load({ '@self': {}, seats: 3 })).toEqual({
			calls: [
				[
					'get',
					'/apps/openregister/api/objects/{register}/{schema}/{id} {"register":"reg","schema":"con","id":"12"}',
				],
			],
			loading: false,
			error: false,
			contract: { '@self': {}, seats: 3 },
		})
		http.calls = []
		expect(
			(await load({ '@self': null, object: { seats: 1 } })).contract,
		).toEqual({ '@self': null, object: { seats: 1 } })
		expect((await load({ object: { seats: 2 } })).contract).toEqual({ seats: 2 })
		expect((await load({ object: null, seats: 4 })).contract).toEqual({
			object: null,
			seats: 4,
		})
		expect((await load(null)).contract).toEqual({})
	})

	it('skips without an id and flags a failure', async () => {
		http.calls = []
		expect(await load({ seats: 1 }, { objectId: '' })).toEqual({
			calls: [],
			loading: false,
			error: false,
			contract: {},
		})
		http.fail = new Error('down')
		expect(await load(null, { contract: { kept: true } })).toEqual({
			calls: [expect.any(Array)],
			loading: false,
			error: true,
			contract: { kept: true },
		})
	})
})
