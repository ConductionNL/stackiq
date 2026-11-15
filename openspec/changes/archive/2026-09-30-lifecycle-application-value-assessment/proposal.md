---
kind: code
depends_on:
  - landscape-usage-registration
---

# Score each application on value, fit, cost and risk

## Summary

An information manager scores every application the organisation uses on business value, technical fit and risk, next to the cost stackiq already adds up from contracts. The portfolio report plots value against fit with cost as the size of each point, and shows the TIME class the scores point to beside the TIME class the organisation recorded. The scores back up a TIME decision instead of replacing it.

## Why

Row from the stackiq matrix:

- `stackiq:life-value-assessment`, "Score each application on business value, cost and risk to decide where to invest." Rated no. Tender demand: Helmond, REQ41, analysis of application use, cost, risk and value (https://www.tenderned.nl/aankondigingen/overzicht/398728). Two competitors rate yes: SAP LeanIX (https://help.sap.com/docs/leanix/ea/application-rationalization, baseline data points "Business criticality" and "Functional and technical fit", with TCO and obsolescence risk feeding the TIME classification) and BlueDolphin (https://bluedolphin.io/application-portfolio-management-application-rationalization/, "Capture lifecycle, technical debt, business value, risk, cost ... multidimensional portfolio analysis").

## What stackiq has today

- A usage records a TIME class, its rationale and a review date (`timeClassification`, `timeRationale`, `timeReviewDate` on the `usage` schema, `lib/Settings/softwarecatalogus_register.json:2656`), from the archived change `2026-07-23-portfolio-rationalization-time`.
- The portfolio report (`GET /api/portfolio-report`, `lib/Service/PortfolioReportService.php:138` buildReport, rows at :284) combines TIME, end-of-support exposure, cloud model and annualised contract cost (`lib/Service/PortfolioReportDerivation.php:163`), and `src/views/organisaties/PortfolioReport.vue` draws the TIME quadrant counts (:110).
- No business value, technical fit or risk score exists on `module` or `usage`, so the TIME class has nothing recorded behind it.
- The archived change's non-goals exclude AI-assisted classification (VNG #53) and budgeting. Neither is part of this change.

## What this change builds

1. On `usage`: `businessValue`, `technicalFit` and `riskScore` (each 1 to 5) and `scoredOn`.
2. A derived `suggestedTimeClassification` from value and fit (high value and high fit: Invest; high value and low fit: Migrate; low value and high fit: Tolerate; low both: Eliminate), declared with OpenRegister's `x-openregister-calculations`. The recorded `timeClassification` stays the decision.
3. Risk signals next to the risk score on the usage page: end-of-support state of the running version and the number of open vulnerabilities of the application, both already computed elsewhere.
4. The portfolio report: a value against fit chart with annualised cost as point size, a column with the suggested TIME class, and a filter on rows where the recorded class differs from the suggested one. The CSV export carries the new columns.

## Out of scope

- AI-assisted scoring or classification (VNG #53, a non-goal of the archived TIME change).
- Budgets and cost forecasts (`stackiq:ctr-budget`, deferred).
- Scoring questionnaires sent to owners: `landscape-owner-attestation` asks owners to confirm entries, and a scoring round can reuse it later.

## Risks

- Scores on a 1 to 5 scale are judgements. The report shows who scored and when (`scoredOn`, the audit trail), and never changes the recorded TIME class by itself.
