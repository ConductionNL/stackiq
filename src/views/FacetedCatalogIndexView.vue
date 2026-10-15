<!--
FacetedCatalogIndexView.vue

GEMMA-dimension faceted index page for the `module` (Applications) and
`dienst` (Services) listings (gemma-faceted-search). Reusable for both
schemas via the `schema` prop — the manifest wires one page per schema.

Layout: a `CnFacetSidebar` (from `@conduction/nextcloud-vue`, ADR-012) driven
by the `facets` Pinia store on the left, and a self-fetching `CnIndexPage`
(register+schema self-fetch mode) on the right.

The free-text search and the column choice are `CnIndexPage`'s own Search
and Columns sidebar (manifest `config.sidebar`): the list sends the term as
`_search` and keeps it in the route, and `onListSearch` hands it to the facet
counts.

Why the GEMMA facets do NOT go through that sidebar's
`activeFilters` facet machinery: that machinery treats
every active-filter key as a LITERAL, directly-filterable schema field and
applies it straight to the object-list fetch (`useListView`'s
`onFilterChange` → `activeFilters.value[key] = values`, merged verbatim into
the OpenRegister query). Two of the four GEMMA dimensions (`domain`,
`applicationService`) are not module/dienst fields at all — they only exist
on the LINKED `element` object — and the other two are exposed here by
display NAME, not the `referentieComponenten`/`standaardVersies` identifiers
the schema actually stores. Feeding GEMMA facet keys through that path would
apply an incorrect direct-field filter (near-guaranteed empty results)
alongside this feature's own narrowing. This view instead narrows the
self-fetch list via the bounded, RBAC-scoped `{ _ids: matchedObjectIds }` list
`FacetService` already computes (`_meta.matchedObjectIds` — see
`lib/Service/FacetService.php::computeFacetsForRequest()`), and keeps GEMMA
facet state in the URL under `_gf_`-prefixed query keys so CnIndexPage's own
generic route-query-to-filter passthrough never sees it (see the
`ROUTE_QUERY_PREFIX` docblock in `src/store/modules/facets.js`).

@spec openspec/changes/gemma-faceted-search/tasks.md#task-11
@spec openspec/changes/gemma-faceted-search/tasks.md#task-12
@spec openspec/changes/gemma-faceted-search/tasks.md#task-13
@spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-sidebar-ui-on-the-module-and-dienst-index-pages
@spec openspec/specs/gemma-faceted-search/spec.md#requirement-filter-state-is-url-encoded-and-deep-linkable
@spec openspec/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view
@spec openspec/specs/bio-compliance-assessment/spec.md#requirement-catalog-can-be-filtered-by-bbn-level-and-dpia-status
-->

<template>
	<div class="faceted-catalog-index">
		<div class="faceted-catalog-index__header">
			<h2 class="faceted-catalog-index__title">
				{{ title }}
			</h2>
			<p v-if="description" class="faceted-catalog-index__description">
				{{ description }}
			</p>
		</div>

		<div class="faceted-catalog-index__toolbar">
			<NcActions :aria-label="t('stackiq', 'Saved views')">
				<template #icon>
					<FolderStarOutline :size="20" />
				</template>
				<NcActionButton
					:disabled="!facetStore.hasActiveFilterOrSearchFor(schema)"
					@click="showSaveViewModal = true">
					<template #icon>
						<ContentSaveOutline :size="20" />
					</template>
					{{ t('stackiq', 'Save current filters as view') }}
				</NcActionButton>
				<NcActionCaption
					v-if="facetStore[schema].savedViews.length > 0"
					:name="t('stackiq', 'Saved views')" />
				<NcActionButton
					v-for="view in facetStore[schema].savedViews"
					:key="view.id"
					@click="applySavedView(view)">
					<template #icon>
						<FolderOutline :size="20" />
					</template>
					{{ view.name }}
				</NcActionButton>
			</NcActions>
		</div>

		<p
			v-if="archivedTabIgnoresFilters"
			class="faceted-catalog-index__archive-note"
			role="note">
			{{
				t(
					'stackiq',
					'This tab lists the whole archive. It ignores the GEMMA filters.',
				)
			}}
		</p>

		<div class="faceted-catalog-index__body">
			<CnFacetSidebar
				:title="t('stackiq', 'GEMMA filters')"
				:schema="facetDimensionSchema"
				:facetData="facetStore.facetDataFor(schema)"
				:activeFilters="facetStore[schema].activeFilters"
				:loading="facetStore[schema].loading"
				:clearLabel="t('stackiq', 'Clear all')"
				class="faceted-catalog-index__sidebar"
				@filterChange="onFacetFilterChange"
				@clearAll="onClearAllFacets" />

			<div class="faceted-catalog-index__list">
				<CnIndexPage
					:key="indexPageKey"
					:title="title"
					:register="register"
					:schema="schema"
					:columns="columns"
					:filter="listFilter"
					:quickFilters="quickFilters"
					:quickFilterMode="quickFilterMode"
					:quickFilterMultiple="quickFilterMultiple"
					:sidebar="sidebar"
					:rowClickToView="Boolean(detailRoute)"
					@search="onListSearch"
					@rowClick="openDetail"
					@view="openDetail"
					@quickFilterChange="onQuickFilterChange" />
			</div>
		</div>

		<SaveFacetViewModal
			:show="showSaveViewModal"
			:saving="savingView"
			@close="showSaveViewModal = false"
			@save="onSaveView" />
	</div>
