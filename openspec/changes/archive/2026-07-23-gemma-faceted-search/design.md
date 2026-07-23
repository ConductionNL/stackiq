# Design: gemma-faceted-search

## Architecture Overview
Softwarecatalog is a thin client over OpenRegister (ADR-001) — no custom tables. This change adds one new aggregation slice (`FacetController` → `FacetService`) that reads from the existing `voorzieningen` register's `module`, `dienst`, and `element` schemas, following the same `Controller → Service` layering (ADR-008) and the same distributed-cache pattern `ViewService` already uses for `view-enrichment-api` (`ICacheFactory::createDistributed`, TTL-based, cache-key includes all query-affecting parameters).

```
Frontend (module/dienst CnIndexPage)
   │  facet selection change / text search change
   ▼
FacetSidebar.vue  ──┬── GET /apps/softwarecatalog/api/facets/{schema}?<filters>
                     └── existing object-list query (CnIndexPage), same <filters>
   ▼
FacetController::getFacets($schema)
   ▼
FacetService::getFacets($schema, $filters, $rbacContext)
   │  1. resolve RBAC-scoped, filter-scoped object ID set for $schema (bounded)
   │  2. for each GEMMA dimension, aggregate distinct values + counts over that set
   │  3. cache result keyed on (schema, filters, rbacContext)
   ▼
OpenRegister ObjectService::searchObjects() / searchObjectsPaginated()
   (module/dienst schema, `_limit` always set — bound-unbounded-searchobjects-scans pattern)
```

The `module` schema already carries the GEMMA links directly (`referentieComponenten`, `standaarden`, `standaardenGemma`, `standaardVersies` — all arrays of `element` refs or element identifiers). `dienst` carries GEMMA links only transitively via its `modules` relation. `domein` and `applicatieservice` are not first-class module fields; they live on the linked `element` object's `domein` / `gemmaType`/`gemmaThema` fields, so aggregating those two dimensions requires resolving each module's linked `element` objects and reading their `domein` field / filtering `gemmaType === 'Applicatieservice'`. This mirrors the relationship-resolution `view-enrichment-api`'s `ViewService` already performs for referentiecomponent overlays — `FacetService` reuses that resolution logic rather than re-implementing element lookups.

## API Design

### `GET /apps/softwarecatalog/api/facets/{schema}`
**Path parameter:** `schema` — one of `module`, `dienst`.

**Query parameters:**
- `search` (optional) — free-text query, same semantics as the index page's existing search box.
- `referentiecomponent[]`, `standaard[]`, `applicatieservice[]`, `domein[]` (optional, repeatable) — currently-selected facet values per dimension (OR within a dimension, AND across dimensions).
- `organization` (optional) — overrides active organisation context, mirroring `view-enrichment-api`'s existing `organization` parameter convention.

**Response:**
```json
{
  "referentiecomponent": [
    { "value": "Zaakregistratiecomponent", "label": "Zaakregistratiecomponent", "count": 12 },
    { "value": "Klantcontactcomponent", "label": "Klantcontactcomponent", "count": 7 }
  ],
  "standaard": [
    { "value": "StUF-ZKN", "label": "StUF-ZKN", "count": 5 }
  ],
  "applicatieservice": [],
  "domein": [
    { "value": "Bedrijfsvoering", "label": "Bedrijfsvoering", "count": 9 }
  ],
  "_meta": {
    "totalMatched": 12,
    "processingTimeMs": 42,
    "cached": false
  }
}
```
**Errors:** 400 for an unsupported `schema` path segment or a malformed facet query parameter; 503/500 with a logged, descriptive error if `ObjectService` is unavailable — same error contract `view-enrichment-api` already uses.

## Nextcloud Integration
- **Controllers:** `lib/Controller/FacetController.php` — one action, `getFacets(string $schema)`, `#[NoAdminRequired]` (facets are a read operation available to any authenticated catalog user; RBAC scoping happens inside the service, not at the controller boundary — same posture as `ViewController`).
- **Services:** `lib/Service/FacetService.php` — new. Depends on `OCA\OpenRegister\Service\ObjectService` (bounded `searchObjects`/`searchObjectsPaginated` calls only) and reuses `ArchiMateService`/`ViewService`'s existing element-relationship resolution helpers rather than duplicating them (extract a shared helper if resolution logic would otherwise be copy-pasted — decided at implementation time, called out in tasks.md).
- **Mappers/Entities:** None new — no custom tables (ADR-001).
- **Events/Hooks:** None new. Cache invalidation is triggered from the same object-mutation path `ViewService`'s module-mapping cache invalidation already hooks (module/element save/delete on the `voorzieningen` register) — extend that existing hook rather than adding a parallel listener.

