// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The email settings section as mounted: every field writes back into the
 * settings store, the transport choice shows the matching fields, the
 * connection and test-email buttons report what the store answered, and the
 * template editor inserts variables, switches templates and saves.
 *
 * @spec openspec/specs/fe-settings-ui/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'

const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => dialogs)

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))

const holder = vi.hoisted(() => ({ store: null }))
vi.mock('../../src/store/store.js', () => ({
	get settingsStore() {
		return holder.store
	},
}))

vi.mock('@nextcloud/vue', async (importOriginal) => {
	const original = await importOriginal()
	const { defineComponent: define, h: render } = await import('vue')
	/**
	 * A form field stub that renders its label and default slot.
	 *
	 * @param {string} name The component name.
	 * @return {object} The stub.
	 */
	function field(name) {
		return define({
			name,
			props: { modelValue: { default: null }, label: { type: String, default: '' } },
			emits: ['update:modelValue'],
			setup(props, { slots }) {
				return () => render('div', { class: 'field-stub', 'data-label': props.label }, slots.default ? slots.default() : [])
			},
		})
	}
	return {
		...original,
		NcTextField: field('NcTextField'),
		NcPasswordField: field('NcPasswordField'),
		NcTextArea: field('NcTextArea'),
		NcSelect: field('NcSelect'),
		NcCheckboxRadioSwitch: field('NcCheckboxRadioSwitch'),
	}
})

const { default: EmailConfiguration } =
	await import('../../src/views/settings/sections/EmailConfiguration.vue')

/**
 * A reactive settings store double.
 *
 * @param {object} overrides Overrides.
 * @return {object} The store.
 */
function makeStore(overrides = {}) {
	return reactive({
		loading: false,
		emailSettings: { enabled: true, transportType: 'smtp' },
		saveEmailSettings: vi.fn().mockResolvedValue(undefined),
		testEmailConnection: vi.fn().mockResolvedValue({ success: true, message: 'Connected' }),
		sendTestEmail: vi.fn().mockResolvedValue({ success: true, message: 'Sent' }),
		...overrides,
	})
}

/**
 * Mount the section and let its creation settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountSection() {
	const wrapper = mount(EmailConfiguration, {
		global: { stubs: { Save: true, Email: true } },
	})
	await flushPromises()
	return wrapper
}

/**
 * The field stub with the given component name and label.
 *
 * @param {object} wrapper The section wrapper.
 * @param {string} name The component name.
 * @param {string} label The field label.
 * @return {object} The field wrapper.
 */
function fieldNamed(wrapper, name, label) {
	const found = wrapper.findAllComponents({ name }).find((f) => f.props('label') === label)
	expect(found, label).toBeDefined()
	return found
}

/**
 * The button whose text contains the given label.
 *
 * @param {object} wrapper The section wrapper.
 * @param {string} label The label.
 * @return {object} The button wrapper.
 */
function button(wrapper, label) {
	const found = wrapper.findAll('button').find((b) => b.text().includes(label))
	expect(found, label).toBeDefined()
	return found
}

