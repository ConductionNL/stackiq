// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the save-as-view modal, mounted: when it renders, the
 * trimmed name it emits, the empty-name guard, the disabled save button,
 * closing, and the name reset each time the modal opens.
 *
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view
 */

import { translate } from '@nextcloud/l10n'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { defineComponent, h } from 'vue'
import SaveFacetViewModal from '../../src/modals/SaveFacetViewModal.vue'

/**
 * A dialog stub that renders its slot and can emit `closing`.
 */
const NcDialogStub = defineComponent({
	name: 'NcDialog',
	props: { name: { type: String, default: '' } },
	emits: ['closing'],
	setup(props, { slots }) {
		return () => h('div', { class: 'dialog-stub', 'data-name': props.name }, slots.default ? slots.default() : [])
	},
})

/**
 * A text-field stub bound through v-model.
 */
const NcTextFieldStub = defineComponent({
	name: 'NcTextField',
	props: { modelValue: { type: String, default: '' } },
	emits: ['update:modelValue'],
	setup(props, { emit }) {
		return () => h('input', {
			class: 'text-field-stub',
			value: props.modelValue,
			onInput: (event) => emit('update:modelValue', event.target.value),
		})
	},
})

/**
 * Mount the modal with the given props.
 *
 * @param {object} props The props.
 * @return {object} The wrapper.
 */
function mountWith(props) {
	return mount(SaveFacetViewModal, {
		props,
		global: {
			mocks: { t: translate },
			stubs: { NcDialog: NcDialogStub, NcTextField: NcTextFieldStub },
		},
	})
}

/**
 * The save button.
 *
 * @param {object} wrapper The wrapper.
 * @return {object} The button wrapper.
 */
function saveButton(wrapper) {
	return wrapper.findAll('button').find((b) => b.text().includes('Save view'))
}

describe('SaveFacetViewModal', () => {
	it('renders nothing while hidden', () => {
		expect(mountWith({ show: false }).find('.dialog-stub').exists()).toBe(false)
	})

	it('emits the trimmed name on submit', async () => {
		const wrapper = mountWith({ show: true })
		expect(wrapper.find('.dialog-stub').attributes('data-name')).toBe('Save as view')
		await wrapper.find('input').setValue('  My view  ')
		expect(saveButton(wrapper).attributes('disabled')).toBeUndefined()
		await wrapper.find('form').trigger('submit')
		expect(wrapper.emitted('save')).toEqual([['My view']])
	})

	it('refuses a blank name and disables the save button', async () => {
		const wrapper = mountWith({ show: true })
		await wrapper.find('input').setValue('   ')
		expect(saveButton(wrapper).attributes('disabled')).toBeDefined()
		await wrapper.find('form').trigger('submit')
		expect(wrapper.emitted('save')).toBeUndefined()
	})

	it('disables the save button while saving', async () => {
		const wrapper = mountWith({ show: true, saving: true })
		await wrapper.find('input').setValue('A view')
		expect(saveButton(wrapper).attributes('disabled')).toBeDefined()
	})

	it('emits close from the cancel button and from the dialog', async () => {
		const wrapper = mountWith({ show: true })
		await wrapper.findAll('button').find((b) => b.text().includes('Cancel')).trigger('click')
		wrapper.findComponent(NcDialogStub).vm.$emit('closing')
		expect(wrapper.emitted('close')).toHaveLength(2)
	})

	it('clears the name each time it opens', async () => {
		const wrapper = mountWith({ show: true })
		await wrapper.find('input').setValue('Old name')
		await wrapper.setProps({ show: false })
		expect(wrapper.vm.name).toBe('Old name')
		await wrapper.setProps({ show: true })
		expect(wrapper.vm.name).toBe('')
		expect(wrapper.find('input').element.value).toBe('')
	})
})
