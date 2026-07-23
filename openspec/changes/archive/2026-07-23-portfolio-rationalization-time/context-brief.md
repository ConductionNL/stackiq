# Context Brief: portfolio-rationalization-time

## What
Gartner **TIME classification** (Tolerate / Invest / Migrate / Eliminate) per application-in-use (gebruik), with rationale and review date, plus a **portfolio rationalization report** per organisation: TIME quadrant counts, EOL exposure (from lifecycle data), cloud-transition share, and cost overlay (from contract cost derivation).

## Why (evidence)
- VNG Softwarecatalogus issue #54 (portfolio statistics incl. EOL and cloud-transition).
- Competitor gap: SAP LeanIX sells exactly this (TIME model) at per-app pricing out of municipal reach; no OSS competitor has it.
- Academic grounding: "A method for application portfolio rationalization" applies APR to small municipalities (logged in Specter external_sources).
- 109 reporting requirements in mapped tenders.
- Specter canonical feature: `portfolio-rationalization-time` (should, demand 9).

## Current state (read these specs first)
- `openspec/specs/application-lifecycle-tracking` — lifecycle phase derived from gebruik dates; EOL indicators + approaching-EOL filter. TIME builds on this.
- `openspec/specs/gebruik-services` — gebruik APIs; the TIME fields belong on the gebruik object (an application's classification is org-specific).
- `openspec/specs/contract-administration` — annualised cost derivation to reuse for cost overlay.
- `openspec/specs/dashboard-views-api` — dashboard/statistics endpoints; the report is a new page + data endpoint following that pattern.
- Charts: apexcharts is an approved shared dep via @conduction/nextcloud-vue.

## Scope
IN: gebruik schema fields (timeClassification enum, timeRationale, timeReviewDate, deploymentModel enum e.g. on-premise/saas/hybrid if not already present), edit UI on the gebruik detail/modal, portfolio report page per organisation (quadrant chart + tables + EOL/cloud/cost aggregates, bounded queries), CSV export of the report, i18n, tests, docs.
OUT: automated classification suggestions (AI advisering is deferred — VNG #53), cross-org benchmarking, budgeting/forecasting.

## Design constraints
- ADR-001 OR storage; schema changes in lib/Settings/softwarecatalogus_register.json (diff against merge base; union merges drop modifications).
- OR saveObject is PUT-semantic — editing TIME fields must carry all other gebruik fields forward.
- Aggregation endpoints bounded (see bound-unbounded-searchobjects-scans pending change) and RBAC-scoped per organisation (respect vendor-visibility work in flight).
- ADR-012 Cn components (CnDashboardPage patterns); ADR-003 tokens; ADR-005 i18n; ADR-009 tests; ADR-010 docs.
- OpenSpec delta headers MUST be `### Requirement: <name>`.
