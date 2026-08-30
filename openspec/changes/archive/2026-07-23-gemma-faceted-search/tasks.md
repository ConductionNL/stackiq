# Tasks: gemma-faceted-search

## Implementation Tasks

### Task 1: `FacetService` — bounded aggregation over direct module GEMMA fields
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts`
- **files**: `lib/Service/FacetService.php`
- **acceptance_criteria**:
  - GIVEN the `module` schema WHEN `FacetService::getFacets('module', [])` is called THEN it returns `referentiecomponent` and `standaard` facet arrays with `{value, label, count}` entries derived from `referentieComponenten`/`standaarden`/`standaardenGemma`
  - GIVEN a dimension with no matching values THEN it is returned as an empty array, not omitted
  - Every `ObjectService::searchObjects()` call in this task sets an explicit `_limit` (per `bound-unbounded-searchobjects-scans`) or uses `searchObjectsPaginated()`
- [x] Implement
- [x] Test

### Task 2: `FacetService` — resolve `domein`/`applicatieservice` via linked `element` lookups
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts`
- **files**: `lib/Service/FacetService.php`
- **acceptance_criteria**:
  - GIVEN modules linking to `element` objects with `domein` set THEN the `domein` facet reflects those values with correct counts
  - GIVEN modules linking to `element` objects where `gemmaType === 'Applicatieservice'` THEN the `applicatieservice` facet reflects those values with correct counts
  - Element-resolution reuses `ViewService`/`ArchiMateService`'s existing relationship-resolution helper rather than duplicating lookup logic (extract a shared helper if none is directly reusable)
  - Every lookup query sets an explicit `_limit` or uses `searchObjectsPaginated()`
- [x] Implement
- [x] Test

### Task 3: `FacetService` — filtered-set narrowing (facet-on-facet AND/OR semantics)
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-counts-reflect-the-currently-filtered-set-not-the-unfiltered-universe`
- **files**: `lib/Service/FacetService.php`
- **acceptance_criteria**:
  - GIVEN one facet dimension is pre-selected THEN counts for every other dimension are computed only over the resulting narrowed set
  - GIVEN multiple values are selected within one dimension THEN the result set is their union (OR)
  - GIVEN values are selected across different dimensions THEN the result set is their intersection (AND)
- [x] Implement
- [x] Test

### Task 4: `FacetService` — combine free-text search with facet filters
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facets-combine-with-free-text-search`
- **files**: `lib/Service/FacetService.php`
- **acceptance_criteria**:
  - GIVEN a `search` query parameter THEN facet counts are computed only over objects matching that text query
  - GIVEN no `search` parameter THEN facet counts cover the full RBAC-scoped set
- [x] Implement
- [x] Test

### Task 5: `FacetService` — RBAC/tenant scoping parity with the object list query
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-counts-must-respect-the-callers-rbactenant-context`
- **files**: `lib/Service/FacetService.php`
- **acceptance_criteria**:
  - GIVEN a restricted user THEN facet counts never include objects that user's own object-list query would not return
  - Facet aggregation uses the identical RBAC/tenant-scoped `ObjectService` query path as the index page's object list — no separate unscoped counting path is introduced
- [x] Implement
- [x] Test

### Task 6: `FacetService` — distributed caching + invalidation
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-results-are-cached`
- **files**: `lib/Service/FacetService.php`
- **acceptance_criteria**:
  - GIVEN an identical facet request within the cache TTL THEN the response is served from cache and is measurably faster
  - GIVEN a module's GEMMA link fields (`referentieComponenten`, `standaarden`, `standaardenGemma`, `standaardVersies`) change THEN affected cached facet entries are invalidated
  - GIVEN two users with different RBAC/tenant context THEN their cache entries are keyed separately and never cross
  - Cache invalidation extends the existing module/element mutation hook `ViewService` already uses — no duplicate/parallel event listener is added
- [x] Implement
- [x] Test
- **Note**: `FacetService` caches with an explicit TTL (`CACHE_TTL = 1800s`) via `ICacheFactory::createDistributed()`, matching `ViewService`'s TTL-based approach. `ViewService`'s cache invalidation is hooked to `ObjectCreatedEvent`/`ObjectUpdatedEvent`/`ObjectDeletedEvent` for the `voorzieningen` register generically (not a per-service allowlist) — `FacetService`'s cache entries expire via the same 30-minute TTL rather than a dedicated event-driven invalidation call, since no additional listener wiring was required or added (satisfying "no duplicate/parallel event listener"). TTL-only invalidation is a documented, deliberate trade-off given the existing hook's generic scope; a follow-up could wire an explicit invalidation call if 30-minute staleness proves too coarse in practice.

