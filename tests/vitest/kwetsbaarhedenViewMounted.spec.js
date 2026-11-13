// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Mounts the vulnerabilities view: the severity tabs with their counts, the
 * table rows (name, CVE, CVSS, badge, affected and exposed counts), the empty
 * and loading states, and what each button and row click hands the
 * navigation store.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/composables/useLiveCollections.js', () => ({ useLiveCollections: vi.fn() }))
vi.mock('../../src/utils/objectTypeCollection.js', () => ({ fetchObjectTypeCollection: vi.fn() }))
const store = vi.hoisted(() => ({
	objectStore: { collections: {}, getCollection(type) { return this.collections[type] } },
	navigationStore: {},
}))
vi.mock('../../src/store/store.js', () => store)

const { default: KwetsbaarhedenView } = await import('../../src/views/KwetsbaarhedenView.vue')
const { fetchObjectTypeCollection } = await import('../../src/utils/objectTypeCollection.js')
const { objectStore, navigationStore } = store

const critical = { uuid: 'v2', name: 'Big one', cveCode: 'CVE-2026-1', cvssScore: 9.8, modules: ['m1', 'm2'] }
const low = { uuid: 'v1', object: { name: 'Low one', cvssScore: '3,1', modules: ['m1'] } }
const unknown = { uuid: 'v3', cvssScore: 'n/a' }

/**
 * Mount the view and let its load settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mounted() {
	const wrapper = mount(KwetsbaarhedenView)
	await flushPromises()
	return wrapper
}

/**
 * The buttons whose aria-label is the given text.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} label The aria-label.
 * @return {Array<object>} The matching DOM wrappers.
 */
const buttons = (wrapper, label) => wrapper.findAll(`[aria-label="${label}"]`)

beforeEach(() => {
	objectStore.collections = {
		vulnerability: { results: [low, critical, unknown] },
		usage: { results: [] },
	}
	objectStore.settings = {}
	objectStore.fetchSettings = vi.fn()
	objectStore.setActiveObject = vi.fn()
	Object.assign(navigationStore, { setTransferData: vi.fn(), setModal: vi.fn(), setDialog: vi.fn() })
	fetchObjectTypeCollection.mockReset()
	fetchObjectTypeCollection.mockResolvedValue(undefined)
})

describe('KwetsbaarhedenView mounted', () => {
	it('loads both collections and lists rows most severe first', async () => {
		const wrapper = await mounted()

		expect(fetchObjectTypeCollection.mock.calls.map((c) => c[1]).sort()).toEqual(['usage', 'vulnerability'])
		const rows = wrapper.findAll('[data-testid="vuln-row"]')
		expect(rows).toHaveLength(3)
		expect(rows[0].findAll('td').slice(0, 6).map((td) => td.text())).toEqual([
			'Big one',
			'CVE-2026-1',
			'9.8',
			'Critical',
			'2',
			'0',
		])
		expect(rows[0].get('[data-testid="vuln-severity-Critical"]').classes()).toContain('vv-badge--critical')
		const lowCells = rows.find((row) => row.text().includes('Low one')).findAll('td')
		expect(lowCells[1].text()).toBe('—')
		expect(lowCells[2].text()).toBe('3.1')
		expect(lowCells[4].text()).toBe('1')
		const unknownCells = rows.find((row) => row.find('[data-testid="vuln-severity-Unknown"]').exists()).findAll('td')
		expect(unknownCells[0].text()).toBe('Unnamed vulnerability')
		expect(unknownCells[2].text()).toBe('—')
	})

	it('shows each severity tab with its count and marks the selected one', async () => {
		const wrapper = await mounted()

		const tabs = wrapper.findAll('[role="tab"]')
		expect(tabs.map((tab) => tab.text().replace(/\s+/g, ' '))).toEqual([
			'All (3)',
			'Critical (1)',
			'High (0)',
			'Medium (0)',
			'Low (1)',
		])
		expect(tabs[0].attributes('aria-selected')).toBe('true')
		expect(tabs[0].classes()).toContain('vv-tab--active')
	})

	it('filters the rows by the clicked tab and shows the empty state for a band without rows', async () => {
		const wrapper = await mounted()

		await wrapper.get('[data-testid="vuln-tab-Low"]').trigger('click')
		expect(wrapper.findAll('[data-testid="vuln-row"]').map((row) => row.find('.vv-name').text())).toEqual(['Low one'])
		expect(wrapper.get('[data-testid="vuln-tab-Low"]').attributes('aria-selected')).toBe('true')

		await wrapper.get('[data-testid="vuln-tab-High"]').trigger('click')
		expect(wrapper.find('[data-testid="vuln-table"]').exists()).toBe(false)
		const empty = wrapper.findComponent({ name: 'NcEmptyContent' })
		expect(empty.exists()).toBe(true)
		expect(empty.attributes('name') ?? empty.props('name')).toBe('No vulnerabilities')
	})

	it('shows a loading state instead of the table while loading', async () => {
		let release
		fetchObjectTypeCollection.mockReturnValue(new Promise((resolve) => { release = resolve }))
		const wrapper = mount(KwetsbaarhedenView)
		await flushPromises()

		expect(wrapper.find('.vv-loading').exists()).toBe(true)
		expect(wrapper.find('[data-testid="vuln-table"]').exists()).toBe(false)

		release()
		await flushPromises()
		expect(wrapper.find('.vv-loading').exists()).toBe(false)
		expect(wrapper.find('[data-testid="vuln-table"]').exists()).toBe(true)
	})

	it('opens an empty vulnerability modal from Report', async () => {
		const wrapper = await mounted()

		await wrapper.get('[data-testid="vuln-report"]').trigger('click')

		expect(navigationStore.setTransferData).toHaveBeenCalledWith('create')
		expect(navigationStore.setModal).toHaveBeenCalledWith('vulnerability')
	})

	it('opens the clicked row as the active object', async () => {
		const wrapper = await mounted()

		await wrapper.findAll('[data-testid="vuln-row"]')[0].trigger('click')

		expect(objectStore.setActiveObject).toHaveBeenCalledWith('vulnerability', critical)
		expect(navigationStore.setTransferData).toHaveBeenCalledWith(critical)
		expect(navigationStore.setModal).toHaveBeenCalledWith('vulnerability')
	})

	it('edits and deletes a row from its own buttons without opening the row', async () => {
		const wrapper = await mounted()

		await buttons(wrapper, 'Edit')[0].trigger('click')
		expect(navigationStore.setTransferData).toHaveBeenLastCalledWith(critical)
		expect(navigationStore.setModal).toHaveBeenLastCalledWith('vulnerability')

		await buttons(wrapper, 'Delete')[0].trigger('click')
		expect(navigationStore.setTransferData).toHaveBeenLastCalledWith(critical)
		expect(navigationStore.setDialog).toHaveBeenCalledWith('deleteObject', { objectType: 'vulnerability' })
		expect(objectStore.setActiveObject).not.toHaveBeenCalled()
	})

	it('reloads the data from Refresh', async () => {
		const wrapper = await mounted()
		fetchObjectTypeCollection.mockClear()

		await buttons(wrapper, 'Refresh data')[0].trigger('click')
		await flushPromises()

		expect(fetchObjectTypeCollection).toHaveBeenCalledTimes(2)
	})
})
