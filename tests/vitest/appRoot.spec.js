// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the app root, mounted: the settings load, the organisations
 * read from `/api/me` and handed to the shell and the switcher, the active
 * organisation recorded for OpenRegister calls, the failure fallbacks, the
 * permissions and translator passed to CnAppRoot, and the object sidebar
 * channel.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({ loadSettings: null }))

vi.mock('../../src/store/store.js', () => ({
	settingsStore: { loadSettings: (...args) => mocks.loadSettings(...args) },
}))
vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent: define, h: render, inject: injected } = await import('vue')
	return {
		CnAppRoot: define({
			name: 'CnAppRoot',
			props: {
				manifest: { type: Object, default: null },
				appId: { type: String, default: '' },
				translate: { type: Function, default: null },
				permissions: { type: Array, default: () => [] },
				initialOrganisationUuid: { type: String, default: null },
				initialOrganisation: { type: Object, default: null },
				formatters: { type: Object, default: null },
				cellWidgets: { type: Object, default: null },
			},
			setup(props, { slots }) {
				const sidebarState = injected('objectSidebarState')
				return () => render('div', { class: 'app-root-stub' }, [
					render('span', { class: 'probe' }, sidebarState.active ? 'active' : 'idle'),
					slots.sidebar ? slots.sidebar({ pageSidebarComponent: null }) : null,
					slots['header-actions'] ? slots['header-actions']() : null,
				])
			},
		}),
		CnObjectSidebar: define({
			name: 'CnObjectSidebar',
			props: { title: { type: String, default: '' } },
			setup: (props) => () => render('aside', { class: 'sidebar-stub' }, props.title),
		}),
	}
})
vi.mock('../../src/components/organisations/OrganisationSwitcher.vue', async () => {
	const { defineComponent: define, h: render } = await import('vue')
	return {
		default: define({
			name: 'OrganisationSwitcher',
			props: { organisations: { type: Array, default: () => [] }, activeOrganisationUuid: { type: String, default: null }, isBeheerder: { type: Boolean, default: false } },
			setup: () => () => render('div', { class: 'switcher-stub' }),
		}),
	}
})
vi.mock('../../src/modals/Modals.vue', () => ({ default: { name: 'Modals', render: () => null } }))
vi.mock('../../src/dialogs/Dialogs.vue', () => ({ default: { name: 'Dialogs', render: () => null } }))
vi.mock('../../src/cellWidgets.js', () => ({ default: { widget: 'cell' } }))
vi.mock('../../src/formatters.js', () => ({ default: { formatter: 'cell' } }))

const { default: App } = await import('../../src/App.vue')
const { getActiveOrganisationUuid, setActiveOrganisationUuid } = await import('../../src/composables/orClient.js')

/**
 * Answer `/api/me` with the given response.
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
 * Mount the app root and wait for created() to finish.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mounted() {
	const wrapper = mount(App, {
		props: { manifest: { pages: [] } },
	})
	await flushPromises()
	return wrapper
}

describe('App', () => {
	beforeEach(() => {
		mocks.loadSettings = vi.fn(async () => {})
		setActiveOrganisationUuid(null)
		vi.stubGlobal('OC', { requestToken: 'token-1', currentUser: { permissions: ['read', 'write'] } })
	})

	afterEach(() => {
		vi.unstubAllGlobals()
		vi.restoreAllMocks()
	})

	it('loads settings and the organisations and hands them to the shell', async () => {
		const orgs = [{ uuid: 'org-1', name: 'One' }, { uuid: 'org-2', name: 'Two' }]
		const fetch = me({ ok: true, json: async () => ({ organisations: { all: orgs, active: orgs[1] }, isBeheerder: true }) })

		const wrapper = await mounted()

		expect(mocks.loadSettings).toHaveBeenCalledTimes(1)
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/stackiq/api/me', { headers: { requesttoken: 'token-1' } })
		const root = wrapper.findComponent({ name: 'CnAppRoot' })
		expect(root.props('appId')).toBe('stackiq')
		expect(root.props('initialOrganisationUuid')).toBe('org-2')
		expect(root.props('initialOrganisation')).toEqual(orgs[1])
		expect(root.props('permissions')).toEqual(['read', 'write'])
		expect(root.props('formatters')).toEqual({ formatter: 'cell' })
		expect(root.props('cellWidgets')).toEqual({ widget: 'cell' })
		expect(root.props('translate')('Save')).toBe('Save')
		const switcher = wrapper.findComponent({ name: 'OrganisationSwitcher' })
		expect(switcher.props()).toEqual({ organisations: orgs, activeOrganisationUuid: 'org-2', isBeheerder: true })
		expect(getActiveOrganisationUuid()).toBe('org-2')
	})

	it('hides the switcher and keeps defaults when the profile request fails', async () => {
		me({ ok: false, json: async () => ({}) })
		const wrapper = await mounted()
		expect(wrapper.findComponent({ name: 'OrganisationSwitcher' }).exists()).toBe(false)
		expect(wrapper.vm.activeOrganisationUuid).toBe(null)
		expect(wrapper.vm.isBeheerder).toBe(false)
		expect(getActiveOrganisationUuid()).toBe(null)
	})

	it('warns and carries on when settings or the organisations cannot be loaded', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		mocks.loadSettings = vi.fn(async () => {
			throw new Error('settings down')
		})
		vi.stubGlobal('fetch', vi.fn(async () => {
			throw new Error('network down')
		}))
		const wrapper = await mounted()
		expect(warn.mock.calls.map((call) => call[0])).toEqual([
			'[stackiq] settingsStore.loadSettings() failed; continuing with defaults',
			'[stackiq] Failed to load organisations; continuing single-tenant',
		])
		expect(wrapper.find('.app-root-stub').exists()).toBe(true)
		expect(wrapper.vm.organisations).toEqual([])
	})

	it('treats a missing isBeheerder or organisation list as none', async () => {
		me({ ok: true, json: async () => ({ organisations: { active: { uuid: 'org-9' } }, isBeheerder: 'yes' }) })
		const wrapper = await mounted()
		expect(wrapper.vm.organisations).toEqual([])
		expect(wrapper.vm.isBeheerder).toBe(false)
		expect(wrapper.vm.activeOrganisationUuid).toBe('org-9')
	})

	it('shows the object sidebar through the injected channel', async () => {
		me({ ok: false, json: async () => ({}) })
		const wrapper = await mounted()
		expect(wrapper.find('.sidebar-stub').exists()).toBe(false)
		expect(wrapper.find('.probe').text()).toBe('idle')
		wrapper.vm.objectSidebarState.active = true
		wrapper.vm.objectSidebarState.title = 'Module A'
		await flushPromises()
		expect(wrapper.find('.sidebar-stub').text()).toBe('Module A')
		expect(wrapper.find('.probe').text()).toBe('active')
	})

	it('passes no permissions without a current user', async () => {
		vi.stubGlobal('OC', { requestToken: 't' })
		me({ ok: false, json: async () => ({}) })
		const wrapper = await mounted()
		expect(wrapper.findComponent({ name: 'CnAppRoot' }).props('permissions')).toEqual([])
	})
})
