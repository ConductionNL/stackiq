---
kind: code
depends_on: []
---

# Connections get their own pages

## Summary

Stackiq stores the connections (koppelingen) between applications, but no page lists them, opens one, or shows them on the application they belong to. This change adds a Connections index page and a connection detail page, a filter on transport type, a connections section on the application page, and a working picker for the national provision a connection reaches.

## Why

Rows from the stackiq matrix (`openspec/parity/capabilities.json`):

- `stackiq:conn-list-page`, "Browse all connections in the catalogue in one list and open each one." Rated no and marked specified with no change directory, so this is the missing change. GEMMA Softwarecatalogus rates yes: https://www.softwarecatalogus.nl/node/13683, "Alle koppelingen ... staan de koppelingen van alle gemeenten en samenwerkingsverbanden". SAP LeanIX rates yes: https://help.sap.com/docs/leanix/ea/interface-modeling-guidelines, interfaces are fact sheets listed in the inventory.
- `stackiq:conn-type-filter`, "Filter connections by type, such as an API, a file exchange or a message." Rated no, marked specified with no change directory. SAP LeanIX rates yes on the same page: interface subtypes and transfer type are filterable in the inventory. Core area (connections).
- `stackiq:conn-per-application`, "See every connection an application has, from that application's own page." Rated partial, built. Three competitors rate yes: SAP LeanIX (https://help.sap.com/docs/leanix/ea/circle-map-report, relations on the fact sheet), BlueDolphin (https://help.bluedolphin.io/en/articles/11967550-working-with-object-relationships, the Relationships tab) and GLPI (source read at 11.0.9, `src/Impact.php:91`, an impact analysis tab listing related items in both directions). The missing half is a connections section on the application page that opens each connection.
- `stackiq:conn-external-provision`, "Record a connection from an application to a national provision such as a basisregistratie." Rated partial, built, one competitor yes: GEMMA Softwarecatalogus (https://www.softwarecatalogus.nl/Opvoeren%20koppeling%20iJw%20en%20iWmo). It rides with `conn-list-page`: its missing half is a page to fill the field, and the connection form on the new page shows it.

No tender, feature request or roadmap row names these rows. `conn-list-page` and `conn-type-filter` sit in the product's core area.

## What stackiq has today

- The `connection` schema (`lib/Settings/softwarecatalogus_register.json:3565`, version 0.3.1) holds name, transport `type`, `status`, four lifecycle dates, `dataExchangeDirection` (:3676), `moduleA` (:3689), `moduleB` (:3705), `nonMunicipalProvision` (:3720), `standardVersions` and `realisedWithIntermediaryModule`.
- No manifest page uses this schema. The Integrations page (`src/manifest.d/connection-registry.json:23`) lists integriq's `app_connection`, which its `_note` says is a different thing.
- The application page `ModuleDetail` (`src/manifest.json:491`) shows connections only as untyped entries in the generic related panel `md-related` (:502).
- `GET /api/koppelingen-gebruik/{uuid}` (`appinfo/routes.php:269`, `lib/Controller/AangebodenGebruikController.php:208`) returns an application's connections and usages. Nothing in `src/` calls it.
- Two register defects would break the pages even once they exist:
  - The connection lifecycle (`register.json:3926`) names `in ontwikkeling`, `in gebruik`, `einde ondersteuning` and `teruggetrokken`. The status enum and the migrated rows (`lib/Repair/RenameDutchCatalogValues.php:87-90`) hold `in development`, `in use`, `end of support` and `withdrawn`, so no transition matches any row.
  - The `nonMunicipalProvision` picker filters on `gemmaType=Buitengemeentenlijke voorziening` (`register.json:3720`). The GEMMA model spells it `Buitengemeentelijke voorziening` (59 times in `lib/Settings/GEMMA_release.xml`), so the picker finds nothing.

## What this change builds

1. A Connections index page (`Koppelingen`, `/koppelingen`) over the `connection` schema, with a filter on transport type and quick filters on status.
2. A connection detail page (`KoppelingDetail`, `/koppelingen/:id`) with the connection data, both applications, the national provision, standards, documents, history and the status transitions.
3. A connections section on the application page: the connections that start at the application and the ones that end there, each row opening the detail page.
4. Register fixes: lifecycle states that match the enum, the picker's GEMMA type, `type` and `status` marked facetable, and the schema version bumped so the edit deploys.
5. A menu entry for Connections under Applications, without a new top-level entry (ADR-097).

## Out of scope

- Drawing connections as a diagram and exporting the link graph: `connections-diagram-and-graph-export`.
- The APIs an application exposes: `connections-api-catalogue`.
- Deriving connections automatically: `connections-derived-dependencies`.
- integriq's `app_connection` and the Integrations page: open change `adopt-connection-registry`.
- The public frontend's own connection form (`README.md:273`, a separate repository).

## Risks

- `landscape-application-page` also edits the `ModuleDetail` grid. Whichever change lands second rebases the layout rows.
- A lifecycle edit without a schema version bump never deploys (register changelog 2.4.4, `register.json:7`).
