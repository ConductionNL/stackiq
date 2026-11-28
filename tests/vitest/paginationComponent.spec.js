// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the pagination component, mounted: when it renders at all,
 * which navigation buttons are disabled, which page a button asks for, and
 * which page size the selector reports. The page-number window itself is
 * pinned by paginationVisiblePagesGolden.spec.js.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { translate } from '@nextcloud/l10n'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { defineComponent, h } from 'vue'
import PaginationComponent from '../../src/components/PaginationComponent.vue'

/**
 * A select stub that shows the selected label and can emit a selection.
 */
const NcSelectStub = defineComponent({
	name: 'NcSelect',
	props: {
		modelValue: { type: Object, default: null },
		options: { type: Array, default: () => [] },
	},
	emits: ['option:selected'],
	setup(props) {
		return () => h('div', { class: 'select-stub' }, props.modelValue ? props.modelValue.label : '')
	},
})

/**
 * Mount the component with the given props.
 *
 * @param {object} props The props.
 * @return {object} The wrapper.
 */
function mountWith(props) {
	return mount(PaginationComponent, {
		props,
		global: {
			mocks: { t: translate },
			stubs: { NcSelect: NcSelectStub },
		},
	})
}

/**
 * The buttons by their text.
 *
 * @param {object} wrapper The wrapper.
 * @return {Map<string, object>} Button wrappers keyed by trimmed text.
 */
function buttons(wrapper) {
	return new Map(wrapper.findAll('button').map((b) => [b.text().trim(), b]))
}

describe('PaginationComponent', () => {
	it('renders nothing for a single page with few items', () => {
		const wrapper = mountWith({ currentPage: 1, totalPages: 1, totalItems: 10 })
		expect(wrapper.find('.viewPagination').exists()).toBe(false)
	})

	it('shows only the page info and size selector for one page with many items', () => {
		const wrapper = mountWith({ currentPage: 1, totalPages: 1, totalItems: 11 })
		expect(wrapper.find('.viewPageInfo').text()).toBe('Page 1 of 1')
		expect(wrapper.find('.viewPaginationNav').exists()).toBe(false)
		expect(wrapper.find('.select-stub').text()).toBe('20')
	})

	it('disables first and previous on the first page and marks the current page', () => {
		const wrapper = mountWith({ currentPage: 1, totalPages: 3, totalItems: 60 })
		const b = buttons(wrapper)
		expect([...b.keys()]).toEqual(['First', 'Previous', '1', '2', '3', 'Next', 'Last'])
		expect(b.get('First').attributes('disabled')).toBeDefined()
		expect(b.get('Previous').attributes('disabled')).toBeDefined()
		expect(b.get('1').attributes('disabled')).toBeDefined()
		expect(b.get('2').attributes('disabled')).toBeUndefined()
		expect(b.get('Next').attributes('disabled')).toBeUndefined()
		expect(b.get('Last').attributes('disabled')).toBeUndefined()
	})

	it('disables next and last on the last page', () => {
		const wrapper = mountWith({ currentPage: 3, totalPages: 3, totalItems: 60 })
		const b = buttons(wrapper)
		expect(b.get('Next').attributes('disabled')).toBeDefined()
		expect(b.get('Last').attributes('disabled')).toBeDefined()
		expect(b.get('First').attributes('disabled')).toBeUndefined()
	})

	it('emits the page each navigation button asks for', async () => {
		const wrapper = mountWith({ currentPage: 5, totalPages: 10, totalItems: 200 })
		const b = buttons(wrapper)
		await b.get('First').trigger('click')
		await b.get('Previous').trigger('click')
		await b.get('Next').trigger('click')
		await b.get('Last').trigger('click')
		await b.get('6').trigger('click')
		expect(wrapper.emitted('page-changed')).toEqual([[1], [4], [6], [10], [6]])
		expect(wrapper.findAll('.viewPaginationEllipsis')).toHaveLength(2)
	})

	it('refuses the current page and pages outside the range', () => {
		const wrapper = mountWith({ currentPage: 2, totalPages: 3, totalItems: 60 })
		wrapper.vm.changePage(2)
		wrapper.vm.changePage(0)
		wrapper.vm.changePage(4)
		expect(wrapper.emitted('page-changed')).toBeUndefined()
		wrapper.vm.changePage(3)
		expect(wrapper.emitted('page-changed')).toEqual([[3]])
	})

	it('emits a new page size only when it differs', async () => {
		const wrapper = mountWith({ currentPage: 1, totalPages: 3, totalItems: 60, currentPageSize: 50 })
		const select = wrapper.findComponent(NcSelectStub)
		expect(select.props('modelValue')).toEqual({ value: 50, label: '50' })
		select.vm.$emit('option:selected', { value: 50, label: '50' })
		select.vm.$emit('option:selected', { value: 100, label: '100' })
		expect(wrapper.emitted('page-size-changed')).toEqual([[100]])
	})

	it('falls back to the second option for an unknown page size', () => {
		const wrapper = mountWith({
			totalPages: 2,
			currentPageSize: 7,
			pageSizeOptions: [{ value: 5, label: 'five' }, { value: 15, label: 'fifteen' }],
		})
		expect(wrapper.find('.select-stub').text()).toBe('fifteen')
	})
})
