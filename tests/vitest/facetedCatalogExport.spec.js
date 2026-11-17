// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * FacetedCatalogIndexView's Export menu: the download carries the list's facets,
 * search, active quick filter and columns.
 *
 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params) => '/index.php' + path.replace('{schema}', params.schema) }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnFacetSidebar: defineComponent({ name: 'CnFacetSidebar', render: () => h('div') }),
	CnIndexPage: defineComponent({ name: 'CnIndexPage', render: () => h('div') }),
}))
vi.mock('../../src/modals/SaveFacetViewModal.vue', () => ({
	default: defineComponent({ name: 'SaveFacetViewModal', render: () => h('div') }),
}))

const { default: View } = await import('../../src/views/FacetedCatalogIndexView.vue')

afterEach(() => {
	vi.restoreAllMocks()
})

/**
 * Run exportList on a context and return the URL it opened.
 *
 * @param {object} state The view's state.
 * @param {string} format The format.
 * @return {URL}
 */
function exported(state, format) {
	const assign = vi.fn()
	vi.spyOn(window, 'location', 'get').mockReturnValue({ assign })
	View.methods.exportList.call(state, format)
	expect(assign).toHaveBeenCalledTimes(1)
	return new URL(assign.mock.calls[0][0], 'https://cloud.example')
}

describe('FacetedCatalogIndexView export', () => {
	it('downloads the list with its facets, search, active quick filter and columns', () => {
		const url = exported({
			schema: 'module',
			facetStore: { module: { activeFilters: { referenceComponent: ['Zaakregistratiecomponent'] }, search: 'zaak' } },
			quickFilters: [{ label: 'All', filter: {} }, { label: 'BBN2', filter: { bbnLevel: 'BBN2' } }],
			activeQuickFilterIndex: 1,
			columns: [{ key: 'name', label: 'Name' }],
		}, 'xlsx')

		expect(url.pathname).toBe('/index.php/apps/stackiq/api/catalog/module/export')
		expect(url.searchParams.getAll('_gf_referenceComponent[]')).toEqual(['Zaakregistratiecomponent'])
		expect(url.searchParams.get('search')).toBe('zaak')
		expect(url.searchParams.get('quickFilter')).toBe('{"bbnLevel":"BBN2"}')
		expect(url.searchParams.get('columns')).toBe('[{"key":"name","label":"Name"}]')
		expect(url.searchParams.get('format')).toBe('xlsx')
	})

	it('sends no quick filter when none is active', () => {
		const url = exported({
			schema: 'catalogService',
			facetStore: { catalogService: { activeFilters: {}, search: '' } },
			quickFilters: [{ label: 'BBN2', filter: { bbnLevel: 'BBN2' } }],
			activeQuickFilterIndex: null,
			columns: [],
		}, 'csv')

		expect(url.searchParams.has('quickFilter')).toBe(false)
		expect(url.searchParams.get('format')).toBe('csv')
	})
})
