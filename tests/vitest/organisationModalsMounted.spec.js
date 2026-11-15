// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The organisation access and add-contact-person modals, mounted: what they
 * render in each state and what their buttons and form do. The logic of the
 * access modal is covered against a plain context in
 * organisationAccessAndContactAccounts.spec.js; this file covers the
 * template that wires it up.
 *
 * @spec openspec/specs/multi-org-membership/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const toasts = vi.hoisted(() => [])
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
const stores = vi.hoisted(() => ({ organisatieStore: {}, objectStore: {}, navigationStore: {} }))
vi.mock('../../src/store/store.js', () => stores)

const { default: GrantOrganisationAccessModal } = await import('../../src/modals/GrantOrganisationAccessModal.vue')
const { default: AddContactpersoonModal } = await import('../../src/modals/AddContactpersoonModal.vue')

const t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * A stub that renders as a tag carrying its props as data attributes, with
 * every slot, and emits click.
 *
 * @param {string} name The component name.
 * @param {string} tag The element to render.
 * @return {object} The stub.
 */
function stub(name, tag = 'div') {
	return defineComponent({
		name,
		inheritAttrs: false,
		props: ['name', 'disabled', 'open', 'type', 'user', 'modelValue', 'label', 'inputLabel', 'variant', 'size'],
		emits: ['click', 'closing', 'update:modelValue'],
		setup(props, { slots, emit }) {
			return () => h(tag, {
				class: ['stub', name],
				'data-name': props.name,
				'data-type': props.type,
				'data-user': props.user,
				'data-label': props.label ?? props.inputLabel,
				disabled: props.disabled === true ? '' : undefined,
				type: tag === 'button' ? props.type : undefined,
				onClick: () => emit('click'),
			}, [
				...(slots.icon ? slots.icon() : []),
				...(slots.default ? slots.default() : []),
				...(slots.actions ? slots.actions() : []),
			])
		},
	})
}

/**
 * A text field stub bound through v-model.
 */
const NcTextField = defineComponent({
	name: 'NcTextField',
	props: { modelValue: { type: String, default: '' }, label: { type: String, default: '' } },
	emits: ['update:modelValue'],
	setup(props, { emit }) {
		return () => h('input', {
			class: 'NcTextField',
			'data-label': props.label,
			value: props.modelValue,
			onInput: (event) => emit('update:modelValue', event.target.value),
		})
	},
})

const stubs = {
	NcDialog: stub('NcDialog', 'section'),
	NcButton: stub('NcButton', 'button'),
	NcSelectUsers: stub('NcSelectUsers'),
	NcLoadingIcon: stub('NcLoadingIcon', 'span'),
	NcNoteCard: stub('NcNoteCard'),
	NcListItem: stub('NcListItem', 'li'),
	NcActionButton: stub('NcActionButton', 'button'),
	NcAvatar: stub('NcAvatar', 'span'),
	CloseIcon: stub('CloseIcon', 'span'),
	NcTextField,
}

/**
 * Mount a component with the shared stubs and translation.
 *
 * @param {object} component The component.
 * @param {object} props Its props.
 * @return {object} The wrapper.
 */
function mountWith(component, props) {
	return mount(component, { props, global: { mocks: { t }, stubs } })
}

