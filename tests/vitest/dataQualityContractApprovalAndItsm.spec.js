// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The data quality report (rules per organisation), the contract approval
 * panel (when a contract may be submitted or renewed, and the decision it
 * gets) and the ITSM exchange settings (desks, organisations and setting up
 * the flows), each run against a plain component context with axios, the
 * admin API and the object store doubled.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = vi.hoisted(() => [])
const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => params[k] ?? m) }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {}, CnIcon: {}, CnKpiGrid: {}, CnProgressBar: {}, CnStatsBlock: {}, CnStatusBadge: {}, CnWidgetWrapper: {} }))
vi.mock('../../src/components/AlwaysVisibleSection.vue', () => ({ default: {} }))
vi.mock('../../src/composables/useLiveCollections.js', () => ({ useLiveCollections: vi.fn() }))
const api = vi.hoisted(() => ({ apiRequest: vi.fn() }))
vi.mock('../../src/utils/adminApi.js', () => api)
const store = vi.hoisted(() => ({ objectStore: { collections: {}, getCollection(type) { return this.collections[type] } } }))
vi.mock('../../src/store/store.js', () => store)

const { default: DataQualityReport } = await import('../../src/views/organisaties/DataQualityReport.vue')
const { default: ContractApprovalPanel } = await import('../../src/components/contracts/ContractApprovalPanel.vue')
const { default: ItsmExchange } = await import('../../src/views/settings/sections/ItsmExchange.vue')

/**
 * A component context: props and extras, methods bound, data, and computed
 * values as getters.
 *
 * @param {object} comp The component definition.
 * @param {object} [props] Its props.
 * @return {object} The context.
 */
function context(comp, props = {}) {
	const ctx = { $emit: vi.fn(), ...props }
	for (const [name, fn] of Object.entries(comp.methods || {})) {
		ctx[name] = fn.bind(ctx)
	}
	Object.assign(ctx, comp.data ? comp.data.call(ctx) : {})
	for (const [name, def] of Object.entries(comp.computed || {})) {
		Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
	}
	return ctx
}

