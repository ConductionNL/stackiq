// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The user groups settings section as it renders: one editable row per
 * configured group in each of the three tabs, adding, renaming and removing
 * rows through the rendered controls, the tab switch, the recommended group
 * descriptions, and the validation and save notes.
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h, reactive } from 'vue'

const toasts = vi.hoisted(() => [])
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
const store = vi.hoisted(() => ({ settingsStore: null }))
vi.mock('../../src/store/store.js', () => store)

const { default: UserGroupsConfiguration } = await import('../../src/views/settings/sections/UserGroupsConfiguration.vue')

/**
 * Mount the section over a store holding these groups.
 *
 * @param {object} groups The generic, organisation admin and super user groups.
 * @return {Promise<object>} The wrapper, after its initial reload.
 */
async function mountSection(groups) {
	store.settingsStore = reactive({
		loading: false,
		saveConfiguration: vi.fn().mockResolvedValue(undefined),
		loadUserGroupsOnly: vi.fn().mockResolvedValue(undefined),
		...groups,
	})
	const wrapper = mount(UserGroupsConfiguration, {
		global: {
			stubs: {
				NcTextField: {
					props: ['modelValue'],
					emits: ['update:modelValue'],
					render() {
						return h('input', {
							class: 'group-input',
							value: this.modelValue,
							onInput: (e) => this.$emit('update:modelValue', e.target.value),
						})
					},
				},
			},
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * The input values in one tab panel.
 *
 * @param {object} wrapper The section.
 * @param {number} panel The panel index: 0 generic, 1 organisation admin, 2 super user.
 * @return {string[]} The values.
 */
const values = (wrapper, panel) => wrapper.findAll('.tab-panel')[panel].findAll('.group-input').map((i) => i.element.value)

/**
 * The button in a panel whose text is exactly this.
 *
 * @param {object} wrapper The section.
 * @param {number} panel The panel index.
 * @param {string} text The button text.
 * @return {object} The button.
 */
const button = (wrapper, panel, text) => wrapper.findAll('.tab-panel')[panel].findAll('button').find((b) => b.text() === text)

beforeEach(() => {
	toasts.length = 0
})

describe('UserGroupsConfiguration rendering', () => {
	it('renders one row per group in each tab, and reloads on mount', async () => {
		const wrapper = await mountSection({
			genericUserGroups: ['gebruiker', 'ambtenaar'],
			organizationAdminGroups: ['organisatiebeheerder'],
			superUserGroups: null,
		})

		expect(store.settingsStore.loadUserGroupsOnly).toHaveBeenCalledTimes(1)
		expect(toasts).toEqual([['success', 'User groups reloaded successfully']])
		expect(values(wrapper, 0)).toEqual(['gebruiker', 'ambtenaar'])
		expect(values(wrapper, 1)).toEqual(['organisatiebeheerder'])
		expect(values(wrapper, 2)).toEqual([])
	})

	it('describes each generic group, known or custom', async () => {
		const wrapper = await mountSection({ genericUserGroups: ['maintainer', 'team-x'] })

		const items = wrapper.findAll('.default-groups-info li').map((li) => li.text())
		expect(items).toEqual(['maintainer - Administrator role', 'team-x - Custom user group'])
	})

	it('adds, renames and removes generic groups through the rendered controls', async () => {
		const wrapper = await mountSection({ genericUserGroups: ['gebruiker'] })

		await button(wrapper, 0, 'Add Group').trigger('click')
		expect(values(wrapper, 0)).toEqual(['gebruiker', ''])

		await wrapper.findAll('.tab-panel')[0].findAll('.group-input')[1].setValue('beheer')
		expect(store.settingsStore.genericUserGroups).toEqual(['gebruiker', 'beheer'])

		await wrapper.findAll('.tab-panel')[0].findAll('.group-item button')[0].trigger('click')
		expect(store.settingsStore.genericUserGroups).toEqual(['beheer'])
		expect(values(wrapper, 0)).toEqual(['beheer'])
	})

	it('adds, renames and removes organisation admin and super user groups', async () => {
		const wrapper = await mountSection({ organizationAdminGroups: ['a'], superUserGroups: ['s'] })

		await button(wrapper, 1, 'Add Organization Admin Group').trigger('click')
		await wrapper.findAll('.tab-panel')[1].findAll('.group-input')[1].setValue('b')
		await button(wrapper, 2, 'Add Super User Group').trigger('click')
		await wrapper.findAll('.tab-panel')[2].findAll('.group-input')[1].setValue('t')
		expect(store.settingsStore.organizationAdminGroups).toEqual(['a', 'b'])
		expect(store.settingsStore.superUserGroups).toEqual(['s', 't'])

		await wrapper.findAll('.tab-panel')[1].findAll('.group-item button')[0].trigger('click')
		await wrapper.findAll('.tab-panel')[2].findAll('.group-item button')[1].trigger('click')
		expect(store.settingsStore.organizationAdminGroups).toEqual(['b'])
		expect(store.settingsStore.superUserGroups).toEqual(['s'])
	})

	it('shows only the active tab, and switches on a tab click', async () => {
		const wrapper = await mountSection({ genericUserGroups: ['gebruiker'] })
		const visible = () => wrapper.findAll('.tab-panel').map((p) => p.element.style.display !== 'none')

		expect(visible()).toEqual([true, false, false])

		await wrapper.findAll('.tab-button').find((b) => b.text() === 'Super User Groups').trigger('click')

		expect(visible()).toEqual([false, false, true])
	})

	it('shows validation errors and the save outcome of each tab', async () => {
		const wrapper = await mountSection({ genericUserGroups: ['gebruiker'] })

		await wrapper.setData({
			groupValidation: { errors: ['Duplicate group', 'Empty name'] },
			groupsSaveResult: { success: false, error: 'Disk full' },
			organizationAdminGroupsSaveResult: { success: true },
			superUserGroupsSaveResult: { success: false, error: 'Denied' },
		})

		expect(wrapper.findAll('.validation-errors li').map((li) => li.text())).toEqual(['Duplicate group', 'Empty name'])
		const saves = wrapper.findAll('.save-results').map((s) => s.text())
		expect(saves).toEqual(['Disk full', 'Organization admin groups saved successfully!', 'Denied'])

		await wrapper.setData({ groupsSaveResult: { success: true }, groupValidation: { errors: [] } })

		expect(wrapper.find('.validation-errors').exists()).toBe(false)
		expect(wrapper.findAll('.save-results')[0].text()).toBe('Groups saved successfully!')
	})

	it('falls back to a generic message for a failed save without an error', async () => {
		const wrapper = await mountSection({})

		await wrapper.setData({ groupsSaveResult: { success: false } })

		expect(wrapper.find('.save-results').text()).toBe('Failed to save groups')
	})
})
