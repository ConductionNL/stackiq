// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * OrganisationModal, mounted: it renders only while shown, titles itself
 * per mode, fills the form from the organisation (and again when the
 * organisation changes or the modal reopens), maps the type select onto the
 * form, keeps submit disabled without a name, shows the countdown after a
 * save, and Cancel and the modal's own close both emit close.
 *
 * @spec openspec/specs/fe-organizations/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({
	objectStore: {
		getSchemaConfig: vi.fn(() => ({ register: 'reg-1', schema: 'schema-1' })),
		saveObject: vi.fn(async () => {}),
		patchObject: vi.fn(async () => {}),
	},
	navigationStore: { setTransferData: vi.fn() },
}))

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../src/store/store.js', () => store)
vi.mock('vue-material-design-icons/CheckCircle.vue', () => ({ default: { render: () => null } }))
vi.mock('@nextcloud/vue', async () => {
	const { h } = await import('vue')
	return {
		NcModal: {
			props: { name: { default: '' } },
			emits: ['close'],
			render() {
				return h('section', { class: 'modal', 'data-name': this.name }, [
					h('button', { class: 'modal-close', onClick: () => this.$emit('close') }),
					this.$slots.default?.(),
				])
			},
		},
		NcButton: {
			props: { variant: { default: '' }, disabled: { default: false }, type: { default: 'button' } },
			emits: ['click'],
			render() {
				return h(
					'button',
					{ class: this.variant, type: this.type, disabled: this.disabled, onClick: () => this.$emit('click') },
					[this.$slots.icon?.(), this.$slots.default?.()],
				)
			},
		},
		NcTextField: {
			props: { modelValue: { default: '' }, label: { default: '' } },
			emits: ['update:modelValue'],
			render() {
				return h('input', {
					'aria-label': this.label,
					value: this.modelValue,
					onInput: (event) => this.$emit('update:modelValue', event.target.value),
				})
			},
		},
		NcSelect: {
			name: 'NcSelect',
			props: { modelValue: { default: null }, options: { default: () => [] }, inputLabel: { default: '' } },
			emits: ['update:modelValue'],
			render() {
				return h('div', { class: 'select', 'data-label': this.inputLabel }, this.modelValue?.label ?? '')
			},
		},
		NcLoadingIcon: { render: () => h('i', { class: 'loading' }) },
	}
})

const { default: OrganisationModal } = await import('../../src/modals/OrganisationModal.vue')

const ORGANISATION = {
	id: 'org-1',
	name: 'Gemeente Test',
	type: 'Supplier',
	contactpersonen: ['c1'],
}

/**
 * Mount the modal.
 *
 * @param {object} props The props.
 * @return {object} The wrapper.
 */
function open(props = {}) {
	return mount(OrganisationModal, {
		props: { show: true, ...props },
		global: {
			mocks: {
				t: (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m)),
			},
		},
	})
}

const field = (wrapper, label) => wrapper.find(`input[aria-label="${label}"]`)
const submit = (wrapper) => wrapper.find('button.primary')

