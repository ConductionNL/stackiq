# Test Plan: gemma-faceted-search

## Test Cases

### TC-1: Facet endpoint returns counts for all four GEMMA dimensions
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts`
- **type**: api
- **persona**: N/A
- **preconditions**: Module register seeded with modules linking to at least 2 referentiecomponenten, 1 standaard, 1 domein, 0 applicatieservice values.
- **steps**: `GET /apps/softwarecatalog/api/facets/module` with no filters.
- **expected result**: Response contains `referentiecomponent`, `standaard`, `applicatieservice`, `domein` keys; `applicatieservice` is an empty array (present, not omitted); counts match seeded data.
- **test command**: `/test-api`

### TC-2: Unsupported schema returns 400
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts`
- **type**: api
- **preconditions**: None.
- **steps**: `GET /apps/softwarecatalog/api/facets/contract`.
- **expected result**: HTTP 400, error message names supported schemas (`module`, `dienst`).
- **test command**: `/test-api`

### TC-3: Selecting a facet value narrows counts for other dimensions
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-counts-reflect-the-currently-filtered-set-not-the-unfiltered-universe`
- **type**: api
- **preconditions**: Seeded modules where a subset of referentiecomponent-A modules also carry standaard-B.
- **steps**: `GET /apps/softwarecatalog/api/facets/module?referentiecomponent[]=A`.
- **expected result**: `standaard` facet's count for B equals the narrowed subset count, not the full-register count.
- **test command**: `/test-api`

### TC-4: Multi-select within one dimension uses OR; across dimensions uses AND
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-counts-reflect-the-currently-filtered-set-not-the-unfiltered-universe`
- **type**: api
- **preconditions**: Seeded modules covering two distinct referentiecomponent values and one standaard value.
- **steps**: (a) `GET .../facets/module?referentiecomponent[]=A&referentiecomponent[]=B` (b) `GET .../facets/module?referentiecomponent[]=A&standaard[]=C`.
- **expected result**: (a) result set is the union of A and B; (b) result set is the intersection of A and C.
- **test command**: `/test-api`

### TC-5: Free-text search narrows facet counts
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facets-combine-with-free-text-search`
- **type**: api
- **preconditions**: Seeded modules where only some match the text query "zaak".
- **steps**: `GET /apps/softwarecatalog/api/facets/module?search=zaak`.
- **expected result**: Facet values that only occur on non-matching modules are absent or zero-count; counts reflect only matching modules.
- **test command**: `/test-api`

### TC-6: Every facet aggregation query sets an explicit `_limit`
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-queries-must-be-bounded`
- **type**: regression
- **preconditions**: `FacetService` implemented.
- **steps**: Code review / static check of every `searchObjects()`/`searchObjectsPaginated()` call site in `lib/Service/FacetService.php` (mirrors the audit method used in `bound-unbounded-searchobjects-scans`: grep the lines preceding each call for `_limit`).
- **expected result**: 100% of call sites set an explicit `_limit` or use `searchObjectsPaginated()`.
- **test command**: `/test-regression`

### TC-7: Facet aggregation performs acceptably on a realistic register size
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-queries-must-be-bounded`
- **type**: performance
- **preconditions**: Dev/test register seeded with several hundred module objects.
- **steps**: Request facet counts (cold cache) for the module listing.
- **expected result**: Response completes without timing out and without unbounded memory growth (compare against the `bound-unbounded-searchobjects-scans` baseline expectations).
- **test command**: `/test-performance`

### TC-8: Restricted user's facet counts never include out-of-scope objects
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-counts-must-respect-the-callers-rbactenant-context`
- **type**: security
- **preconditions**: Two users with different RBAC/tenant scopes; register contains objects visible only to one of them.
- **steps**: Each user requests `GET /apps/softwarecatalog/api/facets/module`.
- **expected result**: Facet values/counts for the restricted user never reflect objects outside their visible scope; matches what their own object-list query would show.
- **test command**: `/test-security`

### TC-9: Applying a facet updates the URL and reloading restores it
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-filter-state-is-url-encoded-and-deep-linkable`
- **type**: functional
- **persona**: Noor Yilmaz (Municipal CISO / Functional Admin) — browses the catalog to assess which modules implement a given standard.
- **preconditions**: Module index page loaded in browser.
- **steps**: Select a `referentiecomponent` facet value; copy the resulting URL; open it in a fresh tab.
- **expected result**: URL contains the facet selection as a query parameter; the fresh tab loads with the same facet pre-selected and the same filtered object list/counts.
- **test command**: `/test-functional`

### TC-10: Facet panel renders on module and dienst index pages alongside existing search/quickFilters
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-sidebar-ui-on-the-module-and-dienst-index-pages`
- **type**: functional
- **persona**: Mark Visser (MKB Software Vendor) — checks which GEMMA standards his own modules are catalogued under.
- **preconditions**: Module and dienst index pages exist with facet config applied.
- **steps**: Navigate to each index page.
- **expected result**: Facet panel visible with all four dimensions; existing free-text search box and any `quickFilters` still render and function.
- **test command**: `/test-functional`

