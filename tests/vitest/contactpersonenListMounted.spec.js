// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact person list as mounted: which state it renders (loading, error,
 * empty, table), which row actions each user status offers, what those
 * actions call on the store, the dialogs they open and close, and the
 * mount/unmount lifecycle.
 *
 * @spec openspec/specs/fe-organizations/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, reactive } from 'vue'

const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => dialogs)

const store = vi.hoisted(() => ({ current: null }))
vi.mock('../../src/store/modules/organisatie.js', () => ({
	useOrganisatieStore: () => store.current,
}))

const { default: ContactpersonenList } =
	await import('../../src/components/ContactpersonenList.vue')

/**
 * A reactive organisation store double.
 *
 * @param {object} overrides State and method overrides.
 * @return {object} The store.
 */
function makeStore(overrides = {}) {
	return reactive({
		isLoading: false,
		getError: null,
		getContactpersonen: [],
		getAvailableGroups: [{ id: 'gebruik-beheerder' }, { id: 'custom' }],
		fetchAvailableGroups: vi.fn().mockResolvedValue(undefined),
		fetchContactpersonen: vi.fn().mockResolvedValue(undefined),
		getBulkUserInfo: vi.fn().mockResolvedValue({}),
		convertToUser: vi.fn().mockResolvedValue({}),
		disableUser: vi.fn().mockResolvedValue(undefined),
		enableUser: vi.fn().mockResolvedValue(undefined),
		...overrides,
	})
}

/**
 * A stub rendering its default slot and its icon slot.
 *
 * @param {string} tag The root tag.
 * @param {string} className The root class.
 * @return {object} The stub component.
 */
function withIcon(tag, className) {
	return defineComponent({
		props: { name: { type: String, default: '' } },
		setup(props, { slots }) {
			return () => h(tag, { class: className }, [
				props.name,
				slots.icon ? slots.icon() : null,
				slots.default ? slots.default() : null,
			])
		},
	})
}

/**
 * Mount the list.
 *
 * @param {object} organisationData The organisation data prop.
 * @return {Promise<object>} The wrapper, after the mount-time loading settled.
 */
