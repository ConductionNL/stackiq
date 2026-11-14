// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CatalogPanels' loading and helpers, which the golden spec leaves alone:
 * settings are fetched only when neither present nor already loading, every
 * registered object type is asked for one row (its pagination), one failing
 * type does not stop the rest, a refresh forces the settings and waits for
 * the object types to register, and the helpers map types to icons, routes,
 * display names and the admin settings URL.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({ objectStore: {}, setSelected: null }))

vi.mock('../../src/store/store.js', () => ({
	objectStore: store.objectStore,
	navigationStore: { setSelected: (value) => store.setSelected(value) },
}))

const { default: CatalogPanels } = await import('../../src/components/CatalogPanels.vue')

const flush = () => new Promise((resolve) => setImmediate(resolve))

/**
 * Reset the store fake.
 *
 * @param {object} overrides Fields to replace.
 * @return {void}
 */
function resetStore(overrides = {}) {
	for (const key of Object.keys(store.objectStore)) {
		delete store.objectStore[key]
	}
	Object.assign(store.objectStore, {
		settings: null,
		isLoading: vi.fn(() => false),
		objectTypeRegistry: { organization: {}, contract: {} },
		fetchSettings: vi.fn(async () => {}),
		fetchCollection: vi.fn(async () => {}),
		getCollection: () => null,
		getPagination: () => null,
		...overrides,
	})
	store.setSelected = vi.fn()
}

/**
 * Mount and let the mount-time load settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountPanels() {
	const wrapper = mount(CatalogPanels, { global: { stubs: { NcLoadingIcon: true } } })
	await flush()
	return wrapper
}

describe('CatalogPanels loading', () => {
	beforeEach(() => {
		resetStore()
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		vi.spyOn(console, 'info').mockImplementation(() => {})
		vi.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
	})

	it('fetches missing settings and one row of every registered type', async () => {
		const wrapper = await mountPanels()

		expect(store.objectStore.fetchSettings).toHaveBeenCalledTimes(1)
		expect(store.objectStore.fetchCollection.mock.calls).toEqual([
			['organization', { _limit: 1 }],
			['contract', { _limit: 1 }],
		])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('does not fetch settings that are present or already loading', async () => {
		resetStore({ settings: { availableRegisters: [] } })
		await mountPanels()
		expect(store.objectStore.fetchSettings).not.toHaveBeenCalled()

		resetStore({ isLoading: vi.fn((what) => what === 'settings') })
		await mountPanels()
		expect(store.objectStore.isLoading).toHaveBeenCalledWith('settings')
		expect(store.objectStore.fetchSettings).not.toHaveBeenCalled()
	})

	it('keeps loading the other types when one fails', async () => {
		store.objectStore.fetchCollection = vi.fn(async (type) => {
			if (type === 'organization') throw new Error('boom')
		})

		const wrapper = await mountPanels()

		expect(store.objectStore.fetchCollection).toHaveBeenCalledTimes(2)
		expect(console.warn).toHaveBeenCalledWith('Failed to fetch organization collection:', expect.any(Error))
		expect(wrapper.vm.loading).toBe(false)
	})

	it('ends loading even when the settings fetch fails', async () => {
		store.objectStore.fetchSettings = vi.fn(async () => { throw new Error('down') })

		const wrapper = await mountPanels()

		expect(console.error).toHaveBeenCalledWith('Failed to load dashboard data:', expect.any(Error))
		expect(store.objectStore.fetchCollection).not.toHaveBeenCalled()
		expect(wrapper.vm.loading).toBe(false)
	})

	it('refreshes by forcing the settings, waiting, then reloading every type', async () => {
		const wrapper = await mountPanels()
		store.objectStore.fetchCollection.mockClear()
		store.objectStore.fetchCollection.mockImplementation(async (type) => {
			if (type === 'contract') throw new Error('x')
		})
		vi.useFakeTimers()

		const done = wrapper.vm.refreshAllData()
		expect(wrapper.vm.loading).toBe(true)
		await vi.advanceTimersByTimeAsync(500)
		await done

		expect(store.objectStore.fetchSettings).toHaveBeenLastCalledWith(true)
		expect(store.objectStore.fetchCollection).toHaveBeenCalledTimes(2)
		expect(console.warn).toHaveBeenCalledWith('Failed to refresh contract collection:', expect.any(Error))
		expect(wrapper.vm.loading).toBe(false)
	})

	it('warns when no types are registered after a refresh, and survives a failed one', async () => {
		const wrapper = await mountPanels()
		store.objectStore.objectTypeRegistry = {}
		store.objectStore.fetchCollection.mockClear()
		vi.useFakeTimers()

		const done = wrapper.vm.refreshAllData()
		await vi.advanceTimersByTimeAsync(500)
		await done
		expect(console.warn).toHaveBeenCalledWith('Dashboard: No object types registered after settings refresh')
		expect(store.objectStore.fetchCollection).not.toHaveBeenCalled()

		store.objectStore.fetchSettings = vi.fn(async () => { throw new Error('down') })
		await wrapper.vm.refreshAllData()
		expect(console.error).toHaveBeenCalledWith('Dashboard: Failed to refresh all data:', expect.any(Error))
		expect(wrapper.vm.loading).toBe(false)
	})
})

describe('CatalogPanels helpers', () => {
	let vm

	beforeEach(async () => {
		resetStore({ settings: { availableRegisters: [] } })
		vm = (await mountPanels()).vm
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('finds a schema configuration in any register, or null', () => {
		store.objectStore.settings = {
			schemaConfigurations: {
				a: { schemas: { contract: { id: 1 } } },
				b: { schemas: { module: { id: 2 } } },
			},
		}

		expect(vm.getSchemaConfig('module')).toEqual({ id: 2 })
		expect(vm.getSchemaConfig('nothing')).toBeNull()
		store.objectStore.settings = {}
		expect(vm.getSchemaConfig('module')).toBeNull()
	})

	it('maps object types to icons, falling back to the database icon', () => {
		expect(vm.getIconForObjectType('organization').name).toBe('OfficeBuildingOutlineIcon')
		expect(vm.getIconForObjectType('contract')).toBe(vm.getIconForObjectType('standard'))
		expect(vm.getIconForObjectType('unknown').name).toBe('DatabaseOutlineIcon')
	})

	it('navigates to the plural route of an object type', () => {
		vm.navigateToObjectType('contactPerson')
		vm.navigateToObjectType('module')
		vm.navigateToOrganizations()

		expect(store.setSelected.mock.calls).toEqual([['contactpersonen'], ['modules'], ['organisaties']])
	})

	it('navigates by schema slug only for the slugs it knows', () => {
		vm.navigateToSchema('moduleversie')
		vm.navigateToSchema('unknown')

		expect(store.setSelected.mock.calls).toEqual([['moduleversies']])
	})

	it('opens the admin settings in a new tab', () => {
		const open = vi.spyOn(window, 'open').mockImplementation(() => null)

		vm.navigateToConfiguration('ignored')

		expect(open).toHaveBeenCalledWith(
			`${window.location.protocol}//${window.location.host}/index.php/settings/admin/stackiq`,
			'_blank',
		)
	})

	it('formats type names and dates', () => {
		expect(vm.formatObjectTypeName('contactPerson')).toBe('Contact Person')
		expect(vm.formatObjectTypeName('data_quality-check')).toBe('Data Quality Check')
		const date = new Date(2026, 2, 4, 5, 6)
		expect(vm.formatDate(date)).toBe(date.toLocaleDateString('en-GB', {
			day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
		}))
	})
})
