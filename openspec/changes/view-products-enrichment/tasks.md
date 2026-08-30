# Tasks — view-products-enrichment

## 1. Establish the domain data model

- [ ] 1.1 Confirm in `lib/Settings/softwarecatalogus_register.json` which
  schema represents a "product" in this catalog's domain — if a dedicated
  `product`-style schema does not exist, decide (and record in `design.md`)
  whether products are: (a) an existing schema (e.g. a `product` field on
  `dienst`/`module`), or (b) a new minimal schema. Prefer (a) — do not invent
  a parallel concept if one already fits (ADR-022 spirit: reuse, don't
  duplicate).
- [ ] 1.2 Confirm the node-linkage field products use to connect to an
  ArchiMate view node (mirror `elementRef`, the field `getModulesData()`
  already indexes modules by — `lib/Service/ViewService.php:679`).

## 2. Backend: real products query

- [ ] 2.1 Rewrite `ViewService::getProductsData()`
  (`lib/Service/ViewService.php:576-581`) to query via `ObjectService::searchObjects()`
  scoped to `getCurrentOrganisation()`, following the exact pattern
  `getModulesData()` uses (register/schema resolution via `SettingsService`,
  organisation filter in the query `@self.organisation`, plus the defensive
  post-filter for entries the query didn't catch). Index the result by the
  node-linkage field decided in 1.2.
- [ ] 2.2 Rewrite `ViewService::getNodeProducts()`
  (`lib/Service/ViewService.php:1205-1216`) to actually look up
  `$productsData[$modelNodeId]` (or filter by the chosen linkage field) and
  return the matched product list plus `available_products_count` — the
  return shape callers already depend on stays the same, only the values
  become real.
- [ ] 2.3 Remove the `@SuppressWarnings(PHPMD.UnusedFormalParameter)` on
  `getNodeProducts()` now that `$productsData` is used.
- [ ] 2.4 Remove the "(placeholder implementation)" docblock language on
  `getProductsData()`.

## 3. Frontend: wire the Product filter toggle

- [ ] 3.1 Locate the view detail component that renders the existing
  Gebruik/Deelnames filter toggles (per `view-enrichment-api` spec scenarios)
  and confirm whether a "Product" toggle exists in the UI already.
- [ ] 3.2 If missing, add the "Product" toggle following the same
  component/store pattern as the Gebruik/Deelnames toggles, sending
  `include_products=true` on the view fetch (per
  `openspec/specs/view-enrichment-api/spec.md:60-63`).
- [ ] 3.3 Render the `products` key on enriched nodes in the view detail UI
  (list/count badge), matching how `modules`/`gebruik` overlays are rendered.

## 4. Tests

- [ ] 4.1 `ViewServiceTest`: `getProductsData()` returns organisation-scoped
  product entries (not `[]`) when the register/schema resolves and products
  exist; returns `[]` when the schema is unconfigured (graceful, not fatal).
- [ ] 4.2 `ViewServiceTest`: `getNodeProducts()` correctly matches products
  linked to a given node and excludes unrelated products; `available_products_count`
  reflects the matched count, not the total `$productsData` count.
- [ ] 4.3 Controller-level test: `GET /api/views/{viewId}?include_products=true`
  returns non-empty `products` arrays on nodes that have linked products.
- [ ] 4.4 Playwright (or existing e2e suite) coverage for the new/wired
  Product filter toggle round-trip, per ADR — gate-19 e2e traceability for the
  MODIFIED scenario below.

## 5. Spec

- [ ] 5.1 Sync this change's spec delta into
  `openspec/specs/view-enrichment-api/spec.md` on archive.
