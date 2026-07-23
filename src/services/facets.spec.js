/* eslint-disable no-console */
/**
 * Unit tests for the facets.js API client (gemma-faceted-search).
 *
 * @spec openspec/changes/gemma-faceted-search/tasks.md#task-10
 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

import { FACET_DIMENSIONS, buildFacetQueryParams, fetchFacets } from './facets.js'

// `virtual: true` — `@nextcloud/axios` ships an ESM-only `exports` map (no
// `require` condition), which Jest's CJS resolver cannot resolve even to
// locate the module for mocking. `virtual: true` mocks the specifier without
// requiring it to resolve for real, matching how the app's real webpack/
// Babel build (not Jest) actually consumes the package at runtime.
jest.mock('@nextcloud/axios', () => ({
	get: jest.fn(),
}), { virtual: true })

jest.mock('@nextcloud/router', () => ({
	generateUrl: jest.fn((path) => path),
}))

describe('facets.FACET_DIMENSIONS', () => {
	it('lists all four GEMMA dimensions', () => {
		expect(FACET_DIMENSIONS).toEqual(['referentiecomponent', 'standaard', 'applicatieservice', 'domein'])
	})
})

describe('facets.buildFacetQueryParams', () => {
	it('builds repeated dimension[]= params for array-valued filters', () => {
		const params = buildFacetQueryParams({
			filters: { referentiecomponent: ['Zaakregistratiecomponent', 'Klantcontactcomponent'] },
		})
		expect(params.getAll('referentiecomponent[]')).toEqual(['Zaakregistratiecomponent', 'Klantcontactcomponent'])
	})

	it('omits a dimension entirely when its filter value is not an array', () => {
		const params = buildFacetQueryParams({ filters: { referentiecomponent: 'not-an-array' } })
		expect(params.has('referentiecomponent[]')).toBe(false)
	})

	it('drops blank/whitespace-only values within a dimension', () => {
		const params = buildFacetQueryParams({ filters: { standaard: ['StUF-ZKN', '', '   '] } })
		expect(params.getAll('standaard[]')).toEqual(['StUF-ZKN'])
	})

	it('sets search only when non-blank', () => {
		expect(buildFacetQueryParams({ search: 'zaak' }).get('search')).toBe('zaak')
		expect(buildFacetQueryParams({ search: '   ' }).has('search')).toBe(false)
		expect(buildFacetQueryParams({}).has('search')).toBe(false)
	})

	it('sets organization only when non-blank', () => {
		expect(buildFacetQueryParams({ organization: 'org-uuid' }).get('organization')).toBe('org-uuid')
		expect(buildFacetQueryParams({}).has('organization')).toBe(false)
	})

	it('produces no params for an empty call', () => {
		expect(buildFacetQueryParams().toString()).toBe('')
	})
})

describe('facets.fetchFacets', () => {
	afterEach(() => {
		axios.get.mockReset()
		generateUrl.mockClear()
	})

	it('requests GET /apps/softwarecatalog/api/facets/{schema} with the encoded schema and query params', async () => {
		axios.get.mockResolvedValue({ data: { referentiecomponent: [], standaard: [], applicatieservice: [], domein: [], _meta: {} } })

		await fetchFacets('module', { filters: { referentiecomponent: ['A'] }, search: 'zaak' })

		expect(generateUrl).toHaveBeenCalledWith('/apps/softwarecatalog/api/facets/module')
		const [calledUrl] = axios.get.mock.calls[0]
		expect(calledUrl).toContain('/apps/softwarecatalog/api/facets/module?')
		expect(calledUrl).toContain('referentiecomponent%5B%5D=A')
		expect(calledUrl).toContain('search=zaak')
	})

	it('requests the bare schema URL (no ?) when no options are given', async () => {
		axios.get.mockResolvedValue({ data: {} })

		await fetchFacets('dienst')

		const [calledUrl] = axios.get.mock.calls[0]
		expect(calledUrl).toBe('/apps/softwarecatalog/api/facets/dienst')
	})

	it('returns the response body', async () => {
		const body = { referentiecomponent: [{ value: 'A', label: 'A', count: 3 }], standaard: [], applicatieservice: [], domein: [], _meta: { totalMatched: 3 } }
		axios.get.mockResolvedValue({ data: body })

		const result = await fetchFacets('module')

		expect(result).toEqual(body)
	})
})
