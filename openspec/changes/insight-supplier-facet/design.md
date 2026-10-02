# Design: insight-supplier-facet

Read at development 49e65cb4, with `@conduction/nextcloud-vue` 2.57.1 and OpenRegister development 4fee776.

## Where it fits

| Part | File and line | What changes |
|---|---|---|
| Service | `lib/Service/FacetService.php:109` `DIMENSIONS` | adds `supplier`; `buildDimensionValueMap()` (`:648`) fills it; `computeFacets()` (`:898`) takes a label map; `buildCacheKey()` (`:360`) adds the dimension list |
| Service | `lib/Service/FacetService.php` new private `resolveSupplierLabels()` | one bounded batch read of the `organization` objects the base set points at, next to `fetchModulesByIdentifiers()` (`:587`) |
| Controller | `lib/Controller/FacetController.php:147` `parseFilters()` | adds `supplier` to its list |
| Client | `src/services/facets.js:22` `FACET_DIMENSIONS` | adds `supplier`, and `src/services/facets.spec.js:31` moves with it |
| View | `src/views/FacetedCatalogIndexView.vue:149` `DIMENSION_LABELS` | adds Supplier; the sidebar schema comes from `src/utils/facetSchema.js` `buildFacetDimensionSchema()`, so no template change |
| Store | `src/store/modules/facets.js` | nothing: it loops over `FACET_DIMENSIONS` (`:88`, `:135`, `:299`, `:334`), so the URL key `_gf_supplier` and saved views follow |
| Seed | `lib/Settings/stackiq_mock_register.json` | `provider` on the demo modules and services |

No new route, no schema change and no page change. The Modules and Diensten pages (`src/manifest.json:592` and `:647`) already mount `FacetedCatalogIndexView`.

## Decisions

### D1. Supplier is a FacetService dimension, not an OpenRegister facet

`module.provider` is `facetable: true` (`lib/Settings/softwarecatalogus_register.json:6921`), so OpenRegister could count it natively. But the list on these pages is narrowed by `{ id: matchedObjectIds }`, which `FacetService` computes over its own bounded base set (`lib/Service/FacetService.php:269`). A native provider count would be taken over a different set, would not narrow the other four facets and would not be narrowed by them. As a fifth `FacetService` dimension, supplier is counted disjunctively like the others (`computeFacets()` excludes a dimension's own selection when counting it, `:898` to `:930`), goes into `matchedObjectIds`, and uses the same cache and the same RBAC-scoped base set.

Rejected: turning on the library's own facet sidebar for provider next to `CnFacetSidebar`. The view explains why `CnIndexPage`'s embedded facets cannot share the narrowing (`src/views/FacetedCatalogIndexView.vue:11` to `:28`), and two sidebars would count two different sets.

### D2. The value is the organisation id, the label is its name

The four existing dimensions use the display name as both value and label (`computeFacets()` writes `'value' => $value, 'label' => $value`). For supplier that would merge two organisations with the same name and break a saved view when a supplier is renamed. So the supplier value is the organisation id from `provider` (read with `extractRelatedIdentifiers()`, `:988`, which accepts a uuid string or an object with `id`), and the label is `organization.name` from one bounded batch read (`_limit` `ELEMENT_LOOKUP_LIMIT`, `:95`), through the organisation schema id in the voorzieningen config (key `organisatie_schema`, `lib/Service/SettingsService.php:135`). An organisation without a name gets its id as label, the fallback `elementDisplayName()` uses (`:1055`).

`computeFacets()` gets an optional label map per dimension; the other four pass none and keep `label = value`.

Rejected: resolving the name from Nextcloud Contacts through `contactsUid`. `organization.name` already mirrors that identity and is what the ArchiMate export writes (its description in the register), and a Contacts lookup per supplier per facet request would be a second, unbounded source.

### D3. A service facets on its own provider

For `/diensten`, `FacetService` resolves the GEMMA dimensions through the service's linked modules (`resolveModulesPerObject()`, `:532`). Supplier does not follow that path: it reads `catalogService.provider` (`:1436` in the register) from the service itself, because that is the Provider column the Services list shows next to it. Reading the modules' suppliers would put a service under a supplier the list does not show.

### D4. The set moves together, and the tests prove it

The comment at `src/services/facets.js:22` records a drift where the frontend sent `standard[]` to a backend still reading `standaard[]`. This change moves all four declarations in one commit. `tests/Unit/Service/FacetServiceTest.php` asserts `DIMENSIONS` and `FacetController::parseFilters()` hold the same five names, and `src/services/facets.spec.js` asserts `FACET_DIMENSIONS` holds them in display order.

## Declarative versus imperative

This is aggregation, and it stays imperative in `FacetService`. The GEMMA facets are stackiq's own computation over linked `element` objects, which no `x-openregister-aggregations` rule can express (ADR-031), and supplier must share that computation's base set and narrowing (D1).

## Seed data

No schema changes. The demo descriptor `lib/Settings/stackiq_mock_register.json` gets a `provider` on the modules and services it seeds in the `stackiq` register (the file repeats the same slugs under `vng-gemma`; those copies stay as they are), written as OpenRegister seed references (`@ref:<slug>`, resolved on import by `lib/Service/Configuration/ImportHandler.php:3343` in OpenRegister).

| Object slug | Schema | `provider` |
|---|---|---|
| module-voorbeeld-name-1-1 | module | `@ref:organization-voorbeeld-name-2-2` |
| module-voorbeeld-name-2-2 | module | `@ref:organization-voorbeeld-name-2-2` |
| module-voorbeeld-name-3-3 | module | `@ref:organization-voorbeeld-name-3-3` |
| service-voorbeeld-name-1-1 | catalogService | `@ref:organization-voorbeeld-name-2-2` |
| service-voorbeeld-name-2-2 | catalogService | `@ref:organization-voorbeeld-name-2-2` |
| service-voorbeeld-name-3-3 | catalogService | `@ref:organization-voorbeeld-name-3-3` |

On a fresh demo instance the Supplier facet then shows Voorbeeld Name 2 with 2 and Voorbeeld Name 3 with 1 on `/modules`.

## Risks

- **Cached four-dimension answers.** The cache lives 30 minutes (`CACHE_TTL`, `:77`) and its key today holds schema, filters, search, organisation and user (`:360` to `:385`). The dimension list joins the key, so a response without `supplier` is never served after the deploy.
- **Many suppliers.** A catalogue with hundreds of suppliers gives a long facet. `CnFacetSidebar` already lists values by count, highest first (`arsort` in `computeFacets()`), so the common suppliers lead.
- **Organisation reads.** The label read is one extra bounded query per cache miss, not one per object.
