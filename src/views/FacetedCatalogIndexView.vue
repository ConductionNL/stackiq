<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
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

Under the sidebar, each reference component value of the facet has an
information button whose popover shows its GEMMA definition and a link to
GEMMA Online, from the `documentation` and `gemmaUrl` the facet buckets carry
(the library sidebar lists the values in a select, which has no place for it).
@spec openspec/changes/archive/2026-11-24-gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-gemma-terms-show-their-definition-where-they-appear-req-gpl-003
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
			<NcButton
				v-if="mayFindDuplicates"
				variant="tertiary"
				:href="duplicateCandidatesHref"
				data-testid="find-duplicates">
				<template #icon>
					<ContentDuplicate :size="20" />
				</template>
				{{ t('stackiq', 'Find duplicates') }}
			</NcButton>
			<NcActions
				v-if="legacyImport && mayLegacyImport"
				:aria-label="t('stackiq', 'Actions')"
				data-testid="catalog-actions">
				<NcActionButton data-testid="legacy-import-open" @click="showLegacyImport = true">
					<template #icon>
						<DatabaseImportOutline :size="20" />
					</template>
					{{ legacyImportTitle }}
				</NcActionButton>
			</NcActions>
			<NcActions :aria-label="t('stackiq', 'Export')" :menuName="t('stackiq', 'Export')">
				<template #icon>
					<Download :size="20" />
				</template>
				<NcActionButton @click="exportList('csv')">
					<template #icon>
						<FileDelimitedOutline :size="20" />
					</template>
					{{ t('stackiq', 'Export as CSV') }}
				</NcActionButton>
				<NcActionButton @click="exportList('xlsx')">
					<template #icon>
						<FileExcelOutline :size="20" />
					</template>
					{{ t('stackiq', 'Export as Excel') }}
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
			<div class="faceted-catalog-index__aside">
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
				<section
					v-if="referenceComponentDefinitions.length"
					class="faceted-catalog-index__definitions"
					data-testid="gemma-definitions">
					<h3 class="faceted-catalog-index__definitions-title">
						{{ t('stackiq', 'What the reference components are') }}
					</h3>
					<ul class="faceted-catalog-index__definitions-list">
						<li
							v-for="value in referenceComponentDefinitions"
							:key="value.value">
							<span>{{ value.label || value.value }}</span>
							<GemmaDefinitionPopover
								:name="value.label || value.value"
								:documentation="value.documentation || ''"
								:gemmaUrl="value.gemmaUrl || ''" />
						</li>
					</ul>
				</section>
			</div>

			<div class="faceted-catalog-index__list">
				<CnIndexPage
					:key="indexPageKey"
					ref="indexPage"
					:title="title"
					:register="register"
					:schema="schema"
					:columns="columns"
					:filter="listFilter"
					:quickFilters="quickFilters"
					:quickFilterMode="quickFilterMode"
					:quickFilterMultiple="quickFilterMultiple"
					:sidebar="sidebar"
					:bulkActions="bulkActions"
					:copy="copy"
					:rowClickToView="Boolean(detailRoute)"
					:showAdd="addWizard === ''"
					@search="onListSearch"
					@rowClick="openDetail"
					@view="openDetail"
					@quickFilterChange="onQuickFilterChange">
					<!-- Publish, Withdraw and Lock next to the built-in Delete in
					     the selection strip (REQ-ODP-010, board SkApplicaties). -->
					<template #selection-actions="{ selectedIds }">
						<NcButton
							variant="secondary"
							data-testid="selection-publish"
							@click="openSelectionDialog('massPublishObjects', selectedIds)">
							<template #icon>
								<Publish :size="20" />
							</template>
							{{ t('stackiq', 'Publish') }}
						</NcButton>
						<NcButton
							variant="secondary"
							data-testid="selection-withdraw"
							@click="openSelectionDialog('massDepublishObjects', selectedIds)">
							<template #icon>
								<PublishOff :size="20" />
							</template>
							{{ t('stackiq', 'Withdraw') }}
						</NcButton>
						<NcButton
							variant="secondary"
							data-testid="selection-lock"
							@click="openSelectionDialog('massLockObjects', selectedIds)">
							<template #icon>
								<LockOutline :size="20" />
							</template>
							{{ t('stackiq', 'Lock') }}
						</NcButton>
					</template>
					<template v-if="addWizard !== ''" #header-actions>
						<EntryWizardAction :kind="addWizard" />
					</template>
				</CnIndexPage>
			</div>
		</div>

		<LegacyCatalogueImportModal
			v-if="legacyImport"
			:show="showLegacyImport"
			@imported="refetch"
			@close="showLegacyImport = false" />
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
import { generateUrl } from '@nextcloud/router'
import { NcActionButton, NcActionCaption, NcActions, NcButton } from '@nextcloud/vue'
import { defineAsyncComponent } from 'vue'
import ContentDuplicate from 'vue-material-design-icons/ContentDuplicate.vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import DatabaseImportOutline from 'vue-material-design-icons/DatabaseImportOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'
import FileDelimitedOutline from 'vue-material-design-icons/FileDelimitedOutline.vue'
import FileExcelOutline from 'vue-material-design-icons/FileExcelOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import FolderStarOutline from 'vue-material-design-icons/FolderStarOutline.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import GemmaDefinitionPopover from '../components/gemma/GemmaDefinitionPopover.vue'
import LegacyCatalogueImportModal from '../modals/import/LegacyCatalogueImportModal.vue'
import SaveFacetViewModal from '../modals/SaveFacetViewModal.vue'
import {
	selectedEntries,
	setPublicationTarget,
} from '../services/publicationActions.js'
import { useFacetStore } from '../store/modules/facets.js'
import { rowDetailLocation } from '../utils/applicationContracts.js'
import { catalogExportUrl } from '../utils/catalogExportUrl.js'
import { listNarrowingFilter } from '../utils/facetNarrowing.js'
import { buildFacetDimensionSchema } from '../utils/facetSchema.js'
import { legacyImportTitle, legacyImportUrls } from '../utils/legacyImport.js'
import { duplicateCandidatesUrl } from '../utils/mergedRecord.js'

