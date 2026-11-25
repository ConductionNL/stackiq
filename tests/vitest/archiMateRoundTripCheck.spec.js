// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * The Check a model file section as rendered: the Check button gated on a
 * picked file, the mode sent, the table with a row per category, the examples
 * of the categories with losses and the summary.
 *
 * @spec openspec/changes/archive/2026-11-25-architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-004-the-admin-settings-page-shall-show-the-round-trip-report
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ checkRoundTrip: vi.fn() }))
vi.mock('../../src/utils/archiMateRoundTrip.js', async (importOriginal) => ({
	...(await importOriginal()),
	checkRoundTrip: api.checkRoundTrip,
}))
vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))

const { default: ArchiMateRoundTripCheck } = await import('../../src/views/settings/sections/ArchiMateRoundTripCheck.vue')

const stubs = {
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template: '<button data-testid="check" :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue', 'value'],
		emits: ['update:modelValue'],
		template: '<label><input type="radio" :value="value" :checked="modelValue === value" @change="$emit(\'update:modelValue\', value)"><slot /></label>',
	},
	NcLoadingIcon: true,
	NcNoteCard: { props: ['type'], template: '<div :data-note="type"><slot /></div>' },
}

/**
 * Mount the section and pick a file.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountWithFile() {
	const wrapper = mount(ArchiMateRoundTripCheck, { global: { stubs } })
	const input = wrapper.get('[data-testid="round-trip-file"]')
	const file = new File(['<model/>'], 'model.xml')
	Object.defineProperty(input.element, 'files', { value: [file] })
	await input.trigger('change')
	return wrapper
}

const category = (overrides = {}) => ({ source: 2, result: 2, missing: 0, extra: 0, changed: 0, examples: [], ...overrides })

describe('ArchiMateRoundTripCheck', () => {
	beforeEach(() => {
		api.checkRoundTrip.mockReset()
	})

	it('keeps Check disabled until a file is picked, and offers both modes', async () => {
		const wrapper = mount(ArchiMateRoundTripCheck, { global: { stubs } })

		expect(wrapper.get('[data-testid="check"]').attributes('disabled')).toBeDefined()
		expect(wrapper.text()).toContain('Before import (nothing is written)')
		expect(wrapper.text()).toContain('Against the imported model')
	})

	it('checks before import by default and shows a row per category with the summary', async () => {
		api.checkRoundTrip.mockResolvedValue({
			imported: true,
			categories: {
				elements: category(),
				relationships: category({ result: 1, missing: 1, examples: [{ kind: 'missing', identifier: 'id-r1', name: 'Uses' }] }),
				views: category(),
			},
		})
		const wrapper = await mountWithFile()

		await wrapper.get('[data-testid="check"]').trigger('click')
		await flushPromises()

		expect(api.checkRoundTrip).toHaveBeenCalledWith(expect.any(File), 'before-import')
		expect(wrapper.findAll('tbody tr').map((row) => row.attributes('data-category'))).toEqual(['elements', 'relationships', 'views'])
		expect(wrapper.get('[data-category="relationships"]').text()).toContain('Relationships')
		expect(wrapper.text()).toContain('Losses in: Relationships')
		expect(wrapper.findAll('details')).toHaveLength(1)
		expect(wrapper.get('details').text()).toContain('Missing: id-r1 (Uses)')
	})

	it('sends the against-imported mode and says when the model is not imported', async () => {
		api.checkRoundTrip.mockResolvedValue({ imported: false, message: 'This model is not imported.' })
		const wrapper = await mountWithFile()

		await wrapper.findAll('input[type="radio"]')[1].trigger('change')
		await wrapper.get('[data-testid="check"]').trigger('click')
		await flushPromises()

		expect(api.checkRoundTrip).toHaveBeenCalledWith(expect.any(File), 'against-imported')
		expect(wrapper.get('[data-note="warning"]').text()).toBe('This model is not imported.')
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('reads No losses found for a clean round trip', async () => {
		api.checkRoundTrip.mockResolvedValue({ imported: true, categories: { elements: category() } })
		const wrapper = await mountWithFile()

		await wrapper.get('[data-testid="check"]').trigger('click')
		await flushPromises()

		expect(wrapper.get('[data-note="success"]').text()).toBe('No losses found')
	})

	it('shows the error when the check fails', async () => {
		api.checkRoundTrip.mockRejectedValue(new Error('Check failed: not XML'))
		const wrapper = await mountWithFile()

		await wrapper.get('[data-testid="check"]').trigger('click')
		await flushPromises()

		expect(wrapper.get('[data-note="error"]').text()).toBe('Check failed: not XML')
	})
})
