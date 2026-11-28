/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The URL of the faceted catalogue export (insight-exports-and-custom-reports):
 * `GET /apps/stackiq/api/catalog/{schema}/export` with the list's GEMMA facet
 * selection as `_gf_<dimension>[]`, its search, the active quick filter's
 * `filter` as JSON, the page's columns as JSON and the format. Pure, so the
 * request the Export menu sends is tested without a browser.
 *
 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
 */

/** The prefix of a facet key, as the facet store keeps it in the route. */
export const FACET_PREFIX = '_gf_'

/**
 * The page's columns as `{ key, label }`: a column given as a key reads as its key.
 *
 * @param {Array<string|object>} columns The manifest columns.
 * @return {Array<{key: string, label: string}>}
 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
 */
export function exportColumns(columns) {
	return (columns ?? [])
		.map((column) => (typeof column === 'string' ? { key: column, label: column } : { key: column?.key, label: column?.label ?? column?.key }))
		.filter((column) => typeof column.key === 'string' && column.key !== '')
}

/**
 * The export URL of a faceted list as the user filtered it.
 *
 * @param {object} options The list's state.
 * @param {string} options.schema `module` or `catalogService`.
 * @param {object} [options.activeFilters] The selected values per GEMMA dimension.
 * @param {string} [options.search] The search term.
 * @param {object|null} [options.quickFilter] The active quick filter's `filter`.
 * @param {Array<string|object>} [options.columns] The page's columns.
 * @param {string} options.format `csv` or `xlsx`.
 * @param {(path: string, params: object) => string} generateUrl Nextcloud's URL generator.
 * @return {string}
 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
 */
export function catalogExportUrl({ schema, activeFilters = {}, search = '', quickFilter = null, columns = [], format }, generateUrl) {
	const params = new URLSearchParams()
	for (const [dimension, values] of Object.entries(activeFilters ?? {})) {
		for (const value of [].concat(values ?? [])) {
			if (typeof value === 'string' && value !== '') {
				params.append(`${FACET_PREFIX}${dimension}[]`, value)
			}
		}
	}
	if (typeof search === 'string' && search.trim() !== '') {
		params.set('search', search.trim())
	}
	if (quickFilter && typeof quickFilter === 'object' && Object.keys(quickFilter).length > 0) {
		params.set('quickFilter', JSON.stringify(quickFilter))
	}
	const pageColumns = exportColumns(columns)
	if (pageColumns.length > 0) {
		params.set('columns', JSON.stringify(pageColumns))
	}
	params.set('format', format === 'xlsx' ? 'xlsx' : 'csv')

	return `${generateUrl('/apps/stackiq/api/catalog/{schema}/export', { schema })}?${params.toString()}`
}