### TC-11: Selecting a facet value updates the list without a full page reload
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-sidebar-ui-on-the-module-and-dienst-index-pages`
- **type**: functional
- **preconditions**: Facet panel visible.
- **steps**: Click a facet value checkbox.
- **expected result**: Object list re-fetches and updates in place; other dimensions' counts update; no full browser navigation/reload occurs (verify via network panel — single XHR, no document navigation).
- **test command**: `/test-functional`

### TC-12: Facet dimension with zero available values is visibly disabled
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-sidebar-ui-on-the-module-and-dienst-index-pages`
- **type**: accessibility
- **preconditions**: A filtered state exists where `applicatieservice` has zero matching values.
- **steps**: Inspect the `applicatieservice` facet section under that filter state.
- **expected result**: Section shows an empty/disabled state and is not focusable/selectable via keyboard or screen reader (no false affordance).
- **test command**: `/test-accessibility`

### TC-13: Saving current facet selection as a view, then reloading it
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view`
- **type**: functional
- **persona**: Mark Visser (MKB Software Vendor)
- **preconditions**: Facet selection active on module index page.
- **steps**: Click "Save as view", provide a name, confirm; navigate away; reopen the saved view.
- **expected result**: View created via existing `ViewService` save-view call; reopening restores the identical facet selection, object list, and URL state.
- **test command**: `/test-functional`

### TC-14: Repeated identical facet request is served from cache
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-results-are-cached`
- **type**: performance
- **preconditions**: No relevant data changes between requests.
- **steps**: Issue the same facet request twice within the cache TTL.
- **expected result**: Second request is measurably faster (served from cache); `_meta.cached` (or equivalent) reflects cache hit.
- **test command**: `/test-performance`

### TC-15: Cache invalidates when a module's GEMMA links change
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-results-are-cached`
- **type**: regression
- **preconditions**: Cached facet result exists.
- **steps**: Update a module's `referentieComponenten` field; re-request facets.
- **expected result**: Response reflects the updated link, not stale cached data.
- **test command**: `/test-regression`

### TC-16: Two RBAC-distinct users never share cached counts
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-results-are-cached`
- **type**: security
- **preconditions**: Two users with different RBAC/tenant scopes.
- **steps**: Both request facets for the same filter combination in quick succession.
- **expected result**: Each receives counts scoped to their own visibility; no cross-tenant cache bleed (combines with TC-8).
- **test command**: `/test-security`

### TC-17: Facet panel renders in Dutch and English
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-labels-and-ui-strings-are-translated`
- **type**: functional
- **persona**: Noor Yilmaz (Municipal CISO / Functional Admin) — Dutch-locale user.
- **preconditions**: Nextcloud locale set to `nl_NL` for one test run, `en_US` for another.
- **steps**: Load the module index page in each locale.
- **expected result**: Dimension labels and "Save as view" render in the active locale; no untranslated English strings leak into the `nl_NL` run.
- **test command**: `/test-functional`

### TC-18: Translation keys are English identifiers
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-labels-and-ui-strings-are-translated`
- **type**: regression
- **preconditions**: `l10n` translation source files for the new facet strings exist.
- **steps**: Inspect the translation key names added for this change.
- **expected result**: All new keys are English identifiers (e.g. `facetSaveAsView`, `facetDimensionReferentiecomponent`); Dutch/English values supplied per key.
- **test command**: `/test-regression`

## Coverage Summary
- Facet aggregation endpoint returns GEMMA dimension counts — covered (TC-1, TC-2)
- Facet counts reflect the currently filtered set — covered (TC-3, TC-4)
- Facets combine with free-text search — covered (TC-5)
- Facet aggregation queries MUST be bounded — covered (TC-6, TC-7)
- Facet counts MUST respect the caller's RBAC/tenant context — covered (TC-8, TC-16)
- Filter state is URL-encoded and deep-linkable — covered (TC-9)
- Facet sidebar UI on the module and dienst index pages — covered (TC-10, TC-11, TC-12)
- A facet selection can be saved as a view — covered (TC-13)
- Facet aggregation results are cached — covered (TC-14, TC-15, TC-16)
- Facet labels and UI strings are translated — covered (TC-17, TC-18)

## Out of Scope
- Free-text relevance ranking behavior — unchanged by this feature (out of scope per proposal); no new test cases beyond confirming existing search still functions alongside facets (TC-5, TC-10).
- Cross-app/federated facet search — deferred per proposal's Out of Scope; not tested here.
