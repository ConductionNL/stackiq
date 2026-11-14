// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Four object dialogs, mounted against a reactive store double: the merge
 * confirmation, the organisation status change, delete and lock. What each
 * renders before, during and after its action, what its buttons call, and
 * the auto-close after a success.
 *
 * @spec openspec/specs/fe-organizations/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('../../src/store/store.js', async () => {
	const { reactive } = await import('vue')
	return {
		navigationStore: reactive({ dialog: false, modal: false, dialogProperties: null }),
		objectStore: reactive({ objectItem: null }),
	}
})

const { navigationStore, objectStore } = await import('../../src/store/store.js')
const { default: MergeOrganisationConfirmDialog } = await import('../../src/modals/object/MergeOrganisationConfirmDialog.vue')
const { default: ChangeOrganisatieStatusDialog } = await import('../../src/modals/object/ChangeOrganisatieStatusDialog.vue')
const { default: DeleteObject } = await import('../../src/modals/object/DeleteObject.vue')
const { default: LockObject } = await import('../../src/modals/object/LockObject.vue')

const t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * A stub rendering its slots, with its name, type and disabled state readable.
 *
 * @param {string} name The component name.
 * @param {string} tag The element.
 * @return {object} The stub.
 */
function stub(name, tag = 'div') {
	return defineComponent({
		name,
		inheritAttrs: false,
		props: ['name', 'disabled', 'type', 'variant', 'canClose', 'modelValue', 'label', 'size'],
		emits: ['click', 'closing', 'update:modelValue'],
		setup(props, { slots, emit }) {
			return () => h(tag, {
				class: ['stub', name],
				'data-name': props.name,
				'data-type': props.type,
				'data-variant': props.variant,
				'data-can-close': String(props.canClose),
				disabled: props.disabled === true ? '' : undefined,
				onClick: () => emit('click'),
			}, [
				...(slots.icon ? slots.icon() : []),
				...(slots.default ? slots.default() : []),
				...(slots.actions ? slots.actions() : []),
			])
		},
	})
}

const NcTextField = defineComponent({
	name: 'NcTextField',
	props: { modelValue: { type: [String, Number], default: '' }, label: { type: String, default: '' }, disabled: { type: Boolean, default: false } },
	emits: ['update:modelValue'],
	setup(props, { emit }) {
		return () => h('input', {
			class: 'NcTextField',
			'data-label': props.label,
			disabled: props.disabled ? '' : undefined,
			value: props.modelValue,
			onInput: (event) => emit('update:modelValue', event.target.value),
		})
	},
})

const stubs = {
	NcDialog: stub('NcDialog', 'section'),
	NcButton: stub('NcButton', 'button'),
	NcLoadingIcon: stub('NcLoadingIcon', 'span'),
	NcNoteCard: stub('NcNoteCard'),
	Cancel: stub('Cancel', 'span'),
	CheckCircle: stub('CheckCircle', 'span'),
	CloseCircle: stub('CloseCircle', 'span'),
	TrashCanOutline: stub('TrashCanOutline', 'span'),
	LockOutline: stub('LockOutline', 'span'),
	NcTextField,
}

/**
 * Mount a component with the stubs and translation.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @return {object} The wrapper.
 */
function mountWith(component, props = {}) {
	return mount(component, { props, global: { mixins: [{ methods: { t } }], stubs } })
}

/**
 * The button whose text is exactly the given label.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} label The label.
 * @return {object} The button.
 */
function button(wrapper, label) {
	return wrapper.findAll('button.NcButton').find((b) => b.text() === label)
}

/**
 * A promise and the functions that settle it.
 *
 * @return {{promise: Promise, resolve: Function, reject: Function}}
 */
function deferred() {
	let resolve
	let reject
	const promise = new Promise((res, rej) => { resolve = res; reject = rej })
	return { promise, resolve, reject }
}

beforeEach(() => {
	Object.assign(navigationStore, {
		dialog: false,
		modal: false,
		dialogProperties: null,
		setDialog: vi.fn((value) => { navigationStore.dialog = value }),
		setModal: vi.fn((value) => { navigationStore.modal = value }),
		setTransferData: vi.fn(),
	})
	Object.assign(objectStore, {
		objectItem: { id: 'obj-1', '@self': { name: 'Zaaksysteem' } },
		getActiveObject: vi.fn(() => ({ id: 'org-1', name: 'Gemeente A', status: 'Inactive' })),
		patchObject: vi.fn(async () => ({})),
		deleteObject: vi.fn(async () => ({})),
		lockObject: vi.fn(async () => ({})),
	})
	vi.spyOn(console, 'info').mockImplementation(() => {})
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.useRealTimers()
	vi.restoreAllMocks()
})

