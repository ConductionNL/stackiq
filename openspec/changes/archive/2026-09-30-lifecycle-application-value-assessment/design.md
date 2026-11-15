# Design: lifecycle-application-value-assessment

Read at development `9a5ece6a` (after the batch 1 merge), OpenRegister development `4fee776`.

## Context

The TIME class lives per usage (`usage.timeClassification`, `timeRationale`, `timeReviewDate`, `lib/Settings/softwarecatalogus_register.json`, usage schema at :2656). `PortfolioReportService::buildRows()` (`lib/Service/PortfolioReportService.php:229`) builds one row per usage of the selected organisation with lifecycle phase, EOL state, cloud share and annualised cost (`PortfolioReportDerivation::annualisedCost`, `lib/Service/PortfolioReportDerivation.php:163`), and `aggregateQuadrants()` (:381) sums them per TIME class. `landscape-usage-registration` gives the usage its own page.

## D1. Scores on the usage, in a fragment

`lib/Settings/register.d/value-assessment.json` adds to `usage`:

| property | type | notes |
|---|---|---|
| `businessValue` | integer 1 to 5 | "How much does the organisation depend on it" |
| `technicalFit` | integer 1 to 5 | "How well does it fit the architecture and standards" |
| `riskScore` | integer 1 to 5 | the organisation's judgement, shown with the signals of D3 |
| `scoredOn` | date | set with the scores |
| `suggestedTimeClassification` | string, enum Tolerate, Invest, Migrate, Eliminate, `hideOnForm: true` | derived, D2 |

The scores sit on the usage, not on the module: two municipalities running the same product value it differently.

## D2. The suggested class is a declared calculation

`usage.configuration["x-openregister-calculations"]` derives `suggestedTimeClassification`: value and fit of 3 or more count as high; high value and high fit give Invest, high value and low fit Migrate, low value and high fit Tolerate, low both Eliminate; empty when either score is missing. OpenRegister validates the annotation at schema save (`openregister lib/Service/Calculation/CalculationAnnotationValidator.php:77`) and evaluates it on save (`lib/Service/Calculation/CalculationEvaluator.php`).

Rejected: computing the suggestion in `PortfolioReportService`. It would exist only in the report, not on the usage page or in the list filters.

## D3. Risk signals beside the score

The usage detail page (`src/manifest.d/usages.json` from `landscape-usage-registration`) gains a small body widget `UsageRiskSignals` (`src/components/portfolio/UsageRiskSignals.vue`) that shows the EOL state of `usage.moduleVersion` (the same `endOfSupportState` the roadmap uses, `src/views/LifecycleRoadmapView.vue:397`) and the count of vulnerabilities linked to the application (`vulnerability.modules`). The widget only reads; the organisation sets `riskScore` itself.

## D4. The report

- `PortfolioReportService::buildRow()` (:284) adds `businessValue`, `technicalFit`, `riskScore`, `suggestedTimeClassification` and `timeMismatch` (recorded and suggested both set and different). `buildCsv()` (:166) writes the new columns.
- `PortfolioReport.vue` adds a value against fit chart next to the quadrant counts (:110), with annualised cost as point size, through the library's `CnChartWidget` (apexcharts, as the quadrant chart already uses), and a "Recorded class differs from scores" filter on the detail table.

## Declarative versus imperative

The scores and the suggestion are declarative (ADR-031). The report additions extend the existing service and view; no new endpoint.

## Seed data

The demo usages get scores that land in all four suggested classes, one of them different from its recorded class.

## Risks

- Existing usages have no scores; the chart shows "not scored" as a count instead of a point.

## Changes at build (2026-09-30)

- D3: the scores, the recorded TIME class and the suggestion got their own `data` section on the usage page (`gb-assessment`, "Value assessment"); `UsageRiskSignals` is a body widget placed after the data. `timeClassification` moved from the first data section into that section.
- D4: the value against fit chart is a small SVG component (`src/components/portfolio/ValueFitPlot.vue`) instead of `CnChartWidget`: the library's chart takes series of numbers, and a bubble per usage with its own colour, ring and tooltip needs per-point styling it does not expose. The mismatch filter is an `NcCheckboxRadioSwitch` over the table.
- D4: `buildRow()` falls back to the same rule in `PortfolioReportDerivation::suggestTimeClassification()` for a usage saved before the calculation existed (no materialised value yet); a test keeps that rule equal to the declared expression.
- The fragment moves `usage` to 1.5.3.
