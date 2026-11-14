// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * KwetsbaarhedenView as rendered. The logic spec drives its computed values
 * on a bare context; this mounts it and pins what a user sees and clicks:
 * the severity tabs with counts, a tab narrowing the table, the empty state,
 * the formatted CVSS and severity badge, a row opening the detail, and the
 * edit and delete buttons acting on their row WITHOUT also opening it.
 *
 * @spec openspec/specs/module-vulnerability-tracking/spec.md
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/composables/useLiveCollections.js', () => ({ useLiveCollections: vi.fn() }))
vi.mock('../../src/utils/objectTypeCollection.js', () => ({ fetchObjectTypeCollection: vi.fn(async () => {}) }))
const store = vi.hoisted(() => ({
	objectStore: { collections: {}, getCollection(type) { return this.collections[type] } },
	navigationStore: {},
}))
vi.mock('../../src/store/store.js', () => store)

const { default: KwetsbaarhedenView } = await import('../../src/views/KwetsbaarhedenView.vue')
const { objectStore, navigationStore } = store

const stubs = {
	NcButton: {
		props: ['ariaLabel'],
		emits: ['click'],
		template: '<button :aria-label="ariaLabel" v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>',
	},
	NcLoadingIcon: { template: '<i class="loading" />' },
	NcEmptyContent: { props: ['name'], template: '<div class="empty">{{ name }}</div>' },
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Mount the view and let its load finish.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountView() {
	const wrapper = mount(KwetsbaarhedenView, { global: { stubs } })
	await flush()
	return wrapper
}

describe('KwetsbaarhedenView rendering', () => {
	beforeEach(() => {
		objectStore.settings = {}
		objectStore.setActiveObject = vi.fn()
		objectStore.collections = {
			vulnerability: { results: [
				{ uuid: 'v1', object: { name: 'Low one', cvssScore: '3,1', modules: ['m1'] } },
				{ uuid: 'v2', name: 'Big one', cveCode: 'CVE-2026-1', cvssScore: 9.8, modules: ['m1', 'm2'] },
			] },
			usage: { results: [] },
		}
		Object.assign(navigationStore, { setTransferData: vi.fn(), setModal: vi.fn(), setDialog: vi.fn() })
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('shows the tabs with counts and the rows, most severe first', async () => {
		const wrapper = await mountView()

		expect(wrapper.findAll('.vv-tab').map((tab) => tab.text().replace(/\s+/g, ' '))).toEqual([
			'All (2)', 'Critical (1)', 'High (0)', 'Medium (0)', 'Low (1)',
		])
		expect(wrapper.find('[data-testid="vuln-tab-All"]').attributes('aria-selected')).toBe('true')
		const rows = wrapper.findAll('[data-testid="vuln-row"]')
		expect(rows.map((row) => row.find('.vv-name').text())).toEqual(['Big one', 'Low one'])
		const cells = rows[0].findAll('td').map((td) => td.text())
		expect(cells.slice(1, 6)).toEqual(['CVE-2026-1', '9.8', 'Critical', '2', '0'])
		expect(rows[1].findAll('td')[1].text()).toBe('—')
		expect(rows[1].find('[data-testid="vuln-severity-Low"]').classes()).toContain('vv-badge--low')
	})

	it('narrows the table to a tab, and shows the empty state when nothing matches', async () => {
		const wrapper = await mountView()

		await wrapper.find('[data-testid="vuln-tab-Low"]').trigger('click')
		expect(wrapper.find('[data-testid="vuln-tab-Low"]').classes()).toContain('vv-tab--active')
		expect(wrapper.findAll('[data-testid="vuln-row"]').map((row) => row.find('.vv-name').text())).toEqual(['Low one'])

		await wrapper.find('[data-testid="vuln-tab-High"]').trigger('click')
		expect(wrapper.find('[data-testid="vuln-table"]').exists()).toBe(false)
		expect(wrapper.find('.empty').text()).toBe('No vulnerabilities')
	})

	it('opens a row on click', async () => {
		const wrapper = await mountView()
		const raw = objectStore.collections.vulnerability.results[1]

		await wrapper.findAll('[data-testid="vuln-row"]')[0].trigger('click')

		expect(objectStore.setActiveObject).toHaveBeenCalledWith('vulnerability', raw)
		expect(navigationStore.setTransferData).toHaveBeenCalledWith(raw)
		expect(navigationStore.setModal).toHaveBeenCalledWith('vulnerability')
	})

	it('edits and deletes a row without also opening it', async () => {
		const wrapper = await mountView()
		const row = wrapper.findAll('[data-testid="vuln-row"]')[1]
		const raw = objectStore.collections.vulnerability.results[0]

		await row.find('button[aria-label="Edit"]').trigger('click')
		await row.find('button[aria-label="Delete"]').trigger('click')

		expect(objectStore.setActiveObject).not.toHaveBeenCalled()
		expect(navigationStore.setTransferData.mock.calls).toEqual([[raw], [raw]])
		expect(navigationStore.setModal).toHaveBeenCalledTimes(1)
		expect(navigationStore.setDialog).toHaveBeenCalledWith('deleteObject', { objectType: 'vulnerability' })
	})

	it('reports a new vulnerability from the header button', async () => {
		const wrapper = await mountView()

		await wrapper.find('[data-testid="vuln-report"]').trigger('click')

		expect(navigationStore.setTransferData).toHaveBeenCalledWith('create')
		expect(navigationStore.setModal).toHaveBeenCalledWith('vulnerability')
	})

	it('shows only the loading icon until the data is in', () => {
		const wrapper = mount(KwetsbaarhedenView, { global: { stubs } })

		expect(wrapper.find('.vv-loading').exists()).toBe(true)
		expect(wrapper.find('[data-testid="vuln-table"]').exists()).toBe(false)
	})
})
