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
