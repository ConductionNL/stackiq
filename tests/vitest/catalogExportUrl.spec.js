/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Unit tests for the faceted catalogue export URL.
 *
 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
 */

import { describe, expect, it } from 'vitest'
import { catalogExportUrl, exportColumns } from '../../src/utils/catalogExportUrl.js'

const generateUrl = (path, params) => '/index.php' + path.replace('{schema}', encodeURIComponent(params.schema))

/**
 * The query of a URL as entries.
 *
 * @param {string} url The URL.
 * @return {Array<Array<string>>}
 */
function query(url) {
	return [...new URL(url, 'https://cloud.example').searchParams.entries()]
}

describe('catalogExportUrl', () => {
	it('carries the reference component facet, the BBN2 quick filter and the search to the export', () => {
		const url = catalogExportUrl({
			schema: 'module',
			activeFilters: { referenceComponent: ['Zaakregistratiecomponent'], domain: ['Zaken', ''] },
			search: ' zaak ',
			quickFilter: { bbnLevel: 'BBN2' },
			columns: ['name', { key: 'bbnLevel', label: 'BBN level' }],
			format: 'csv',
		}, generateUrl)

		expect(url.startsWith('/index.php/apps/stackiq/api/catalog/module/export?')).toBe(true)
		expect(query(url)).toEqual([
			['_gf_referenceComponent[]', 'Zaakregistratiecomponent'],
			['_gf_domain[]', 'Zaken'],
			['search', 'zaak'],
			['quickFilter', '{"bbnLevel":"BBN2"}'],
			['columns', '[{"key":"name","label":"name"},{"key":"bbnLevel","label":"BBN level"}]'],
			['format', 'csv'],
		])
	})

	it('asks Excel only as xlsx, and sends no empty search, quick filter or columns', () => {
		const url = catalogExportUrl({ schema: 'catalogService', search: '  ', quickFilter: {}, format: 'xlsx' }, generateUrl)

		expect(query(url)).toEqual([['format', 'xlsx']])
		expect(query(catalogExportUrl({ schema: 'module', format: 'pdf' }, generateUrl))).toEqual([['format', 'csv']])
	})
})

describe('exportColumns', () => {
	it('names each column by its label, a key column by its key, and drops one without a key', () => {
		expect(exportColumns(['name', { key: 'supplier', label: 'Supplier' }, { key: 'status' }, { label: 'no key' }, null])).toEqual([
			{ key: 'name', label: 'name' },
			{ key: 'supplier', label: 'Supplier' },
			{ key: 'status', label: 'status' },
		])
	})
})
