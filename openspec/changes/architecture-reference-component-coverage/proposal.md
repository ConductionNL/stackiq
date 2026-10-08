---
kind: code
depends_on:
  - architecture-views-editor
---

# Show which reference components your landscape covers, misses and doubles

## Summary

A municipal information manager opens a coverage report for their organisation. It lists every GEMMA reference component with the applications in use that fulfil it, and marks each one as a gap (no application), covered (one) or overlap (two or more). The same result is drawn on a GEMMA view of their choice, as a map. The portfolio rationalization report gains the overlap it promises on its card, so a reader sees overlapping and ageing software in one place.

## Why

This change builds four rows of the stackiq parity matrix. No tender or feature request names them.

- `stackiq:arch-capability-map`, "Map applications to business capabilities or functions and see the map." Rated partial, built. SAP LeanIX rates yes: "A business capability is supported by an application" and a "Business capability map" (https://help.sap.com/docs/leanix/ea/meta-model, https://help.sap.com/docs/leanix/ea/application-portfolio-assessment). BlueDolphin rates yes: "Drag and drop multi-layer current and future state capability mapping" (https://bluedolphin.io/capability-based-planning/). GEMMA Softwarecatalogus rates partial: packages are plotted "op een GEMMA architectuurkaart" (https://www.softwarecatalogus.nl/Hoe%20print%20ik%20een%20kaart%3F). The lane decided build: the missing half is a map view of applications on reference components inside stackiq.
- `stackiq:arch-gap-analysis`, "Find reference components that no application in your landscape covers." Rated no. GEMMA Softwarecatalogus rates partial with the tile "Pakketten met meer mogelijkheden" (https://www.softwarecatalogus.nl/Releasebrief%20GEMMA%20Softwarecatalogus%20versie%204.1), SAP LeanIX partial with a Matrix Report for "Coverage gap analysis" (https://help.sap.com/docs/leanix/ea/report-types), BlueDolphin partial: "Identify capability gaps" (https://bluedolphin.io/capability-based-planning/). Decided build: core area.
- `stackiq:life-overlap`, "Find applications that overlap because they fulfil the same reference component." Rated no. GEMMA Softwarecatalogus rates yes: "Deze tegel signaleert dat er meer dan 1 pakket(versie) bij eenzelfde referentiecomponent in productie is" (https://www.softwarecatalogus.nl/Releasebrief%20GEMMA%20Softwarecatalogus%20versie%204.1). BlueDolphin rates yes: "overlapping application functions are quickly made visible" (https://help.bluedolphin.io/en/articles/11967472-welcome-to-bluedolphin). Decided build: two competitors rate yes.
- `stackiq:life-rationalisation-report`, "Open a report of overlapping and ageing software for rationalisation." Rated partial, built. SAP LeanIX rates yes: "automated TIME classification, application portfolio and landscape reports ... to streamline application rationalization" (https://help.sap.com/docs/leanix/ea/application-rationalization-evaluate-data). This row rides with `stackiq:life-overlap`: its missing half is overlap in the portfolio report, which is the overlap this change computes.

## What stackiq has today

- The mapping exists in the data. `module.referenceComponents` (`lib/Settings/softwarecatalogus_register.json:6992`, module schema at :6777) says which reference components a product implements, and `usage.usedForReferenceComponents` (:2982) which ones the organisation uses it for. Both relate to `element` objects with `gemmaType=referentiecomponent`. The GEMMA release holds 168 reference components (`lib/Settings/GEMMA_release.xml`, elements of type `ApplicationComponent` with GEMMA type Referentiecomponent).
- `lib/Service/FacetService.php` counts modules per reference component across the catalogue (`DIMENSIONS`, :109, built in `buildDimensionValueMap`, :648). It never lists a component with no module, and it works on `module`, not on one organisation's usages.
- The view API enriches a view's nodes with the organisation's usages (`lib/Service/ViewService.php:813`, `getGebruikData`), but it groups usages by the single field `elementRef` (:914), not by `usedForReferenceComponents`, so a usage for three components lands on one node or none. No page renders a view (`architecture-views-editor`, What stackiq has today).
- The organisation export draws applications into copies of GEMMA views (`lib/Service/ArchiMateExportService.php:2734`, `copyAndEnrichViews`), so the map exists only in Archi after an admin export.
- The portfolio report (`GET /api/portfolio-report`, `appinfo/routes.php:303`) is built by `lib/Service/PortfolioReportService.php` from the organisation's usages (`buildRows`, :229) with TIME quadrants, EOL exposure, cloud share and cost, and a CSV (`buildCsv`, :166). No row carries a reference component. The Reports card still reads "Overlapping and ageing software across the portfolio." (`src/manifest.json:1031`).

## What this change builds

- `lib/Service/ReferenceComponentCoverageDerivation.php`, pure functions that turn usages and reference components into a coverage list with gap, covered and overlap states.
- `lib/Service/ReferenceComponentCoverageService.php` and `GET /api/reference-component-coverage`, organisation-scoped and bounded like the portfolio report, with a CSV.
- A Reference component coverage page with a table, filters for gaps and overlaps, and a map on a GEMMA view, reached from a new card on the Reports page.
- Overlap in the portfolio report: per row, the reference components it shares with another application in use, a count in the summary and a column in the CSV.

## Out of scope

- The organisation's own capability model. The map uses GEMMA reference components and GEMMA views, which is what municipalities share. A self-defined capability tree is a later change.
- Fixing `ViewService` enrichment on `elementRef`. The coverage map reads its own endpoint and leaves the view API as it is. The `elementRef` grouping is named in Risks.
- Planned future coverage. Usages in status Acquisition or Planned are shown but do not count as coverage. `architecture-future-state-scenarios` compares current and planned landscapes.
- The catalogue-wide facet counts on the Modules page, which stay as they are.

## Risks

- A usage that names no reference component covers nothing, so a municipality that never filled `usedForReferenceComponents` sees every component as a gap. The page says how many usages name no component, next to the gap count.
- The report reads usages with RBAC off after an organisation check, as the portfolio report does. The check is shared, not copied, so the two reports cannot drift.
