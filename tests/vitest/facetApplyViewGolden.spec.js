/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for how the facet store applies a saved view: which view and
 * query shapes are accepted, which filter dimensions survive, and what the
 * search term becomes. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import { FACET_DIMENSIONS } from '../../src/services/facets.js'
import { useFacetStore } from '../../src/store/modules/facets.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

const GOLDEN = path.resolve(import.meta.dirname, 'golden/facetApplyView.json')

const [first, second] = FACET_DIMENSIONS

const VIEWS = {
	'null view': null,
	'string view': 'view',
	'empty view': {},
	'null query': { query: null },
	'string query': { query: 'q' },
	'array query': { query: [] },
	'empty query': { query: {} },
	'search only': { query: { search: 'cloud' } },
	'numeric search': { query: { search: 42 } },
	'array filters': { query: { filters: ['x'], search: 's' } },
	'string filters': { query: { filters: 'x' } },
	'null filters': { query: { filters: null } },
	'mixed filters': {
		query: {
			filters: {
				[first]: ['a', 'b'],
				[second]: [],
				unknown: ['z'],
			},
			search: 'term',
		},
	},
	'non-array values': { query: { filters: { [first]: 'a', [second]: { a: 1 } } } },
	'every dimension': {
		query: {
			filters: Object.fromEntries(
				FACET_DIMENSIONS.map((d) => [d, [d + '-1']]),
			),
		},
	},
}

/**
 * Apply a view to a fresh store and record the schema state.
 *
 * @param {string} schema The schema slice.
 * @param {*} view The view.
 * @param {boolean} preset Whether the slice holds state beforehand.
 * @return {object} The filters and search afterwards, plus whether the slice exists.
 */
function record(schema, view, preset) {
	setActivePinia(createPinia())
	const store = useFacetStore()
	if (preset) {
		store.applyView(schema, {
			query: { filters: { [first]: ['old'] }, search: 'old' },
		})
		store[schema].savedViews = ['kept']
	} else {
		delete store[schema]
	}
	store.applyView(schema, structuredClone(view))
	return {
		activeFilters: store[schema].activeFilters,
		search: store[schema].search,
		savedViews: store[schema].savedViews,
		loading: store[schema].loading,
	}
}

describe('facet store applyView golden', () => {
	it('matches the recorded outcomes', () => {
		const actual = {}
		for (const [name, view] of Object.entries(VIEWS)) {
			actual['module ' + name] = record('module', view, true)
			actual['fresh ' + name] = record('fresh', view, false)
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(actual, null, '\t') + '\n')
		}
		expect(actual).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
