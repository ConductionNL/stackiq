// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the submit-a-review modal, mounted: the rendered form, the
 * disabled submit button, the request a submit makes, the error note after a
 * failure and closing. The request body itself is pinned by
 * submitReviewGolden.spec.js.
 *
 * @spec openspec/specs/catalog-ratings/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mocks = vi.hoisted(() => ({
	apiRequest: null,
	showError: null,
	showSuccess: null,
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: (...args) => mocks.showError(...args),
	showSuccess: (...args) => mocks.showSuccess(...args),
}))
vi.mock('../../src/utils/adminApi.js', () => ({
	apiRequest: (...args) => mocks.apiRequest(...args),
}))

const { default: SubmitReviewModal } =
	await import('../../src/modals/SubmitReviewModal.vue')

/**
 * A stub bound through v-model, rendering the given tag.
 *
 * @param {string} name Component name.
 * @param {string} tag Root tag.
 * @return {object} The stub.
 */
function field(name, tag) {
	return defineComponent({
		name,
		props: { modelValue: { type: String, default: '' }, label: { type: String, default: '' } },
		emits: ['update:modelValue'],
		setup(props, { emit }) {
			return () => h(tag, {
				'data-label': props.label,
				value: props.modelValue,
				onInput: (event) => emit('update:modelValue', event.target.value),
			})
		},
	})
}

const NcDialogStub = defineComponent({
	name: 'NcDialog',
	props: { name: { type: String, default: '' } },
	emits: ['closing'],
	setup(props, { slots }) {
		return () => h('div', { class: 'dialog-stub', 'data-name': props.name }, slots.default ? slots.default() : [])
	},
})

const NcSelectStub = defineComponent({
	name: 'NcSelect',
	props: { modelValue: { type: Number, default: null }, options: { type: Array, default: () => [] } },
	emits: ['update:modelValue'],
	setup(props) {
		return () => h('div', { class: 'select-stub' }, String(props.modelValue))
	},
})

const NcNoteCardStub = defineComponent({
	name: 'NcNoteCard',
	props: { type: { type: String, default: '' } },
	setup(props, { slots }) {
		return () => h('div', { class: 'note-card-stub', 'data-type': props.type }, slots.default ? slots.default() : [])
	},
})

/**
 * Mount the modal, shown, for a module.
 *
 * @return {object} The wrapper.
 */
function mountShown() {
	return mount(SubmitReviewModal, {
		props: { show: true, subjectType: 'module', subjectId: 'uuid-1' },
		global: {
			stubs: {
				NcDialog: NcDialogStub,
				NcTextField: field('NcTextField', 'input'),
				NcTextArea: field('NcTextArea', 'textarea'),
				NcSelect: NcSelectStub,
				NcNoteCard: NcNoteCardStub,
			},
		},
	})
}

/**
 * The submit button.
 *
 * @param {object} wrapper The wrapper.
 * @return {object} The button wrapper.
 */
function submitButton(wrapper) {
	return wrapper.findAll('button').find((b) => b.text().includes('Submit review'))
}

describe('SubmitReviewModal', () => {
	beforeEach(() => {
		mocks.apiRequest = vi.fn(async () => ({ ok: true }))
		mocks.showError = vi.fn()
		mocks.showSuccess = vi.fn()
	})

	it('renders nothing while hidden', () => {
		const wrapper = mount(SubmitReviewModal, { props: { show: false, subjectType: 'module', subjectId: 'x' } })
		expect(wrapper.html()).toBe('<!--v-if-->')
	})

	it('renders the form with the default rating and a disabled submit button', () => {
		const wrapper = mountShown()
		expect(wrapper.find('.dialog-stub').attributes('data-name')).toBe('Write a review')
		expect(wrapper.find('input').attributes('data-label')).toBe('Title')
		expect(wrapper.find('textarea').attributes('data-label')).toBe('Testimonial')
		expect(wrapper.find('.select-stub').text()).toBe('8')
		expect(wrapper.findComponent(NcSelectStub).props('options')).toHaveLength(10)
		expect(submitButton(wrapper).attributes('disabled')).toBeDefined()
		expect(wrapper.find('.note-card-stub').exists()).toBe(false)
	})

	it('posts the review, thanks the user, emits and resets on success', async () => {
		const wrapper = mountShown()
		await wrapper.find('input').setValue('Great tool')
		await wrapper.find('textarea').setValue('Worked well')
		expect(submitButton(wrapper).attributes('disabled')).toBeUndefined()
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(mocks.apiRequest).toHaveBeenCalledTimes(1)
		const [route, options] = mocks.apiRequest.mock.calls[0]
		expect(route).toBe('reviews')
		expect(options.method).toBe('POST')
		expect(options.body).not.toHaveProperty('auteur')
		expect(mocks.showSuccess).toHaveBeenCalledWith('Thank you — your review was submitted for moderation')
		expect(wrapper.emitted('review-submitted')).toHaveLength(1)
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(wrapper.vm.formData).toEqual({ name: '', longDescription: '' })
		expect(wrapper.vm.loading).toBe(false)
	})

	it('shows the error note and keeps the form after a failure', async () => {
		mocks.apiRequest = vi.fn(async () => {
			throw new Error('rate limited')
		})
		const wrapper = mountShown()
		await wrapper.find('input').setValue('Great tool')
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(mocks.showError).toHaveBeenCalledWith('Could not submit your review: rate limited')
		const note = wrapper.find('.note-card-stub')
		expect(note.attributes('data-type')).toBe('error')
		expect(note.text()).toBe('rate limited')
		expect(wrapper.vm.formData.name).toBe('Great tool')
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(wrapper.vm.loading).toBe(false)
	})

	it('does not post an invalid form', async () => {
		const wrapper = mountShown()
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(mocks.apiRequest).not.toHaveBeenCalled()
	})

	it('resets the form and emits close from cancel and from the dialog', async () => {
		const wrapper = mountShown()
		await wrapper.find('input').setValue('Draft')
		await wrapper.findAll('button').find((b) => b.text().includes('Cancel')).trigger('click')
		expect(wrapper.vm.formData.name).toBe('')
		wrapper.findComponent(NcDialogStub).vm.$emit('closing')
		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})
