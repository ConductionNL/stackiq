/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The GEMMA facet store asked about a schema it has no slice for: the getters
 * answer with empty values, and every action starts the schema from an empty
 * slice instead of throwing. Also the failure paths whose error has no message.
 *
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-filter-state-is-url-encoded-and-deep-linkable
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fetchFacets } from '../../src/services/facets.js'
import { useFacetStore } from '../../src/store/modules/facets.js'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((path) => path),
}))

vi.mock('../../src/services/facets.js', async () => {
	const actual = await vi.importActual('../../src/services/facets.js')
	return {
		...actual,
		fetchFacets: vi.fn(),
	}
})

const EMPTY_FACETS = {
	referenceComponent: { values: [] },
	standard: { values: [] },
	applicationService: { values: [] },
	domain: { values: [] },
	supplier: { values: [] },
}

describe('facets store — a schema without a slice', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.mocked(fetchFacets).mockReset()
		vi.mocked(axios.get).mockReset()
		vi.mocked(axios.post).mockReset()
		vi.spyOn(console, 'error').mockImplementation(() => {})
	})

	it('the getters answer with empty values', () => {
		const store = useFacetStore()
		expect(store.facetDataFor('legacy')).toEqual(EMPTY_FACETS)
		expect(store.hasActiveFilterOrSearchFor('legacy')).toBe(false)
		expect(store.matchedObjectIdsFor('legacy')).toEqual([])
		expect(store.filtersToQuery('legacy')).toEqual({})
	})

	it('fetchFacets starts the schema and stores the answer', async () => {
		vi.mocked(fetchFacets).mockResolvedValue({ domain: [{ value: 'Zaken', count: 2 }] })
		const store = useFacetStore()
		await store.fetchFacets('legacy')
		expect(fetchFacets).toHaveBeenCalledWith('legacy', { filters: {}, search: '', organization: undefined })
		expect(store.legacy.data).toEqual({ domain: [{ value: 'Zaken', count: 2 }] })
		expect(store.legacy.loading).toBe(false)
	})

	it('setFilter starts the schema and wraps a single value in a list', () => {
		const store = useFacetStore()
		store.setFilter('legacy', 'domain', 'Zaken')
		expect(store.legacy.activeFilters).toEqual({ domain: ['Zaken'] })
		expect(store.legacy.search).toBe('')
	})

	it('setSearch starts the schema, and a non-string term becomes empty', () => {
		const store = useFacetStore()
		store.setSearch('legacy', 42)
		expect(store.legacy.search).toBe('')
		store.setSearch('other', 'zaak')
		expect(store.other.search).toBe('zaak')
	})

	it('clearFilters starts the schema with no filters', () => {
		const store = useFacetStore()
		store.clearFilters('legacy')
		expect(store.legacy.activeFilters).toEqual({})
		expect(store.legacy.data._meta.totalMatched).toBe(0)
	})

	it('setFiltersFromQuery starts the schema; no query clears filter and search', () => {
		const store = useFacetStore()
		store.setFiltersFromQuery('legacy', null)
		expect(store.legacy.activeFilters).toEqual({})
		expect(store.legacy.search).toBe('')
	})

	it('fetchSavedViews starts the schema and keeps only its own views', async () => {
		vi.mocked(axios.get).mockResolvedValue({
			data: {
				results: [
					{ id: 1, query: { marker: 'stackiq-gemma-facets', gemmaSchema: 'legacy' } },
					{ id: 2, query: { marker: 'stackiq-gemma-facets', gemmaSchema: 'module' } },
					{ id: 3, query: { marker: 'other', gemmaSchema: 'legacy' } },
				],
			},
		})
		const store = useFacetStore()
		await store.fetchSavedViews('legacy')
		expect(store.legacy.savedViews.map((view) => view.id)).toEqual([1])
		expect(store.legacy.savedViewsLoading).toBe(false)
	})

	it('saveCurrentAsView starts the schema and keeps no view when the answer has none', async () => {
		vi.mocked(axios.post).mockResolvedValue({ data: {} })
		const store = useFacetStore()
		await expect(store.saveCurrentAsView('legacy', 'Mijn view')).resolves.toBeUndefined()
		expect(axios.post).toHaveBeenCalledWith('/apps/openregister/api/views', {
			name: 'Mijn view',
			description: '',
			isPublic: false,
			isDefault: false,
			query: { marker: 'stackiq-gemma-facets', gemmaSchema: 'legacy', filters: {}, search: '' },
		})
		expect(store.legacy.savedViews).toEqual([])
	})
})

describe('facets store — failures without a message', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.spyOn(console, 'error').mockImplementation(() => {})
	})

	it('a facet fetch failure without a message stores the fallback error', async () => {
		vi.mocked(fetchFacets).mockRejectedValue({})
		const store = useFacetStore()
		await store.fetchFacets('module')
		expect(store.module.error).toBe('Failed to fetch facets')
		expect(store.module.loading).toBe(false)
	})

	it('a saved-views failure without a message stores the fallback error and no views', async () => {
		vi.mocked(axios.get).mockRejectedValue({})
		const store = useFacetStore()
		store.module.savedViews = [{ id: 'old' }]
		await store.fetchSavedViews('module')
		expect(store.module.savedViewsError).toBe('Failed to fetch saved views')
		expect(store.module.savedViews).toEqual([])
	})

	it('an answer without a results list gives no saved views', async () => {
		vi.mocked(axios.get).mockResolvedValue({ data: {} })
		const store = useFacetStore()
		await store.fetchSavedViews('catalogService')
		expect(store.catalogService.savedViews).toEqual([])
		expect(store.catalogService.savedViewsError).toBeNull()
	})
})
