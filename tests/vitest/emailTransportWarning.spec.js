// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The mail settings and the user and group settings warn when no transport
 * is set, so no registration, activation or account mail goes out.
 *
 * @spec openspec/changes/mail-template-editor-saves/specs/email-delivery/spec.md#requirement-the-settings-say-when-mail-is-not-being-sent-req-011
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'
import { isTransportUnset } from '../../src/utils/emailTemplates.js'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn().mockResolvedValue({ data: {} }), post: vi.fn() } }))

const holder = vi.hoisted(() => ({ store: null }))
vi.mock('../../src/store/store.js', () => ({
	get settingsStore() {
		return holder.store
	},
}))

const { default: EmailConfiguration } =
	await import('../../src/views/settings/sections/EmailConfiguration.vue')

const WARNING = 'No mail transport is set, so registration, activation and account mails are not sent.'

/**
 * Mount the mail section with a transport.
 *
 * @param {*} transportType The stored transport.
 * @return {Promise<object>} The wrapper.
 */
async function mountEmail(transportType) {
	holder.store = reactive({ loading: false, emailSettings: { enabled: true, transportType } })
	const wrapper = mount(EmailConfiguration, { global: { stubs: { Save: true, Email: true } } })
	await flushPromises()
	return wrapper
}

describe('the transport warning', () => {
	it('knows an unset transport', () => {
		for (const unset of [null, undefined, '', 'null']) {
			expect(isTransportUnset({ transportType: unset }), String(unset)).toBe(true)
		}
		expect(isTransportUnset(null)).toBe(true)
		for (const set of ['smtp', 'mailjet', 'sendgrid']) {
			expect(isTransportUnset({ transportType: set }), set).toBe(false)
		}
	})

	it('shows in the mail settings when the transport is null, and links to the transport', async () => {
		const wrapper = await mountEmail(null)
		wrapper.vm.activeTab = 'templates'

		const warning = wrapper.find('[data-testid="email-transport-warning"]')
		expect(warning.exists()).toBe(true)
		expect(warning.text()).toContain(WARNING)

		await warning.find('button').trigger('click')
		expect(wrapper.vm.activeTab).toBe('settings')
	})

	it('stays away when a transport is set', async () => {
		const wrapper = await mountEmail('smtp')

		expect(wrapper.find('[data-testid="email-transport-warning"]').exists()).toBe(false)
	})

	it('shows in the user and group settings with a link to the mail settings', async () => {
		holder.store = reactive({ emailSettings: { transportType: '' } })
		const { default: UserGroupsConfiguration } =
			await import('../../src/views/settings/sections/UserGroupsConfiguration.vue')
		const wrapper = mount(UserGroupsConfiguration, { shallow: true, global: { renderStubDefaultSlot: true } })

		expect(wrapper.vm.transportUnset).toBe(true)
		holder.store.emailSettings.transportType = 'smtp'
		expect(wrapper.vm.transportUnset).toBe(false)
	})
})
