// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The mail template editor saves through the backend: it loads each stored
 * template, posts `{template}` to the template's route, reports success only
 * when the backend confirms it, shows the backend's own refusal, and resets
 * from the template's default.
 *
 * @spec openspec/changes/mail-template-editor-saves/specs/email-delivery/spec.md#requirement-an-administrator-edits-and-saves-a-mail-template-req-010
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
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

const { default: EmailConfiguration } =
	await import('../../src/views/settings/sections/EmailConfiguration.vue')

const BASE = '/index.php/apps/stackiq/api/email/templates/'

/**
 * Mount the section over a store with a transport set.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountSection() {
	holder.store = reactive({ loading: false, emailSettings: { enabled: true, transportType: 'smtp' } })
	const wrapper = mount(EmailConfiguration, { global: { stubs: { Save: true, Email: true } } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	dialogs.showError.mockReset()
	dialogs.showSuccess.mockReset()
	http.get.mockReset()
	http.post.mockReset()
	http.get.mockImplementation(async (url) => {
		if (url.endsWith('/default')) {
			return { data: { success: true, template: 'Default activation text' } }
		}
		return { data: { success: true, template: 'Stored ' + url.slice(BASE.length) } }
	})
})

describe('the mail template editor', () => {
	it('opens each template with what the backend stores', async () => {
		const wrapper = await mountSection()

		for (const { key } of wrapper.vm.availableTemplates) {
			expect(http.get).toHaveBeenCalledWith(BASE + key)
			expect(wrapper.vm.templates[key]).toBe('Stored ' + key)
		}
	})

	it('saves an edited template and says so only when the backend confirms it', async () => {
		http.post.mockResolvedValue({ data: { success: true, message: 'Template organization_activation updated successfully' } })
		const wrapper = await mountSection()
		wrapper.vm.activeTemplate = 'organization_activation'
		wrapper.vm.updateTemplateContent('Welkom {{ user.name }}')

		await wrapper.vm.saveTemplate()

		expect(http.post).toHaveBeenCalledWith(BASE + 'organization_activation', { template: 'Welkom {{ user.name }}' })
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Template organization_activation updated successfully')
		expect(dialogs.showError).not.toHaveBeenCalled()
		expect(wrapper.vm.templateSaveResult).toEqual({ success: true, message: 'Template organization_activation updated successfully' })
		expect(wrapper.vm.savingTemplate).toBe(false)
	})

	it('shows the backend refusal and no success for an empty template', async () => {
		http.post.mockRejectedValue({ response: { status: 400, data: { success: false, message: 'Template content is required' } } })
		const wrapper = await mountSection()
		wrapper.vm.updateTemplateContent('')

		await wrapper.vm.saveTemplate()

		expect(dialogs.showError).toHaveBeenCalledWith('Template content is required')
		expect(dialogs.showSuccess).not.toHaveBeenCalled()
		expect(wrapper.vm.templateSaveResult).toEqual({ success: false, message: 'Template content is required' })
	})

	it('treats an answer without success as a failure', async () => {
		http.post.mockResolvedValue({ data: { success: false, message: 'Failed to update template user_creation' } })
		const wrapper = await mountSection()

		await wrapper.vm.saveTemplate()

		expect(dialogs.showSuccess).not.toHaveBeenCalled()
		expect(dialogs.showError).toHaveBeenCalledWith('Failed to update template user_creation')
	})

	it('resets to the default, which a save then stores', async () => {
		http.post.mockResolvedValue({ data: { success: true } })
		const wrapper = await mountSection()
		wrapper.vm.activeTemplate = 'organization_activation'

		await wrapper.vm.resetTemplate()
		expect(http.get).toHaveBeenCalledWith(BASE + 'organization_activation/default')
		expect(wrapper.vm.getActiveTemplateContent()).toBe('Default activation text')

		await wrapper.vm.saveTemplate()
		expect(http.post).toHaveBeenCalledWith(BASE + 'organization_activation', { template: 'Default activation text' })
	})

	it('says when the default cannot be loaded and keeps the edit', async () => {
		const wrapper = await mountSection()
		wrapper.vm.updateTemplateContent('My edit')
		http.get.mockRejectedValue({ response: { data: { message: 'Template not found' } } })

		await wrapper.vm.resetTemplate()

		expect(dialogs.showError).toHaveBeenCalledWith('Template not found')
		expect(wrapper.vm.getActiveTemplateContent()).toBe('My edit')
	})

	it('opens a template the backend cannot read as empty', async () => {
		http.get.mockRejectedValue(new Error('offline'))
		const wrapper = await mountSection()

		expect(wrapper.vm.templates.organization_registration).toBe('')
	})
})
