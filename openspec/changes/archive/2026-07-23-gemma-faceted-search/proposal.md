# Proposal: gemma-faceted-search

## Summary
Adds faceted search and filtering to the catalog's application/voorziening listing pages, letting users narrow the module and dienst indexes by GEMMA architecture dimension — referentiecomponent, standaard, applicatieservice, and domein — with live per-facet-value counts, combinable free-text search, deep-linkable filter state, and the ability to save a facet selection as an existing dashboard view.

## Motivation
"Zoeken/filteren op GEMMA architectuur" is the top open user wish in the VNG Softwarecatalogus issue tracker (#146, #70, plus 18 other `Zoeken`-labelled issues) and appears as a usability requirement in 281 of the 301 tenders mapped against softwarecatalog in the Specter intelligence database. No OSS competitor in the space offers GEMMA-dimension facets, and the GEMMA Softwarecatalogus incumbent itself only supports basic keyword search — this is a clear, evidenced market gap (Specter canonical feature `gemma-faceted-search`, demand score 24, priority `must`). The underlying data is already in place: `view-enrichment-api` resolves referentiecomponent relationships server-side, and the `element` schema (register `voorzieningen`) already carries every GEMMA dimension (`gemmaType`, `domein`) the facets need. What's missing is an aggregation endpoint and a filter UI wired to it.

## Affected Projects
- [ ] Project: `softwarecatalog` — new facet aggregation endpoint (Controller → Service), facet sidebar UI on the module/dienst index pages, URL-encoded filter state, integration with the existing saved-views API.

## Scope

### In Scope
- A bounded, cached facet aggregation endpoint that counts `element`-linked GEMMA dimension values (referentiecomponent, standaard, applicatieservice, domein) across the module and dienst listings, scoped to the caller's RBAC/tenant context.
- A facet sidebar/filter panel on the catalog's application (`module`) and service (`dienst`) index pages, built from `@conduction/nextcloud-vue` `CnIndexPage` filter patterns.
- Combinability with the existing free-text search box already present on these index pages (AND semantics — text query narrows the facet-counted set, not the other way round).
- URL-encoded filter state so a filtered view is shareable/deep-linkable and survives a page reload.
- Per-facet-value counts that update as other facets are applied (counts reflect the currently filtered set, not the unfiltered universe).
- Saving a facet selection as a saved view via the existing `dashboard-views-api` `ViewService`.
- Dutch + English i18n for all new facet labels and UI strings.
- Unit tests (PHPUnit, ≥75% new code) and Playwright/browser tests for the facet UI.
- User-facing documentation with screenshots.

### Out of Scope
- Changes to free-text relevance ranking or search algorithm — this change only adds structured facets alongside existing text search.
- New GEMMA data imports or changes to the ArchiMate/GGM import pipeline — facets aggregate over `element` objects already present in the register.
- Cross-app / federated search (searching another Conduction app's catalog from softwarecatalog) — deferred, tracked separately if raised again.

## Approach
Add a `FacetController` → `FacetService` pair (ADR-008 layering) that issues bounded, `_limit`-respecting `searchObjects()`/aggregate queries against the `voorzieningen` register's `module`/`dienst`/`element` schemas, grouping by GEMMA dimension and returning value→count pairs plus the RBAC-filtered object IDs needed to drive the index page's existing list query. Cache aggregation results per (schema, active filter-set, user/tenant) similar in shape to `view-enrichment-api`'s existing cache. The frontend adds a facet sidebar component to the module/dienst `CnIndexPage` configs, translates facet selections to query parameters and to the URL (via the Vue router, mirroring the enrichment API's query-parameter pattern), and re-fetches both the object list and the facet counts on every selection change. Full technical detail (query shape, cache key, RBAC scoping mechanism) is worked out in design.md.

## New Dependencies
None.

## Impact
- New: `lib/Controller/FacetController.php`, `lib/Service/FacetService.php`, `src/sidebars/facets/` (or equivalent), facet-aware additions to the `module`/`dienst` entries in `src/manifest.json`.
- Modified: module and dienst index page configs (add `facets` config block, mirroring the existing `quickFilters` pattern); frontend endpoint constants (new `FACETS` endpoint group, alongside the existing `GEMMA` group in the endpoints file used by `view-enrichment-api`).
- No changes to existing controllers/services outside the new facet slice; `ViewService`/`ViewController` (dashboard-views-api) gain no new endpoints, only a consumer (facet-selection-as-view uses the existing save-view call).

## Cross-Project Dependencies
None — self-contained within softwarecatalog. The facet endpoint is consumed only by the softwarecatalog frontend.

## Risks

### Risk 1: Unbounded aggregation queries reintroduce the full-table-scan defect class
**Severity:** High — **Mitigation:** The pending `bound-unbounded-searchobjects-scans` change establishes the pattern (explicit `_limit` on every `searchObjects()` call, or `searchObjectsPaginated()`/documented ceiling for index-building queries). `FacetService` MUST follow that pattern from day one — every facet-count query sets an explicit `_limit` or uses a paginated/aggregate query path; this is called out explicitly as an acceptance criterion in design.md and tasks.md so it is not an afterthought bolted on after the fact.

### Risk 2: Facet counts leak the existence of objects the user cannot see
**Severity:** Medium — **Mitigation:** Facet aggregation MUST run through the same RBAC/tenant-scoped query path the index page's own object list already uses (no separate unscoped counting query). Covered by a dedicated spec scenario and a test task that asserts counts for a restricted user never include out-of-scope objects.

### Risk 3: Facet sidebar duplicates rather than reuses the existing `quickFilters` / `CnIndexPage` filter machinery
**Severity:** Low — **Mitigation:** design.md evaluates extending the existing `quickFilters` config shape before introducing a parallel `facets` config block, and the tasks explicitly reference `CnIndexPage`'s existing filter slot per ADR-012.

## Rollback Strategy
The facet endpoint and sidebar are additive — the existing module/dienst index pages, `quickFilters`, and free-text search continue to work unchanged if the facet UI is hidden or the manifest `facets` config block is removed. Rollback is: remove the `facets` config from the affected manifest entries (hides the sidebar), and/or unregister the `FacetController` route. No data migration, no schema change, nothing to reverse in OpenRegister.

## Open Questions
- Should facet selections persist per-user as a default (via the existing `PreferencesController`) the way list columns already do, or reset on every visit? Deferred to design.md — default assumption is "reset on navigation, restorable only via a saved view or a shared URL" unless design.md finds a cheap way to reuse `PreferencesController`.
