# Design: landscape-application-page

Read at development `49e65cb4`, `@conduction/nextcloud-vue` 2.57.1.

## Context

`ModuleDetail` is a manifest detail page (`src/manifest.json:491`) with a grid of widgets (:500-511) and body widgets (:514). The Applications list is a custom page, `FacetedCatalogIndexView` (`src/manifest.json` page `Modules`, component at `src/views/FacetedCatalogIndexView.vue`), which wraps a standalone `CnIndexPage` beside a GEMMA facet sidebar.

## D1. Field keys

In `src/manifest.json`:

- `md-data.content.include` (:500): `beschrijvingKort` becomes `shortDescription`, `beschrijvingLang` becomes `longDescription`, `contactpersoon` becomes `contactPerson`.
- `suite-data.content.include` (:683): the same three renames.
- `md-compliance.content.columns` (:503): `bioMaatregel` becomes `bioMeasure`.

`tests/vitest/manifestFilterEnumParity.spec.js` already checks enum filters against the schema; a new `tests/vitest/manifestIncludeKeys.spec.js` asserts every `include` key and every `object-list` column key of a detail page exists on its schema, so a rename cannot leave a page blank again.

## D2. Usages list

A new `object-list` widget `md-usages`: register `@resolve:voorzieningen_register`, schema `usage`, filter `{ "module": "@objectId" }`, columns consumer, moduleVersion, status. The `usage` read rule (`register.json:2656` schema) already scopes rows: a municipality sees its own usages, a supplier sees usages of its products. No row route until `landscape-usage-registration` adds the usage detail page; that change sets `rowRoute`.

## D3. Contracts list

`catalogContract` reaches an application in two ways: through `usage` (a usage of the application) or through `service` (a service whose `modules` include the application, `catalogService.modules`). A single `object-list` filter cannot follow a hop (`CnObjectListWidget.vue:503`, one filter object).

A small custom panel `ApplicationContractsPanel` (`src/components/contracts/ApplicationContractsPanel.vue`, registered in `src/customComponents.js`) does it with the object store. Built 2026-09-29: the detail grid of `@conduction/nextcloud-vue` 2.57.1 renders only the library's widget types, so the panel is a `bodyWidgets` section (`placement: end`, before the reviews) instead of a grid widget. The two-hop lookup lives in `src/utils/applicationContracts.js`:

1. Fetch the application's usages (`usage`, `module` equals the id) and the services that offer it (`catalogService`, `modules` contains the id).
2. Fetch `catalogContract` with `usage` in the usage ids, and with `service` in the service ids, and merge by id.
3. Render a table with contract number, type, end date and status; the number links to `ContractDetail`. It reads with `fetchCollectionForOptions`, so it never overwrites a list the store drives.

Rejected: a denormalised `module` field on `catalogContract`. It would go stale when a usage or service changes its application, and needs a save hook to fill it.

## D4. Open from the Applications list

`FacetedCatalogIndexView.vue` gains a `detailRoute` prop, sets `rowClickToView` when it is given (the list is selectable, so a click would otherwise select), and binds `row-click` and `view` on its `CnIndexPage` (:108) to `$router.push({ name: detailRoute, params: { id } })` through `rowDetailLocation` in `src/utils/applicationContracts.js`. The `Modules` page config passes `detailRoute: "ModuleDetail"`; the `Diensten` page passes none, and its rows stay as they are. `CnIndexPage` emits `row-click` for register and schema pages (`CnIndexPage.vue:5500` onwards); only a named source routes by itself.

## D5. Layout

The grid becomes: data 8 wide with files and related at the right, then versions and usages side by side, then compliance full width. Contracts follow the grid as a body section (see D3). Body widgets (reviews) stay at the end. The layout follows ADR-062 (detail page grid discipline).

## Declarative versus imperative

D1, D2 and D5 are manifest edits. D3 is one custom widget because the relation is two hops; it reads through the object store and adds no endpoint (ADR-022).

## Seed data

No schema change.

## Risks

- `connections-catalogue-pages` and `connections-api-catalogue` add widgets to the same page; they append below this layout.
- The contract widget fires three queries per page view; each is scoped by ids and paged.
