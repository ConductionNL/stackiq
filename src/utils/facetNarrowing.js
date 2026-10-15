/**
 * The `CnIndexPage` filter that narrows a self-fetch object list to the ids
 * a facets response matched (gemma-faceted-search).
 *
 * The filter key is OpenRegister's `_ids` list parameter, NOT a plain `id`
 * field filter: the objects endpoint ignores `id` (`id[]=<uuid>` returned
 * the whole list on OpenRegister 2.1.36), so a search or GEMMA facet
 * selection narrowed nothing while the facet counts said it did.
 *
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-counts-reflect-the-currently-filtered-set-not-the-unfiltered-universe
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facets-combine-with-free-text-search
 */

/**
 * Id that no real object carries. Sent when the facets matched nothing, so
 * the list is empty instead of `CnIndexPage` treating an empty id list as
 * "no filter" and showing everything.
 */
export const FACET_NO_MATCH_SENTINEL = '__gemma_facet_no_match__'

/**
 * Build the narrowing filter for a matched id set.
 *
 * @param {Array<string>} ids The `_meta.matchedObjectIds` of the last facets response.
 * @return {{ _ids: Array<string> }} The `CnIndexPage` `filter` prop value.
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-counts-reflect-the-currently-filtered-set-not-the-unfiltered-universe
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facets-combine-with-free-text-search
 */
export function facetNarrowingFilter(ids) {
	const list = Array.isArray(ids)
		? ids.filter((id) => typeof id === 'string' && id !== '')
		: []
	return { _ids: list.length > 0 ? list : [FACET_NO_MATCH_SENTINEL] }
}

/**
 * Whether any GEMMA facet dimension has a selected value.
 *
 * @param {object} activeFilters The facet store's `{ dimension: [values] }` map.
 * @return {boolean} True when at least one dimension has a value.
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facets-combine-with-free-text-search
 */
export function hasFacetSelection(activeFilters) {
	return Object.values(activeFilters ?? {}).some(
		(values) => Array.isArray(values) && values.length > 0,
	)
}

/**
 * The `CnIndexPage` `filter` prop of a faceted list.
 *
 * Only a GEMMA facet selection narrows by id. A free-text search alone goes
 * through the list's own `_search`, which OpenRegister pages server side:
 * narrowing it by the matched ids put every match in the URL, and from about
 * 170 matches the request line was too long (HTTP 414) and the list failed.
 *
 * @param {object} activeFilters The facet store's `{ dimension: [values] }` map.
 * @param {Array<string>} ids The `_meta.matchedObjectIds` of the last facets response.
 * @return {object} `{}` without a facet selection, else `{ _ids: [...] }`.
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facets-combine-with-free-text-search
 */
export function listNarrowingFilter(activeFilters, ids) {
	return hasFacetSelection(activeFilters) ? facetNarrowingFilter(ids) : {}
}