beforeEach(() => {
	toasts.length = 0
	Object.assign(stores.organisatieStore, {
		fetchMembers: vi.fn(async () => ['ann', 'bob']),
		grantAccess: vi.fn(async () => {}),
		revokeAccess: vi.fn(async () => {}),
	})
	Object.assign(stores.objectStore, {
		getSchemaConfig: vi.fn(() => ({ register: 'reg-1', schema: 'sch-7' })),
		saveObject: vi.fn(async (object) => ({ data: { id: 'new', ...object } })),
	})
	Object.assign(stores.navigationStore, { setTransferData: vi.fn() })
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('GrantOrganisationAccessModal mounted', () => {
	it('titles the dialog after the organisation and lists the members once opened', async () => {
		const wrapper = mountWith(GrantOrganisationAccessModal, { open: false, organisationUuid: 'org1', organisationName: 'Gemeente A' })
		expect(wrapper.find('.NcDialog').attributes('data-name')).toBe('Manage access to Gemeente A')
		expect(wrapper.find('.grant-organisation-access__empty').text()).toBe('No members yet.')

		await wrapper.setProps({ open: true })
		await flushPromises()

		expect(stores.organisatieStore.fetchMembers).toHaveBeenCalledWith('org1')
		expect(wrapper.findAll('li.NcListItem').map((item) => item.attributes('data-name'))).toEqual(['ann', 'bob'])
		expect(wrapper.findAll('.NcAvatar').map((avatar) => avatar.attributes('data-user'))).toEqual(['ann', 'bob'])
		expect(wrapper.find('.grant-organisation-access__empty').exists()).toBe(false)
	})

	it('shows a spinner instead of the list while loading, and the error in a note', async () => {
		let release
		stores.organisatieStore.fetchMembers.mockImplementationOnce(() => new Promise((resolve, reject) => { release = reject }))
		const wrapper = mountWith(GrantOrganisationAccessModal, { open: false, organisationUuid: 'org1' })

		await wrapper.setProps({ open: true })
		expect(wrapper.find('.grant-organisation-access__members').exists()).toBe(false)
		expect(wrapper.find('span.NcLoadingIcon').exists()).toBe(true)

		release(new Error('No access'))
		await flushPromises()
		expect(wrapper.find('.NcNoteCard').attributes('data-type')).toBe('error')
		expect(wrapper.find('.NcNoteCard').text()).toBe('No access')
		expect(wrapper.find('.grant-organisation-access__members').exists()).toBe(true)
	})

	it('disables the grant button until a user is chosen, then grants on click', async () => {
		const wrapper = mountWith(GrantOrganisationAccessModal, { open: false, organisationUuid: 'org1' })
		const grant = () => wrapper.findAll('button.NcButton').find((button) => button.text().includes('Grant access'))
		expect(grant().attributes('disabled')).toBeDefined()

		await wrapper.findComponent({ name: 'NcSelectUsers' }).vm.$emit('update:modelValue', { id: 'eve' })
		expect(grant().attributes('disabled')).toBeUndefined()
		await grant().trigger('click')
		await flushPromises()

		expect(stores.organisatieStore.grantAccess).toHaveBeenCalledWith('org1', 'eve')
		expect(grant().attributes('disabled')).toBeDefined()
	})

	it('revokes the clicked member and drops them from the list', async () => {
		const wrapper = mountWith(GrantOrganisationAccessModal, { open: false, organisationUuid: 'org1' })
		await wrapper.setProps({ open: true })
		await flushPromises()

		const revokes = wrapper.findAll('button.NcActionButton')
		expect(revokes.map((button) => button.text())).toEqual(['Revoke access', 'Revoke access'])
		expect(wrapper.findAll('.CloseIcon')).toHaveLength(2)
		await revokes[1].trigger('click')
		await flushPromises()

		expect(stores.organisatieStore.revokeAccess).toHaveBeenCalledWith('org1', 'bob')
		expect(wrapper.findAll('li.NcListItem').map((item) => item.attributes('data-name'))).toEqual(['ann'])
	})

	it('the close button asks the parent to close it', async () => {
		const wrapper = mountWith(GrantOrganisationAccessModal, { open: true, organisationUuid: 'org1' })
		const close = wrapper.findAll('button.NcButton').find((button) => button.text() === 'Close')

		await close.trigger('click')

		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})
})

describe('AddContactpersoonModal mounted', () => {
	/**
	 * Fill the three fields.
	 *
	 * @param {object} wrapper The wrapper.
	 * @param {Array<string>} values First name, last name, email.
	 * @return {Promise<void>}
	 */
	async function fill(wrapper, values) {
		const inputs = wrapper.findAll('input.NcTextField')
		for (const [index, value] of values.entries()) {
			await inputs[index].setValue(value)
		}
	}

	it('renders nothing while hidden', () => {
		expect(mountWith(AddContactpersoonModal, { show: false }).find('.NcDialog').exists()).toBe(false)
	})

	it('names the organisation, or Unknown, and labels the three fields', () => {
		const wrapper = mountWith(AddContactpersoonModal, { show: true, organisation: { name: 'Gemeente A' } })

		expect(wrapper.find('.modal-description').text()).toBe('Add a new contact person to organisation: Gemeente A')
		expect(wrapper.findAll('input.NcTextField').map((input) => input.attributes('data-label'))).toEqual(['First Name', 'Last Name', 'Email Address'])
		expect(mountWith(AddContactpersoonModal, { show: true, organisation: {} }).find('.modal-description').text()).toBe('Add a new contact person to organisation: Unknown')
	})

	it('keeps the submit button disabled until the form is valid', async () => {
		const wrapper = mountWith(AddContactpersoonModal, { show: true, organisation: { id: 'org1' } })
		const submit = () => wrapper.find('button.NcButton[type="submit"]')
		expect(submit().attributes('disabled')).toBeDefined()

		await fill(wrapper, ['Ann', 'Smit', 'not-an-email'])
		expect(submit().attributes('disabled')).toBeDefined()

		await fill(wrapper, ['Ann', 'Smit', 'ann@example.nl'])
		expect(submit().attributes('disabled')).toBeUndefined()
	})

	it('submitting an invalid form shows an error and saves nothing', async () => {
		const wrapper = mountWith(AddContactpersoonModal, { show: true, organisation: { id: 'org1' } })

		await wrapper.find('form').trigger('submit')

		expect(toasts).toEqual([['error', 'Please fill in all required fields with valid data']])
		expect(stores.objectStore.saveObject).not.toHaveBeenCalled()
	})

	it('submitting a valid form saves the contact person linked to the organisation', async () => {
		const wrapper = mountWith(AddContactpersoonModal, { show: true, organisation: { uuid: 'org-uuid', name: 'Gemeente A' } })
		await fill(wrapper, ['Ann', 'Smit', 'ann@example.nl'])

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		const [object, target] = stores.objectStore.saveObject.mock.calls[0]
		expect(target).toEqual({ register: 'reg-1', schema: 'sch-7' })
		expect(stores.objectStore.getSchemaConfig).toHaveBeenCalledWith('contactPerson')
		expect([object.voornaam, object.achternaam, object['e-mailadres'], object.name, object.organization, object['@self'].organisation])
			.toEqual(['Ann', 'Smit', 'ann@example.nl', 'Ann Smit', 'org-uuid', 'org-uuid'])
		expect(toasts).toEqual([['success', 'Contactpersoon added successfully']])
		expect(wrapper.emitted('contactpersoon-added')[0][0].name).toBe('Ann Smit')
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(stores.navigationStore.setTransferData).toHaveBeenCalledWith({ action: 'contactpersoonAdded' })
		expect(wrapper.findAll('input.NcTextField').map((input) => input.element.value)).toEqual(['', '', ''])
	})

	it('a failing save shows the cause and keeps the form open', async () => {
		stores.objectStore.saveObject.mockRejectedValueOnce(new Error('Duplicate email'))
		const wrapper = mountWith(AddContactpersoonModal, { show: true, organisation: { id: 'org1' } })
		await fill(wrapper, ['Ann', 'Smit', 'ann@example.nl'])

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(toasts).toEqual([['error', 'Failed to add contact person: Duplicate email']])
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(wrapper.find('button.NcButton[type="submit"]').attributes('disabled')).toBeUndefined()
	})

	it('cancel resets the form and asks the parent to close', async () => {
		const wrapper = mountWith(AddContactpersoonModal, { show: true, organisation: { id: 'org1' } })
		await fill(wrapper, ['Ann', 'Smit', 'ann@example.nl'])

		await wrapper.findAll('button.NcButton').find((button) => button.text() === 'Cancel').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(wrapper.findAll('input.NcTextField').map((input) => input.element.value)).toEqual(['', '', ''])
	})
})