</template>

<script>
import { CnFacetSidebar, CnIndexPage } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcActionButton, NcActionCaption, NcActions } from '@nextcloud/vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import FolderStarOutline from 'vue-material-design-icons/FolderStarOutline.vue'
import SaveFacetViewModal from '../modals/SaveFacetViewModal.vue'
import { useFacetStore } from '../store/modules/facets.js'
import { rowDetailLocation } from '../utils/applicationContracts.js'
import { listNarrowingFilter } from '../utils/facetNarrowing.js'
import { buildFacetDimensionSchema } from '../utils/facetSchema.js'

/** Dimension key -> translated label, matching `FacetController`'s query params. */
const DIMENSION_LABELS = {
	referenceComponent: () => t('stackiq', 'Reference component'),
	standard: () => t('stackiq', 'Standard'),
	applicationService: () => t('stackiq', 'Application service'),
	domain: () => t('stackiq', 'Domain'),
}

/**
 * The tab CnIndexPage starts on: the first quick filter marked `default`,
 * else the first one (the library's own rule in `useSelfFetchList.js`).
 *
 * @param {Array<object>|null} quickFilters The quick filters
 * @return {number|null} The index, or null without quick filters
 */
function initialQuickFilterIndex(quickFilters) {
	if (!Array.isArray(quickFilters) || quickFilters.length === 0) {
		return null
	}
	const index = quickFilters.findIndex((tab) => tab && tab.default === true)
	return index >= 0 ? index : 0
}

/** Debounce delay (ms) between search keystrokes and the facets refetch. */
const SEARCH_DEBOUNCE_MS = 400

/**
 * The list's search term in a route query (`CnIndexPage` keeps it in `_search`).
 *
 * @param {object} query `$route.query`.
 * @return {string} The term, or ''.
 */
function routeSearch(query) {
	return typeof query?._search === 'string' ? query._search : ''
}

