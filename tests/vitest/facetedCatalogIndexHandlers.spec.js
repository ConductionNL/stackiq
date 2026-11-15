// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * FacetedCatalogIndexView's handlers, run against a plain instance with a
 * recording facet store and router: restoring a legacy `_gf_search` link,
 * keeping the URL in step with the facets, the debounced search, saved
 * views, the detail route, quick filters and the unmount clean-up.
 *
 * @spec openspec/specs/gemma-faceted-search/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (match, key) => (key in vars ? vars[key] : match)),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnFacetSidebar: defineComponent({ name: 'CnFacetSidebar', render: () => h('div') }),
	CnIndexPage: defineComponent({ name: 'CnIndexPage', render: () => h('div') }),
}))
vi.mock('../../src/modals/SaveFacetViewModal.vue', () => ({
	default: defineComponent({ name: 'SaveFacetViewModal', render: () => h('div') }),
}))

const { default: View } = await import('../../src/views/FacetedCatalogIndexView.vue')

let calls = []

/**
 * A facet store that records every call made on it.
 *
 * @param {object} state The per-schema state.
 * @return {object} The store.
 */
function facetStore(state = { search: '' }) {
	const record = (name, answer) => (...args) => {
		calls.push([name, ...args])
		return typeof answer === 'function' ? answer(...args) : answer
	}
	return {
		module: state,
		setFiltersFromQuery: record('setFiltersFromQuery'),
		setSearch: record('setSearch'),
		setFilter: record('setFilter'),
		clearFilters: record('clearFilters'),
		fetchFacets: record('fetchFacets', Promise.resolve()),
		fetchSavedViews: record('fetchSavedViews'),
		filtersToQuery: record('filtersToQuery', () => ({ _gf_type: 'saas', _gf_search: 'drop me' })),
		applyView: record('applyView'),
		saveCurrentAsView: record('saveCurrentAsView', Promise.resolve()),
	}
}

/**
 * A plain instance of the view.
 *
 * @param {object} query The route query.
 * @param {object} overrides Props, data or doubles to set.
 * @return {object} The instance.
 */
function instance(query = {}, overrides = {}) {
	const self = {
		schema: 'module',
		quickFilters: null,
		detailRoute: 'applicationDetail',
		$route: { query },
		$router: {
			replace: vi.fn((location) => {
				calls.push(['router.replace', location])
				return Promise.resolve()
			}),
			push: vi.fn((location) => calls.push(['router.push', location])),
		},
		facetStore: facetStore(),
		...overrides,
	}
	Object.assign(self, View.data.call(self), overrides.data ?? {})
	for (const [name, method] of Object.entries(View.methods)) {
		self[name] = method.bind(self)
	}
	return self
}

beforeEach(() => {
	calls = []
	dialogs.showError.mockReset()
	dialogs.showSuccess.mockReset()
	vi.useFakeTimers()
})

afterEach(() => {
	vi.useRealTimers()
})

describe('restoring the route', () => {
	it('moves a legacy _gf_search term to _search and remounts the list', async () => {
		const vm = instance({ _gf_search: 'crm', _gf_type: 'saas', page: '2' })

		View.created.call(vm)
		await vi.runAllTimersAsync()

		expect(calls.slice(0, 3)).toEqual([
			['setFiltersFromQuery', 'module', { _gf_search: 'crm', _gf_type: 'saas', page: '2' }],
			['setSearch', 'module', 'crm'],
			['router.replace', { query: { _gf_type: 'saas', page: '2', _search: 'crm' } }],
		])
		expect(vm.listRemount).toBe(1)
	})

	it('keeps a current _search term and leaves the route alone', () => {
		const vm = instance({ _search: 'erp', _gf_search: 'old' })

		View.created.call(vm)

		expect(calls).toEqual([
			['setFiltersFromQuery', 'module', { _search: 'erp', _gf_search: 'old' }],
			['setSearch', 'module', 'erp'],
		])
		expect(vm.listRemount).toBe(0)
	})

	it('fetches the facets and saved views on mount', () => {
		const vm = instance()

		View.mounted.call(vm)

		expect(calls).toEqual([['fetchFacets', 'module'], ['fetchSavedViews', 'module']])
	})
})

describe('keeping the URL in step', () => {
	it('replaces the facet keys and never writes _gf_search', async () => {
		const vm = instance({ _gf_status: 'old', _search: 'crm', page: '3' })

		await vm.syncUrl()

		expect(calls).toEqual([
			['filtersToQuery', 'module'],
			['router.replace', { query: { _search: 'crm', page: '3', _gf_type: 'saas' } }],
		])
	})

	it('swallows a duplicate navigation', async () => {
		const vm = instance()
		vm.$router.replace = vi.fn(() => Promise.reject(new Error('NavigationDuplicated')))

		await expect(vm.syncUrl()).resolves.toBeUndefined()
	})

	it('sets or clears a facet, then syncs the URL and refetches', () => {
		const vm = instance()

		vm.onFacetFilterChange({ key: 'type', values: ['saas'] })
		vm.onClearAllFacets()

		expect(calls.map((call) => call[0])).toEqual([
			'setFilter', 'filtersToQuery', 'router.replace', 'fetchFacets',
			'clearFilters', 'filtersToQuery', 'router.replace', 'fetchFacets',
		])
		expect(calls[0]).toEqual(['setFilter', 'module', 'type', ['saas']])
	})
})

