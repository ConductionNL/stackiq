// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The contract approval panel as it renders: the not-configured notice with
 * no actions, the approval badge and decision reference, which submit
 * button a contract's status offers, and what a click on it posts and shows.
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => params[k] ?? m) }))
const toasts = vi.hoisted(() => [])
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@conduction/nextcloud-vue', async () => {
	const { h } = await import('vue')
	return {
		CnIcon: { render: () => h('i') },
		CnStatusBadge: {
			props: ['status', 'variant'],
			render() {
				return h('span', { class: 'badge', 'data-variant': this.variant }, this.status)
			},
		},
		CnWidgetWrapper: {
			render() {
				return h('div', [this.$slots['title-icon']?.(), this.$slots.default?.()])
			},
		},
	}
})

const { default: ContractApprovalPanel } = await import('../../src/components/contracts/ContractApprovalPanel.vue')

/**
 * Mount the panel over a delegation config and a contract.
 *
 * @param {object} config The approval config reply.
 * @param {object} contract The contract object reply.
 * @return {Promise<object>} The mounted wrapper, loaded.
 */
async function mountPanel(config, contract) {
	http.get.mockImplementation((url) => Promise.resolve({
		data: url.includes('/approval/config') ? config : contract,
	}))
	const wrapper = mount(ContractApprovalPanel, { props: { objectId: 'c-1' } })
	await flushPromises()
	return wrapper
}

/**
 * The labels of the rendered buttons.
 *
 * @param {object} wrapper The mounted panel.
 * @return {string[]} The button texts.
 */
const buttons = (wrapper) => wrapper.findAll('button').map((b) => b.text())

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	toasts.length = 0
})

describe('ContractApprovalPanel rendering', () => {
	it('shows the not-configured notice and no actions when delegation is off', async () => {
		const wrapper = await mountPanel({ configured: false }, { '@self': {}, status: 'In negotiation' })

		expect(wrapper.text()).toContain('Approval delegation is not configured on this instance.')
		expect(buttons(wrapper)).toEqual([])
		expect(wrapper.find('.badge').text()).toBe('Not submitted')
	})

	it('offers first approval for a contract in negotiation and shows the decision', async () => {
		const wrapper = await mountPanel(
			{ configured: true },
			{ '@self': {}, status: 'In negotiation', approvalState: 'rejected', approvalDecisionId: 'd-9' },
		)

		expect(buttons(wrapper)).toEqual(['Submit for approval'])
		expect(wrapper.find('.badge').text()).toBe('Rejected')
		expect(wrapper.find('.badge').attributes('data-variant')).toBe('error')
		expect(wrapper.find('.contract-approval-panel__decision code').text()).toBe('d-9')
		expect(wrapper.text()).not.toContain('Approval delegation is not configured')
	})

	it('offers renewal for an expired contract that is not pending', async () => {
		const wrapper = await mountPanel({ configured: true }, { object: { status: 'Expired', approvalState: 'approved' } })

		expect(buttons(wrapper)).toEqual(['Submit renewal'])
		expect(wrapper.find('.badge').attributes('data-variant')).toBe('success')
	})

	it('offers nothing while a decision is pending', async () => {
		const wrapper = await mountPanel({ configured: true }, { '@self': {}, status: 'Expired', approvalState: 'pending' })

		expect(buttons(wrapper)).toEqual([])
		expect(wrapper.find('.badge').text()).toBe('Pending decision')
	})

	it('posts a renewal on click and shows the returned decision', async () => {
		const wrapper = await mountPanel({ configured: true }, { '@self': {}, status: 'Expired', approvalState: 'none' })
		http.post.mockResolvedValueOnce({ data: { approvalState: 'pending', decisionId: 'd-1' } })

		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/stackiq/api/contracts/c-1/approval/renewal')
		expect(wrapper.find('.badge').text()).toBe('Pending decision')
		expect(wrapper.find('.contract-approval-panel__decision code').text()).toBe('d-1')
		expect(buttons(wrapper)).toEqual([])
		expect(toasts).toEqual([['success', 'Contract submitted to decidesk for a decision.']])
	})

	it('shows the server message inline when a submission fails', async () => {
		const wrapper = await mountPanel({ configured: true }, { '@self': {}, status: 'In negotiation' })
		http.post.mockRejectedValueOnce({ response: { data: { message: 'decidesk unreachable' } } })

		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/stackiq/api/contracts/c-1/approval/submit')
		expect(wrapper.find('.note-card-stub').text()).toBe('decidesk unreachable')
		expect(buttons(wrapper)).toEqual(['Submit for approval'])
		expect(toasts).toEqual([['error', 'decidesk unreachable']])
	})

	it('shows the load error when the config cannot be read', async () => {
		http.get.mockRejectedValue(new Error('down'))
		const wrapper = mount(ContractApprovalPanel, { props: { objectId: 'c-1' } })
		await flushPromises()

		expect(wrapper.text()).toContain('Could not load the approval state.')
		expect(buttons(wrapper)).toEqual([])
	})
})
