---
kind: config
depends_on:
  - landscape-usage-registration
---

# Score how complete and current each entry is

## Summary

Every application, and every organisation's usage of one, gets a data quality score from a declared rule set: which fields must be filled, which must have the right format, and how recently the entry was confirmed. The score shows on the Applications list and the application page, and a Data quality report lists the weakest entries per type, so an information manager knows what to fix first.

## Why

Rows from the stackiq matrix:

- `stackiq:land-completeness-score`, "See how complete and up to date each application's entry is, as a score." Rated no. Two competitors rate yes: SAP LeanIX (https://help.sap.com/docs/leanix/ea/fact-sheet-completeness, "The fact sheet completion score measures how much of the required data has been filled out ... in the fact sheet's header") and BlueDolphin (https://help.bluedolphin.io/en/articles/11967713-governance-insights, "Object completeness: Lists all objects and the completeness score of each object"). Core area (landscape).
- `stackiq:comp-health-scoring`, "Score the correctness and completeness of the register against a rule set." Rated no. Two competitors rate yes: SAP LeanIX (https://help.sap.com/docs/leanix/ea/application-portfolio-management-dashboard, "Data Quality KPI ... Overall Completion of Applications", weights set by admins) and BlueDolphin (the same governance insights page).

No tender, feature request or roadmap row names these rows.

## What stackiq has today

- No completeness, quality or freshness score in `lib/` or `src/`.
- OpenRegister scores data quality from a declared annotation: `configuration.x-openregister-quality` with `rules` of type `required`, `format` and `freshness`, weights and `good` and `fair` thresholds (`openregister lib/Service/Quality/QualityScorer.php`, `QualityAnnotationValidator.php:42`). A save listener writes the score to `@self.quality` and to the body fields `qualityScore` and `qualityStatus` where the schema declares them (`lib/Listener/QualityScoreOnSaveListener.php:153-191`). Read-only statistics and a lowest-first listing sit at `GET /api/objects/quality/{register}/{schema}/stats` and `GET /api/objects/quality/{register}/{schema}` (openregister `appinfo/routes.php:628-629`), and they read the body field (`QualityStatisticsService.php:154`).
- No stackiq schema declares the annotation.

## What this change builds

1. A declared rule set on `module` and on `usage`: required fields with weights, a URL format check on websites, and a freshness rule on a new `lastConfirmedAt` date.
2. Hidden `qualityScore` and `qualityStatus` fields on both schemas, so OpenRegister's statistics and listing work.
3. A Data quality column on the Applications list and on Applications in use, and a score tile on the application page.
4. A "Confirm this entry is current" action on the application and usage pages that sets `lastConfirmedAt`.
5. A Data quality report, a card on the Reports page, with the score spread per type and the twenty weakest entries.

## Out of scope

- Asking owners to confirm their entries by survey: `landscape-owner-attestation`, which sets the same `lastConfirmedAt`.
- Scores for contracts, connections and compliance claims; the same annotation can be added per schema later.
- An admin screen for the rule set. The rules live in the register fragment and are edited like code, or in OpenRegister's schema editor.

## Risks

- Existing rows have no score until they are saved. The design adds a one-off rescore after the import.