beforeEach(() => {
	vi.spyOn(console, 'info').mockImplementation(() => {})
	vi.spyOn(console, 'error').mockImplementation(() => {})
	dialogs.showError.mockClear()
	dialogs.showSuccess.mockClear()
	http.get.mockReset()
	http.post.mockReset()
	http.get.mockImplementation(async (url) => ({ data: { success: true, template: url.endsWith('/default') ? 'Standaardtekst' : '' } }))
	http.post.mockResolvedValue({ data: { success: true } })
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('EmailConfiguration mounted', () => {
	it('writes every sender, SMTP and test field back into the store', async () => {
		holder.store = makeStore()
		const wrapper = await mountSection()
		const updates = {
			'Sender Email': ['NcTextField', 'senderEmail', 'noreply@gemeente.nl'],
			'Sender Name': ['NcTextField', 'senderName', 'Gemeente'],
			'Test Receiver Override': ['NcTextField', 'testReceiverOverride', 'qa@gemeente.nl'],
		}
		for (const [label, [name, key, value]] of Object.entries(updates)) {
			fieldNamed(wrapper, name, label).vm.$emit('update:modelValue', value)
			expect(holder.store.emailSettings[key], label).toBe(value)
		}

		const smtpFields = wrapper.findAll('.smtp-config .field-stub')
		expect(smtpFields.length).toBeGreaterThan(0)
		const smtpComponents = wrapper.findAllComponents({ name: 'NcTextField' })
			.concat(wrapper.findAllComponents({ name: 'NcPasswordField' }))
			.filter((f) => f.element.closest('.smtp-config'))
		smtpComponents.forEach((f, i) => f.vm.$emit('update:modelValue', 'v' + i))
		const written = Object.values(holder.store.emailSettings)
		smtpComponents.forEach((f, i) => expect(written).toContain('v' + i))
	})

	it('keeps the transport value a string and swaps SMTP for API-key fields', async () => {
		holder.store = makeStore()
		const wrapper = await mountSection()
		const transport = wrapper.findAllComponents({ name: 'NcSelect' })[0]

		expect(transport.vm.$attrs.reduce({ label: 'Mailjet', value: 'mailjet' })).toBe('mailjet')
		transport.vm.$emit('update:modelValue', 'mailjet')
		await flushPromises()
		expect(holder.store.emailSettings.transportType).toBe('mailjet')
		expect(wrapper.find('.smtp-config').exists()).toBe(false)

		const apiFields = wrapper.findAllComponents({ name: 'NcTextField' })
			.concat(wrapper.findAllComponents({ name: 'NcPasswordField' }))
			.filter((f) => /api|secret|key/i.test(f.props('label')))
		expect(apiFields.length).toBeGreaterThan(0)
		apiFields.forEach((f, i) => f.vm.$emit('update:modelValue', 'k' + i))
		const written = Object.values(holder.store.emailSettings)
		apiFields.forEach((f, i) => expect(written).toContain('k' + i))
	})

	it('saves through the store and survives a failing save', async () => {
		holder.store = makeStore({ saveEmailSettings: vi.fn().mockRejectedValueOnce(new Error('down')).mockResolvedValue(undefined) })
		const wrapper = await mountSection()

		await button(wrapper, 'Save Email Settings').trigger('click')
		await flushPromises()
		expect(console.error).toHaveBeenCalledWith('Failed to save email settings:', expect.any(Error))
		expect(wrapper.vm.saving).toBe(false)

		await button(wrapper, 'Save Email Settings').trigger('click')
		await flushPromises()
		expect(holder.store.saveEmailSettings).toHaveBeenCalledTimes(2)
	})

	it('switches tabs through the tab bar', async () => {
		holder.store = makeStore()
		const wrapper = await mountSection()

		await wrapper.findAll('.tab-button').find((b) => b.text() === 'Testing').trigger('click')
		expect(wrapper.vm.activeTab).toBe('testing')
	})

	it('reports the connection test and the test email, also when they fail', async () => {
		holder.store = makeStore()
		const wrapper = await mountSection()

		await button(wrapper, 'Test Connection').trigger('click')
		await flushPromises()
		expect(holder.store.testEmailConnection).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.connectionTestResult).toEqual({ success: true, message: 'Connected' })
		expect(wrapper.text()).toContain('Connected')

		wrapper.findAllComponents({ name: 'NcTextField' }).find((f) => f.attributes('placeholder') === 'test@example.com' && !f.props('label').includes('Override'))
			.vm.$emit('update:modelValue', 'me@gemeente.nl')
		await flushPromises()
		await button(wrapper, 'Send Test Email').trigger('click')
		await flushPromises()
		expect(holder.store.sendTestEmail).toHaveBeenCalledWith('me@gemeente.nl')
		expect(wrapper.text()).toContain('Sent')

		holder.store.testEmailConnection.mockRejectedValueOnce(new Error('refused'))
		holder.store.sendTestEmail.mockRejectedValueOnce(new Error('bounced'))
		await button(wrapper, 'Test Connection').trigger('click')
		await button(wrapper, 'Send Test Email').trigger('click')
		await flushPromises()
		expect(wrapper.vm.connectionTestResult).toEqual({ success: false, message: 'Connection test failed: refused' })
		expect(wrapper.vm.testEmailResult).toEqual({ success: false, message: 'Test email failed: bounced' })
	})

	it('toggles the email type switches into the store', async () => {
		holder.store = makeStore()
		const wrapper = await mountSection()
		const keys = ['enabled', 'organizationRegistrationEnabled', 'organizationActivationEnabled', 'userCreationEnabled', 'userPasswordEnabled']

		wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).forEach((s) => s.vm.$emit('update:modelValue', false))
		for (const key of keys) {
			expect(holder.store.emailSettings[key], key).toBe(false)
		}
	})

	it('edits, extends, switches, resets and saves a template', async () => {
		holder.store = makeStore()
		const wrapper = await mountSection()
		const editor = () => wrapper.findComponent({ name: 'NcTextArea' })

		editor().vm.$emit('update:modelValue', 'Hallo ')
		await flushPromises()
		await wrapper.findAll('.variable-tag').find((b) => b.text() === '{{ organization.name }}').trigger('click')
		expect(wrapper.vm.templates.organization_registration).toBe('Hallo {{ organization.name }}')
		expect(editor().props('modelValue')).toBe('Hallo {{ organization.name }}')

		const other = wrapper.vm.availableTemplates[1]
		await button(wrapper.find('.tab-buttons'), other.name).trigger('click')
		expect(wrapper.vm.activeTemplate).toBe(other.key)
		expect(wrapper.find('.template-info h4').text()).toBe(other.name)
		expect(editor().props('modelValue')).toBe('')

		await button(wrapper, 'Reset to Default').trigger('click')
		await flushPromises()
		expect(editor().props('modelValue')).toBe('Standaardtekst')

		await button(wrapper, 'Save Template').trigger('click')
		await flushPromises()
		expect(http.post).toHaveBeenCalledWith(
			expect.stringContaining('/apps/stackiq/api/email/templates/' + other.key),
			{ template: 'Standaardtekst' },
		)
		expect(wrapper.find('.save-results').text()).toBe('Template saved.')
		expect(wrapper.vm.savingTemplate).toBe(false)
	})

	it('answers empty template details for an unknown template', async () => {
		holder.store = makeStore()
		const wrapper = await mountSection()
		wrapper.vm.activeTemplate = 'nope'

		expect(wrapper.vm.getActiveTemplateName()).toBe('')
		expect(wrapper.vm.getActiveTemplateDescription()).toBe('')
		expect(wrapper.vm.getActiveTemplateVariables()).toEqual({})
	})
})
