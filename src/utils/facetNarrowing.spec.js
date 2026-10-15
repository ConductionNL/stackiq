/**
 * facetNarrowing.spec.js — the filter that narrows the Applications list to
 * the ids a facets response matched uses OpenRegister's `_ids` parameter.
 *
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facets-combine-with-free-text-search
 */
import {
	FACET_NO_MATCH_SENTINEL,
	facetNarrowingFilter,
	hasFacetSelection,
	listNarrowingFilter,
} from '../../src/utils/facetNarrowing.js'

describe('facetNarrowingFilter', () => {
	it('sends the matched ids as the _ids list parameter, never as a plain id field filter', () => {
		const filter = facetNarrowingFilter(['a1', 'b2'])
		expect(filter).toEqual({ _ids: ['a1', 'b2'] })
		expect(filter).not.toHaveProperty('id')
	})

	it('sends the no-match sentinel when nothing matched, so the list is empty rather than unfiltered', () => {
		expect(facetNarrowingFilter([])).toEqual({ _ids: [FACET_NO_MATCH_SENTINEL] })
		expect(facetNarrowingFilter(undefined)).toEqual({
			_ids: [FACET_NO_MATCH_SENTINEL],
		})
	})

	it('drops blank entries before deciding whether anything matched', () => {
		expect(facetNarrowingFilter(['', null, 'c3'])).toEqual({ _ids: ['c3'] })
		expect(facetNarrowingFilter(['', null])).toEqual({
			_ids: [FACET_NO_MATCH_SENTINEL],
		})
	})
})

describe('listNarrowingFilter', () => {
	it("does not narrow by id without a facet selection, so a search stays on the list's own _search", () => {
		const many = Array.from({ length: 1200 }, (_, i) => `id-${i}`)
		expect(listNarrowingFilter({}, many)).toEqual({})
		expect(listNarrowingFilter({ domain: [] }, many)).toEqual({})
		expect(listNarrowingFilter(undefined, many)).toEqual({})
	})

	it('narrows by the matched ids once a facet dimension has a value', () => {
		expect(listNarrowingFilter({ domain: ['Burgerzaken'] }, ['a1'])).toEqual({
			_ids: ['a1'],
		})
		expect(listNarrowingFilter({ standard: ['StUF'] }, [])).toEqual({
			_ids: [FACET_NO_MATCH_SENTINEL],
		})
	})

	it('tells a selection from an empty dimension', () => {
		expect(hasFacetSelection({ domain: [], standard: ['StUF'] })).toBe(true)
		expect(hasFacetSelection({ domain: [] })).toBe(false)
		expect(hasFacetSelection(null)).toBe(false)
	})
})