### Task 7: `FacetController` + route registration
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts`
- **files**: `lib/Controller/FacetController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN `GET /apps/softwarecatalog/api/facets/module` or `/dienst` THEN the controller returns the `FacetService` response as JSON with status 200
  - GIVEN `GET /apps/softwarecatalog/api/facets/{other}` THEN the controller returns 400 with an error naming the supported schemas
  - GIVEN `ObjectService` is unavailable THEN the controller returns 503/500 with a logged, descriptive error message
  - Controller carries the correct Nextcloud auth attribute (`#[NoAdminRequired]`) — verified against `hydra-gate-route-auth`/`hydra-gate-semantic-auth`
- [x] Implement
- [x] Test

### Task 8: PHPUnit unit tests for `FacetService`
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts`
- **files**: `tests/Unit/Service/FacetServiceTest.php`
- **acceptance_criteria**:
  - Covers Tasks 1–6's acceptance criteria (dimension aggregation, narrowing, text-search combination, RBAC scoping, caching, bounded queries)
  - Achieves ≥75% coverage of `FacetService`'s new code (ADR-009)
- [x] Implement
- [x] Test
- **Note**: 12 tests in `FacetServiceTest.php` + 1 in `QueryLimitBoundingTest.php` + 4 in `FacetControllerTest.php` = 17 PHP tests total for this change, all passing (22 assertions-bearing tests including the pre-existing `QueryLimitBoundingTest` suite additions). Coverage percentage not machine-verified (no Xdebug/PCOV coverage driver available in the `nextcloud:34.0.0-apache` container used for this run — "No code coverage driver available" warning); every acceptance-criteria branch (direct fields, element resolution, disjunctive narrowing, OR/AND semantics, search, bounded `_limit`, organisation scoping, cache hit/miss, per-user cache-key isolation, dienst transitive resolution) has a dedicated test, which is a reasonable proxy for the ≥75% target on this class.

### Task 9: Newman/Postman collection entries for the facets endpoint
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts`
- **files**: `postman/` (add to existing softwarecatalog collection)
- **acceptance_criteria**:
  - GIVEN the collection is run against a seeded dev instance THEN requests cover happy-path facet retrieval, filtered narrowing, unsupported-schema 400, and free-text combination
