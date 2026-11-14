// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The claimed-usage list on a supplier's page: shown only on the active
 * organisation's own page, Accept answers at once, Decline asks first, and an
 * answered claim leaves the list.
 *
 * @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import ClaimedUsageList from '../../src/components/organisations/ClaimedUsageList.vue'
import { answerClaim, claimDate, fetchClaims } from '../../src/components/organisations/claimedUsage.js'

vi.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnIcon: { render: () => null },
	CnWidgetWrapper: { inheritAttrs: true, render() { return h('section', this.$slots.default?.()) } },
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		emits: ['click'],
		render() {
			return h('button', { onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
	NcNoteCard: { render() { return h('p', { role: 'alert' }, this.$slots.default?.()) } },
	NcDialog: {
		render() {
			return h('div', { 'data-testid': 'decline-claim-dialog' }, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	},
}))

const CLAIMS = [
	{ id: 'u-1', organisation: 'Gemeente Zuiddrecht', module: 'Suite4Gemeenten', moduleVersion: '10.4', claimedAt: '2026-10-01T09:00:00+00:00' },
	{ id: 'u-2', organisation: 'Gemeente Oostmaas', module: 'Key2Financiën', moduleVersion: '2026.1', claimedAt: '2026-10-02T09:00:00+00:00' },
]

const reply = (organisation) => axios.get.mockResolvedValue({ data: { status: 'ok', organisation, results: CLAIMS } })

describe('claimed usage', () => {
	afterEach(() => vi.clearAllMocks())

	it('shows the claims only on the active organisation\'s own page', async () => {
		reply('org-centric')
		expect(await fetchClaims('org-centric')).toEqual(CLAIMS)
		expect(await fetchClaims('org-other')).toBeNull()
		expect(await fetchClaims('')).toBeNull()

		axios.get.mockRejectedValue(new Error('403'))
		expect(await fetchClaims('org-centric')).toBeNull()
	})

	it('sends an answer to the claimed-usage route and nothing else', async () => {
		await answerClaim('u-1', 'accept')
		await answerClaim('u-1', 'decline')
		expect(axios.put.mock.calls.map(([url]) => url)).toEqual([
			'/apps/stackiq/api/aanbod/claimed/u-1/accept',
			'/apps/stackiq/api/aanbod/claimed/u-1/decline',
		])
		await expect(answerClaim('u-1', 'delete')).rejects.toThrow()
		expect(claimDate('2026-10-01T09:00:00+00:00')).toBe('2026-10-01')
		expect(claimDate(null)).toBe('')
	})

	it('lists each claim with organisation, product, version and date', async () => {
		reply('org-centric')
		const wrapper = mount(ClaimedUsageList, { props: { objectId: 'org-centric' } })
		await flushPromises()

		expect(wrapper.find('[data-testid="claim-u-1"]').text()).toContain('Gemeente Zuiddrecht')
		expect(wrapper.find('[data-testid="claim-u-1"]').text()).toContain('Suite4Gemeenten')
		expect(wrapper.find('[data-testid="claim-u-1"]').text()).toContain('10.4')
		expect(wrapper.find('[data-testid="claim-u-1"]').text()).toContain('2026-10-01')
	})

	it('is not shown to a non-member', async () => {
		reply('org-centric')
		const wrapper = mount(ClaimedUsageList, { props: { objectId: 'org-other' } })
		await flushPromises()

		expect(wrapper.html()).not.toContain('Claimed usage of our offering')
		expect(wrapper.find('[data-testid="claim-u-1"]').exists()).toBe(false)
	})

	it('accepts at once and drops the claim from the list', async () => {
		reply('org-centric')
		axios.put.mockResolvedValue({ data: { status: 'ok' } })
		const wrapper = mount(ClaimedUsageList, { props: { objectId: 'org-centric' } })
		await flushPromises()

		await wrapper.find('[data-testid="claim-u-1"] button').trigger('click')
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith('/apps/stackiq/api/aanbod/claimed/u-1/accept')
		expect(wrapper.find('[data-testid="claim-u-1"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="claim-u-2"]').exists()).toBe(true)
	})

	it('asks before declining, then declines', async () => {
		reply('org-centric')
		axios.put.mockResolvedValue({ data: { status: 'ok' } })
		const wrapper = mount(ClaimedUsageList, { props: { objectId: 'org-centric' } })
		await flushPromises()

		await wrapper.findAll('[data-testid="claim-u-2"] button')[1].trigger('click')
		expect(axios.put).not.toHaveBeenCalled()
		const dialog = wrapper.find('[data-testid="decline-claim-dialog"]')
		expect(dialog.exists()).toBe(true)

		await dialog.findAll('button')[1].trigger('click')
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith('/apps/stackiq/api/aanbod/claimed/u-2/decline')
		expect(wrapper.find('[data-testid="claim-u-2"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="decline-claim-dialog"]').exists()).toBe(false)
	})

	it('keeps the claim and says so when the answer fails', async () => {
		reply('org-centric')
		axios.put.mockRejectedValue(new Error('403'))
		const wrapper = mount(ClaimedUsageList, { props: { objectId: 'org-centric' } })
		await flushPromises()

		await wrapper.find('[data-testid="claim-u-1"] button').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="claim-u-1"]').exists()).toBe(true)
		expect(wrapper.find('[role="alert"]').exists()).toBe(true)
	})
})