/** Dimension key -> translated label, matching `FacetController`'s query params. */
const DIMENSION_LABELS = {
	referenceComponent: () => t('stackiq', 'Reference component'),
	standard: () => t('stackiq', 'Standard'),
	applicationService: () => t('stackiq', 'Application service'),
	domain: () => t('stackiq', 'Domain'),
	supplier: () => t('stackiq', 'Supplier'),
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
		NcButton,
		ContentDuplicate,
		CnFacetSidebar,
		CnIndexPage,
		GemmaDefinitionPopover,
		// Loaded when a page asks for it: the wizard reads the object store.
		EntryWizardAction: defineAsyncComponent(() => import('../components/entryWizard/EntryWizardAction.vue')),
		LegacyCatalogueImportModal,
		SaveFacetViewModal,
		ContentSaveOutline,
		DatabaseImportOutline,
		Download,
		FileDelimitedOutline,
		FileExcelOutline,
		FolderOutline,
		FolderStarOutline,
		NcButton,
		Publish,
		PublishOff,
		LockOutline,
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

		/**
		 * The guided entry wizard the page's Add opens (manifest `config.addWizard`),
		 * a kind of src/utils/entryWizard.js such as `module`. Empty keeps the list's own Add.
		 *
		 * @spec openspec/specs/guided-entry-wizard/spec.md#requirement-req-gew-001-applications-services-and-connections-are-added-through-a-step-by-step-wizard
		 */
		addWizard: {
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

		/**
		 * Whether the page offers "Bring over from the previous Softwarecatalogus"
		 * in its actions menu (manifest `config.legacyImport`, the Applications
		 * page). The entry shows only when `/api/legacy-import/access` allows it.
		 *
		 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-4
		 */
		legacyImport: {
			type: Boolean,
			default: false,
		},

		/** Table column definitions, forwarded to `CnIndexPage` (manifest `config.columns`). */
		columns: {
			type: Array,
			default: () => [],
		},

		/**
		 * Selection-strip actions, forwarded to `CnIndexPage` (manifest
		 * `config.bulkActions`), e.g. Move to organisation.
		 */
		bulkActions: {
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

		/**
		 * Copy settings, forwarded to `CnIndexPage` (manifest `config.copy`):
		 * `include` lists the links a copy may take along, which the single and
		 * mass copy dialogs then offer, ticked; `overrides` holds the field
		 * values a copy starts with (the publication dates cleared, so a copy is
		 * never published with its source).
		 *
		 * @spec openspec/changes/archive/2026-11-24-applications-copy-with-links/specs/application-copy/spec.md#requirement-an-application-is-copied-with-the-links-the-user-picks-req-acp-001
		 */
		copy: {
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
			mayFindDuplicates: false,
			mayLegacyImport: false,
			showLegacyImport: false,
			legacyImportTitle: legacyImportTitle(),
			duplicateCandidatesHref: duplicateCandidatesUrl(generateUrl),
		}
	},

	computed: {
		/**
		 * The reference component values of the facet, in facet order, each
		 * with the GEMMA definition and link its bucket carries.
		 *
		 * @return {Array<object>} The facet values.
		 * @spec openspec/changes/archive/2026-11-24-gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-gemma-terms-show-their-definition-where-they-appear-req-gpl-003
		 */
		referenceComponentDefinitions() {
			return (
				this.facetStore.facetDataFor(this.schema)?.referenceComponent?.values || []
			).filter((value) => value && value.value)
		},

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
		 * @spec openspec/changes/archive/2026-11-24-cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-archived-applications-and-usages-shall-be-listable-in-stackiq-and-shall-stay-out-of-opencatalogi-and-portaliq-by-default-req-cmdb-017
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
		 * @spec openspec/changes/archive/2026-11-24-cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-archived-applications-and-usages-shall-be-listable-in-stackiq-and-shall-stay-out-of-opencatalogi-and-portaliq-by-default-req-cmdb-017
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
		this.loadMayFindDuplicates()
		this.loadMayLegacyImport()
	},

	beforeUnmount() {
		clearTimeout(this.searchDebounceTimer)
	},

	methods: {
		/**
		 * Open a mass dialog (publish, withdraw or lock) for the rows selected
		 * in the list: the dialog acts on those objects, and publish and
		 * withdraw go through the publication endpoint for this list's type.
		 *
		 * @param {string} dialog The dialog key (`massPublishObjects`, ...).
		 * @param {Array<string>} selectedIds The selected row ids.
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-24-publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-selected-entries-are-published-withdrawn-or-locked-together-from-the-list-req-odp-010
		 */
		async openSelectionDialog(dialog, selectedIds) {
			// The app's object and navigation stores, loaded on first use so
			// the list itself does not pull them in.
			const { navigationStore, objectStore } = await import('../store/store.js')
			const page = this.$refs.indexPage
			const loaded = (page && (page.selectedObjects || page.effectiveObjects)) || []
			setPublicationTarget({ objectType: this.schema })
			objectStore.setSelectedObjects(selectedEntries(selectedIds, loaded))
			navigationStore.setDialog(dialog)
			// Reload the list once the dialog closes, so published, withdrawn
			// or locked entries show their new state.
			const stop = this.$watch(
				() => navigationStore.dialog,
				(open) => {
					if (!open) {
						stop()
						this.listRemount++
					}
				},
			)
		},

		/**
		 * Whether the user may bring over an export from the previous
		 * Softwarecatalogus; asked only on a page that offers it. A failed
		 * lookup hides the entry; the endpoints check the right themselves.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-4
		 */
		async loadMayLegacyImport() {
			if (!this.legacyImport) {
				return
			}
			try {
				const response = await fetch(legacyImportUrls().access, {
					headers: { requesttoken: globalThis.OC?.requestToken ?? '' },
				})
				const data = response.ok ? await response.json() : null
				this.mayLegacyImport = data?.mayImport === true
			} catch {
				this.mayLegacyImport = false
			}
		},

		/**
		 * Whether the user may open OpenRegister's duplicate candidates: a
		 * Nextcloud admin or a functional administrator (`/api/me`). Anyone
		 * else, or a failed lookup, does not see Find duplicates; the
		 * candidates page checks access itself.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-002-the-catalogue-pages-shall-lead-an-administrator-to-openregisters-duplicate-candidates
		 */
		async loadMayFindDuplicates() {
			try {
				const response = await fetch(generateUrl('/apps/stackiq/api/me'), {
					headers: { requesttoken: globalThis.OC?.requestToken ?? '' },
				})
				const data = response.ok ? await response.json() : null
				this.mayFindDuplicates = data?.mayFindDuplicates === true
			} catch {
				this.mayFindDuplicates = false
			}
		},

		/**
		 * Remember which quick-filter tab is active (single-select mode emits the
		 * tab index; multi-select emits an array, which never selects the
		 * Archived tab here).
		 *
		 * @param {number|number[]} index The tab index, or the selected indices
		 * @return {void}
		 * @spec openspec/changes/archive/2026-11-24-cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-archived-applications-and-usages-shall-be-listable-in-stackiq-and-shall-stay-out-of-opencatalogi-and-portaliq-by-default-req-cmdb-017
		 */
		/**
		 * Download the list as filtered: the GEMMA facets, the search and the
		 * active quick filter go to stackiq's export, in the page's columns.
		 *
		 * @param {string} format `csv` or `xlsx`.
		 * @return {void}
		 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-002-the-applications-and-services-pages-shall-export-the-rows-the-facets-the-quick-filter-and-the-search-leave
		 */
		exportList(format) {
			const tab = (this.quickFilters ?? [])[this.activeQuickFilterIndex]
			window.location.assign(catalogExportUrl({
				schema: this.schema,
				activeFilters: this.facetStore[this.schema].activeFilters,
				search: this.facetStore[this.schema].search,
				quickFilter: tab?.filter ?? null,
				columns: this.columns,
				format,
			}, generateUrl))
		},

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

.faceted-catalog-index__aside {
	flex-shrink: 0;
	display: flex;
	flex-direction: column;
}

.faceted-catalog-index__definitions {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	border-right: 1px solid var(--color-border);
}

.faceted-catalog-index__definitions-title {
	margin: 0 0 calc(var(--default-grid-baseline, 4px) * 2);
	font-size: 1rem;
}

.faceted-catalog-index__definitions-list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.faceted-catalog-index__definitions-list li {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline, 4px);
}

.faceted-catalog-index__list {
	flex: 1;
	min-width: 0;
}
</style>
