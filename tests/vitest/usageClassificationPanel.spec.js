// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest for the BIO classification panels (security-baseline-classification
 * REQ-BCL-002..004): the usage panel's suggestion fills without saving and
 * Save patches only the classification fields; the application summary
 * shows counts without names, withholds levels below three and hides itself
 * on 403.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ModuleClassificationSummary from '../../src/components/modules/ModuleClassificationSummary.vue'
import UsageClassificationPanel, { highestLevel } from '../../src/components/usages/UsageClassificationPanel.vue'

const mocks = vi.hoisted(() => ({
	get: vi.fn(),
	fetchObject: vi.fn(),
	patchObject: vi.fn(),
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnIcon: {}, CnWidgetWrapper: {} }))
vi.mock('@nextcloud/axios', () => ({ default: { get: mocks.get } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url, params = {}) => url.replace(/\{(\w+)\}/g, (_m, key) => params[key]) }))
vi.mock('../../src/store/store.js', () => ({ objectStore: { fetchObject: mocks.fetchObject, patchObject: mocks.patchObject } }))
vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	translate: (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_m, key) => vars[key] ?? `{${key}}`),
	translatePlural: (_app, one, many, count) => (count === 1 ? one : many).replace('%n', count),
}))

const stubs = {
	CnWidgetWrapper: { template: '<section><slot /></section>' },
	CnIcon: true,
	NcLoadingIcon: true,
	NcNoteCard: { template: '<div class="note"><slot /></div>' },
	NcTextField: { props: ['modelValue', 'label'], emits: ['update:modelValue'], template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">' },
	NcButton: { props: ['disabled', 'type'], emits: ['click'], template: '<button :type="type || \'button\'" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('UsageClassificationPanel', () => {
	it('loads the levels, and Suggest from GEMMA fills them without saving', async () => {
		mocks.fetchObject.mockResolvedValue({ availabilityLevel: 'BBN1', availabilityReason: 'Kort uit te zetten', classifiedAt: '2026-11-01', classifiedBy: 'ciso' })
		mocks.get.mockResolvedValue({ data: { levels: { availability: 'BBN1', integrity: 'BBN2', confidentiality: 'BBN2' }, scored: 1, components: 1 } })
		const wrapper = mount(UsageClassificationPanel, { props: { objectId: 'u-1' }, global: { stubs } })
		await flushPromises()

		expect(mocks.fetchObject).toHaveBeenCalledWith('usage', 'u-1')
		expect(wrapper.find('[data-testid="classification-overall"]').text()).toContain('BBN1')
		expect(wrapper.find('[data-testid="classification-save"]').attributes('disabled')).toBeDefined()

		await wrapper.find('[data-testid="classification-suggest"]').trigger('click')
		await flushPromises()

		expect(mocks.get).toHaveBeenCalledWith('/apps/stackiq/api/gebruik/u-1/classification-suggestion')
		expect(wrapper.find('[data-testid="classification-integrity"]').element.value).toBe('BBN2')
		expect(wrapper.find('[data-testid="classification-overall"]').text()).toContain('BBN2')
		expect(wrapper.find('[data-testid="classification-suggestion-note"]').text()).toBe('Suggested from 1 reference component with a GEMMA score')
		expect(mocks.patchObject).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="classification-save"]').attributes('disabled')).toBeUndefined()
	})

	it('saves only the classification fields, empty as null', async () => {
		mocks.fetchObject.mockResolvedValue({})
		mocks.patchObject.mockResolvedValue({})
		const wrapper = mount(UsageClassificationPanel, { props: { objectId: 'u-1' }, global: { stubs } })
		await flushPromises()

		await wrapper.find('[data-testid="classification-confidentiality"]').setValue('BBN3')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(mocks.patchObject).toHaveBeenCalledWith('usage', 'u-1', {
			availabilityLevel: null,
			availabilityReason: null,
			integrityLevel: null,
			integrityReason: null,
			confidentialityLevel: 'BBN3',
			confidentialityReason: null,
		})
	})

	it('derives the highest level, or none', () => {
		expect(highestLevel(['BBN1', '', 'BBN3', 'BBN2'])).toBe('BBN3')
		expect(highestLevel(['', null, 'high'])).toBe('')
	})
})

describe('ModuleClassificationSummary', () => {
	it('shows counts per level for three organisations, naming none', async () => {
		mocks.get.mockResolvedValue({
			data: {
				organisations: 3,
				levels: {
					availability: { BBN1: 2, BBN2: 1, BBN3: 0 },
					integrity: { BBN1: 0, BBN2: 3, BBN3: 0 },
					confidentiality: { BBN1: 0, BBN2: 2, BBN3: 1 },
					overall: { BBN1: 0, BBN2: 2, BBN3: 1 },
				},
			},
		})
		const wrapper = mount(ModuleClassificationSummary, { props: { objectId: 'm-1' }, global: { stubs } })
		await flushPromises()

		expect(mocks.get).toHaveBeenCalledWith('/apps/stackiq/api/modules/m-1/classification-summary')
		expect(wrapper.find('[data-testid="module-classification-count"]').text()).toBe('3 organisations classified this application')
		expect(wrapper.find('[data-testid="module-classification-overall"]').findAll('td').map((td) => td.text())).toEqual(['0', '2', '1'])
	})

	it('withholds the levels below three, and hides itself for a refused reader', async () => {
		mocks.get.mockResolvedValueOnce({ data: { organisations: 2, levels: null } })
		const two = mount(ModuleClassificationSummary, { props: { objectId: 'm-1' }, global: { stubs } })
		await flushPromises()
		expect(two.find('[data-testid="module-classification-withheld"]').exists()).toBe(true)
		expect(two.find('table').exists()).toBe(false)

		mocks.get.mockRejectedValueOnce({ response: { status: 403 } })
		const supplier = mount(ModuleClassificationSummary, { props: { objectId: 'm-1' }, global: { stubs } })
		await flushPromises()
		expect(supplier.find('[data-testid="module-classification-summary"]').exists()).toBe(false)
	})
})
