---
kind: code
depends_on: []
---

# softwarecatalog — implement view "products" enrichment (currently a no-op)

## Why

`openspec/specs/view-enrichment-api/spec.md` commits, as a MUST, to a
"Product filter" enrichment on ArchiMate view detail: enabling the toggle
MUST send `include_products=true` and the response MUST reflect it
(`openspec/specs/view-enrichment-api/spec.md:60-63`, `:178`). The backend
implementation is a hardcoded stub:

- `ViewService::getProductsData()` (`lib/Service/ViewService.php:576-581`) —
  docblocked "placeholder implementation", logs a debug line, and
  `return []` unconditionally. No register/schema query is ever made.
- `ViewService::getNodeProducts()` (`lib/Service/ViewService.php:1205-1216`) —
  docblocked `// TODO: Implement actual node products matching logic against
  $productsData`, and carries a PHPMD suppression
  (`@SuppressWarnings(PHPMD.UnusedFormalParameter) $productsData reserved for
  future node products matching`) confirming the parameter is never actually
  used to match anything.

The enrichment IS wired end-to-end at the plumbing level —
`shouldIncludeProducts()` reads `include_products` from the query
(`lib/Service/ViewService.php:500-503`), `getAppliedEnrichments()` reports
`'products'` (`:552-553`), and `enrichedNode['products']` is set on every
node (`:458-461`) — so `GET /api/views/{viewId}?include_products=true`
returns a `products` key on every node, and the API's self-documentation
(`view#getApiDocumentation`) and the `view-enrichment-api` spec both
present it as a real capability. But the data is always empty, so the
"Product filter" toggle the spec requires is currently indistinguishable
from doing nothing — a silently broken feature, not a missing one.

No frontend usage of `include_products` was found under `src/`, so the
toggle itself may not be built yet either — this change closes both ends
(the toggle UI and the backend query) so the spec's MUST scenarios are
actually true.

## What Changes

- Implement `ViewService::getProductsData()` to query the actual product
  entities via OpenRegister's `ObjectService` (ADR-022 — no bespoke store),
  scoped to the current organisation the same way `getModulesData()` already
  does (`lib/Service/ViewService.php:622+`), rather than returning `[]`.
  Establish (and document, in `design.md`) which register/schema/field
  constitutes a "product" in this app's domain — the softwarecatalog register
  config (`lib/Settings/softwarecatalogus_register.json`) is the source of
  truth; if no `product`-equivalent schema currently exists, this change adds
  the minimal one (or maps onto an existing schema, e.g. `dienst`/`module`
  variant, whichever the domain data model actually supports) rather than
  inventing a new parallel concept.
- Implement `ViewService::getNodeProducts()` to actually match `$productsData`
  against `$modelNodeId` (drop the `PHPMD.UnusedFormalParameter` suppression
  once the parameter is used), returning the same shape callers already expect
  (`available_products_count` plus the matched product list).
- Add (or wire up, if partially present) the frontend "Product" filter toggle
  that sends `include_products=true`, matching the existing Gebruik/Deelnames
  toggle pattern in the view detail UI.
- Unit tests: `ViewServiceTest` coverage for `getProductsData()` returning real
  organisation-scoped data and `getNodeProducts()` correctly matching/excluding
  products by node linkage (mirroring existing module-matching test coverage).
- Not BREAKING: the `include_products` parameter and `products` response key
  already exist; this only makes them return real data instead of always `[]`.
