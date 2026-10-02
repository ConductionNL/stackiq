---
kind: code
depends_on: []
---

# One application page with versions, usages, contracts and compliance

## Summary

The application page becomes the one place to read an application: its data with the right field names, the supplier's contact person, its versions, the organisations' usages, the contracts behind it and its compliance claims. It also opens from the Applications list, which it does not today.

## Why

Rows from the stackiq matrix:

- `stackiq:land-detail-page`, "Open one application and see its versions, usages, contracts and compliance on one page." Rated partial, built. Two competitors rate yes: SAP LeanIX (https://help.sap.com/docs/leanix/ea/application-modeling-guidelines, the application fact sheet with relations and cost on one page) and BlueDolphin (https://help.bluedolphin.io/en/articles/11967521-object-viewer, "shows all the object properties ... relationships, history"). The missing half: contracts on the page, opening it from the Applications list, and the stale field keys. Core area (landscape).
- `stackiq:ctr-per-application`, "See the contracts behind an application from that application's page." Rated no. Two competitors rate yes: SAP LeanIX (https://help.sap.com/docs/leanix/ea/contract-extension-to-meta-model, "Attached to Contract - Application Many-to-Many") and GLPI (source read at 11.0.9, `src/Appliance.php:99` adds the Contracts tab listing every contract of the application).
- `stackiq:mkt-contacts-per-product`, "Name different contact persons per product a supplier offers." Rated partial, built, no competitor yes and no demand. It rides with `land-detail-page`: its missing half is the stale `contactpersoon` key on the application page, which this change corrects.

No tender, feature request or roadmap row names these rows.

## What stackiq has today

- `ModuleDetail` (`src/manifest.json:491`) has a data widget, files, a generic related panel, compliance claims and versions (:500-504), and a reviews panel.
- The data widget includes `beschrijvingKort`, `beschrijvingLang` and `contactpersoon` (:500). The module schema renamed them to `shortDescription`, `longDescription` and `contactPerson` (`lib/Settings/softwarecatalogus_register.json:6779` schema), so the descriptions and the contact person do not render. SuiteDetail carries the same stale keys (:683).
- The compliance list's column `bioMaatregel` (:503) names a key the `compliancy` schema calls `bioMeasure`, so that column stays empty.
- Usages show only as untyped entries in the related panel (:502).
- No contract list: `catalogContract` points at a `service` and a `usage` (`register.json:3252` schema), not at the application, so a one-hop list cannot reach it.
- The Applications list (`src/views/FacetedCatalogIndexView.vue:108`) renders `CnIndexPage` with no `@row-click` handler, so clicking a row or View does nothing. The page opens only from an organisation's list (`src/manifest.json:417`, `rowRoute: ModuleDetail`).

## What this change builds

1. Correct field keys on ModuleDetail and SuiteDetail, and the compliance column key.
2. A Usages list on the application page, filtered on `usage.module`.
3. A Contracts list on the application page: contracts whose usage is a usage of this application, or whose service offers this application.
4. Opening the application page from the Applications list, by row click and by the View action.

## Out of scope

- Registering a usage and its status on a usage page: `landscape-usage-registration`.
- Business and technical owners: `landscape-usage-registration`.
- Connections and APIs on the page: `connections-catalogue-pages`, `connections-api-catalogue`.
- A detail page for services (the Services list has none today); no matrix row asks for it in this pass.

## Risks

- Three changes in this pass add widgets to `ModuleDetail`. This one reorders the grid first; the others stack below.
- A contract visible through a service may belong to another organisation. The list shows only contracts the user may read under the `catalogContract` read rule.
