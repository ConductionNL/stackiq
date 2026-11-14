// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ChangePasswordDialog, mounted: the dialog names the user, Save stays
 * disabled until the password meets every requirement (breach check
 * included), shows a spinner while saving, and Cancel and the dialog's own
 * close both close it.
 *
 * @spec openspec/specs/fe-organizations/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const organisatie = vi.hoisted(() => ({}))

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../src/store/modules/organisatie.js', () => ({
	useOrganisatieStore: () => organisatie,
}))
vi.mock('vue-material-design-icons/CheckCircle.vue', () => ({ default: { render: () => null } }))
vi.mock('vue-material-design-icons/CloseCircle.vue', () => ({ default: { render: () => null } }))
vi.mock('@nextcloud/vue', async () => {
	const { h } = await import('vue')
	return {
		NcDialog: {
			props: { name: { default: '' } },
			emits: ['closing'],
			render() {
				return h('section', { class: 'dialog', 'data-name': this.name }, this.$slots.default?.())
			},
		},
		NcButton: {
			props: { variant: { default: '' }, disabled: { default: false } },
			emits: ['click'],
			render() {
				return h(
					'button',
					{ class: this.variant, disabled: this.disabled, onClick: () => this.$emit('click') },
					[this.$slots.icon?.(), this.$slots.default?.()],
				)
			},
		},
		NcTextField: {
			props: { modelValue: { default: '' }, label: { default: '' } },
			emits: ['update:modelValue'],
			render() {
				return h('input', { 'aria-label': this.label, value: this.modelValue })
			},
		},
		NcLoadingIcon: { render: () => h('i', { class: 'loading' }) },
	}
})

const { default: ChangePasswordDialog } =
	await import('../../src/dialogs/ChangePasswordDialog.vue')

const STRONG = 'Sterk-Wachtwoord1!'

/**
 * Mount the dialog with the given starting state.
 *
 * @param {object} state Data overrides.
 * @return {object} The wrapper.
 */
function open(state = {}) {
	return mount(ChangePasswordDialog, {
		props: { username: 'jdoe' },
		data: () => state,
		global: {
			mocks: {
				t: (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m)),
			},
		},
	})
}

const save = (wrapper) => wrapper.find('button.primary')

beforeEach(() => {
	vi.clearAllMocks()
	for (const key of Object.keys(organisatie)) delete organisatie[key]
})

describe('ChangePasswordDialog (mounted)', () => {
	it('names the dialog and the user', () => {
		const wrapper = open()
		expect(wrapper.find('.dialog').attributes('data-name')).toBe('Change Password')
		expect(wrapper.find('.dialog-description').text()).toBe('Change password for user: jdoe')
		expect(wrapper.find('input').attributes('aria-label')).toBe('New password')
	})

	it.each([
		['an empty password', { newPassword: '' }],
		['a strong password not yet checked against breaches', { newPassword: STRONG }],
		['a strong password still being checked', { newPassword: STRONG, isPasswordPwned: false, pwnedCheckLoading: true }],
		['a breached password', { newPassword: STRONG, isPasswordPwned: true, pwnedCheckLoading: false }],
		['a password without a special character', { newPassword: 'Wachtwoord12', isPasswordPwned: false }],
	])('keeps Save disabled for %s', (label, state) => {
		expect(save(open(state)).attributes('disabled')).toBeDefined()
	})

	it('enables Save for a strong password that is not breached', () => {
		expect(save(open({ newPassword: STRONG, isPasswordPwned: false })).attributes('disabled')).toBeUndefined()
	})

	it('shows a spinner and disables Save while saving', async () => {
		let finish
		organisatie.changePassword = vi.fn(() => new Promise((resolve) => { finish = resolve }))
		const wrapper = open({ newPassword: STRONG, isPasswordPwned: false })

		await save(wrapper).trigger('click')
		expect(organisatie.changePassword).toHaveBeenCalledTimes(1)
		expect(save(wrapper).attributes('disabled')).toBeDefined()
		expect(save(wrapper).find('.loading').exists()).toBe(true)

		finish({})
		await flushPromises()
		expect(save(wrapper).find('.loading').exists()).toBe(false)
	})

	it('Cancel and the dialog close both ask the parent to close it', async () => {
		const wrapper = open()

		await wrapper.find('button.secondary').trigger('click')
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('closing')

		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})
