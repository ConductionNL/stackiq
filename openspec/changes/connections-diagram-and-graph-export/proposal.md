---
kind: code
depends_on:
  - connections-catalogue-pages
---

# See connections as a diagram and export the link graph

## Summary

An application owner can see an application's connections drawn as a map on its page, and an information manager can see the filtered connections list as a diagram. The link graph leaves stackiq in two forms: the application map as an image and a CSV of its links, and the connections inside the organisation's ArchiMate export, so Archi and other modelling tools receive them.

## Why

Rows from the stackiq matrix:

- `stackiq:conn-diagram`, "See the connections between applications drawn as a diagram." Rated no. Four competitors rate yes: SAP LeanIX (https://help.sap.com/docs/leanix/ea/data-flow, data flow diagrams "understand how applications are connected"), BlueDolphin (https://help.bluedolphin.io/en/articles/11967472-welcome-to-bluedolphin, "visualize chains and information flows"), GLPI (source read at 11.0.9, `src/Impact.php:252` displayGraphView draws the relation network) and TOPdesk (https://docs.topdesk.com/en/linking-assets-to-other-assets.html, "the graphical overview to see a visual representation of their relationship"). Core area (connections).
- `stackiq:conn-export-graph`, "Export the graph of what an application is linked to, for use elsewhere." Rated no. Two competitors rate yes: GEMMA Softwarecatalogus (https://www.softwarecatalogus.nl/Handleiding%20koppeling%20architectuurtools, "Pakketten én koppelingen worden in 1 model geëxporteerd ... AMEFF-export") and GLPI (source read at 11.0.9, CSV export in `front/impactcsv.php` and PNG or JPEG download at `js/impact.js:2528`). Core area.

No tender, feature request or roadmap row names these rows.

## What stackiq has today

- No page draws connections. `src/store/modules/view.js` calls `GET /api/views` and nothing imports it.
- The organisation ArchiMate export (`GET /api/archimate/export/organization/{organizationUuid}`, `appinfo/routes.php:98`, `lib/Controller/SettingsController.php:1685`) takes the options `modules`, `deelnames` and `usage` (:1698-1700) and writes GEMMA view copies with the organisation's applications (`lib/Service/ArchiMateExportService.php:2734`). It writes no catalogue connection: the only `connection` handling in the export service is a diagram line inside a copied view (`:632-634`, `:742`).
- The export is reached from the admin settings section (`src/views/settings/sections/ArchiMateImportExport.vue:571`), with checkboxes Modules, Deelnames and Gebruik, for a Nextcloud admin or an organisation admin (`SettingsController.php:1737`).
- `@conduction/nextcloud-vue` 2.57.1 ships `CnRelationshipGraph` (an SVG graph with radial, grid and manual layouts) and `CnGraphCanvas` (a Vue Flow canvas).

## What this change builds

1. A connection map on the application page: the application in the centre, every application or national provision it connects to around it, each line labelled with transport and direction. Clicking a node opens that application or the connection.
2. A diagram view on the connections list (`Koppelingen`, from `connections-catalogue-pages`), drawing the rows the current filters return.
3. Downloads from the application map: the drawing as SVG and PNG, and the links as CSV.
4. A `connections` option on the organisation ArchiMate export, writing each connection of the organisation as an ArchiMate flow relationship between the application components, with transport type, direction and status as properties.

## Out of scope

- Drawing or editing architecture views: `architecture-views-editor`.
- Making the organisation export reachable outside admin settings (`stackiq:arch-export-org`, deferred as partial, built, no demand).
- Impact analysis before retiring an application (`stackiq:conn-impact-analysis`, owned by openregister).

## Risks

- A large landscape gives an unreadable diagram. The list diagram caps at the page size and asks the user to filter.
- Archi reads flow relationships only between elements it knows. The export writes a flow only when both ends are in the exported model, and counts the ones it skipped.