beforeEach(() => {
	toasts.length = 0
	http.get.mockReset()
	http.post.mockReset()
	api.apiRequest.mockReset()
	store.objectStore.collections = {}
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('DataQualityReport', () => {
	const report = () => context(DataQualityReport)

	it('picks the only organisation and loads its rules', async () => {
		http.get.mockResolvedValueOnce({ data: { rules: [{ type: 'freshness', field: 'confirmedAt', title: 'Confirmation', met: 3, total: 5 }, { type: 'format', field: 'email', met: 1, total: 2 }, { type: 'filled', field: 'owner', met: 0, total: 2 }] } })
		const r = report()
		DataQualityReport.watch.organisationOptions.handler.call(r, [{ uuid: 'o1', label: 'A' }])
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/stackiq/api/data-quality', { params: { organisation: 'o1' } })
		expect(r.ruleItems).toEqual([
			{ key: 'freshness:confirmedAt', label: 'Confirmation is recent: 3 of 5 meet it', count: 3, total: 5 },
			{ key: 'format:email', label: 'email has the right format: 1 of 2 meet it', count: 1, total: 2 },
			{ key: 'filled:owner', label: 'owner is filled in: 0 of 2 meet it', count: 0, total: 2 },
		])
		DataQualityReport.watch.organisationOptions.handler.call(report(), [{ uuid: 'a' }, { uuid: 'b' }])
		expect(http.get).toHaveBeenCalledTimes(1)
		expect(r.columns.map((c) => c.key)).toEqual(['name', 'completeness', 'missing', 'confirmedAt'])
	})

	it('lists organisations, clears without one and explains a failure', async () => {
		store.objectStore.collections.organization = { results: [{ uuid: 'o1', object: { title: 'B' } }] }
		const r = report()
		expect(r.organisationOptions).toEqual([{ uuid: 'o1', label: 'B' }])
		r.report = { rules: [] }
		await r.loadReport()
		expect([r.report, r.ruleItems]).toEqual([null, []])
		r.selectedOrg = { uuid: 'o1' }
		const errors = []
		for (const failure of [{ response: { data: { message: 'M' } } }, { response: { data: { error: 'E' } } }, {}]) {
			http.get.mockRejectedValueOnce(failure)
			await r.loadReport()
			errors.push(r.error)
		}
		expect([errors, r.loading]).toEqual([['M', 'E', 'The data quality could not be loaded.'], false])
		expect([r.formatDate('garbage'), r.formatDate('2026-05-15')]).toEqual(['garbage', new Date('2026-05-15').toLocaleDateString()])
	})
})

describe('ContractApprovalPanel', () => {
	const panel = (objectId = 'k1') => context(ContractApprovalPanel, { objectId, register: 'stackiq', schema: 'catalogContract' })

	it('reads whether approval is configured and the contract state', async () => {
		http.get.mockImplementation(async (url) => (url.endsWith('/config') ? { data: { configured: 1 } } : { data: { object: { status: 'In negotiation', approvalDecisionId: 'd1' } } }))
		const p = panel()
		await ContractApprovalPanel.mounted.call(p)
		expect([http.get.mock.calls[1][0], p.configured, p.status, p.approvalState, p.decisionId, p.loading]).toEqual(['/index.php/apps/openregister/api/objects/stackiq/catalogContract/k1', true, 'In negotiation', 'none', 'd1', false])
		expect([p.canSubmitApproval, p.canSubmitRenewal, p.approvalStateLabel, p.approvalStateVariant]).toEqual([true, false, 'Not submitted', 'default'])
		http.get.mockImplementation(async (url) => (url.endsWith('/config') ? { data: {} } : { data: { '@self': {}, status: 'Expired', approvalState: 'rejected' } }))
		await p.load()
		expect([p.configured, p.canSubmitApproval, p.canSubmitRenewal, p.approvalStateLabel, p.approvalStateVariant]).toEqual([false, false, true, 'Rejected', 'error'])
	})

	it('reads no contract without an id and says when loading fails', async () => {
		http.get.mockResolvedValue({ data: { configured: true } })
		const none = panel('')
		await none.load()
		expect([http.get.mock.calls.length, none.status]).toEqual([1, ''])
		http.get.mockRejectedValueOnce(new Error('x'))
		await none.load()
		expect([none.error, none.loading]).toEqual(['Could not load the approval state.', false])
	})

	it('allows a submission only in negotiation and a renewal only once expired', () => {
		const p = panel()
		const states = [['In negotiation', 'rejected'], ['In negotiation', 'pending'], ['Expired', 'pending'], ['Expired', 'approved'], ['Active', 'none']]
		expect(states.map(([status, approvalState]) => {
			Object.assign(p, { status, approvalState })
			return [p.canSubmitApproval, p.canSubmitRenewal]
		})).toEqual([[true, false], [false, false], [false, false], [false, true], [false, false]])
		p.approvalState = 'pending'
		expect([p.approvalStateLabel, p.approvalStateVariant]).toEqual(['Pending decision', 'warning'])
		p.approvalState = 'approved'
		expect(p.approvalStateVariant).toBe('success')
		p.approvalState = 'odd'
		expect([p.approvalStateLabel, p.approvalStateVariant]).toEqual(['odd', 'default'])
	})

	it('submits for approval or renewal and keeps the decision', async () => {
		const p = panel()
		p.decisionId = 'old'
		http.post.mockResolvedValueOnce({ data: { approvalState: 'pending', decisionId: 'd9' } })
		await p.submit(false)
		http.post.mockResolvedValueOnce({ data: {} })
		await p.submit(true)
		expect([http.post.mock.calls.map((c) => c[0]), p.approvalState, p.decisionId, toasts.length]).toEqual([['/index.php/apps/stackiq/api/contracts/k1/approval/submit', '/index.php/apps/stackiq/api/contracts/k1/approval/renewal'], 'pending', 'd9', 2])
		http.post.mockRejectedValueOnce({ response: { data: { message: 'Decidesk is down' } } })
		await p.submit(false)
		http.post.mockRejectedValueOnce({})
		await p.submit(false)
		expect([toasts.slice(2), p.busy]).toEqual([[['error', 'Decidesk is down'], ['error', 'Submitting the contract failed; it remains in negotiation.']], false])
	})
})

describe('ItsmExchange', () => {
	const section = () => context(ItsmExchange)
	const config = { desk: 'topdesk', organisation: 'o2', setUpAt: '2026-05-01T10:00:00Z', desks: [{ id: 'topdesk', label: 'TOPdesk' }, { id: 'jira', label: 'Jira' }] }

	it('loads the configuration and the organisations it can exchange with', async () => {
		api.apiRequest.mockResolvedValue(config)
		http.get.mockImplementation(async (url, options) => (url.endsWith('/voorzieningen/config')
			? { data: { config: { register: 'vz', organisatie_schema: 'org' } } }
			: { data: { results: [{ '@self': { id: 'o1', name: 'Self name' } }, { id: 'o2', name: 'Gemeente A' }, { id: 's1', type: 'Supplier', name: 'Vendor' }] }, options }))
		const s = section()
		await ItsmExchange.mounted.call(s)
		expect([http.get.mock.calls[1][0], http.get.mock.calls[1][1]]).toEqual(['/index.php/apps/openregister/api/objects/vz/org', { params: { _limit: 500 } }])
		expect([s.organisations, s.desk, s.organisation, s.deskLabel, s.setUpDate, s.loading]).toEqual([[{ id: 'o1', name: 'Self name' }, { id: 'o2', name: 'Gemeente A' }], { id: 'topdesk', label: 'TOPdesk' }, { id: 'o2', name: 'Gemeente A' }, 'TOPdesk', new Date('2026-05-01T10:00:00Z').toLocaleDateString(), false])
	})

	it('explains a missing organisation register and a failed load', async () => {
		api.apiRequest.mockResolvedValue({ desk: 'other' })
		http.get.mockResolvedValueOnce({ data: { config: {} } })
		const s = section()
		await s.load()
		expect([s.organisationLoadError, s.desk, s.deskLabel, s.setUpDate]).toEqual(['The organisation register is not configured, so there are no organisations to choose from.', null, 'other', ''])
		http.get.mockRejectedValueOnce(new Error('x'))
		await s.loadOrganisations()
		expect([s.organisationLoadError, s.loadingOrganisations]).toEqual(['The organisations could not be loaded. Reload the page to try again.', false])
		api.apiRequest.mockRejectedValueOnce(new Error('Not an admin'))
		await s.load()
		expect([s.refusal, s.loading]).toEqual(['Not an admin', false])
	})

	it('sets up the flows and reloads the configuration, or shows the refusal', async () => {
		const s = section()
		Object.assign(s, { desk: { id: 'jira' }, organisation: { id: 'o1' }, templateId: 'tpl' })
		api.apiRequest.mockResolvedValueOnce({ flows: { a: 1, b: 2, c: 3 } }).mockResolvedValueOnce({ desk: 'jira', desks: [{ id: 'jira', label: 'Jira' }] })
		await s.setUp()
		expect([api.apiRequest.mock.calls[0], toasts[0], s.deskLabel, s.saving]).toEqual([['itsm/setup', { method: 'POST', body: { desk: 'jira', organisation: 'o1', templateId: 'tpl' } }], ['success', '3 flows are set up. The first import runs tonight.'], 'Jira', false])
		api.apiRequest.mockRejectedValueOnce(new Error('Desk unreachable'))
		await s.setUp()
		expect([s.refusal, s.saving]).toEqual(['Desk unreachable', false])
	})
})
