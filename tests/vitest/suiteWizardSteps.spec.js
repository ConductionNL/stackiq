// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The suite wizard's three steps as mounted: what each step emits into the
 * wizard's payload, which applications step 2 offers and keeps selected, and
 * what step 3 summarises.
 *
 * @spec openspec/specs/suite-wizard/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const store = vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => String(vars[key] ?? m))
	return { objectStore: {} }
})

vi.mock('../../src/store/store.js', () => store)

/**
 * A stub that keeps its props readable and renders its slot.
 *
 * @param {string} name The component name.
 * @return {object} The stub.
 */
function stub(name) {
	return defineComponent({
		name,
		inheritAttrs: false,
		props: ['modelValue', 'options', 'loading', 'multiple', 'label', 'type', 'required', 'placeholder', 'inputLabel', 'closeOnSelect', 'trackBy'],
		emits: ['update:modelValue'],
		render() {
			return h('div', { class: name }, this.$slots.default?.())
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcTextField: stub('NcTextField'),
	NcTextArea: stub('NcTextArea'),
	NcSelect: stub('NcSelect'),
	NcNoteCard: stub('NcNoteCard'),
}))

const { default: Step1Details } = await import('../../src/dialogs/SuiteWizard/Step1Details.vue')
const { default: Step2Applications } = await import('../../src/dialogs/SuiteWizard/Step2Applications.vue')
const { default: Step3Confirm } = await import('../../src/dialogs/SuiteWizard/Step3Confirm.vue')

beforeEach(() => {
	for (const key of Object.keys(store.objectStore)) {
		delete store.objectStore[key]
	}
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

describe('Step1Details', () => {
	it('merges each field into the payload and flags validity on name and short description', async () => {
		const w = mount(Step1Details, { props: { payload: { name: 'Suite', extra: 1 } } })
		const fields = w.findAllComponents({ name: 'NcTextField' })

		fields[1].vm.$emit('update:modelValue', 'Short')
		fields[0].vm.$emit('update:modelValue', '   ')
		fields[2].vm.$emit('update:modelValue', 'https://suite.example')

		expect(w.emitted('update:payload')).toEqual([
			[{ name: 'Suite', extra: 1, shortDescription: 'Short', _step1Valid: true }],
			[{ name: '   ', extra: 1, _step1Valid: false }],
			[{ name: 'Suite', extra: 1, website: 'https://suite.example', _step1Valid: false }],
		])
	})

	it('binds the long description through the payload', () => {
		const w = mount(Step1Details, { props: { payload: { name: 'S', shortDescription: 'K' } } })
		const area = w.findComponent({ name: 'NcTextArea' })

		expect(area.props('modelValue')).toBe('')
		area.vm.$emit('update:modelValue', 'Long text')

		expect(w.emitted('update:payload')[0][0]).toEqual({ name: 'S', shortDescription: 'K', longDescription: 'Long text', _step1Valid: true })
	})

	it('shows the stored values', () => {
		const w = mount(Step1Details, { props: { payload: { name: 'S', shortDescription: 'K', longDescription: 'L', website: 'W' } } })

		expect(w.findAllComponents({ name: 'NcTextField' }).map((f) => f.props('modelValue'))).toEqual(['S', 'K', 'W'])
		expect(w.findComponent({ name: 'NcTextArea' }).props('modelValue')).toBe('L')
	})
})

describe('Step2Applications', () => {
	it('offers the module collection and keeps the payload selection', async () => {
		store.objectStore.settings = {}
		store.objectStore.getCollection = (type) => (type === 'module' ? { results: [{ id: 'm-1', name: 'Zaaksysteem' }, { uuid: 'm-2', title: 'DMS' }] } : null)
		const w = mount(Step2Applications, { props: { payload: { applications: [{ id: 'm-1', name: 'Zaaksysteem' }, { id: 'm-9' }] } } })
		await flushPromises()
		const select = w.findComponent({ name: 'NcSelect' })

		expect(select.props('options').map((o) => [o.uuid, o.label])).toEqual([['m-1', 'Zaaksysteem'], ['m-2', 'DMS']])
		expect(select.props('modelValue')).toEqual([
			{ uuid: 'm-1', label: 'Zaaksysteem', raw: { id: 'm-1', name: 'Zaaksysteem' } },
			{ uuid: 'm-9', label: 'm-9', raw: { id: 'm-9' } },
		])
		expect(select.props('loading')).toBe(false)
	})

	it('writes the chosen modules back as applications', async () => {
		const w = mount(Step2Applications, { props: { payload: { name: 'S' } } })
		await flushPromises()
		const select = w.findComponent({ name: 'NcSelect' })

		select.vm.$emit('update:modelValue', [{ uuid: 'm-2', raw: { id: 'm-2' } }])
		select.vm.$emit('update:modelValue', null)

		expect(w.emitted('update:payload')).toEqual([
			[{ name: 'S', applications: [{ id: 'm-2' }] }],
			[{ name: 'S', applications: [] }],
		])
	})

	it('without a store collection offers nothing, and a failed load shows the error card', async () => {
		store.objectStore.settings = {}
		store.objectStore.fetchCollection = vi.fn(async () => {
			throw new Error('offline')
		})
		const w = mount(Step2Applications, { props: { payload: {} } })
		await flushPromises()

		expect(w.findComponent({ name: 'NcSelect' }).props('options')).toEqual([])
		expect(w.findComponent({ name: 'NcNoteCard' }).text()).toBe('Could not load applications. Please try again.')
		expect(store.objectStore.fetchCollection).toHaveBeenCalledWith('module', { _limit: 1000 })
	})
})

describe('Step3Confirm', () => {
	it('summarises the details, the website when given, and the applications', () => {
		const w = mount(Step3Confirm, { props: { payload: { name: 'Suite', shortDescription: 'Short', website: 'https://w', applications: [{ id: 'a', name: 'Alpha' }, { id: 'b' }] } } })

		expect(w.findAll('dd').map((d) => d.text())).toEqual(['Suite', 'Short', 'https://w'])
		expect(w.find('h3').text()).toBe('Applications (2)')
		expect(w.findAll('li').map((li) => li.text())).toEqual(['Alpha', 'b'])
		expect(w.find('.suite-wizard-step3__empty').exists()).toBe(false)
	})

	it('without applications or website says nothing is attached', () => {
		const w = mount(Step3Confirm, { props: { payload: { name: 'Suite', shortDescription: 'Short' } } })

		expect(w.findAll('dt').map((d) => d.text())).toEqual(['Name', 'Short description'])
		expect(w.find('h3').text()).toBe('Applications (0)')
		expect(w.find('.suite-wizard-step3__empty').text()).toBe('No applications attached yet.')
	})
})
