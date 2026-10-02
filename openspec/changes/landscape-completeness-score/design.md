# Design: landscape-completeness-score

Read at development `49e65cb4`, OpenRegister development `4fee776`.

## Context

OpenRegister computes a weighted data quality score on every save when a schema's `configuration` carries `x-openregister-quality` (`openregister lib/Listener/QualityScoreOnSaveListener.php:223-233` reads it). Rule types are `required`, `format` (named `email`, `url`, `date`, or a `pattern`) and `freshness` (exponential decay on a date field with `halfLifeDays`, default 180) (`lib/Service/Quality/QualityScorer.php`). `good` and `fair` thresholds default to 0.8 and 0.5 (`QualityScorer.php:134-147`). The score always lands in `@self.quality` and also in body fields the schema declares (`QualityScoreOnSaveListener.php:153-191`). The statistics endpoint reads the body field (`QualityStatisticsService.php:154`, default `qualityScore`).

## D1. The rule set, in a fragment

`lib/Settings/register.d/data-quality.json`:

`module.configuration["x-openregister-quality"]`:

| type | field | weight |
|---|---|---|
| required | name | 1 |
| required | shortDescription | 2 |
| required | longDescription | 1 |
| required | provider | 2 |
| required | contactPerson | 2 |
| required | referenceComponents | 3 |
| required | licentietype | 1 |
| required | cloudDienstverleningsmodel | 1 |
| required | hostingLocation | 1 |
| required | bbnLevel | 1 |
| format (url) | website | 1 |
| freshness (halfLifeDays 365) | lastConfirmedAt | 3 |

`usage.configuration["x-openregister-quality"]`: required `module`, `moduleVersion`, `status`, `businessOwner`, `technicalOwner` (from `landscape-usage-registration`), `usedForReferenceComponents`, and freshness on `lastConfirmedAt`. Thresholds good 0.8, fair 0.5 on both.

Plus, on both schemas: `lastConfirmedAt` (date-time, visible, editable only through the action in D3), `qualityScore` (number) and `qualityStatus` (string, enum good, fair, poor), both `hideOnForm: true`, so the form never shows a number the platform overwrites.

Rejected: a stackiq scoring service. OpenRegister already scores on save and serves the statistics; a second scorer would drift from it (ADR-022, ADR-031).

## D2. Where the score shows

- Applications list (`FacetedCatalogIndexView`, `Modules` page columns) and Applications in use (`src/manifest.d/usages.json` from `landscape-usage-registration`): a `qualityStatus` column rendered as a status badge.
- `ModuleDetail` (`src/manifest.json:491`): a `stat` widget showing `qualityScore` as a percentage with its status.

## D3. Confirm this entry is current

A header action on `ModuleDetail` and on the usage detail page that saves `lastConfirmedAt = now` and nothing else. The save triggers the rescore, so the freshness part goes back to full. The action shows for users who may update the entry.

## D4. The report

`Reports` (`src/manifest.json:1021`) gets a second card, "Data quality", routing to a new page `DataQualityReport` (`/data-quality`), a custom view `src/views/DataQualityReportView.vue` that calls the two OpenRegister endpoints per schema (`module`, `usage`): the score spread (good, fair, poor counts, from `stats`) and the twenty lowest entries (from the listing), each row opening its page.

## D5. Existing rows

A repair step `lib/Repair/RescoreDataQuality.php`, run after the register import, saves every module and usage once through the object service so each gets a score. It logs the count and is idempotent.

## Declarative versus imperative

The rules and the scoring are declarative (ADR-031); the report is a read view over OpenRegister's endpoints; the rescore is a one-off repair.

## Seed data

`lastConfirmedAt` is set on the demo modules and usages so the demo shows good, fair and poor entries.

## Risks

- The rescore saves every row once; on a catalogue of 6,000 modules it runs as a background job rather than inside the repair step if it exceeds the step's time budget.
- Weights encode a judgement. They are in the fragment where a pull request can change them.