describe('the list search', () => {
	it('refetches after 400 ms, restarting on every keystroke', () => {
		const vm = instance()

		vm.onListSearch('c')
		vi.advanceTimersByTime(300)
		vm.onListSearch('cr')
		vi.advanceTimersByTime(399)
		expect(calls.filter((call) => call[0] === 'fetchFacets')).toEqual([])

		vi.advanceTimersByTime(1)
		expect(calls).toEqual([
			['setSearch', 'module', 'c'],
			['setSearch', 'module', 'cr'],
			['fetchFacets', 'module'],
		])
	})

	it('refetches at once when the search is cleared, and unmount cancels a pending one', () => {
		const vm = instance()

		vm.onListSearch('')
		vi.advanceTimersByTime(0)
		expect(calls.at(-1)).toEqual(['fetchFacets', 'module'])

		vm.onListSearch('crm')
		View.beforeUnmount.call(vm)
		vi.advanceTimersByTime(1000)
		expect(calls.filter((call) => call[0] === 'fetchFacets')).toHaveLength(1)
	})
})

describe('saved views', () => {
	it('applies a view with its search into the route and remounts the list', async () => {
		const vm = instance({ page: '2', _search: 'old' })
		vm.facetStore.module.search = '  crm '

		await vm.applySavedView({ id: 'v1', name: 'SaaS' })

		expect(calls[0]).toEqual(['applyView', 'module', { id: 'v1', name: 'SaaS' }])
		expect(calls[1]).toEqual(['router.replace', { query: { page: '2', _search: 'crm' } }])
		expect(vm.listRemount).toBe(1)
		expect(calls.at(-1)).toEqual(['fetchFacets', 'module'])
	})

	it('drops _search for a view without a search term', async () => {
		const vm = instance({ _search: 'old' })

		await vm.applySavedView({ id: 'v2' })

		expect(calls[1]).toEqual(['router.replace', { query: {} }])
	})

	it('saves the current filters, closes the modal and confirms', async () => {
		const vm = instance({}, { data: { showSaveViewModal: true } })

		await vm.onSaveView('My view')

		expect(calls).toEqual([['saveCurrentAsView', 'module', 'My view']])
		expect(vm.showSaveViewModal).toBe(false)
		expect(vm.savingView).toBe(false)
		expect(dialogs.showSuccess).toHaveBeenCalledWith('View "My view" saved')
	})

	it('keeps the modal open and reports a failed save', async () => {
		const vm = instance({}, { data: { showSaveViewModal: true } })
		vm.facetStore.saveCurrentAsView = () => Promise.reject(new Error('Name taken'))

		await vm.onSaveView('Dup')
		expect(vm.showSaveViewModal).toBe(true)
		expect(vm.savingView).toBe(false)
		expect(dialogs.showError).toHaveBeenCalledWith('Failed to save view: Name taken')

		vm.facetStore.saveCurrentAsView = () => Promise.reject({})
		await vm.onSaveView('Dup')
		expect(dialogs.showError).toHaveBeenLastCalledWith('Failed to save view: ')
	})
})

describe('rows and quick filters', () => {
	it('opens the detail route of a row with an id, and nothing without one', () => {
		const vm = instance()

		vm.openDetail({ id: 42 })
		vm.openDetail({})

		expect(vm.$router.push).toHaveBeenCalledTimes(1)
		expect(vm.$router.push).toHaveBeenCalledWith({ name: 'applicationDetail', params: { id: '42' } })
	})

	it('remembers an integer tab index and forgets anything else', () => {
		const vm = instance()

		vm.onQuickFilterChange(1)
		expect(vm.activeQuickFilterIndex).toBe(1)

		vm.onQuickFilterChange('1')
		expect(vm.activeQuickFilterIndex).toBeNull()
	})

	it('starts on the default quick filter, the first one, or none', () => {
		expect(instance({}, { quickFilters: [{ label: 'All' }, { label: 'Mine', default: true }] }).activeQuickFilterIndex).toBe(1)
		expect(instance({}, { quickFilters: [{ label: 'All' }] }).activeQuickFilterIndex).toBe(0)
		expect(instance({}, { quickFilters: [] }).activeQuickFilterIndex).toBeNull()
	})
})