describe('MergeOrganisationConfirmDialog', () => {
	it('renders nothing while hidden', () => {
		expect(mountWith(MergeOrganisationConfirmDialog).find('.NcDialog').exists()).toBe(false)
	})

	it('names both organisations and lists every count, missing ones as zero', () => {
		const wrapper = mountWith(MergeOrganisationConfirmDialog, {
			show: true,
			sourceName: 'Leverancier B',
			targetName: 'Leverancier A',
			counts: { usage: 4, contactPerson: 2, groupMembers: 1, organizationDeelnames: 3 },
		})

		expect(wrapper.find('p').text()).toBe('This will permanently fold Leverancier B into Leverancier A.')
		expect(wrapper.find('.merge-confirm-dialog__note').text()).toBe('Leverancier B will be marked as merged (not deleted) and will disappear from the organisations list.')
		expect(wrapper.find('caption').text()).toBe('Records that will be re-pointed to Leverancier A:')
		expect(wrapper.findAll('tbody tr').map((row) => [row.find('th').text(), row.find('td').text()])).toEqual([
			['Usage records', '4'],
			['Contracts', '0'],
			['Contact persons', '2'],
			['Offerings', '0'],
			['Applications supplied', '0'],
			['Services supplied', '0'],
			['Applications managed', '0'],
			['Services managed', '0'],
			['Compliance records', '0'],
			['Usage records as supplier', '0'],
			['Participations', '3'],
			['Participants', '0'],
			['Contracts as supplier', '0'],
			['Models', '0'],
			['AI systems supplied', '0'],
			['Group members', '1'],
		])
		expect(wrapper.find('.NcNoteCard').exists()).toBe(false)
	})

	it('emits confirm and cancel from its buttons and from closing', async () => {
		const wrapper = mountWith(MergeOrganisationConfirmDialog, { show: true })

		await button(wrapper, 'Merge organisations').trigger('click')
		await button(wrapper, 'Cancel').trigger('click')
		await wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('closing')

		expect(wrapper.emitted('confirm')).toHaveLength(1)
		expect(wrapper.emitted('cancel')).toHaveLength(2)
		expect(button(wrapper, 'Merge organisations').attributes('data-variant')).toBe('error')
	})

	it('while busy, locks the dialog and both buttons and spins; an error shows in a note', () => {
		const wrapper = mountWith(MergeOrganisationConfirmDialog, { show: true, busy: true, error: 'Target is merged already' })

		expect(wrapper.find('.NcDialog').attributes('data-can-close')).toBe('false')
		expect(wrapper.findAll('button.NcButton').map((b) => b.attributes('disabled'))).toEqual(['', ''])
		expect(wrapper.find('span.NcLoadingIcon').exists()).toBe(true)
		expect(wrapper.find('.NcNoteCard').attributes('data-type')).toBe('error')
		expect(wrapper.find('.NcNoteCard').text()).toBe('Target is merged already')
	})
})

