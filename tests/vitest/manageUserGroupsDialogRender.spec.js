// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ManageUserGroupsDialog, mounted: one checkbox per available group, checked
 * for the user's current groups and kept in step when toggled, Cancel and
 * the dialog's own close emitting `close`, and Save writing the selection
 * back while it is disabled and spinning.
 *
 * @spec openspec/specs/fe-organizations/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const organisatie = vi.hoisted(() => ({}))

vi.mock('../../src/store/modules/organisatie.js', () => ({
	useOrganisatieStore: () => organisatie,
}))

const { default: ManageUserGroupsDialog } =
	await import('../../src/dialogs/ManageUserGroupsDialog.vue')

const PERSON = { id: 42, user: { username: 'jdoe', groups: ['old'] } }
const GROUPS = [
	{ id: 'admin', name: 'Admin', description: 'Beheer' },
	{ id: 'editors', name: 'Editors', description: 'Redactie' },
	{ id: 'readers', name: 'Readers', description: 'Lezen' },
]

/**
 * Mount the dialog once the user's groups have loaded.
 *
 * @return {Promise<object>} The wrapper.
 */
async function dialog() {
	const wrapper = mount(ManageUserGroupsDialog, {
		props: { contactPerson: PERSON },
		global: { mocks: { t: (app, text) => text } },
	})
	await flushPromises()
	return wrapper
}

/**
 * Whether each checkbox is checked, in render order.
 *
 * @param {object} wrapper The dialog.
 * @return {Array<boolean>} The checked state per group.
 */
function checked(wrapper) {
	return wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).map((box) => box.vm.$attrs.modelValue)
}

beforeEach(() => {
	for (const key of Object.keys(organisatie)) delete organisatie[key]
	organisatie.getAvailableGroups = GROUPS
	organisatie.fetchUserInfo = vi.fn().mockResolvedValue({ groups: ['editors'] })
	organisatie.updateUserGroups = vi.fn().mockResolvedValue({})
})

describe('ManageUserGroupsDialog rendered', () => {
	it('shows one checkbox per available group, checked for the current groups', async () => {
		const wrapper = await dialog()

		expect(wrapper.findAll('.checkbox-stub').map((box) => box.text())).toEqual(['Admin', 'Editors', 'Readers'])
		expect(checked(wrapper)).toEqual([false, true, false])
		expect(wrapper.emitted('groups-loaded')).toEqual([[['editors']]])
	})

	it('keeps the checkboxes in step with toggling', async () => {
		const wrapper = await dialog()
		const boxes = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })

		boxes[0].vm.$emit('update:modelValue', true)
		boxes[1].vm.$emit('update:modelValue', false)
		await wrapper.vm.$nextTick()

		expect(checked(wrapper)).toEqual([true, false, false])
	})

	it('writes the toggled selection when Save is pressed', async () => {
		const wrapper = await dialog()
		wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })[2].vm.$emit('update:modelValue', true)

		const save = wrapper.findAll('button').find((button) => button.text() === 'Save')
		await save.trigger('click')
		await flushPromises()

		expect(organisatie.updateUserGroups).toHaveBeenCalledWith('jdoe', ['editors', 'readers'])
		expect(wrapper.emitted('saved')).toEqual([[['editors', 'readers']]])
	})

	it('disables Save and shows a spinner while saving', async () => {
		let finish
		organisatie.updateUserGroups = vi.fn(() => new Promise((resolve) => { finish = resolve }))
		const wrapper = await dialog()
		const save = () => wrapper.findAll('button').find((button) => button.text().includes('Save'))

		expect(save().attributes('disabled')).toBeUndefined()
		expect(wrapper.find('.dialog-actions').findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(false)

		await save().trigger('click')
		expect(save().attributes('disabled')).toBeDefined()
		expect(wrapper.find('.dialog-actions').findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(true)

		finish({})
		await flushPromises()
		expect(save().attributes('disabled')).toBeUndefined()
	})

	it('closes from Cancel and from the dialog itself without saving', async () => {
		const wrapper = await dialog()

		await wrapper.findAll('button').find((button) => button.text() === 'Cancel').trigger('click')
		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('closing')

		expect(wrapper.emitted('close')).toEqual([[], []])
		expect(organisatie.updateUserGroups).not.toHaveBeenCalled()
	})
})