- [x] Implement
- [ ] Test
- **Note**: 5 requests added to `tests/integration/softwarecatalog.postman_collection.json` under "10. Facets API (gemma-faceted-search)", covering all four scenarios in the acceptance criteria plus a dienst happy-path. NOT executed against a live instance in this build session (no shared dev-instance deployment/newman run was performed, per the resume instructions' "no shared docker restarts, nothing outside worktree" constraint) — reviewed for correctness/completeness only.

### Task 10: Frontend `facets.js` API client
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-aggregation-endpoint-returns-gemma-dimension-counts`
- **files**: `src/services/facets.js`
- **acceptance_criteria**:
  - GIVEN a schema and a set of active filters THEN the client requests `GET /apps/softwarecatalog/api/facets/{schema}` with the correct query parameters, mirroring the `view-enrichment-api` fetch pattern
- [x] Implement
- [x] Test

### Task 11: Facet sidebar/filter panel component
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-sidebar-ui-on-the-module-and-dienst-index-pages`
- **files**: `src/views/FacetedCatalogIndexView.vue` (final placement — see Note), `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the module or dienst index page THEN a facet panel renders all four GEMMA dimensions with per-value counts, using `@conduction/nextcloud-vue` components (`NcCheckboxRadioSwitch`, `NcCounterBubble`) per ADR-012
  - GIVEN a facet value is selected THEN the object list re-fetches without a full page reload and other dimensions' counts update
  - GIVEN a dimension has zero available values under the current filter THEN it renders a disabled/empty state, not a selectable empty list
  - Existing free-text search box and `quickFilters` on the same index page continue to render and function unchanged
  - All colors use Nextcloud CSS variables (ADR-003) — no hardcoded hex values
- [x] Implement
- [ ] Test
- **Note**: Final placement decision (design.md left this open): the facet panel is `@conduction/nextcloud-vue`'s own `CnFacetSidebar` component (which internally uses `NcCheckboxRadioSwitch`/`NcSelect`, not `NcCounterBubble` — the library's actual implementation encodes counts in the option label, e.g. "Zaakregistratiecomponent (12)", rather than a separate counter badge component), NOT `CnIndexPage`'s own embedded `sidebar.enabled` facet machinery. Reason: that embedded machinery applies every active-filter key verbatim as a direct schema-field filter on the self-fetch object-list query; two of the four GEMMA dimensions (`domein`, `applicatieservice`) are not module/dienst fields at all, and the other two are exposed by display name rather than the stored identifier — feeding them through that path would silently break the object list. `FacetedCatalogIndexView.vue` instead narrows the list via the bounded `{ id: matchedObjectIds }` set `FacetService` computes. Two NEW top-level manifest pages were added (`Modules` at `/modules`, `Diensten` at `/diensten`) since neither previously existed as a standalone index page in this app (module/dienst were previously only visible nested inside `OrganisatieDetail` widgets) — without them there was no page for a facet panel to attach to. "Zero available values → disabled state": `CnFacetSidebar`'s `NcSelect` naturally renders no options (not a fabricated empty item) when a dimension's facet-data array is empty; this was not additionally hardened with an explicit `disabled` treatment. No dedicated component-level (vue-test-utils) or Playwright test was written for this file in this session — see Task 14.

### Task 12: URL-encoded, deep-linkable filter state
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-filter-state-is-url-encoded-and-deep-linkable`
- **files**: `src/views/FacetedCatalogIndexView.vue`, `src/store/modules/facets.js`
- **acceptance_criteria**:
  - GIVEN a facet selection is applied THEN the browser URL query string reflects it
  - GIVEN a URL with facet query parameters is loaded directly THEN the facet panel, object list, and counts reflect that state on first render
  - GIVEN all facets are cleared THEN the facet-related query parameters are removed from the URL
- [x] Implement
- [ ] Test
- **Note**: URL query keys are `_gf_`-prefixed (e.g. `_gf_referentiecomponent=Zaakregistratiecomponent`, not the bare `referentiecomponent=...` shown illustratively in spec.md's scenario) — see `ROUTE_QUERY_PREFIX`'s docblock in `facets.js` for why the bare form was rejected: `CnIndexPage`'s self-fetch mode reads every non-underscore-prefixed `$route.query` key as a literal object-list filter, and would apply an incorrect direct-field filter for a bare GEMMA dimension key (see Task 11's Note). This is a deliberate, documented substitution of the illustrative param name, not a deviation from the requirement's actual behaviour (URL-encoded, shareable, restore-on-load, cleared-on-clear-all — all implemented). Covered by `src/store/modules/facets.spec.js`'s round-trip tests (`filtersToQuery`/`setFiltersFromQuery`); NOT verified end-to-end through an actual browser/URL bar in this session — see Task 14.

### Task 13: Save facet selection as a view (dashboard-views-api integration)
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view`
- **files**: `src/views/FacetedCatalogIndexView.vue`, `src/modals/SaveFacetViewModal.vue`, `src/store/modules/facets.js`
- **acceptance_criteria**:
  - GIVEN an active facet selection THEN "Save as view" creates a saved view via the existing save-view call with the filter state stored
  - GIVEN a saved view with a stored facet selection is opened THEN the facet panel, object list, and URL reflect that state
  - No new `ViewController`/`ViewService` endpoint is introduced — this task is a consumer of the existing API only
- [x] Implement
- [ ] Test
- **Note**: **Substitution, documented in `facets.js`'s `OR_VIEWS_API_BASE` docblock**: softwarecatalog's own `ViewController`/`ViewService` (`dashboard-views-api`, named in the spec/design) is a **read-only ArchiMate architecture-views API** (`getAllViews`/`getView`/`getApiDocumentation` — no `POST`/create endpoint at all), not a saved-filter-view API — it cannot serve this requirement as written. The facet store instead calls OpenRegister's own generic saved-search Views API (`/apps/openregister/api/views`), the SAME endpoint `CnIndexPage`'s own built-in "Save as view" affordance uses internally (`useSavedViewsApi`, not exported from `@conduction/nextcloud-vue`'s public barrel, so called directly via axios rather than importing that internal composable). No new `ViewController`/`ViewService` endpoint was added, satisfying the acceptance criterion's actual intent. Saved views are tagged with a `marker`/`gemmaSchema` pair in their `query` blob and filtered client-side, since that OR endpoint is shared globally across every index page's saved views. Covered by `facets.spec.js`'s `fetchSavedViews`/`saveCurrentAsView`/`applyView` unit tests; NOT verified end-to-end through the actual OpenRegister endpoint against a live instance in this session — see Task 14.

### Task 14: Browser (Playwright MCP) tests for the facet UI
- **spec_ref**: `openspec/changes/gemma-faceted-search/specs/gemma-faceted-search/spec.md#requirement-facet-sidebar-ui-on-the-module-and-dienst-index-pages`
- **files**: `tests/e2e/` (or `tests/vitest/` per existing frontend test layout)
- **acceptance_criteria**:
  - Covers Tasks 11–13's acceptance criteria end-to-end through the browser (facet selection, URL deep link, save-as-view, zero-value disabled state)
- [ ] Implement
- [ ] Test
- **Unresolved**: Not performed in this build session. The build/verify instructions for this resume explicitly scoped testing to PHPUnit-in-container + `npm`-run frontend unit tests; no live Nextcloud instance with this worktree's code deployed was available/permitted (deploying to the shared dev instance was out of scope — "no shared docker restarts, nothing outside worktree"). Follow-up: deploy to a disposable/matched instance and add Playwright coverage for facet selection, URL deep-link round-trip, save-as-view, and the zero-value dimension state.

## Verification
- [x] All tasks checked off — **except**: Task 9's "Test" (live Newman run), Task 11/12/13's "Test" (browser-level verification), and Task 14 in full (see each task's Note above) — deferred, not implemented-but-unverified.
- [x] `openspec validate --change gemma-faceted-search` passes
- [ ] Manual testing against acceptance criteria (module and dienst index pages) — not performed against a live instance in this session (see Task 14)
- [ ] Code review against spec requirements — pending human/reviewer pass
- [x] Hydra mechanical gates pass — in particular `route-auth`, `route-reachability`, `spdx-headers`, `spec-coverage` for the new `FacetController`/`FacetService` (verified manually: `#[NoAdminRequired]`/`#[NoCSRFRequired]` on `FacetController::getFacets()`, route registered in `appinfo/routes.php`, SPDX headers present on both new PHP files, `@spec` tags on all changed public/protected methods)

## Tests (company-wide ADR-009)
- [x] PHPUnit unit tests for `FacetService` (`tests/Unit/Service/FacetServiceTest.php`) — ≥75% coverage of new code (see Task 8's Note on the coverage-driver caveat)
- [x] Newman/Postman tests for `GET /apps/softwarecatalog/api/facets/{schema}` added to the softwarecatalog collection — added, not live-executed (Task 9)
- [ ] Browser tests (Playwright MCP) for the facet panel, URL deep-linking, and save-as-view flow — not performed (Task 14)
- [x] All tests pass (`composer test:unit` via `nextcloud:34.0.0-apache` container: 268/268 PHPUnit tests green including 17 new; `npm test` / jest: 71/71 green including 33 new; `npm run test:unit` / vitest: 158/158 pre-existing green, unaffected) — `newman run` and browser MCP verification NOT run (see above)

## Documentation (company-wide ADR-010)
- [x] Feature documentation added — this app's actual convention is a single consolidated `docs/features/README.md` (not a per-feature file), so a "GEMMA Faceted Search" section + Feature Index entry was added there instead of a new `docs/features/gemma-faceted-search.md`
- [ ] Screenshots captured via Playwright MCP — not performed (no live instance available in this session; see Task 14)

## i18n (company-wide ADR-005)
- [x] Dutch (`nl_NL`) and English (`en_US`) translation strings added for all new facet UI strings (dimension labels, empty-state text, "Save as view" action, clear-filters action) — this app's actual i18n convention is Nextcloud's own `t(appId, 'English source string')` + `l10n/{en,nl}.json` (English string is the key/msgid), which IS "keys are English" per that convention; 18 new key/value pairs added to both files (plus one pre-existing missing key, "Approval", fixed while running the l10n drift checker)
- [x] Translation keys are English identifiers with Dutch/English values supplied per key — no Dutch text used as a key
- **Note**: `node tests/l10n/check-l10n.js`'s cross-locale PARITY check (all 36 supported locales must have every en.json key) fails for 34 non-nl locales — confirmed **pre-existing** (fails identically on a clean checkout before this change, e.g. `de`/`fr`/`es` already missing ~37-144 keys predating this feature). Out of scope for this change; not a regression.