describe('ChangeOrganisatieStatusDialog', () => {
	/**
	 * Open the dialog for an action.
	 *
	 * @param {object} properties The dialog properties.
	 * @return {object} The wrapper.
	 */
	function open(properties) {
		navigationStore.dialog = 'changeOrganisatieStatus'
		navigationStore.dialogProperties = properties
		return mountWith(ChangeOrganisatieStatusDialog)
	}

	it('renders only when its dialog is open', () => {
		expect(mountWith(ChangeOrganisatieStatusDialog).find('.NcDialog').exists()).toBe(false)
	})

	it('asks to activate the active organisation, under the given or default title', () => {
		const wrapper = open({ action: 'activeren', newStatus: 'Active' })

		expect(wrapper.find('.NcDialog').attributes('data-name')).toBe('Change status')
		expect(wrapper.find('p').text().replace(/\s+/g, ' ')).toBe('Are you sure you want to change the status of Gemeente A to Active? This organisation will be activated and will be visible to users.')
		expect(button(wrapper, 'Activate').find('.CheckCircle').exists()).toBe(true)
		expect(button(wrapper, 'Cancel').exists()).toBe(true)
		expect(open({ action: 'activeren', newStatus: 'Active', dialogTitle: 'Activeer' }).find('.NcDialog').attributes('data-name')).toBe('Activeer')
	})

	it('asks to deactivate with the deactivation text and icon', () => {
		const wrapper = open({ action: 'deactiveren', newStatus: 'Inactive' })

		expect(wrapper.find('.status-change-info').text()).toBe('This organisation will be deactivated and will no longer be visible to users.')
		expect(button(wrapper, 'Deactivate').find('.CloseCircle').exists()).toBe(true)
		expect(button(wrapper, 'Deactivate').find('.CheckCircle').exists()).toBe(false)
	})

	it('falls back to Unknown organisation without a name', () => {
		objectStore.getActiveObject.mockReturnValue({ id: 'org-9' })

		expect(open({ action: 'activeren', newStatus: 'Active' }).find('b').text()).toBe('Unknown organisation')
	})

	it('patches the status, shows the success and closes itself after two seconds', async () => {
		vi.useFakeTimers()
		const wrapper = open({ action: 'activeren', newStatus: 'Active' })

		await button(wrapper, 'Activate').trigger('click')
		await flushPromises()

		expect(objectStore.patchObject).toHaveBeenCalledWith('organization', 'org-1', { status: 'Active' })
		expect(wrapper.find('.NcNoteCard').text()).toBe('Status successfully changed to Active')
		expect(button(wrapper, 'Activate')).toBeUndefined()
		expect(button(wrapper, 'Close').exists()).toBe(true)
		expect(navigationStore.setTransferData).toHaveBeenCalledWith({ action: 'organisationActivated', organisationName: 'Gemeente A', status: 'Active' })

		vi.advanceTimersByTime(2000)
		await flushPromises()
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		expect(wrapper.find('.NcDialog').exists()).toBe(false)
	})

	it('a successful deactivation is not announced as an activation', async () => {
		const wrapper = open({ action: 'deactiveren', newStatus: 'Inactive' })

		await button(wrapper, 'Deactivate').trigger('click')
		await flushPromises()

		expect(objectStore.patchObject).toHaveBeenCalledWith('organization', 'org-1', { status: 'Inactive' })
		expect(wrapper.find('.NcNoteCard[data-type="success"]').text()).toBe('Status successfully changed to Inactive')
		expect(navigationStore.setTransferData).not.toHaveBeenCalled()
	})

	it('spins while patching and shows the error after a failure', async () => {
		const pending = deferred()
		objectStore.patchObject.mockReturnValueOnce(pending.promise)
		const wrapper = open({ action: 'deactiveren', newStatus: 'Inactive' })

		await button(wrapper, 'Deactivate').trigger('click')
		expect(button(wrapper, 'Deactivate').attributes('disabled')).toBe('')
		expect(button(wrapper, 'Deactivate').find('.NcLoadingIcon').exists()).toBe(true)
		expect(button(wrapper, 'Deactivate').find('.CloseCircle').exists()).toBe(false)

		pending.reject(new Error('Forbidden'))
		await flushPromises()
		expect(wrapper.find('.NcNoteCard[data-type="error"]').text()).toBe('Forbidden')
		expect(button(wrapper, 'Deactivate').attributes('disabled')).toBeUndefined()
		expect(navigationStore.setTransferData).not.toHaveBeenCalled()
	})

	it('cancel closes the dialog without patching', async () => {
		const wrapper = open({ action: 'activeren', newStatus: 'Active' })

		await button(wrapper, 'Cancel').trigger('click')

		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
		expect(objectStore.patchObject).not.toHaveBeenCalled()
	})
})

describe('DeleteObject', () => {
	it('renders only when its dialog is open', () => {
		expect(mountWith(DeleteObject).find('.NcDialog').exists()).toBe(false)
	})

	it('names the object by @self name, name, title or id', async () => {
		navigationStore.dialog = 'deleteObject'
		const wrapper = mountWith(DeleteObject)
		expect(wrapper.find('.NcDialog').attributes('data-name')).toBe('Delete Zaaksysteem')
		expect(wrapper.find('b').text()).toBe('Zaaksysteem')

		objectStore.objectItem = { id: 'obj-2', name: 'Plain name' }
		await flushPromises()
		expect(wrapper.find('b').text()).toBe('Plain name')
		objectStore.objectItem = { id: 'obj-3', '@self': { title: 'Title' } }
		await flushPromises()
		expect(wrapper.find('b').text()).toBe('Title')
		objectStore.objectItem = { id: 'obj-4' }
		await flushPromises()
		expect(wrapper.find('.NcDialog').attributes('data-name')).toBe('Delete obj-4')
		objectStore.objectItem = {}
		await flushPromises()
		expect(wrapper.find('.NcDialog').attributes('data-name')).toBe('Delete Publication')
	})

	it('deletes, confirms, offers Close and closes itself after two seconds', async () => {
		vi.useFakeTimers()
		navigationStore.dialog = 'deleteObject'
		const wrapper = mountWith(DeleteObject)
		const item = objectStore.objectItem

		await button(wrapper, 'Delete').trigger('click')
		await flushPromises()

		expect(objectStore.deleteObject).toHaveBeenCalledWith(item)
		expect(wrapper.find('.NcNoteCard[data-type="success"]').text()).toBe('Publication successfully deleted')
		expect(button(wrapper, 'Delete')).toBeUndefined()
		expect(wrapper.find('p').text()).toBe('Publication successfully deleted')

		vi.advanceTimersByTime(2000)
		await flushPromises()
		expect(navigationStore.setDialog).toHaveBeenCalledWith(false)
	})

	it('a failed delete shows the cause, or a fallback text', async () => {
		navigationStore.dialog = 'deleteObject'
		objectStore.deleteObject.mockRejectedValueOnce(new Error('Object is locked'))
		const wrapper = mountWith(DeleteObject)

		await button(wrapper, 'Delete').trigger('click')
		await flushPromises()
		expect(wrapper.find('.NcNoteCard[data-type="error"]').text()).toBe('Object is locked')

		await button(wrapper, 'Close').trigger('click')
		navigationStore.dialog = 'deleteObject'
		objectStore.deleteObject.mockRejectedValueOnce(new Error(''))
		await flushPromises()
		await button(wrapper, 'Delete').trigger('click')
		await flushPromises()
		expect(wrapper.find('.NcNoteCard[data-type="error"]').text()).toBe('An error occurred while deleting the publication')
	})
})

