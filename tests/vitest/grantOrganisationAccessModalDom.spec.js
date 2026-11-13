// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The rendered organisation access dialog: its title, the grant button that
 * waits for a chosen user, the error note, the member list with one revoke
 * action per member (spinning for the one being revoked), the empty and
 * loading states, and that its buttons reach the store.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const organisatie = vi.hoisted(() => ({
	fetchMembers: vi.fn(),
	grantAccess: vi.fn(),
	revokeAccess: vi.fn(),
}))
vi.mock('../../src/store/store.js', () => ({ organisatieStore: organisatie }))

const { default: GrantOrganisationAccessModal } = await import('../../src/modals/GrantOrganisationAccessModal.vue')

const t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

// A member row that renders its name and its icon and actions slots.
const NcListItem = defineComponent({
	name: 'NcListItem',
	props: { name: { type: String, default: '' } },
	setup(props, { slots }) {
		return () => h('li', { class: 'member', 'data-name': props.name }, [
			h('span', { class: 'member-icon' }, slots.icon?.()),
			h('span', { class: 'member-actions' }, slots.actions?.()),
		])
	},
})

// An action button that renders its icon slot before its label.
const NcActionButton = defineComponent({
	name: 'NcActionButton',
	setup(props, { slots }) {
		return () => h('button', { class: 'action-button' }, [slots.icon?.(), slots.default?.()])
	},
})

// A user picker that can be told which user was picked.
const NcSelectUsers = defineComponent({
	name: 'NcSelectUsers',
	props: { modelValue: { type: Object, default: null }, inputLabel: { type: String, default: '' } },
	emits: ['update:modelValue'],
	setup(props) {
		return () => h('div', { class: 'select-users', 'data-label': props.inputLabel })
	},
})

// A dialog that shows its name and its default slot.
const NcDialog = defineComponent({
	name: 'NcDialog',
	props: { name: { type: String, default: '' }, open: { type: Boolean, default: false } },
	emits: ['closing'],
	setup(props, { slots }) {
		return () => h('div', { class: 'dialog', 'data-name': props.name }, slots.default?.())
	},
})

/**
 * Mount the dialog with the given props.
 *
 * @param {object} props Its props.
 * @return {object} The wrapper.
 */
function dialog(props = {}) {
	return mount(GrantOrganisationAccessModal, {
		props: { open: false, organisationUuid: 'org-1', organisationName: 'Gemeente A', ...props },
		global: {
			mocks: { t },
			stubs: {
				NcDialog,
				NcListItem,
				NcSelectUsers,
				NcActionButton,
				NcAvatar: true,
				CloseIcon: defineComponent({ name: 'CloseIcon', setup: () => () => h('i', { class: 'close-icon' }) }),
			},
		},
	})
}

const revokeButtons = (wrapper) => wrapper.findAll('.member-actions > *')

beforeEach(() => {
	vi.clearAllMocks()
})

describe('GrantOrganisationAccessModal rendered', () => {
	it('names the organisation and labels the user picker', () => {
		const wrapper = dialog()

		expect(wrapper.find('.dialog').attributes('data-name')).toBe('Manage access to Gemeente A')
		expect(wrapper.find('.select-users').attributes('data-label')).toBe('Grant access to an existing Nextcloud user')
		expect(wrapper.text()).toContain('Current members')
	})

	it('keeps granting disabled until a user is picked', async () => {
		const wrapper = dialog()
		const grant = () => wrapper.findAll('button').find((b) => b.text() === 'Grant access')

		expect(grant().attributes('disabled')).toBeDefined()

		await wrapper.findComponent({ name: 'NcSelectUsers' }).vm.$emit('update:modelValue', { id: 'bob' })

		expect(grant().attributes('disabled')).toBeUndefined()
	})

	it('grants the picked user from the button, then reloads the members', async () => {
		organisatie.grantAccess.mockResolvedValue(undefined)
		organisatie.fetchMembers.mockResolvedValue(['alice', 'bob'])
		const wrapper = dialog()
		await wrapper.findComponent({ name: 'NcSelectUsers' }).vm.$emit('update:modelValue', { id: 'bob' })

		await wrapper.findAll('button').find((b) => b.text() === 'Grant access').trigger('click')
		await flushPromises()

		expect(organisatie.grantAccess).toHaveBeenCalledWith('org-1', 'bob')
		expect(wrapper.findAll('.member').map((m) => m.attributes('data-name'))).toEqual(['alice', 'bob'])
	})

	it('lists the members it loads when it opens, each with a revoke action', async () => {
		organisatie.fetchMembers.mockResolvedValue(['alice', 'carol'])
		const wrapper = dialog()

		await wrapper.setProps({ open: true })
		await flushPromises()

		expect(organisatie.fetchMembers).toHaveBeenCalledWith('org-1')
		expect(wrapper.findAll('.member').map((m) => m.attributes('data-name'))).toEqual(['alice', 'carol'])
		expect(revokeButtons(wrapper).map((b) => b.text())).toEqual(['Revoke access', 'Revoke access'])
		expect(wrapper.findAll('.close-icon')).toHaveLength(2)
		expect(wrapper.find('.grant-organisation-access__empty').exists()).toBe(false)
	})

	it('says there are no members yet for an empty list', async () => {
		organisatie.fetchMembers.mockResolvedValue([])
		const wrapper = dialog()

		await wrapper.setProps({ open: true })
		await flushPromises()

		expect(wrapper.find('.grant-organisation-access__empty').exists()).toBe(true)
		expect(wrapper.findAll('.member')).toHaveLength(0)
	})

	it('shows a spinner instead of the list while loading', async () => {
		const wrapper = dialog()
		wrapper.vm.loading = true
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.grant-organisation-access__members').exists()).toBe(false)
	})

	it('revokes a member from its row and shows the store error when it fails', async () => {
		organisatie.fetchMembers.mockResolvedValue(['alice', 'carol'])
		organisatie.revokeAccess.mockRejectedValue(new Error('Not allowed'))
		const wrapper = dialog()
		await wrapper.setProps({ open: true })
		await flushPromises()

		await revokeButtons(wrapper)[1].trigger('click')
		await flushPromises()

		expect(organisatie.revokeAccess).toHaveBeenCalledWith('org-1', 'carol')
		expect(wrapper.text()).toContain('Not allowed')
		expect(wrapper.findAll('.member')).toHaveLength(2)
	})

	it('spins only the action of the member being revoked', async () => {
		organisatie.fetchMembers.mockResolvedValue(['alice', 'carol'])
		const wrapper = dialog()
		await wrapper.setProps({ open: true })
		await flushPromises()

		wrapper.vm.revokingUserId = 'carol'
		await wrapper.vm.$nextTick()

		expect(wrapper.findAll('.close-icon')).toHaveLength(1)
		expect(revokeButtons(wrapper)[0].find('.close-icon').exists()).toBe(true)
	})

	it('asks to close, clearing the pick and the error, when the dialog closes', async () => {
		const wrapper = dialog({ open: true })
		organisatie.fetchMembers.mockResolvedValue([])
		wrapper.vm.errorMessage = 'old'
		await wrapper.findComponent({ name: 'NcSelectUsers' }).vm.$emit('update:modelValue', { id: 'bob' })

		await wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('closing')

		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.vm.selectedUser).toBeNull()
		expect(wrapper.find('.note-card-stub').exists()).toBe(false)
	})
})
