// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the CatalogPanels methods the golden test does not reach:
 * loading and refreshing the dashboard data (and surviving failures), the
 * schema configuration lookup, icons, routes, the settings link and the
 * object type names.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

const store = vi.hoisted(() => ({ objectStore: {}, selected: [] }))

vi.mock('../../src/store/store.js', () => ({
	objectStore: store.objectStore,
	navigationStore: { setSelected: (value) => store.selected.push(value) },
}))

const { default: CatalogPanels } = await import('../../src/components/CatalogPanels.vue')

/**
 * Reset the store double with the given overrides.
 *
 * @param {object} overrides Store members to set.
 * @return {object} The store double.
 */
function useStore(overrides = {}) {
	for (const key of Object.keys(store.objectStore)) {
		delete store.objectStore[key]
	}
	store.selected.length = 0
	Object.assign(store.objectStore, {
		settings: null,
		isLoading: () => false,
		objectTypeRegistry: {},
		fetchSettings: vi.fn(async () => {}),
		fetchCollection: vi.fn(async () => {}),
		getCollection: () => null,
		getPagination: () => null,
	}, overrides)
	return store.objectStore
}

/**
 * Mount the panels and let the mount-time load finish.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountPanels() {
	const wrapper = mount(CatalogPanels, { global: { stubs: { NcLoadingIcon: true } } })
	await nextTick()
	await new Promise((resolve) => setImmediate(resolve))
	return wrapper
}

describe('CatalogPanels data loading', () => {
	beforeEach(() => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		vi.spyOn(console, 'error').mockImplementation(() => {})
		vi.spyOn(console, 'info').mockImplementation(() => {})
	})

	afterEach(() => {
		vi.restoreAllMocks()
		vi.useRealTimers()
	})

	it('loads settings once and asks each registered type for one row', async () => {
		const objectStore = useStore({ objectTypeRegistry: { organization: {}, contract: {} } })

		const wrapper = await mountPanels()

		expect(objectStore.fetchSettings).toHaveBeenCalledTimes(1)
		expect(objectStore.fetchCollection.mock.calls).toEqual([
			['organization', { _limit: 1 }],
			['contract', { _limit: 1 }],
		])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('does not refetch settings that are present or already loading', async () => {
		const present = useStore({ settings: {} })
		await mountPanels()
		expect(present.fetchSettings).not.toHaveBeenCalled()

		const loading = useStore({ isLoading: (key) => key === 'settings' })
		await mountPanels()
		expect(loading.fetchSettings).not.toHaveBeenCalled()
	})

	it('warns about one failing type and still finishes', async () => {
		const failure = new Error('boom')
		useStore({
			objectTypeRegistry: { organization: {}, contract: {} },
			fetchCollection: vi.fn(async (type) => {
				if (type === 'contract') {
					throw failure
				}
			}),
		})

		const wrapper = await mountPanels()

		expect(console.warn).toHaveBeenCalledWith('Failed to fetch contract collection:', failure)
		expect(wrapper.vm.loading).toBe(false)
	})

	it('logs a failing settings load and stops loading', async () => {
		const failure = new Error('no settings')
		useStore({ fetchSettings: vi.fn(async () => { throw failure }) })

		const wrapper = await mountPanels()

		expect(console.error).toHaveBeenCalledWith('Failed to load dashboard data:', failure)
		expect(wrapper.vm.loading).toBe(false)
	})

	it('refreshes settings by force, then every registered collection', async () => {
		const objectStore = useStore({ settings: {}, objectTypeRegistry: { standard: {} } })
		const wrapper = await mountPanels()
		objectStore.fetchCollection.mockClear()
		vi.useFakeTimers()

		const done = wrapper.vm.refreshAllData()
		expect(wrapper.vm.loading).toBe(true)
		await vi.advanceTimersByTimeAsync(500)
		await done

		expect(objectStore.fetchSettings).toHaveBeenCalledWith(true)
		expect(objectStore.fetchCollection.mock.calls).toEqual([['standard', { _limit: 1 }]])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('warns when no types are registered after a refresh, and logs a failed refresh', async () => {
		const objectStore = useStore({ settings: {} })
		const wrapper = await mountPanels()
		vi.useFakeTimers()

		const empty = wrapper.vm.refreshAllData()
		await vi.advanceTimersByTimeAsync(500)
		await empty
		expect(console.warn).toHaveBeenCalledWith('Dashboard: No object types registered after settings refresh')

		const failure = new Error('down')
		objectStore.fetchSettings.mockRejectedValueOnce(failure)
		await wrapper.vm.refreshAllData()
		expect(console.error).toHaveBeenCalledWith('Dashboard: Failed to refresh all data:', failure)
		expect(wrapper.vm.loading).toBe(false)
	})

	it('warns about one collection that fails to refresh', async () => {
		const failure = new Error('timeout')
		const objectStore = useStore({ settings: {}, objectTypeRegistry: { suite: {} } })
		const wrapper = await mountPanels()
		objectStore.fetchCollection.mockRejectedValueOnce(failure)
		vi.useFakeTimers()

		const done = wrapper.vm.refreshAllData()
		await vi.advanceTimersByTimeAsync(500)
		await done

		expect(console.warn).toHaveBeenCalledWith('Failed to refresh suite collection:', failure)
	})
})

describe('CatalogPanels helpers', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('finds a schema configuration in any register', async () => {
		useStore({ settings: { schemaConfigurations: { a: { schemas: {} }, b: { schemas: { contract: { id: 9 } } }, c: null } } })
		const wrapper = await mountPanels()

		expect(wrapper.vm.getSchemaConfig('contract')).toEqual({ id: 9 })
		expect(wrapper.vm.getSchemaConfig('missing')).toBeNull()

		useStore({ settings: {} })
		expect(wrapper.vm.getSchemaConfig('contract')).toBeNull()
	})

	it('picks an icon per type and a default for unknown types', async () => {
		useStore({ settings: {} })
		const wrapper = await mountPanels()

		expect(wrapper.vm.getIconForObjectType('organization')).not.toBe(wrapper.vm.getIconForObjectType('contactPerson'))
		expect(wrapper.vm.getIconForObjectType('suite')).toBe(wrapper.vm.getIconForObjectType('module'))
		expect(wrapper.vm.getIconForObjectType('standard')).toBe(wrapper.vm.getIconForObjectType('contract'))
		expect(wrapper.vm.getIconForObjectType('unknown')).not.toBe(wrapper.vm.getIconForObjectType('suite'))
	})

	it('navigates to plural routes, the organisations page and only known schema pages', async () => {
		useStore({ settings: {} })
		const wrapper = await mountPanels()

		wrapper.vm.navigateToObjectType('organization')
		wrapper.vm.navigateToObjectType('contactPerson')
		wrapper.vm.navigateToObjectType('voorziening')
		wrapper.vm.navigateToObjectType('contract')
		wrapper.vm.navigateToObjectType('suite')
		wrapper.vm.navigateToOrganizations()
		wrapper.vm.navigateToSchema('compliancy')
		wrapper.vm.navigateToSchema('moduleversie')
		wrapper.vm.navigateToSchema('unknown')

		expect(store.selected).toEqual([
			'organisaties', 'contactpersonen', 'voorzieningen', 'contracten', 'suites',
			'organisaties', 'komplianties', 'moduleversies',
		])
	})

	it('opens the admin settings in a new tab', async () => {
		useStore({ settings: {} })
		const open = vi.spyOn(window, 'open').mockImplementation(() => null)
		const wrapper = await mountPanels()

		wrapper.vm.navigateToConfiguration('ignored')

		expect(open).toHaveBeenCalledWith(`${window.location.protocol}//${window.location.host}/index.php/settings/admin/stackiq`, '_blank')
	})

	it('turns camelCase, kebab-case and snake_case into title case', async () => {
		useStore({ settings: {} })
		const wrapper = await mountPanels()

		expect(wrapper.vm.formatObjectTypeName('contactPerson')).toBe('Contact Person')
		expect(wrapper.vm.formatObjectTypeName('module-versie')).toBe('Module Versie')
		expect(wrapper.vm.formatObjectTypeName('data_set')).toBe('Data Set')
	})

	it('formats a date as day/month/year with the time', async () => {
		useStore({ settings: {} })
		const wrapper = await mountPanels()
		const date = new Date(2026, 2, 4, 5, 6)

		expect(wrapper.vm.formatDate(date)).toBe(
			date.toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }),
		)
		expect(wrapper.vm.formatDate(date)).toContain('04/03/2026')
	})
})
