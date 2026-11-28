// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The SaaS subscriptions section of the License posture page: every SaaS usage
 * with its supplier, organisation, buying department and yearly cost, and the
 * Bought outside IT filter.
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/saas-subscriptions/spec.md#requirement-the-licences-page-lists-saas-subscriptions-with-their-yearly-cost-including-ones-bought-outside-it-req-clr-001
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/composables/useLiveCollections.js', () => ({ useLiveCollections: vi.fn() }))
vi.mock('../../src/utils/objectTypeCollection.js', () => ({ fetchObjectTypeCollection: vi.fn() }))
const store = vi.hoisted(() => ({
	objectStore: { collections: {}, getCollection(type) { return this.collections[type] } },
}))
vi.mock('../../src/store/store.js', () => store)

const { default: LicensePostureView } = await import('../../src/views/LicensePostureView.vue')
const { objectStore } = store

const stubs = {
	NcSelect: { name: 'NcSelect', props: ['modelValue', 'options'], template: '<div class="select-stub" />' },
	RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}

/**
 * Mount the view and let its load settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mounted() {
	const wrapper = mount(LicensePostureView, { global: { stubs } })
	await flushPromises()
	return wrapper
}

/**
 * The cell texts of each SaaS row.
 *
 * @param {object} wrapper The wrapper.
 * @return {Array<Array<string>>} The rows.
 */
function saasRows(wrapper) {
	return wrapper.findAll('[data-testid="posture-saas-row"]').map((row) => row.findAll('td').map((td) => td.text()))
}

beforeEach(() => {
	objectStore.settings = {}
	objectStore.collections = {
		organization: { results: [{ uuid: 'org-gem', name: 'Gemeente A' }, { uuid: 'org-menti', name: 'Mentimeter B.V.' }] },
		module: { results: [{ uuid: 'm-menti', name: 'Mentimeter', provider: 'org-menti' }, { uuid: 'm-zaak', name: 'Zaaksysteem' }] },
		usage: {
			results: [
				{ uuid: 'u-menti', module: 'm-menti', consumer: 'org-gem', cloudDienstverleningsmodel: ['SaaS'], buyingDepartment: 'Communicatie', boughtOutsideIt: true },
				{ uuid: 'u-zaak', module: 'm-zaak', consumer: 'org-gem', cloudDienstverleningsmodel: ['SaaS'] },
				{ uuid: 'u-onprem', module: 'm-zaak', consumer: 'org-gem', cloudDienstverleningsmodel: ['On-premises (self-managed)'] },
			],
		},
		catalogContract: { results: [{ uuid: 'c1', usage: 'u-menti', cost: 1200, costPeriod: 'Annually' }] },
	}
})

describe('the SaaS subscriptions section', () => {
	it('lists every SaaS usage with supplier, organisation, department and yearly cost', async () => {
		const wrapper = await mounted()
		const rows = saasRows(wrapper)

		expect(rows).toHaveLength(2)
		expect(rows[0].slice(0, 5)).toEqual(['Mentimeter', 'Mentimeter B.V.', 'Gemeente A', 'Communicatie', 'Yes'])
		expect(rows[0][5]).toMatch(/1\.200/)
		expect(rows[1]).toEqual(['Zaaksysteem', '', 'Gemeente A', '', 'No', '—'])
	})

	it('keeps only the subscriptions bought outside IT under the filter', async () => {
		const wrapper = await mounted()
		await wrapper.get('[data-testid="posture-saas-outside-it"]').trigger('click')

		expect(saasRows(wrapper).map((row) => row[0])).toEqual(['Mentimeter'])
		expect(wrapper.get('[data-testid="posture-saas-outside-it"]').attributes('aria-pressed')).toBe('true')

		await wrapper.get('[data-testid="posture-saas-all"]').trigger('click')
		expect(saasRows(wrapper)).toHaveLength(2)
	})

	it('says so when nothing was bought outside IT', async () => {
		objectStore.collections.usage.results[0].boughtOutsideIt = false
		const wrapper = await mounted()
		await wrapper.get('[data-testid="posture-saas-outside-it"]').trigger('click')

		expect(saasRows(wrapper)).toEqual([])
		expect(wrapper.get('[data-testid="posture-saas"] .empty-content-stub').attributes('name')).toBe('No subscriptions bought outside IT')
	})
})
