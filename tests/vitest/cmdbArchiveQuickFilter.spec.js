/**
 * The Applications and Applications in use pages list the archive on request.
 *
 * OpenRegister leaves archived objects out of every list unless the request
 * asks for `_archived`; CnIndexPage spreads the active quick filter's map into
 * the list request as it is, so a quick filter `{ _archived: 'true' }` lists
 * the archive alone. The CMDB import archives applications that moved to the
 * sheet "Gearchiveerde Applicaties", and without this filter an admin could
 * not find them in stackiq any more.
 *
 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-archived-applications-and-usages-shall-be-listable-in-stackiq-and-shall-stay-out-of-opencatalogi-and-portaliq-by-default-req-cmdb-017
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'
import { describe, expect, it, vi } from 'vitest'
import { effectScope } from 'vue'
// resolveFilterTokens reads the current user at import time, which needs a
// browser; the tokens this spec merges (`_archived`, `_ids`) resolve no user.
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => null }))
// useSelfFetchList is the real function; only the list composable it feeds its
// `fixedFilters` getter to is replaced, so the test can read the options it got.
const captured = vi.hoisted(() => ({ options: null }))
vi.mock(
	'../../node_modules/@conduction/nextcloud-vue/src/composables/index.js',
	() => ({
		useListView: (objectType, options) => {
			captured.options = options
			return { refresh: () => {}, searchTerm: { value: '' } }
		},
	}),
)
vi.mock(
	'../../node_modules/@conduction/nextcloud-vue/src/composables/useObjectSubscription.js',
	() => ({
		useObjectSubscription: () => ({}),
	}),
)
vi.mock('../../node_modules/@conduction/nextcloud-vue/src/store/index.js', () => ({
	useObjectStore: () => ({ registerObjectType: () => {} }),
}))
import { useSelfFetchList } from '../../node_modules/@conduction/nextcloud-vue/src/components/CnIndexPage/useSelfFetchList.js'
// The REAL implementations from the installed package — not a local restatement.
import { buildQueryString } from '../../node_modules/@conduction/nextcloud-vue/src/utils/headers.js'
import { facetNarrowingFilter } from '../../src/utils/facetNarrowing.js'

const here = path.dirname(fileURLToPath(import.meta.url))
const repoRoot = path.resolve(here, '../..')

/**
 * Every page of the manifest and its fragments.
 *
 * @return {Array<object>} The pages
 */
function pages() {
	const files = [
		path.join(repoRoot, 'src/manifest.json'),
		...fs
			.readdirSync(path.join(repoRoot, 'src/manifest.d'))
			.filter((name) => name.endsWith('.json'))
			.map((name) => path.join(repoRoot, 'src/manifest.d', name)),
	]
	return files.flatMap(
		(file) => JSON.parse(fs.readFileSync(file, 'utf8')).pages ?? [],
	)
}

describe('the archive quick filter', () => {
	it('the Gebruik page lists the archive alone under "Archived"', () => {
		const page = pages().find((candidate) => candidate.id === 'Gebruik')
		const archived = (page?.config?.quickFilters ?? []).filter(
			(tab) => tab.label === 'Archived',
		)

		expect(archived).toHaveLength(1)
		expect(archived[0].filter).toEqual({ _archived: 'true' })
		expect(archived[0].default).not.toBe(true)
	})

	it('the Modules page lists the archive under "Archived" without the facet id set', () => {
		const page = pages().find((candidate) => candidate.id === 'Modules')
		const archived = (page?.config?.quickFilters ?? []).filter(
			(tab) => tab.label === 'Archived',
		)

		expect(archived).toHaveLength(1)
		expect(archived[0].filter).toEqual({ _archived: 'true', _ids: '' })
		expect(archived[0].default).not.toBe(true)
	})

	// The Applications page sends `_ids` (the working set a facet or search
	// matched) and merges the active tab's filter over it. OpenRegister leaves
	// archived objects out of that id set, so `_ids` AND `_archived=true` lists
	// nothing: with a facet selected, the Archived tab must not send `_ids`.
	// The merge is the library's own: useSelfFetchList hands useListView a
	// `fixedFilters` getter, which is called here exactly as a fetch calls it.
	it('sends no _ids with the Archived tab when a facet narrowed the list', () => {
		const page = pages().find((candidate) => candidate.id === 'Modules')
		const quickFilters = page.config.quickFilters
		const archivedIndex = quickFilters.findIndex(
			(tab) => tab.label === 'Archived',
		)
		const facetFilter = facetNarrowingFilter(['live-1', 'live-2'])

		/**
		 * The query string the library builds for the list with one tab active.
		 *
		 * @param {number|null} tabIndex The active quick-filter tab
		 * @return {string} The query string
		 */
		function queryWithTab(tabIndex) {
			const scope = effectScope()
			let fixedFilters
			captured.options = null
			scope.run(() => {
				const result = useSelfFetchList(
					{
						register: 'voorzieningen',
						schema: 'module',
						filter: facetFilter,
						quickFilters,
						columns: [],
					},
					null,
					() => null,
				)
				result.activeQuickFilterIndex.value = tabIndex
				fixedFilters = captured.options.fixedFilters
			})
			const query = buildQueryString(fixedFilters())
			scope.stop()
			return query
		}

		const archivedQuery = queryWithTab(archivedIndex)
		const otherQuery = queryWithTab(archivedIndex === 0 ? 1 : 0)

		expect(new URLSearchParams(archivedQuery).get('_archived')).toBe('true')
		expect(archivedQuery).not.toContain('_ids')
		// A tab without the override keeps the facet narrowing.
		expect(otherQuery).toContain('_ids')
	})

	it('the Modules page note names the Archived tab as the exception to the additive merge', () => {
		const page = pages().find((candidate) => candidate.id === 'Modules')

		expect(page._note).toContain('additive AND')
		expect(page._note).toMatch(/exception is the Archived tab/)
		expect(page._note).toMatch(
			/ignores the GEMMA facets; the search term still applies/,
		)
	})

	it('uses an icon the app registers', () => {
		const icons = fs.readFileSync(path.join(repoRoot, 'src/icons.js'), 'utf8')
		expect(icons).toMatch(/^\tArchiveOutline,$/m)
	})
})
