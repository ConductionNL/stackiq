// @vitest-environment jsdom
/**
 * The Archived tab of the Applications page says it ignores the facets and
 * the search term.
 *
 * The facet sidebar and the search box stay on screen on that tab, but its
 * filter carries an empty `_ids` that drops the facet id set. Without a note
 * an admin who picks a facet sees an unchanged list and no reason.
 *
 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-archived-applications-and-usages-shall-be-listable-in-stackiq-and-shall-stay-out-of-opencatalogi-and-portaliq-by-default-req-cmdb-017
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

// CnIndexPage stands in as a component that emits what the real one emits
// when a quick-filter tab is chosen.
vi.mock('@conduction/nextcloud-vue', () => ({
	CnFacetSidebar: defineComponent({
		name: 'CnFacetSidebar',
		render: () => h('div'),
	}),
	CnIndexPage: defineComponent({
		name: 'CnIndexPage',
		emits: ['quick-filter-change'],
		render() {
			return h('div', { class: 'index-page-stub' })
		},
	}),
}))
vi.mock('../../src/modals/SaveFacetViewModal.vue', () => ({
	default: defineComponent({ name: 'SaveFacetViewModal', render: () => h('div') }),
}))

import FacetedCatalogIndexView from '../../src/views/FacetedCatalogIndexView.vue'

const NOTE = '.faceted-catalog-index__archive-note'
const QUICK_FILTERS = [
	{ label: 'All', filter: {}, default: true },
	{ label: 'Archived', filter: { _archived: 'true', _ids: '' } },
]

/**
 * Mount the view with the facet store's network calls silenced.
 *
 * @return {object} The wrapper
 */
function mountView(quickFilters = QUICK_FILTERS) {
	setActivePinia(createPinia())
	return mount(FacetedCatalogIndexView, {
		props: {
			schema: 'module',
			register: 'voorzieningen',
			title: 'Applications',
			quickFilters,
		},
		global: {
			mocks: {
				$route: { query: {} },
				$router: { push: vi.fn() },
				t: (app, text) => text,
			},
			stubs: {
				NcTextField: true,
				NcButton: true,
				NcActions: true,
				NcActionButton: true,
				NcActionCaption: true,
			},
			plugins: [
				(app) => {
					app.config.globalProperties.t = (a, text) => text
				},
			],
		},
	})
}

describe('FacetedCatalogIndexView on the Archived tab', () => {
	it('shows the note only while the Archived tab is active', async () => {
		const wrapper = mountView()
		const index = wrapper.findComponent({ name: 'CnIndexPage' })

		expect(wrapper.find(NOTE).exists()).toBe(false)

		await index.vm.$emit('quick-filter-change', 1)
		expect(wrapper.find(NOTE).exists()).toBe(true)
		expect(wrapper.find(NOTE).text()).toContain('ignores the GEMMA filters')

		await index.vm.$emit('quick-filter-change', 0)
		expect(wrapper.find(NOTE).exists()).toBe(false)
	})

	it('follows the default tab at mount, before any tab event', () => {
		expect(mountView().find(NOTE).exists()).toBe(false)

		const archivedFirst = [
			{ label: 'Archived', filter: { _archived: 'true', _ids: '' } },
			{ label: 'All', filter: {} },
		]
		// No default marked: CnIndexPage starts on the first tab.
		expect(mountView(archivedFirst).find(NOTE).exists()).toBe(true)
	})

	it('drops the note when a facet or search change remounts the list on its default tab', async () => {
		const wrapper = mountView()
		const index = wrapper.findComponent({ name: 'CnIndexPage' })
		await index.vm.$emit('quick-filter-change', 1)
		expect(wrapper.find(NOTE).exists()).toBe(true)

		// A search term changes the key CnIndexPage is mounted under.
		wrapper.vm.facetStore.module.search = 'mail'
		await wrapper.vm.$nextTick()
		await wrapper.vm.$nextTick()

		expect(wrapper.find(NOTE).exists()).toBe(false)
	})
})
