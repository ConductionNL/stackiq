---
kind: code
depends_on: []
---

# Supplier as a facet on the Applications and Services pages

## Summary

A municipal information manager on the Applications page can narrow the list by reference component, standard, application service and domain, with a count next to every value. They cannot narrow it by supplier, which is the other example the matrix row names and the first facet GEMMA Softwarecatalogus users expect. This change adds Supplier as a fifth facet on `/modules` and `/diensten`, computed by the same service, counted the same way and kept in the URL and in saved views like the other four.

## Why

This change covers one matrix row.

- `stackiq:ins-faceted-search`, "Search the catalogue and narrow the results with facets such as reference component and supplier." Stackiq rates itself partial: search and the four GEMMA facets work, and supplier is only a column. GEMMA Softwarecatalogus rates yes: "Aan de linkerkant staan zogenaamde filter mogelijkheden. Deze werken ook in combinatie ... Achter de te zetten filters staat een getal" (https://www.softwarecatalogus.nl/node/13683), and its package version list offers the facets Leverancier, Standaard, Referentiecomponent, Status planning, Domein, Doelgroep and Bedrijfsfunctie (https://www.softwarecatalogus.nl/pakketversies). SAP LeanIX rates yes: "Apply a filter using the facet filter column" (https://help.sap.com/docs/leanix/ea/filtering-in-report-urls), with inventory filters by lifecycle, subscription, tags and fields (https://help.sap.com/docs/leanix/ea/advanced-filter-options). BlueDolphin rates yes: "narrow down repository data based on object properties" (https://help.bluedolphin.io/en/articles/11967727-add-filters-to-the-data). GLPI rates yes from its source at 11.0.9: every list has a criteria builder (`src/Glpi/Search/Input/QueryBuilder.php:72`), for example on the manufacturer of an appliance.

The row is partial and built: this change builds the missing half, supplier as a facet.

## What stackiq has today

Read at development 49e65cb4.

- `lib/Service/FacetService.php:109` `DIMENSIONS` is `referenceComponent`, `standard`, `applicationService` and `domain`. `GET /api/facets/{schema}` (`appinfo/routes.php:195`) returns counts for those four and the matched object ids.
- The same four names are repeated in `lib/Controller/FacetController.php:147` `parseFilters()`, `src/services/facets.js:22` `FACET_DIMENSIONS` and `src/views/FacetedCatalogIndexView.vue:149` `DIMENSION_LABELS`. The comment at `src/services/facets.js:22` records that the set once drifted between frontend and backend and filtering by standard silently returned everything.
- `module.provider` (`lib/Settings/softwarecatalogus_register.json:6921`) is a related `organization`, titled Supplier, marked `facetable: true`. `catalogService.provider` (`:1436`) is the same relation, titled Provider. On both pages provider is only a column (`src/manifest.json` Modules and Diensten `columns`).
- The archived change 2026-07-23-gemma-faceted-search scoped the sidebar to the four GEMMA dimensions. It records no reason for leaving supplier out; supplier is simply not a GEMMA dimension.
- In the demo descriptor `lib/Settings/stackiq_mock_register.json` every module and service has an empty `provider` (`{}`), so no supplier facet would show a value on a demo instance.

## What this change builds

- A fifth dimension, `supplier`, in `FacetService`: for an application the organisation in `module.provider`, for a service the organisation in `catalogService.provider`, shown by the organisation's name.
- The same name in the controller, the frontend dimension list and the label map, so the set moves together.
- Supplier in the facet sidebar on `/modules` and `/diensten`, with counts that follow the other facets and the search, a `_gf_supplier` key in the URL, and saved views that keep it.
- Demo modules and services with a supplier, so the facet shows values on a fresh instance.

## Out of scope

- The other GEMMA Softwarecatalogus facets (Status planning, Doelgroep, Bedrijfsfunctie). Each needs its own data decision.
- The supplier of the applications behind a service. A service facet uses the service's own provider, which is what the Services list shows.
- Changes to how organisations get their name. Organisation identity can live in Nextcloud Contacts through `contactsUid`; this change reads `organization.name` and falls back as described in the design.

## Risks

- An organisation without a `name` shows under its identifier, the same fallback the reference component facet uses. The demo data names every organisation.
- Facet answers are cached for 30 minutes (`lib/Service/FacetService.php:77`). The cache key gets the dimension list, so an answer cached before the deploy, without supplier, is not served after it.
