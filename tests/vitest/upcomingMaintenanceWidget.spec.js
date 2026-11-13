// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the upcoming-maintenance widget, mounted: the request for
 * the active organisation, the store reads it makes, the rendered rows and
 * labels, the empty state and the error state. Dates are relative to now so
 * the tests do not depend on the clock.
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-002-organisations-that-use-a-product-see-its-planned-maintenance
 */

import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
	fetchCollectionForOptions: null,
	ensureObjectType: null,
}))

vi.mock('../../src/store/store.js', () => ({
	objectStore: {
		fetchCollectionForOptions: (...args) => mocks.fetchCollectionForOptions(...args),
	},
}))
vi.mock('../../src/utils/objectTypes.js', () => ({
	ensureObjectType: (...args) => mocks.ensureObjectType(...args),
}))

const { default: UpcomingMaintenanceWidget } =
	await import('../../src/components/maintenance/UpcomingMaintenanceWidget.vue')

const DAY = 24 * 60 * 60 * 1000

/**
 * An ISO date the given number of days from now.
 *
 * @param {number} days Days from now.
 * @return {string} The ISO date.
 */
function inDays(days) {
	return new Date(Date.now() + days * DAY).toISOString()
}

/**
 * Answer the `/api/me` request with the given organisation.
 *
 * @param {object} response What fetch resolves to.
 * @return {object} The fetch mock.
 */
function me(response) {
	const fetch = vi.fn(async () => response)
	vi.stubGlobal('fetch', fetch)
	return fetch
}

/**
 * Mount the widget and wait for it to load.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mounted() {
	const wrapper = mount(UpcomingMaintenanceWidget, { global: { stubs: { RouterLink: RouterLinkStub } } })
	await flushPromises()
	return wrapper
}

describe('UpcomingMaintenanceWidget', () => {
	beforeEach(() => {
		vi.stubGlobal('OC', { requestToken: 'token-1' })
		mocks.ensureObjectType = vi.fn()
		mocks.fetchCollectionForOptions = vi.fn(async () => [])
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('reads the windows on the products the active organisation uses', async () => {
		const fetch = me({ ok: true, json: async () => ({ organisations: { active: { uuid: 'org-1' } } }) })
		mocks.fetchCollectionForOptions = vi.fn(async (type) => {
			if (type === 'usage') {
				return [{ module: 'mod-1' }, { module: { id: 'mod-2' } }]
			}
			return [
				{ id: 'w-2', status: 'planned', module: { id: 'mod-2', name: 'Second app' }, title: 'Upgrade', startsAt: inDays(5), endsAt: inDays(6), impact: 'unavailable' },
				{ id: 'w-1', status: 'planned', module: 'mod-1', title: 'Patch', startsAt: inDays(2), impact: 'degraded' },
				{ id: 'w-3', status: 'planned', module: 'mod-1', title: 'Far away', startsAt: inDays(60), impact: 'no impact' },
			]
		})

		const wrapper = await mounted()

		expect(fetch).toHaveBeenCalledWith('/index.php/apps/stackiq/api/me', { headers: { requesttoken: 'token-1' } })
		expect(mocks.ensureObjectType.mock.calls).toEqual([['usage'], ['maintenanceWindow']])
		expect(mocks.fetchCollectionForOptions.mock.calls[0][0]).toBe('usage')
		expect(mocks.fetchCollectionForOptions.mock.calls[0][1]).toMatchObject({ consumer: 'org-1' })
		expect(mocks.fetchCollectionForOptions.mock.calls[1][1]).toMatchObject({ module: ['mod-1', 'mod-2'], status: 'planned' })

		const items = wrapper.findAll('[data-testid="upcoming-maintenance-item"]')
		expect(items.map((i) => i.find('.upcoming-maintenance__title').text())).toEqual(['Patch', 'Upgrade'])
		expect(items.map((i) => i.find('.upcoming-maintenance__impact').text())).toEqual(['Degraded', 'Unavailable'])
		expect(items.map((i) => i.find('.upcoming-maintenance__module').text())).toEqual(['Application', 'Second app'])
		const links = wrapper.findAllComponents(RouterLinkStub)
		expect(links.map((l) => l.props('to'))).toEqual([
			{ name: 'ModuleDetail', params: { id: 'mod-1' } },
			{ name: 'ModuleDetail', params: { id: 'mod-2' } },
		])
		expect(items[0].find('.upcoming-maintenance__when').text()).toMatch(/^.+ to$/)
		expect(items[1].find('.upcoming-maintenance__when').text()).toMatch(/^.+ to .+$/)
		expect(wrapper.find('.upcoming-maintenance__empty').exists()).toBe(false)
	})

	it('shows the empty text when the user has no active organisation', async () => {
		me({ ok: true, json: async () => ({ organisations: {} }) })
		const wrapper = await mounted()
		expect(mocks.fetchCollectionForOptions).not.toHaveBeenCalled()
		expect(wrapper.find('.upcoming-maintenance__empty').text()).toBe('No maintenance planned on the applications you use in the next 30 days')
	})

	it('treats a failed profile request as no organisation', async () => {
		me({ ok: false, json: async () => { throw new Error('not called') } })
		const wrapper = await mounted()
		expect(mocks.fetchCollectionForOptions).not.toHaveBeenCalled()
		expect(wrapper.find('.upcoming-maintenance__empty').exists()).toBe(true)
	})

	it('shows the error note when a read fails', async () => {
		me({ ok: true, json: async () => ({ organisations: { active: { uuid: 'org-1' } } }) })
		mocks.fetchCollectionForOptions = vi.fn(async () => {
			throw new Error('down')
		})
		const wrapper = await mounted()
		expect(wrapper.text()).toContain('The planned maintenance could not be loaded.')
		expect(wrapper.vm.windows).toEqual([])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('labels unknown impacts as empty and names modules from @self', () => {
		const vm = UpcomingMaintenanceWidget.methods
		expect(vm.impactLabel('no impact')).toBe('No impact')
		expect(vm.impactLabel('other')).toBe('')
		expect(vm.moduleName({ module: { '@self': { name: 'Self name' } } })).toBe('Self name')
		expect(vm.moduleName({ module: 'mod-1' })).toBe('Application')
	})
})