## Security Considerations
- **AuthZ:** Facet aggregation MUST run through the identical RBAC/tenant-scoped `ObjectService` query path the module/dienst index page's own object list already uses. No separate, unscoped counting query is permitted (see spec requirement "Facet counts MUST respect the caller's RBAC/tenant context"). This is the same class of risk flagged in the proposal (Risk 2) and is a first-class acceptance criterion, not an afterthought.
- **Input validation:** `schema` path parameter validated against an allowlist (`module`, `dienst`); facet value query parameters are treated as opaque strings matched against known `element`/module field values — never interpolated into a raw query string (OpenRegister's parameterized query builder handles this, consistent with existing `ViewService` usage).
- **CSRF:** Standard Nextcloud CSRF protection applies (GET request, no state mutation) — no `#[NoCSRFRequired]` needed.
- **DoS / resource exhaustion:** Every `searchObjects()` call in `FacetService` sets an explicit `_limit` (Risk 1 in the proposal); the 30-minute-scale distributed cache further bounds repeated-request cost, matching `ViewService`'s existing cache TTL choice.

## NL Design System
- Facet panel is a new `CnAppSidebar`/`CnIndexPage`-slot component (final placement — sidebar tab vs. inline filter panel — decided against `CnIndexPage`'s existing filter slot API at implementation time; ADR-012 requires reusing `CnIndexPage` machinery rather than a bespoke panel from scratch).
- Facet value chips/checkboxes use standard `NcCheckboxRadioSwitch` / `NcCounterBubble` (for the count badge) rather than custom-styled equivalents.
- All colors via Nextcloud CSS variables (`--color-*`) per ADR-003 — no hardcoded hex values in the new facet components.
- WCAG 2.2 AA: facet checkboxes carry accessible labels (dimension + value, e.g. "Referentiecomponent: Zaakregistratiecomponent, 12 results") so screen readers announce the count; the "clear all facets" action is keyboard-reachable and has a visible focus state (SC 2.4.11 Focus Not Obscured); facet checkbox hit targets meet the 24×24px minimum (SC 2.5.8 Target Size).

## File Structure
```
lib/
  Controller/
    FacetController.php          (new)
  Service/
    FacetService.php             (new)
src/
  sidebars/
    facets/
      FacetSideBar.vue           (new) — or CnIndexPage filter-slot component, TBD at implementation
  services/
    facets.js                    (new) — API client, mirrors existing view-enrichment fetch pattern
  manifest.json                  (modified) — add `facets` config block to the module/dienst index entries
tests/
  Unit/
    Service/
      FacetServiceTest.php       (new)
  Integration/
    (Newman/Postman collection entry for GET /api/facets/{schema})
```

## Seed Data
Not applicable — this change introduces no new schemas or data entities. Facets aggregate over the existing `module`, `dienst`, and `element` objects already seeded by prior changes (GEMMA/ArchiMate import + existing module/dienst seed data). Manual verification during implementation SHOULD confirm the dev-environment register already has enough `element` objects with populated `gemmaType`/`domein` values across at least two of each dimension to exercise the facet UI meaningfully; if not, augmenting the existing GEMMA import fixture is a task, not a schema change.

## Trade-offs
- **New `facets` manifest config block vs. extending `quickFilters`:** `quickFilters` is a flat list of exact-match, single-select filter presets (see `Contracten` index in `src/manifest.json`) — it has no concept of a dimension with many dynamic values and live counts. Extending it to cover facets would overload a simple config shape with multi-select, count-aware semantics it wasn't designed for. A new, purpose-built `facets` config block (parallel to, not replacing, `quickFilters`) keeps both simple. Both remain available on the same index page.
- **Resolving `domein`/`applicatieservice` via linked `element` lookups vs. denormalizing onto `module`:** Denormalizing would mean writing GEMMA metadata onto every module at import/save time — an ADR-001-adjacent smell (duplicating data that already lives on `element`) and a data-consistency risk if the source `element` changes later. Resolving on read (same pattern `view-enrichment-api` already uses for referentiecomponent overlays) keeps `element` as the single source of truth at the cost of an extra bounded lookup per facet request — acceptable given the response is cached.
- **Cache invalidation via existing module/element mutation hook vs. a new event listener:** Reusing `ViewService`'s existing invalidation hook avoids a second listener reacting to the same underlying object-save events (the "orphaned capability" failure class this fleet has hit before — a second, independently-wired listener is an easy place for cache staleness to silently regress). Confirmed as the intended approach; final wiring point identified during implementation.
