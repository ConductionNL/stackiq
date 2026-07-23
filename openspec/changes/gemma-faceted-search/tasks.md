# Tasks: gemma-faceted-search

## Implementation Tasks

### Task 1: `FacetService` — bounded aggregation over direct module GEMMA fields
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts`
- **files**: `lib/Service/FacetService.php`
- **acceptance_criteria**:
  - GIVEN the `module` schema WHEN `FacetService::getFacets('module', [])` is called THEN it returns `referentiecomponent` and `standaard` facet arrays with `{value, label, count}` entries derived from `referentieComponenten`/`standaarden`/`standaardenGemma`
  - GIVEN a dimension with no matching values THEN it is returned as an empty array, not omitted
  - Every `ObjectService::searchObjects()` call in this task sets an explicit `_limit` (per `bound-unbounded-searchobjects-scans`) or uses `searchObjectsPaginated()`
- [ ] Implement
- [ ] Test

### Task 2: `FacetService` — resolve `domein`/`applicatieservice` via linked `element` lookups
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts`
- **files**: `lib/Service/FacetService.php`
- **acceptance_criteria**:
  - GIVEN modules linking to `element` objects with `domein` set THEN the `domein` facet reflects those values with correct counts
  - GIVEN modules linking to `element` objects where `gemmaType === 'Applicatieservice'` THEN the `applicatieservice` facet reflects those values with correct counts
  - Element-resolution reuses `ViewService`/`ArchiMateService`'s existing relationship-resolution helper rather than duplicating lookup logic (extract a shared helper if none is directly reusable)
  - Every lookup query sets an explicit `_limit` or uses `searchObjectsPaginated()`
- [ ] Implement
- [ ] Test

### Task 3: `FacetService` — filtered-set narrowing (facet-on-facet AND/OR semantics)
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-counts-reflect-the-currently-filtered-set-not-the-unfiltered-universe`
- **files**: `lib/Service/FacetService.php`
- **acceptance_criteria**:
  - GIVEN one facet dimension is pre-selected THEN counts for every other dimension are computed only over the resulting narrowed set
  - GIVEN multiple values are selected within one dimension THEN the result set is their union (OR)
  - GIVEN values are selected across different dimensions THEN the result set is their intersection (AND)
- [ ] Implement
- [ ] Test

### Task 4: `FacetService` — combine free-text search with facet filters
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facets-combine-with-free-text-search`
- **files**: `lib/Service/FacetService.php`
- **acceptance_criteria**:
  - GIVEN a `search` query parameter THEN facet counts are computed only over objects matching that text query
  - GIVEN no `search` parameter THEN facet counts cover the full RBAC-scoped set
- [ ] Implement
- [ ] Test

### Task 5: `FacetService` — RBAC/tenant scoping parity with the object list query
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-counts-must-respect-the-callers-rbactenant-context`
- **files**: `lib/Service/FacetService.php`
- **acceptance_criteria**:
  - GIVEN a restricted user THEN facet counts never include objects that user's own object-list query would not return
  - Facet aggregation uses the identical RBAC/tenant-scoped `ObjectService` query path as the index page's object list — no separate unscoped counting path is introduced
- [ ] Implement
- [ ] Test

### Task 6: `FacetService` — distributed caching + invalidation
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-results-are-cached`
- **files**: `lib/Service/FacetService.php`
- **acceptance_criteria**:
  - GIVEN an identical facet request within the cache TTL THEN the response is served from cache and is measurably faster
  - GIVEN a module's GEMMA link fields (`referentieComponenten`, `standaarden`, `standaardenGemma`, `standaardVersies`) change THEN affected cached facet entries are invalidated
  - GIVEN two users with different RBAC/tenant context THEN their cache entries are keyed separately and never cross
  - Cache invalidation extends the existing module/element mutation hook `ViewService` already uses — no duplicate/parallel event listener is added
- [ ] Implement
- [ ] Test

### Task 7: `FacetController` + route registration
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts`
- **files**: `lib/Controller/FacetController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN `GET /apps/softwarecatalog/api/facets/module` or `/dienst` THEN the controller returns the `FacetService` response as JSON with status 200
  - GIVEN `GET /apps/softwarecatalog/api/facets/{other}` THEN the controller returns 400 with an error naming the supported schemas
  - GIVEN `ObjectService` is unavailable THEN the controller returns 503/500 with a logged, descriptive error message
  - Controller carries the correct Nextcloud auth attribute (`#[NoAdminRequired]`) — verified against `hydra-gate-route-auth`/`hydra-gate-semantic-auth`
- [ ] Implement
- [ ] Test

