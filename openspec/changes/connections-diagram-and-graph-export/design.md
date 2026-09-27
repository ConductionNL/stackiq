# Design: connections-diagram-and-graph-export

Read at development `49e65cb4`, with `@conduction/nextcloud-vue` 2.57.1 (`package.json:45`).

## Context

The connection data is complete in the `connection` schema (`lib/Settings/softwarecatalogus_register.json:3565`): `moduleA` (:3689), `moduleB` (:3705), `nonMunicipalProvision` (:3720), `type`, `dataExchangeDirection` (:3676) and `status`. `connections-catalogue-pages` gives it an index page, a detail page and two lists on the application page. This change draws and exports what those pages list.

## D1. The application map uses CnRelationshipGraph

A new custom widget `ConnectionMapWidget` (`src/components/connections/ConnectionMapWidget.vue`), registered in `src/customComponents.js` and placed on `ModuleDetail` (`src/manifest.json:491`) as a body widget. It reads the application's connections through the object store (two queries, `moduleA` and `moduleB` equal to the application, as `connections-catalogue-pages` D2 does) and hands nodes and edges to `CnRelationshipGraph` (`src/components/CnRelationshipGraph/CnRelationshipGraph.vue` in the library) with `layout: 'radial'` and the application as root. Edge labels carry the transport type; the arrow direction follows `dataExchangeDirection`.

Rejected: a new graph library. `CnRelationshipGraph` covers one hop, which is what "what is this application linked to" asks (ADR-012).

## D2. The list diagram uses CnGraphCanvas read-only

The `Koppelingen` index page gets a "Diagram" toggle next to the table, rendered by a custom component `ConnectionsDiagram` (`src/components/connections/ConnectionsDiagram.vue`). It takes the rows the index page fetched (same filters, same page) and draws them with `CnGraphCanvas` with `interactive: false`. Node positions come from a small layered layout in `src/utils/connectionLayout.js`: applications that only send on the left, that only receive on the right, the rest in the middle, sorted by name. No new dependency: Vue Flow already ships with the library.

Rejected: a force layout. It moves nodes on every render and needs a layout engine the library chose not to carry (`CnRelationshipGraph.vue:90`).

## D3. Downloads are client-side

The map widget's action menu offers "Download as SVG", "Download as PNG" and "Download links as CSV". SVG serialises the rendered `svg` element; PNG draws it on a canvas; CSV lists one line per connection with application A, direction, application B or national provision, type and status. Nothing goes to the server, so the download follows exactly what the user may read.

## D4. Connections in the organisation ArchiMate export

- `SettingsController::exportOrgArchiMate()` (`lib/Controller/SettingsController.php:1685`) reads a fourth option `connections` next to `modules`, `deelnames` and `usage` (:1698-1700), default false.
- `ArchiMateService::exportOrgArchiMate()` (`lib/Service/ArchiMateService.php:302`) passes it on. A new private method in `lib/Service/ArchiMateExportService.php` loads the connections whose `moduleA` or `moduleB` is an application of the organisation, and writes each as an ArchiMate `Flow` relationship in the model's `relationships` section (the section writer at `:1160` and `:1197`), source and target by the exported application component identifiers, with properties for transport type, direction and status. A bi-directional connection writes two flows. A connection to a national provision targets that element's identifier.
- A flow whose end is not in the exported model is skipped and counted in the export result.
- `src/views/settings/sections/ArchiMateImportExport.vue:571` gains a fourth checkbox, "Connections".

Rejected: a new export endpoint for connections only. The GEMMA Softwarecatalogus exports "pakketten én koppelingen in 1 model", and one file is what Archi opens.

## Declarative versus imperative

The map and the diagram are views over data the schema already holds; they add no relation or lifecycle behaviour. The export is imperative because it is: the ArchiMate writer is a PHP service today.

## Seed data

No schema change. The three demo connections from `connections-catalogue-pages` are enough to draw a map.

## Risks

- `ArchiMateExportService.php` is large (the export path around `:2734`). The new method stays separate and is unit tested against a fixture.
- The list diagram draws only the fetched page; the toggle says so.
