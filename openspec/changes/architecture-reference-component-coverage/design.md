# Design: architecture-reference-component-coverage

Read at development 49e65cb4. Line numbers below are from that sha. The read-only rendering of a GEMMA view (`src/utils/viewGraph.js` on `CnGraphCanvas`) comes from `architecture-views-editor` (its D3 and D4).

## Where it fits

| Layer | Touched | Read at |
|---|---|---|
| Derivation | new `lib/Service/ReferenceComponentCoverageDerivation.php` | pure, like `lib/Service/PortfolioReportDerivation.php` |
| Service | new `lib/Service/ReferenceComponentCoverageService.php` | reads usages the way `PortfolioReportService::buildRows` does (:229) |
| Service | `lib/Service/PortfolioReportService.php` `buildRow` (:284), `buildReport` (:138), `buildCsv` (:166) | overlap per row, in the summary and the CSV |
| Access check | new `lib/Service/OrganisationReportAccess.php`, taken from `PortfolioReportController::isAuthorisedForOrganisation` (`lib/Controller/PortfolioReportController.php:139`) | both report controllers call it |
| Controller and route | new `lib/Controller/ReferenceComponentCoverageController.php`, route `referenceComponentCoverage#index` at `GET /api/reference-component-coverage` next to `portfolioReport#index` (`appinfo/routes.php:303`) | |
| Pages | new `src/manifest.d/reference-component-coverage.json` with the custom page `ReferenceComponentCoverage` (`/reference-component-coverage`) and a second card on `Reports` (`src/manifest.json:1027`) | |
| Views | new `src/views/organisaties/ReferenceComponentCoverage.vue` and `src/views/organisaties/CoverageViewMap.vue`; `src/views/organisaties/PortfolioReport.vue` gains an overlap section | registered in `src/customComponents.js` next to `PortfolioReportView` (:32, :137) |
| Register | none | |

## Decisions

### D1. Coverage is counted on the organisation's usages

A reference component is covered by a usage of the organisation when the usage names it in `usedForReferenceComponents` (`lib/Settings/softwarecatalogus_register.json:2982`) and its `status` is not Acquisition, Planned or Phased out. The derivation gives each reference component one state:

| State | Rule |
|---|---|
| gap | no counting usage |
| covered | one counting usage |
| overlap | two or more counting usages with different modules |

Two usages of the same module (two versions side by side during a migration) are one application for this count. A gap is also marked fillable when a module the organisation already uses declares the component in `module.referenceComponents` (:6992). That is the GEMMA Softwarecatalogus tile "Pakketten met meer mogelijkheden".

Rejected: counting on `module.referenceComponents`. That says what a product can do, not what the municipality uses it for, and it would mark a component covered by a product the municipality bought for something else.

### D2. A bounded, organisation-scoped backend endpoint

`ReferenceComponentCoverageService::build(organisationUuid)` reads the organisation's usages with the same query `PortfolioReportService::buildRows` uses (`consumer` equal to the organisation, bounded by the page size ceiling, :544) and the reference components with `gemmaType=referentiecomponent`, the query the schemas use for their pickers (register.json:2993), bounded at 1,000 as `FacetService::ELEMENT_LOOKUP_LIMIT` is (`lib/Service/FacetService.php:95`). It resolves module names once per module, like `PortfolioReportService::fetchRelation` (:482), and hands everything to the derivation.

`GET /api/reference-component-coverage?organisation=<uuid>&format=json|csv` returns `{ organisation, generatedAt, truncated, usagesWithoutComponent, summary: { gap, fillable, covered, overlap }, components: [{ uuid, name, state, fillable, usages: [{ uuid, moduleName, status }] }] }`, or the same rows as CSV.

The controller runs the organisation check before any query and fails closed, as `PortfolioReportController::index` does (:86). The check moves into `OrganisationReportAccess::isAuthorised(user, organisationUuid)` and both controllers call it, so the two reports keep one rule.

Rejected: computing coverage in the browser from OpenRegister facets on `usage.usedForReferenceComponents`. A facet has no bucket for a value no row holds, so gaps need the full component list anyway, and overlap needs the usages behind each count. The portfolio report moved the same kind of cross-register join to the backend for the same reason (its manifest note, `src/manifest.json:1040`).

### D3. The page: summary, table and map

`ReferenceComponentCoverage.vue` follows `PortfolioReport.vue`: the same organisation picker (:61), Refresh and Export CSV buttons, and a truncation notice. Under that:

- a summary with the four counts and "N applications in use name no reference component",
- a `CnDataTable` of components with columns name, state, applications and fillable, and quick filters All, Gaps, Fillable gaps and Overlap,
- `CoverageViewMap.vue`: a picker of imported GEMMA views from `GET /api/views` (`appinfo/routes.php:185`), and the chosen view drawn read-only through `viewGraph.js` on `CnGraphCanvas` (`@conduction/nextcloud-vue` 2.57.1, `src/components/CnGraphCanvas/CnGraphCanvas.vue`, `readOnly` at :196). Every node whose element is a reference component gets its state: a border in `--color-error` (gap), `--color-success` (covered) or `--color-warning` (overlap), and a text badge with the count and the application names, so colour is never the only signal.

The page is reached from a second card on the Reports page, "Reference component coverage", next to Portfolio rationalization. No menu entry is added (ADR-097).

Rejected: drawing the map with `ViewService` enrichment (`include_gebruik`). It groups usages by the single field `elementRef` (`lib/Service/ViewService.php:914`), so a usage for three components lands on at most one node.

### D4. Overlap in the portfolio report

`PortfolioReportService::buildRow` adds `referenceComponents` (the names the usage names) and `overlapsWith`: for each shared component, the other modules that cover it. The derivation computes this from the rows `buildRows` already fetched, so the report makes one extra bounded read, the component names. `buildReport` adds `overlap` (the number of rows with at least one overlap) to its payload, and `buildCsv` adds the column `overlapsWith` as `component: module | module`. `PortfolioReport.vue` shows an Overlap section under the quadrant summary that lists each overlapping component with its applications, and marks overlapping rows in the row list. The card text "Overlapping and ageing software across the portfolio." (`src/manifest.json:1031`) then describes what the report does.

## Declarative versus imperative

This change adds aggregation, so ADR-031's declarative route was checked first.

- OpenRegister facets on `usage.usedForReferenceComponents` count usages per component that has one, but return no bucket for a component nobody uses, and gaps are exactly those.
- OpenRegister's aggregation primitive omits empty buckets by contract ("buckets with zero rows SHALL be omitted from the response", `openregister-ro/openspec/specs/aggregation-api/spec.md:32`) and groups one collection, while coverage joins usages in `stackiq` with reference components in `vng-gemma`.

So the join is imperative, in a pure derivation class with its own unit tests, behind one bounded endpoint. No lifecycle, notification or relation is added, and no schema changes.

## Risks

- **Seeded status values.** The three seeded usages hold `status` `in-gebruik` (register.json `components.objects`), which is not an enum value. The derivation counts any status other than Acquisition, Planned and Phased out, so an unknown value counts as in use rather than hiding an application.
- **No GEMMA import.** Without an imported model there are no reference components. The page then shows "Import the GEMMA model to see coverage" instead of an empty table, and the portfolio report shows no Overlap section.
- **`elementRef` enrichment.** `ViewService` keeps grouping on `elementRef`. A later change can move it to `usedForReferenceComponents`; this change does not depend on it.
- **Large organisations.** A usage ceiling that truncates also truncates coverage. The page shows the truncation notice the portfolio report shows.