async function mountList(organisationData) {
	const wrapper = mount(ContactpersonenList, {
		props: { organisationId: 'org-1', organisationData },
		global: {
			mocks: {
				t: (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m)),
			},
			stubs: {
				NcActionButton: withIcon('button', 'action-button-stub'),
				NcEmptyContent: withIcon('div', 'empty-content-stub'),
				ChangePasswordDialog: true,
				ManageUserGroupsDialog: true,
			},
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * The action button with the given label in the given row.
 *
 * @param {object} row The row wrapper.
 * @param {string} label The button label.
 * @return {object} The button wrapper.
 */
function action(row, label) {
	const button = row.findAll('button').find((b) => b.text().includes(label))
	expect(button, label).toBeDefined()
	return button
}

/**
 * The contact persons of the organisation: one without a user, one active, one disabled.
 *
 * @return {Array<object>} Fresh contact person rows.
 */
function contacts() {
	return [
		{ id: 'c-none', data: { voornaam: 'Ann', achternaam: 'Smit', 'e-mailadres': 'ann@x.nl' } },
		{ id: 'c-active', data: { name: 'Bob', email: 'bob@x.nl', username: 'bob' }, user: { groups: ['gebruik-beheerder', 'custom', 'hidden'], disabled: false } },
		{ id: 'c-off', data: { name: 'Cor', username: 'cor' }, user: { groups: [], disabled: true } },
	]
}

beforeEach(() => {
	vi.spyOn(console, 'info').mockImplementation(() => {})
	vi.spyOn(console, 'error').mockImplementation(() => {})
	dialogs.showError.mockClear()
	dialogs.showSuccess.mockClear()
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('ContactpersonenList mounted', () => {
	it('shows the loading state', async () => {
		store.current = makeStore({ isLoading: true })
		const wrapper = await mountList({ contactpersonen: [] })
		expect(wrapper.find('.loading').text()).toContain('Loading contactpersonen...')
	})

	it('shows the store error', async () => {
		store.current = makeStore({ getError: 'Boom' })
		const wrapper = await mountList({ contactpersonen: [] })
		expect(wrapper.find('.error').text()).toBe('Boom')
	})

	it('shows the empty state and fetches contact persons when the organisation has none loaded', async () => {
		store.current = makeStore()
		const wrapper = await mountList({})
		expect(wrapper.find('.empty').text()).toContain('No contactpersonen found')
		expect(store.current.fetchAvailableGroups).toHaveBeenCalledTimes(1)
		expect(store.current.fetchContactpersonen).toHaveBeenCalledWith('org-1')
		expect(store.current.getBulkUserInfo).not.toHaveBeenCalled()
	})

	it('renders a row per contact person with status, filtered groups and actions', async () => {
		store.current = makeStore()
		const wrapper = await mountList({ contactpersonen: contacts() })

		expect(store.current.fetchContactpersonen).not.toHaveBeenCalled()
		expect(store.current.getBulkUserInfo).toHaveBeenCalledWith(['c-none', 'c-active', 'c-off'])
		const rows = wrapper.findAll('tbody tr')
		expect(rows).toHaveLength(3)

		expect(rows[0].find('.name-cell').text()).toBe('Ann Smit')
		expect(rows[0].find('.email-cell').text()).toBe('ann@x.nl')
		expect(rows[0].find('.status-cell').text()).toBe('No User')
		expect(rows[0].find('.groups-cell').text()).toBe('-')
		expect(rows[0].findAll('button').map((b) => b.text())).toEqual(['Convert to User'])

		expect(rows[1].find('.status-cell').text()).toBe('User')
		expect(rows[1].findAll('.group-chip').map((c) => c.text())).toEqual(['Gebruik Beheerder', 'custom'])
		expect(rows[1].findAll('button').map((b) => b.text())).toEqual(['Change Password', 'Manage Groups', 'Disable User'])

		expect(rows[2].find('.status-cell').text()).toBe('Disabled')
		expect(rows[2].findAll('button').map((b) => b.text())).toEqual(['Change Password', 'Manage Groups', 'Enable User'])
	})

	it('disables and enables users through the store and reflects the status', async () => {
		store.current = makeStore()
		const organisationData = reactive({ contactpersonen: contacts() })
		const wrapper = await mountList(organisationData)

		await action(wrapper.findAll('tbody tr')[1], 'Disable User').trigger('click')
		await flushPromises()
		expect(store.current.disableUser).toHaveBeenCalledWith('c-active')
		expect(dialogs.showSuccess).toHaveBeenCalledWith('User disabled successfully')
		expect(organisationData.contactpersonen[1].user.disabled).toBe(true)
		expect(organisationData.contactpersonen[1].data.disabled).toBe(true)

		await action(wrapper.findAll('tbody tr')[2], 'Enable User').trigger('click')
		await flushPromises()
		expect(store.current.enableUser).toHaveBeenCalledWith('c-off')
		expect(dialogs.showSuccess).toHaveBeenCalledWith('User enabled successfully')
		expect(organisationData.contactpersonen[2].user.disabled).toBe(false)
	})

	it('reports a failing disable', async () => {
		store.current = makeStore({ disableUser: vi.fn().mockRejectedValue(new Error('denied')) })
		const wrapper = await mountList({ contactpersonen: contacts() })

		await action(wrapper.findAll('tbody tr')[1], 'Disable User').trigger('click')
		await flushPromises()
		expect(dialogs.showError).toHaveBeenCalledWith('Failed to disable user: denied')
	})

	it('converts a contact person into a user and replaces its row', async () => {
		store.current = makeStore({
			convertToUser: vi.fn().mockResolvedValue({
				contactPerson: { '@self': { id: 'c-none' }, voornaam: 'Ann', achternaam: 'Smit', username: 'ann' },
			}),
		})
		const organisationData = reactive({ contactpersonen: contacts() })
		const wrapper = await mountList(organisationData)

		await action(wrapper.findAll('tbody tr')[0], 'Convert to User').trigger('click')
		await flushPromises()
		expect(store.current.convertToUser).toHaveBeenCalledWith('c-none')
		expect(organisationData.contactpersonen[0]).toEqual({
			id: 'c-none',
			uuid: 'c-none',
			data: {
				voornaam: 'Ann',
				achternaam: 'Smit',
				'e-mailadres': undefined,
				name: undefined,
				organization: undefined,
				username: 'ann',
				groups: [],
			},
			loading: false,
		})
		expect(dialogs.showSuccess).toHaveBeenCalledWith('User account created successfully')
	})

	it('opens and closes the password and group dialogs', async () => {
		store.current = makeStore()
		const organisationData = reactive({ contactpersonen: contacts() })
		const wrapper = await mountList(organisationData)
		const row = () => wrapper.findAll('tbody tr')[1]

		await action(row(), 'Change Password').trigger('click')
		const password = wrapper.findComponent({ name: 'ChangePasswordDialog' })
		expect(password.exists()).toBe(true)
		expect(password.props('username')).toBe('bob')
		password.vm.$emit('saved')
		await flushPromises()
		expect(wrapper.findComponent({ name: 'ChangePasswordDialog' }).exists()).toBe(false)

		await action(row(), 'Change Password').trigger('click')
		wrapper.findComponent({ name: 'ChangePasswordDialog' }).vm.$emit('close')
		await flushPromises()
		expect(wrapper.vm.selectedContactpersoon).toBeNull()

		await action(row(), 'Manage Groups').trigger('click')
		const groups = wrapper.findComponent({ name: 'ManageUserGroupsDialog' })
		expect(groups.exists()).toBe(true)
		expect(wrapper.vm.selectedContactpersoon.id).toBe('c-active')
		groups.vm.$emit('groupsLoaded', ['custom'])
		await flushPromises()
		expect(organisationData.contactpersonen[1].user.groups).toEqual(['custom'])
		wrapper.findComponent({ name: 'ManageUserGroupsDialog' }).vm.$emit('saved', ['gebruik-beheerder'])
		await flushPromises()
		expect(organisationData.contactpersonen[1].user.groups).toEqual(['gebruik-beheerder'])
		expect(wrapper.findComponent({ name: 'ManageUserGroupsDialog' }).exists()).toBe(false)

		await action(row(), 'Manage Groups').trigger('click')
		wrapper.findComponent({ name: 'ManageUserGroupsDialog' }).vm.$emit('close')
		await flushPromises()
		expect(wrapper.findComponent({ name: 'ManageUserGroupsDialog' }).exists()).toBe(false)
	})

	it('refreshes user data only once per session and clears its timeout on unmount', async () => {
		store.current = makeStore()
		const wrapper = await mountList({ contactpersonen: contacts() })
		expect(store.current.getBulkUserInfo).toHaveBeenCalledTimes(1)

		await wrapper.vm.refreshUserData()
		await wrapper.vm.refreshUserStatuses()
		expect(store.current.getBulkUserInfo).toHaveBeenCalledTimes(1)

		const clear = vi.spyOn(globalThis, 'clearTimeout')
		wrapper.vm.userStatusRefreshTimeout = 1234
		wrapper.unmount()
		expect(clear).toHaveBeenCalledWith(1234)
	})
})
