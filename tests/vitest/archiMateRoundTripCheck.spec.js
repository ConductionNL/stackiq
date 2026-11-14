// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest for "Check a model file" (architecture-round-trip-check REQ-ART-004):
 * the picker, the two modes and Check, the table per category with expandable
 * examples, the summary, the not-imported answer and the error.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ArchiMateRoundTripCheck, { examplesOf } from '../../src/views/settings/sections/ArchiMateRoundTripCheck.vue'

const store = vi.hoisted(() => ({ checkRoundTrip: vi.fn() }))
vi.mock('../../src/store/store.js', () => ({ settingsStore: store }))
vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	translate: (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_m, key) => vars[key] ?? `{${key}}`),
}))

const stubs = {
	NcButton: { props: ['disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: true,
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
}

const category = (overrides = {}) => ({ source: 3, result: 3, missing: 0, extra: 0, changed: 0, examples: [], ...overrides })
const REPORT = {
	success: true,
	mode: 'before-import',
	lossless: false,
	categories: {
		elements: category({ source: 120, result: 119, missing: 1, examples: [{ kind: 'missing', identifier: 'id-e1', name: 'Zaaksysteem' }] }),
		relationships: category({ changed: 1, examples: [{ kind: 'changed', identifier: 'id-r1', name: '', fields: ['target'] }] }),
		views: category(),
		viewNodes: category(),
		propertyDefinitions: category(),
		organizations: category(),
	},
}

/**
 * Mount the section and pick a file.
 *
 * @return {Promise<object>} The wrapper.
 */
async function withFile() {
	const wrapper = mount(ArchiMateRoundTripCheck, { global: { stubs } })
	const input = wrapper.find('[data-test="round-trip-file"]')
	Object.defineProperty(input.element, 'files', { value: [new File(['<model/>'], 'gemma.xml')] })
	await input.trigger('change')
	return wrapper
}

beforeEach(() => {
	store.checkRoundTrip.mockReset()
})

describe('ArchiMateRoundTripCheck', () => {
	it('offers the picker, the two modes and a Check that waits for a file', () => {
		const wrapper = mount(ArchiMateRoundTripCheck, { global: { stubs } })
		expect(wrapper.find('[data-test="round-trip-mode-before"]').text()).toBe('Before import (nothing is written)')
		expect(wrapper.find('[data-test="round-trip-mode-against"]').text()).toBe('Against the imported model')
		expect(wrapper.find('[data-test="round-trip-check-button"]').attributes('disabled')).toBeDefined()
	})

	it('checks the file in the chosen mode and shows a row per category with expandable examples', async () => {
		store.checkRoundTrip.mockResolvedValue(REPORT)
		const wrapper = await withFile()
		await wrapper.find('[data-test="round-trip-mode-against"] input').setValue(true)
		await wrapper.find('[data-test="round-trip-check-button"]').trigger('click')
		await flushPromises()

		expect(store.checkRoundTrip).toHaveBeenCalledWith(expect.any(File), 'against-imported')
		expect(wrapper.find('[data-test="round-trip-summary"]').text()).toBe('Losses in: Elements, Relationships')
		expect(wrapper.find('[data-test="round-trip-row-elements"]').findAll('td').map((td) => td.text())).toEqual(['120', '119', '1', '0', '0'])
		expect(wrapper.findAll('tbody tr[data-test^="round-trip-row-"]')).toHaveLength(6)

		await wrapper.find('[data-test="round-trip-row-relationships"] button').trigger('click')
		expect(wrapper.find('[data-test="round-trip-examples-relationships"]').text()).toContain('id-r1')
		expect(wrapper.find('[data-test="round-trip-examples-relationships"]').text()).toContain('target')
		expect(wrapper.find('[data-test="round-trip-row-views"] button').exists()).toBe(false)
	})

	it('reads "No losses found" for a lossless report', async () => {
		store.checkRoundTrip.mockResolvedValue({ ...REPORT, lossless: true, categories: { elements: category(), views: category() } })
		const wrapper = await withFile()
		await wrapper.vm.check()
		await flushPromises()
		expect(wrapper.find('[data-test="round-trip-summary"]').text()).toBe('No losses found')
	})

	it('names a model that is not imported, and shows a failure as text', async () => {
		store.checkRoundTrip.mockResolvedValueOnce({ success: true, imported: false, message: 'The model is not imported' })
		const wrapper = await withFile()
		await wrapper.vm.check()
		await flushPromises()
		expect(wrapper.find('[data-test="round-trip-not-imported"]').text()).toBe('The model is not imported')

		store.checkRoundTrip.mockRejectedValueOnce(new Error('Admin privileges required'))
		await wrapper.vm.check()
		await flushPromises()
		expect(wrapper.find('[data-test="round-trip-error"]').text()).toBe('Admin privileges required')
	})

	it('flattens examples given per kind', () => {
		expect(examplesOf({ examples: { missing: ['id-1'], changed: [{ identifier: 'id-2', fields: ['name'] }] } }))
			.toEqual([{ kind: 'missing', identifier: 'id-1' }, { kind: 'changed', identifier: 'id-2', fields: ['name'] }])
		expect(examplesOf(undefined)).toEqual([])
	})
})