export default {
	name: 'FacetedCatalogIndexView',

	components: {
		NcActions,
		NcActionButton,
		NcActionCaption,
		CnFacetSidebar,
		CnIndexPage,
		SaveFacetViewModal,
		ContentSaveOutline,
		FolderOutline,
		FolderStarOutline,
	},

	props: {
		/**
		 * Named route of the page one row opens, for example `ModuleDetail`.
		 * Empty keeps rows where they are (the Services list has no detail page).
		 */
		detailRoute: {
			type: String,
			default: '',
		},

		/** `module` or `dienst` — which GEMMA-faceted listing this page renders. */
		schema: {
			type: String,
			required: true,
			validator: (value) => ['module', 'catalogService'].includes(value),
		},

		/** OpenRegister register id/slug (resolved from the manifest's `@resolve:voorzieningen_register`). */
		register: {
			type: [String, Number],
			required: true,
		},

		/** Page title (manifest `pages[].title`). */
		title: {
			type: String,
			default: '',
		},

		/** Optional page description. */
		description: {
			type: String,
			default: '',
		},

		/** Table column definitions, forwarded to `CnIndexPage` (manifest `config.columns`). */
		columns: {
			type: Array,
			default: () => [],
		},

		/**
		 * Clickable filter-tab strip, forwarded to `CnIndexPage` (manifest
		 * `config.quickFilters`) — e.g. the BIO BBN-level / DPIA-status quick
		 * filters on the Modules page (bio-compliance-assessment). Composes
		 * with the GEMMA facet narrowing below: `CnIndexPage`'s self-fetch
		 * merges `{ ...base(filter prop), ...activeTab.filter }` (see
		 * `useSelfFetchList.js#fixedFilters`), and this view's own `filter`
		 * prop only ever carries the single `_ids` key
		 * (`{ _ids: matchedObjectIds }`, see `listFilter` below). A quick
		 * filter's keys (`bbnLevel`, `dpiaStatus`, …) never collide with
		 * `_ids`, so the merge is additive — both the facet-matched id set AND
		 * the active quick filter's field constraints apply together
		 * (logical AND), never clobbering one another. The one exception is
		 * the Archived tab of the Applications page, below.
		 *
		 * The exception is the Archived tab of the Applications page
		 * (cmdb-import-archive-reconciliation): the facet id set is built from
		 * the working set, which OpenRegister's default lens limits to objects
		 * that are not archived, so ANDing `_ids` with `_archived=true` could
		 * never match an archived application. That tab's filter therefore
		 * carries an empty-string `_ids` (not an empty array, which the filter
		 * resolver drops before the merge), which wins over this view's `_ids`
		 * and which the request leaves out: the tab lists the whole archive
		 * and ignores the GEMMA selection. The search term still applies,
		 * because the list sends it as `_search`, outside the filter. The tab's
		 * filter, not this view, owns that, so it holds however the page's
		 * tab state is restored.
		 */
		quickFilters: {
			type: Array,
			default: null,
		},

		/** Quick-filter render mode, forwarded to `CnIndexPage` (manifest `config.quickFilterMode`). */
		quickFilterMode: {
			type: String,
			default: 'chips',
		},

		/** Allow several quick filters active at once, forwarded to `CnIndexPage` (manifest `config.quickFilterMultiple`). */
		quickFilterMultiple: {
			type: Boolean,
			default: false,
		},

		/**
		 * Search and Columns sidebar, forwarded to `CnIndexPage` (manifest
		 * `config.sidebar`), as on the other index pages. Its search is the
		 * page's free-text search: the list sends it as `_search`, and
		 * `onListSearch` hands it to the facet counts.
		 */
		sidebar: {
			type: Object,
			default: null,
		},
	},

	/**
	 * Expose the facet Pinia store to the options API.
	 *
	 * @return {{facetStore: object}} The store bindings.
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-sidebar-ui-on-the-module-and-dienst-index-pages
	 */
	setup() {
		const facetStore = useFacetStore()
		return { facetStore }
	},

	data() {
		return {
			showSaveViewModal: false,
			savingView: false,
			searchDebounceTimer: null,
			activeQuickFilterIndex: initialQuickFilterIndex(this.quickFilters),
			listRemount: 0,
		}
	},

	computed: {
		/**
		 * `CnFacetSidebar`'s `schema` prop — a schema-shaped document whose
		 * facetable properties are the four GEMMA dimensions.
		 *
		 * ⚠️ This USED to be a `filters` prop carrying the already-derived
		 * filter list. `CnFacetSidebar` declares no `filters` prop: its props
		 * are `schema`, `facetData`, `activeFilters`, `loading`, `title`,
		 * `clearLabel`, `userIsAdmin`, and it derives its filter list itself
		 * via `effectiveFilters() => filtersFromSchema(this.schema)`. Vue drops
		 * an undeclared prop into `$attrs` silently, so the four dimensions
		 * were passed, discarded, and `filtersFromSchema(null)` returned `[]` —
		 * the sidebar rendered its title and an empty body, and no console
		 * error was logged. Verified against the shipped
		 * `@conduction/nextcloud-vue` dist, not only its `src/`.
		 *
		 * `filtersFromSchema` keeps only properties with `facetable: true`,
		 * orders them by `order`, labels them from `title`, and (absent an
		 * `enum`) makes each a `select` whose options come from live
		 * `facetData` — which is exactly what this feature supplies.
		 *
		 * @return {object} A schema document with the four facetable dimensions.
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-sidebar-ui-on-the-module-and-dienst-index-pages
		 */
		facetDimensionSchema() {
			return buildFacetDimensionSchema(DIMENSION_LABELS)
		},

		/**
		 * Bounded `{ _ids: [...] }` narrowing filter for `CnIndexPage`'s
		 * self-fetch object list, sourced from the last facets response's
		 * `_meta.matchedObjectIds`, while a GEMMA facet has a value. A search
		 * alone does not narrow by id: the list searches itself (`_search`).
		 * `listNarrowingFilter` says why.
		 *
		 * @return {object} The `CnIndexPage` `filter` prop value.
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-counts-reflect-the-currently-filtered-set-not-the-unfiltered-universe
		 */
		listFilter() {
			return listNarrowingFilter(
				this.facetStore[this.schema].activeFilters,
				this.facetStore.matchedObjectIdsFor(this.schema),
			)
		},

		/**
		 * Whether the active quick-filter tab drops the facet id set: a tab
		 * whose filter carries the empty-string `_ids` override (the Archived
		 * tab, see the `quickFilters` prop). The facet sidebar stays on
		 * screen, so the page says so. The search term still applies: the
		 * list sends it as `_search`, outside the filter.
		 *
		 * @return {boolean} True while such a tab is active.
		 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-archived-applications-and-usages-shall-be-listable-in-stackiq-and-shall-stay-out-of-opencatalogi-and-portaliq-by-default-req-cmdb-017
		 */
		archivedTabIgnoresFilters() {
			const tab = (this.quickFilters ?? [])[this.activeQuickFilterIndex]
			return tab?.filter?._ids === ''
		},

		/**
		 * Remount key for `CnIndexPage`. A change of the `filter` prop needs
		 * no remount: the self-fetch list watches it and refetches (2.65.0 and
		 * later), so the sidebar search keeps its focus while the facet counts
		 * follow the term. The list reads its search term from the route only
		 * when it mounts, so a saved view or a legacy `_gf_search` link that
		 * puts a term in the route bumps `listRemount`.
		 *
		 * @return {string} A key that changes when `listRemount` does.
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-sidebar-ui-on-the-module-and-dienst-index-pages
		 */
		indexPageKey() {
			return `${this.schema}-${this.listRemount}`
		},
	},

	watch: {
		/**
		 * CnIndexPage is remounted when the key changes and starts on its
		 * default tab again, so the tab this view remembers goes back to it.
		 *
		 * @return {void}
		 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-archived-applications-and-usages-shall-be-listable-in-stackiq-and-shall-stay-out-of-opencatalogi-and-portaliq-by-default-req-cmdb-017
		 */
		indexPageKey() {
			this.activeQuickFilterIndex = initialQuickFilterIndex(this.quickFilters)
		},
	},

	/**
	 * Restore the facet selection and the search term from the route.
	 *
	 * The list restores its search from `_search`; the facet counts follow
	 * the same term. A link from before the sidebar search carries the term
	 * as `_gf_search`: it moves to `_search` and the list remounts to read it.
	 *
	 * @return {void}
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-filter-state-is-url-encoded-and-deep-linkable
	 */
	created() {
		const query = this.$route.query
		this.facetStore.setFiltersFromQuery(this.schema, query)
		const legacy =
			routeSearch(query) === '' && typeof query._gf_search === 'string'
				? query._gf_search
				: ''
		this.facetStore.setSearch(this.schema, legacy || routeSearch(query))
		if (legacy !== '') {
			const next = { ...query, _search: legacy }
			delete next._gf_search
			this.$router
				.replace({ query: next })
				.catch(() => {
					// NavigationDuplicated — same query, no-op.
				})
				.then(() => {
					this.listRemount++
				})
		}
	},

	/**
	 * Fetch the initial facet counts and this schema's saved views.
	 *
	 * @return {void}
	 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view
	 */
	mounted() {
		this.refetch()
		this.facetStore.fetchSavedViews(this.schema)
	},

	beforeUnmount() {
		clearTimeout(this.searchDebounceTimer)
	},

	methods: {
		/**
		 * Remember which quick-filter tab is active (single-select mode emits the
		 * tab index; multi-select emits an array, which never selects the
		 * Archived tab here).
		 *
		 * @param {number|number[]} index The tab index, or the selected indices
		 * @return {void}
		 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-archived-applications-and-usages-shall-be-listable-in-stackiq-and-shall-stay-out-of-opencatalogi-and-portaliq-by-default-req-cmdb-017
		 */
		onQuickFilterChange(index) {
			this.activeQuickFilterIndex = Number.isInteger(index) ? index : null
		},

		/**
		 * Open the detail page of a clicked row, or of the row whose View action was used.
		 *
		 * @param {object} row The row
		 * @return {void}
		 * @spec openspec/specs/application-page/spec.md#requirement-req-apg-004-the-applications-list-opens-the-application-page
		 */
		openDetail(row) {
			const location = rowDetailLocation(this.detailRoute, row)
			if (location) {
				this.$router.push(location)
			}
		},

		/**
		 * Re-fetch this schema's facet counts (search + active filters
		 * already live in the store).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facet-counts-reflect-the-currently-filtered-set-not-the-unfiltered-universe
		 */
		async refetch() {
			await this.facetStore.fetchFacets(this.schema)
		},

		/**
		 * Push the current filter+search state to the URL as `_gf_`-prefixed
		 * query params, preserving any other query params already present.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-filter-state-is-url-encoded-and-deep-linkable
		 */
		syncUrl() {
			const currentQuery = { ...this.$route.query }
			Object.keys(currentQuery).forEach((key) => {
				if (key.startsWith('_gf_')) {
					delete currentQuery[key]
				}
			})

			// The search term lives in the list's own `_search`, not in `_gf_search`.
			const facetQuery = this.facetStore.filtersToQuery(this.schema)
			delete facetQuery._gf_search

			const nextQuery = {
				...currentQuery,
				...facetQuery,
			}
			return this.$router.replace({ query: nextQuery }).catch(() => {
				// NavigationDuplicated — same query, no-op.
			})
		},

		/**
		 * `CnFacetSidebar`'s `filter-change` handler: `{ key, values }`.
		 *
		 * @param {{key: string, values: Array}} payload The filter change.
		 * @return {void}
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-filter-state-is-url-encoded-and-deep-linkable
		 */
		onFacetFilterChange({ key, values }) {
			this.facetStore.setFilter(this.schema, key, values)
			this.syncUrl()
			this.refetch()
		},

		/**
		 * Clear every active facet filter for this schema and refetch.
		 *
		 * @return {void}
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-filter-state-is-url-encoded-and-deep-linkable
		 */
		onClearAllFacets() {
			this.facetStore.clearFilters(this.schema)
			this.syncUrl()
			this.refetch()
		},

		/**
		 * The list's search changed (sidebar Search tab): the list has
		 * already searched itself; the facet counts follow, debounced.
		 *
		 * @param {string} value The new search term.
		 * @return {void}
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-facets-combine-with-free-text-search
		 */
		onListSearch(value) {
			this.facetStore.setSearch(this.schema, value)
			clearTimeout(this.searchDebounceTimer)
			this.searchDebounceTimer = setTimeout(
				() => {
					this.refetch()
				},
				value === '' ? 0 : SEARCH_DEBOUNCE_MS,
			)
		},

		/**
		 * Restore a saved view's facet selection + search term.
		 *
		 * @param {object} view The saved view.
		 * @return {void}
		 *
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view
		 */
		async applySavedView(view) {
			this.facetStore.applyView(this.schema, view)
			const search = this.facetStore[this.schema].search.trim()
			const query = { ...this.$route.query }
			if (search === '') {
				delete query._search
			} else {
				query._search = search
			}
			await this.$router.replace({ query }).catch(() => {
				// NavigationDuplicated — same query, no-op.
			})
			await this.syncUrl()
			// Remount the list so it reads the view's search from the route.
			this.listRemount++
			this.refetch()
		},

		/**
		 * Persist the current facet selection + search term as a named view.
		 *
		 * @param {string} name The view name.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view
		 */
		async onSaveView(name) {
			this.savingView = true
			try {
				await this.facetStore.saveCurrentAsView(this.schema, name)
				this.showSaveViewModal = false
				showSuccess(t('stackiq', 'View "{name}" saved', { name }))
			} catch (error) {
				showError(
					t('stackiq', 'Failed to save view: {message}', {
						message: error.message ?? '',
					}),
				)
			} finally {
				this.savingView = false
			}
		},
	},
}
</script>

<style scoped>
.faceted-catalog-index__header {
	padding: calc(var(--default-grid-baseline, 8px) * 2)
		calc(var(--default-grid-baseline, 8px) * 2) 0;
}

.faceted-catalog-index__title {
	margin: 0 0 var(--default-grid-baseline, 8px);
}

.faceted-catalog-index__description {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.faceted-catalog-index__toolbar {
	display: flex;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.faceted-catalog-index__archive-note {
	color: var(--color-text-maxcontrast);
	margin: 0;
	padding: 0 calc(var(--default-grid-baseline, 8px) * 2)
		calc(var(--default-grid-baseline, 8px) * 2);
}

.faceted-catalog-index__body {
	display: flex;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
	padding: 0 calc(var(--default-grid-baseline, 8px) * 2)
		calc(var(--default-grid-baseline, 8px) * 2);
}

.faceted-catalog-index__sidebar {
	flex-shrink: 0;
}

.faceted-catalog-index__list {
	flex: 1;
	min-width: 0;
}
</style>