describe('LockObject', () => {
	it('renders only when its modal is open', () => {
		expect(mountWith(LockObject).find('.NcDialog').exists()).toBe(false)
	})

	it('locks with the process and duration entered, then hides the form', async () => {
		vi.useFakeTimers()
		navigationStore.modal = 'lockObject'
		const wrapper = mountWith(LockObject)
		expect(wrapper.find('.NcDialog').attributes('data-name')).toBe('Lock Zaaksysteem')
		const [process, duration] = wrapper.findAll('input.NcTextField')
		expect([process.attributes('data-label'), duration.attributes('data-label'), duration.element.value]).toEqual(['Process Name (optional)', 'Duration in seconds (optional)', '3600'])

		await process.setValue('Jaarlijkse review')
		await duration.setValue('60')
		await button(wrapper, 'Lock').trigger('click')
		await flushPromises()

		expect(objectStore.lockObject).toHaveBeenCalledWith(objectStore.objectItem, 'Jaarlijkse review', '60')
		expect(wrapper.find('.NcNoteCard[data-type="success"]').text()).toBe('Object successfully locked')
		expect(wrapper.find('.formContainer').exists()).toBe(false)
		expect(button(wrapper, 'Lock').attributes('disabled')).toBe('')
		expect(button(wrapper, 'Close').exists()).toBe(true)

		vi.advanceTimersByTime(2000)
		await flushPromises()
		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
	})

	it('sends no process or duration when they are cleared', async () => {
		navigationStore.modal = 'lockObject'
		const wrapper = mountWith(LockObject)
		await wrapper.findAll('input.NcTextField')[1].setValue('')

		await button(wrapper, 'Lock').trigger('click')
		await flushPromises()

		expect(objectStore.lockObject).toHaveBeenCalledWith(objectStore.objectItem, undefined, undefined)
	})

	it('disables the fields while locking and shows the failure', async () => {
		const pending = deferred()
		objectStore.lockObject.mockReturnValueOnce(pending.promise)
		navigationStore.modal = 'lockObject'
		const wrapper = mountWith(LockObject)

		await button(wrapper, 'Lock').trigger('click')
		expect(wrapper.findAll('input.NcTextField').map((input) => input.attributes('disabled'))).toEqual(['', ''])
		expect(button(wrapper, 'Lock').find('.NcLoadingIcon').exists()).toBe(true)

		pending.reject(new Error(''))
		await flushPromises()
		expect(wrapper.find('.NcNoteCard[data-type="error"]').text()).toBe('Failed to lock object')
		expect(wrapper.find('.formContainer').exists()).toBe(true)
		expect(button(wrapper, 'Cancel').exists()).toBe(true)
	})

	it('cancel closes the modal and resets the form', async () => {
		navigationStore.modal = 'lockObject'
		const wrapper = mountWith(LockObject)
		await wrapper.findAll('input.NcTextField')[0].setValue('x')

		await button(wrapper, 'Cancel').trigger('click')

		expect(navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(objectStore.lockObject).not.toHaveBeenCalled()
		navigationStore.modal = 'lockObject'
		await flushPromises()
		expect(wrapper.findAll('input.NcTextField').map((input) => input.element.value)).toEqual(['', '3600'])
	})
})
