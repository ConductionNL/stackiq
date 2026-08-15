/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Builder for the schema document `CnFacetSidebar` consumes.
 *
 * `CnFacetSidebar` does NOT take a ready-made filter list. Its props are
 * `schema`, `facetData`, `activeFilters`, `loading`, `title`, `clearLabel` and
 * `userIsAdmin`; it derives its own filters with
 * `effectiveFilters() => filtersFromSchema(this.schema)`. Passing a `filters`
 * prop is silently dropped into `$attrs`, and `filtersFromSchema(null)`
 * returns `[]` — a sidebar with a title and an empty body, no console error.
 *
 * This builder produces the shape `filtersFromSchema` actually reads, and
 * `tests/vitest/facetSchema.spec.js` asserts that against the REAL function
 * loaded from the installed package rather than a local copy of its rules.
 */

/**
 * Build a schema document whose facetable properties are the given dimensions.
 *
 * @param {Record<string, () => string>} dimensionLabels Dimension key → label thunk.
 * @return {{properties: Record<string, object>}} A schema document for `CnFacetSidebar`.
 *
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-sidebar-ui-on-the-module-and-dienst-index-pages
 */
export function buildFacetDimensionSchema(dimensionLabels) {
	const properties = {}

	Object.keys(dimensionLabels || {}).forEach((key, index) => {
		properties[key] = {
			type: 'string',
			// `filtersFromSchema` labels a filter from `title`, falling back to
			// the raw key — a missing title degrades to "referenceComponent".
			title: dimensionLabels[key](),
			// Without this the property is filtered out entirely.
			facetable: true,
			order: index,
		}
	})

	return { properties }
}
