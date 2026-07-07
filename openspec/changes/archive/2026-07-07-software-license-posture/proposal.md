---
kind: feature
depends_on: []
---

# softwarecatalog — software license posture (SAM overview)

## Why

`licentie` (52 hits) is the **second-highest demand keyword** across
softwarecatalog's matched tenders, behind only `itsm`; `license management` /
`software asset management (SAM)` is a recurring competitor category (Flexera,
Snow, GLPI, ServiceNow, ManageEngine). The data model already carries the raw
material:

- `module.licentietype` (Licentievorm): **`Open source` / `Closed source`**,
- `module.licentie` (Licentie): the specific licence (MIT, GPL, Apache 2.0, BSD,
  EUPL-1.2),
- `gebruik` records: the **in-production deployments** of each application,
- `contract.kosten` / `kostenPeriode`: the cost, whose annualisation
  `contract-administration` already derives and totals.

But nothing joins them into a **portfolio license posture**. The `contract-administration`
change answers "what does *this contract* cost and when does it expire"; there
is **no** capability answering the portfolio question:

> "What share of the applications we actually **run** is **open source vs
> closed source**, from which **suppliers**, at what **cost** — and how does
> that track against an open-source-first policy?"

For a GEMMA / Dutch-government catalog this is not generic SAM: the open-source
vs closed-source split of the in-production landscape is a **policy-compliance**
metric ("open, tenzij" / open-source-first). Today it is unanswerable without a
manual spreadsheet.

## What Changes

A **license-posture** surface that aggregates existing objects — routed through
OpenRegister aggregation where expressible, never a bespoke app-local analytics
engine, and **consuming** contract-administration's annualised cost rather than
re-deriving it.

1. **Portfolio posture.** Aggregate in-production usage by
   `module.licentietype` and `module.licentie`: the open-source vs closed-source
   share of the running portfolio, and the licence-type mix — weighted by
   deployment, not by catalogue rows.
2. **Deployment count per licensed application.** For each module, the count of
   in-production `gebruik` (the same "in production" predicate
   `application-lifecycle-tracking` uses) — license consumption, the basis for
   any entitlement conversation.
3. **Per-vendor rollup with cost.** Group the posture by `aanbieder` (vendor):
   deployments and licence mix per supplier, joined to cost by **consuming**
   `contract-administration`'s `totalAnnualisedCost` (this change does NOT
   re-derive contract cost).
4. **Per-organisation report + policy signal.** For an organisation, the
   open-source vs closed-source share of its in-use applications, so a CISO /
   architect can report open-source-first posture.

Surfaced as a manifest dashboard / CnDataTable surface (ADR-012), no app-local
controllers for CRUD.

## Impact

- **New**: a license-posture dashboard + per-vendor and per-organisation
  breakdowns, all derived at query time from existing schemas.
- **Reuses**: the lifecycle "in production" predicate and
  contract-administration's annualised cost (consumed, not duplicated).
- **No new schema, no app-local aggregation engine where OR declarative
  aggregation can express the rollups.**
- **Risk**: low — read-only aggregation over existing relations.

## Dependencies

Consumes `contract-administration` (annualised cost) and shares the "in
production" predicate with `application-lifecycle-tracking`. Uses OpenRegister
declarative cross-object aggregation (`@aggregate`) where expressible (ADR-022).
Boundary: this owns **portfolio posture**; `contract-administration` owns
**per-contract cost/expiry**; neither duplicates the other.