describe('OrganisationModal mounted', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		vi.spyOn(console, 'info').mockImplementation(() => {})
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
	})

	it('renders nothing while hidden', () => {
		const wrapper = open({ show: false })

		expect(wrapper.find('section.modal').exists()).toBe(false)
	})

	it.each([
		['create', 'Create Organisation'],
		['edit', 'Edit Organisation'],
		['copy', 'Copy Organisation'],
	])('titles the %s mode "%s"', (mode, title) => {
		const wrapper = open({ mode, organisation: mode === 'create' ? null : ORGANISATION })

		expect(wrapper.find('section.modal').attributes('data-name')).toBe(title)
		expect(wrapper.find('h2.modal-title').text()).toBe(title)
	})

	it('labels the submit button by mode', () => {
		expect(submit(open({ mode: 'edit', organisation: ORGANISATION })).text()).toBe('Update Organisation')
		expect(submit(open({ mode: 'copy', organisation: ORGANISATION })).text()).toBe('Create Organisation')
	})

	it('fills the form and the type select from the organisation', () => {
		const wrapper = open({ mode: 'edit', organisation: ORGANISATION })

		expect(field(wrapper, 'Name').element.value).toBe('Gemeente Test')
		expect(wrapper.find('.select').text()).toBe('Supplier')
		expect(wrapper.vm.selectedType).toEqual({ value: 'Supplier', label: 'Supplier' })
	})

	it('reloads the form when the organisation changes', async () => {
		const wrapper = open({ mode: 'edit', organisation: ORGANISATION })

		await wrapper.setProps({ organisation: { id: 'org-2', name: 'Andere', type: 'Community' } })

		expect(field(wrapper, 'Name').element.value).toBe('Andere')
		expect(wrapper.vm.selectedType).toEqual({ value: 'Community', label: 'Community' })
	})

	it('resets and reloads when it is reopened', async () => {
		const wrapper = open({ mode: 'edit', organisation: ORGANISATION })
		await field(wrapper, 'Name').setValue('Typed but not saved')
		wrapper.vm.success = true

		await wrapper.setProps({ show: false })
		await wrapper.setProps({ show: true })

		expect(field(wrapper, 'Name').element.value).toBe('Gemeente Test')
		expect(wrapper.vm.success).toBe(false)
	})

	it('reopening without an organisation leaves an empty form', async () => {
		const wrapper = open()
		await field(wrapper, 'Name').setValue('Draft')

		await wrapper.setProps({ show: false })
		await wrapper.setProps({ show: true })

		expect(field(wrapper, 'Name').element.value).toBe('')
		expect(wrapper.vm.selectedType).toBeNull()
	})

	it('maps the selected type onto the form, and a cleared one to an empty type', async () => {
		const wrapper = open()
		const select = wrapper.findComponent({ name: 'NcSelect' })

		await select.vm.$emit('update:modelValue', { value: 'Municipality', label: 'Municipality' })
		expect(wrapper.vm.formData.type).toBe('Municipality')

		await select.vm.$emit('update:modelValue', null)
		expect(wrapper.vm.formData.type).toBe('')
	})

	it('writes every text field into the form', async () => {
		const wrapper = open()
		const values = {
			Name: ['name', 'Gemeente X'],
			Website: ['website', 'https://x.nl'],
			'Short Description': ['shortDescription', 'Kort'],
			Email: ['e-mailadres', 'info@x.nl'],
			Phone: ['telefoonnummer', '010'],
			OIN: ['oin', '0001'],
			CBS: ['cbs', '0363'],
		}

		for (const [label, [, value]] of Object.entries(values)) {
			await field(wrapper, label).setValue(value)
		}

		for (const [key, value] of Object.values(values)) {
			expect(wrapper.vm.formData[key]).toBe(value)
		}
	})

	it('keeps submit disabled until the name has text', async () => {
		const wrapper = open()
		expect(submit(wrapper).attributes('disabled')).toBeDefined()

		await field(wrapper, 'Name').setValue('   ')
		expect(submit(wrapper).attributes('disabled')).toBeDefined()

		await field(wrapper, 'Name').setValue('Nieuwe organisatie')
		expect(submit(wrapper).attributes('disabled')).toBeUndefined()
	})

	it('after a create it hides submit, shows the message and closes when the countdown ends', async () => {
		vi.useFakeTimers()
		const wrapper = open()
		await field(wrapper, 'Name').setValue('Nieuwe organisatie')

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(store.objectStore.saveObject).toHaveBeenCalledTimes(1)
		expect(submit(wrapper).exists()).toBe(false)
		expect(wrapper.find('.success-message').text()).toContain('Organisation created successfully')
		expect(wrapper.find('.auto-close-message').text()).toBe('This dialog will close automatically in 3 seconds...')

		await vi.advanceTimersByTimeAsync(1000)
		expect(wrapper.find('.auto-close-message').text()).toBe('This dialog will close automatically in 2 seconds...')
		expect(wrapper.emitted('close')).toBeUndefined()

		await vi.advanceTimersByTimeAsync(2000)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('Cancel and the modal close both emit close', async () => {
		const wrapper = open()

		await wrapper.find('button.secondary').trigger('click')
		await wrapper.find('button.modal-close').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(2)
	})

	it('stops a running countdown when it unmounts', async () => {
		const clear = vi.spyOn(globalThis, 'clearInterval')
		const wrapper = open()
		wrapper.vm.countdownInterval = 42

		wrapper.unmount()

		expect(clear).toHaveBeenCalledWith(42)
	})
})
