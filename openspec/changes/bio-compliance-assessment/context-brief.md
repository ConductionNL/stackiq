# Context Brief: bio-compliance-assessment

## What
Extend the existing compliance model with the Dutch government security/privacy stack: **BIO maatregelen register** (measures from the Baseline Informatiebeveiliging Overheid), per-application **BBN level** (BBN1/BBN2/BBN3), **DPIA status** (required/executed/date/document ref), and a reference to the **register van verwerkingen** entry. Per-organisation BIO coverage report extending the existing compliance matrix.

## Why (evidence)
- VNG Softwarecatalogus issues #44 (monitor BIO), #45/#47 (register measures), #46 (BBN), #67 (DPIA), #82 (register van verwerkingen) + 10 IBD-labelled issues.
- 561 compliance requirements in the 301 mapped tenders — the single largest demand theme.
- BIO 2.0 is mandatory for all Dutch government bodies; NIS2/BIO2 transition is active in 2026.
- Specter canonical feature: `bio-compliance-assessment` (must, demand 16).

## Current state (read these specs first)
- `openspec/specs/module-compliance-assessment` — compliance records with evidence files, verified-vs-claimed matrix, per-org coverage report. THIS change extends that model; do not fork a parallel one.
- `openspec/specs/settings-admin-controller` / `repair-init` — schemas live in `lib/Settings/softwarecatalogus_register.json` (OpenAPI 3.0.0) imported via repair step.
- Standards register pages already exist (standards-conformance).

## Scope
IN: new/extended OR schemas (bioMaatregel catalog entries seedable from the published BIO measure list; per-application compliance fields: bbnLevel, dpiaStatus, dpiaDate, dpiaDocumentRef, verwerkingsregisterRef), CRUD via manifest pages where possible, BIO coverage report per organisation (extends compliance matrix), filters (e.g. applications without DPIA at BBN2+), notifications rule for overdue DPIA reviews (declarative OR notification dialect — see softwarecatalog-notifications spec), i18n, tests, docs.
OUT: automated BIO evidence collection, ISMS workflows, NIS2 incident reporting, audit certification flows.

## Design constraints
- ADR-001 OR storage only; schema additions to softwarecatalogus_register.json with schema.org type annotations where applicable.
- Union-merge trap: when editing the register JSON, diff against merge base — naive union merges drop modifications.
- Notification rules use the canonical x-openregister-notifications dialect (gate-18 checks; legacy dialect hard-fails).
- ADR-012 Cn components; ADR-005 i18n NL+EN; ADR-009 tests; ADR-010 docs.
- OpenSpec delta headers MUST be `### Requirement: <name>`.