### Task 8: PHPUnit unit tests for `FacetService`
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts`
- **files**: `tests/Unit/Service/FacetServiceTest.php`
- **acceptance_criteria**:
  - Covers Tasks 1–6's acceptance criteria (dimension aggregation, narrowing, text-search combination, RBAC scoping, caching, bounded queries)
  - Achieves ≥75% coverage of `FacetService`'s new code (ADR-009)
- [ ] Implement
- [ ] Test

### Task 9: Newman/Postman collection entries for the facets endpoint
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts`
- **files**: `postman/` (add to existing softwarecatalog collection)
- **acceptance_criteria**:
  - GIVEN the collection is run against a seeded dev instance THEN requests cover happy-path facet retrieval, filtered narrowing, unsupported-schema 400, and free-text combination
- [ ] Implement
- [ ] Test

### Task 10: Frontend `facets.js` API client
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts`
- **files**: `src/services/facets.js`
- **acceptance_criteria**:
  - GIVEN a schema and a set of active filters THEN the client requests `GET /apps/softwarecatalog/api/facets/{schema}` with the correct query parameters, mirroring the `view-enrichment-api` fetch pattern
- [ ] Implement
- [ ] Test

### Task 11: Facet sidebar/filter panel component
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-sidebar-ui-on-the-module-and-dienst-index-pages`
- **files**: `src/sidebars/facets/FacetSideBar.vue` (or `CnIndexPage` filter-slot equivalent — final placement decided against ADR-012's existing filter-slot API), `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the module or dienst index page THEN a facet panel renders all four GEMMA dimensions with per-value counts, using `@conduction/nextcloud-vue` components (`NcCheckboxRadioSwitch`, `NcCounterBubble`) per ADR-012
  - GIVEN a facet value is selected THEN the object list re-fetches without a full page reload and other dimensions' counts update
  - GIVEN a dimension has zero available values under the current filter THEN it renders a disabled/empty state, not a selectable empty list
  - Existing free-text search box and `quickFilters` on the same index page continue to render and function unchanged
  - All colors use Nextcloud CSS variables (ADR-003) — no hardcoded hex values
- [ ] Implement
- [ ] Test

### Task 12: URL-encoded, deep-linkable filter state
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-filter-state-is-url-encoded-and-deep-linkable`
- **files**: `src/sidebars/facets/FacetSideBar.vue`, `src/router/index.js` (or equivalent router query-sync logic)
- **acceptance_criteria**:
  - GIVEN a facet selection is applied THEN the browser URL query string reflects it
  - GIVEN a URL with facet query parameters is loaded directly THEN the facet panel, object list, and counts reflect that state on first render
  - GIVEN all facets are cleared THEN the facet-related query parameters are removed from the URL
- [ ] Implement
- [ ] Test

### Task 13: Save facet selection as a view (dashboard-views-api integration)
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view`
- **files**: `src/sidebars/facets/FacetSideBar.vue`, existing view-save UI/store (reuse, do not duplicate `ViewService`/`ViewController`)
- **acceptance_criteria**:
  - GIVEN an active facet selection THEN "Save as view" creates a saved view via the existing save-view call with the filter state stored
  - GIVEN a saved view with a stored facet selection is opened THEN the facet panel, object list, and URL reflect that state
  - No new `ViewController`/`ViewService` endpoint is introduced — this task is a consumer of the existing API only
- [ ] Implement
- [ ] Test

### Task 14: Browser (Playwright MCP) tests for the facet UI
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-sidebar-ui-on-the-module-and-dienst-index-pages`
- **files**: `tests/e2e/` (or `tests/vitest/` per existing frontend test layout)
- **acceptance_criteria**:
  - Covers Tasks 11–13's acceptance criteria end-to-end through the browser (facet selection, URL deep link, save-as-view, zero-value disabled state)
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate --change gemma-faceted-search` passes
- [ ] Manual testing against acceptance criteria (module and dienst index pages)
- [ ] Code review against spec requirements
- [ ] Hydra mechanical gates pass — in particular `route-auth`, `route-reachability`, `spdx-headers`, `spec-coverage` for the new `FacetController`/`FacetService`

## Tests (company-wide ADR-009)
- [ ] PHPUnit unit tests for `FacetService` (`tests/Unit/Service/FacetServiceTest.php`) — ≥75% coverage of new code
- [ ] Newman/Postman tests for `GET /apps/softwarecatalog/api/facets/{schema}` added to the softwarecatalog collection
- [ ] Browser tests (Playwright MCP) for the facet panel, URL deep-linking, and save-as-view flow
- [ ] All tests pass (`composer test`, `newman run`, browser MCP verification)

## Documentation (company-wide ADR-010)
- [ ] Feature documentation added at `docs/features/gemma-faceted-search.md` describing the facet panel, dimensions, and save-as-view flow
- [ ] Screenshots captured via Playwright MCP showing: the facet panel on the module index page, a narrowed selection with updated counts, and the "Save as view" dialog — committed to `docs/images/`

## i18n (company-wide ADR-005)
- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added for all new facet UI strings (dimension labels, empty-state text, "Save as view" action, clear-filters action)
- [ ] Translation keys are English identifiers (e.g. `facetSaveAsView`, `facetDimensionReferentiecomponent`, `facetClearAll`) with Dutch/English values supplied per key — no Dutch text used as a key
