# Design: portfolio-rationalization-time

## Architecture Overview
Two additive pieces on top of existing capabilities, no new storage layer:

1. **TIME classification on `gebruik`** — three new optional properties on
   the existing `gebruik` schema in `lib/Settings/softwarecatalogus_register.json`,
   edited through the existing gebruik detail/edit surface, persisted through
   OpenRegister exactly like every other gebruik field (ADR-001, ADR-022 — no
   app-local CRUD).
2. **Portfolio rationalization report** — a new manifest page + a bounded,
   RBAC-scoped read endpoint that composes three existing derivations
   (TIME counts from `gebruik`, lifecycle/EOL exposure from
   `application-lifecycle-tracking`'s phase/EOL logic, annualised cost from
   `contract-administration`'s cost derivation) into one aggregate response,
   plus a CSV-format variant of the same data.

```
Gebruik detail/edit (Vue)          Portfolio report page (Vue, new)
        │ PUT (full object)                │ GET aggregate JSON / GET ?format=csv
        ▼                                   ▼
OpenRegister ObjectService  ◄───── PortfolioReportService (new, PHP)
        │                                   │ reads (bounded, org-scoped)
        ▼                                   ▼
   gebruik / moduleVersie / contract objects in the softwarecatalogus register
```

The report endpoint does not introduce a new data model — it is a read-side
aggregation over existing objects, following the same "thin client, OR is
the only store" pattern as `dashboard-views-api`.

## Goals / Non-Goals
**Goals:**
- Persist TIME classification, rationale, and review date per gebruik.
- Surface a single organisation-scoped report combining TIME + EOL + cloud +
  cost, bounded and RBAC-scoped.
- CSV export of the same underlying rows.

**Non-Goals:**
- No AI-assisted classification (VNG #53, explicitly deferred).
- No new RBAC/visibility-matrix mechanism — this change consumes whatever
  organisation-scoping is current and MUST track (not duplicate or race)
  the in-flight `vendor-visibility-rbac` change.
- No cross-organisation benchmarking or budgeting/forecasting.
- No new database tables or Nextcloud DB migration — ADR-001 forbids
  app-owned tables; schema changes go through the existing register-config +
  repair-step import path, not `lib/Migration/`.

## Decisions

### Decision 1: Add three fields to `gebruik`; do not add a `deploymentModel` field
The context brief proposed a `deploymentModel` enum "if not already present".
Inspection of `lib/Settings/softwarecatalogus_register.json` shows `gebruik`
already has `cloudDienstverleningsmodel` (title "Hosting", enum
`On-premises (self-managed)` / `IaaS` / `PaaS` / `SaaS`, `facetable: true`,
already a table-default column). This is exactly the deployment-model
signal the cloud-transition metric needs.
**Decision:** reuse `cloudDienstverleningsmodel` for the cloud-transition
aggregate; add only `timeClassification`, `timeRationale`, and
`timeReviewDate`. Adding a second, competing deployment-model field would
fork the data and confuse existing Hosting-column consumers.
**Alternatives considered:** adding a new `deploymentModel` field as the
brief literally suggested — rejected because it duplicates data already
captured and passes the "if not already present" escape hatch in the brief.

### Decision 2: `timeClassification` follows the existing enum-on-string convention
Match the `status` field's shape (`type: string`, `enum: [...]`, `title`,
`example`) rather than inventing a new pattern. Values: `Tolerate`,
`Invest`, `Migrate`, `Eliminate` (English canonical values per the Gartner
TIME model; the UI/i18n layer localises labels the same way `status`'s Dutch
enum values are already displayed untranslated-but-labelled — follow
whatever the current `status` field UI convention is at implementation
time, confirmed during apply).
**Alternatives considered:** a numeric quadrant code — rejected, less
self-describing in raw OR object payloads and harder to facet/filter on.

### Decision 3: Report endpoint composes existing derivations, does not duplicate them
`PortfolioReportService` reuses:
- `application-lifecycle-tracking`'s phase/EOL derivation logic (same
  "derive at query time, never persist" principle — TIME counts and EOL
  exposure are computed from live `gebruik`/`moduleVersie` data, not cached).
- `contract-administration`'s annualised-cost derivation (`kosten` ×
  `kostenPeriode`), applied to the set of contracts linked to the
  organisation's gebruiken.
No shared derivation is forked into a second implementation; if the existing
JS derivation utilities (`lifecyclePhase.js`, `contractCost.js`) are
frontend-only, the report endpoint reimplements the same rules server-side
for the aggregate (since aggregation over potentially many gebruiken is a
backend job, not a per-row frontend render) — the requirement scenarios
below assert both stay behaviourally identical (same phase/EOL/cost rules,
same inputs → same outputs) rather than assert code-sharing, since the
frontend/backend split makes literal code-sharing impractical.
**Alternatives considered:** running the whole aggregation client-side over
paginated raw gebruik fetches — rejected: would require fetching full
gebruik+contract data for every application in an organisation into the
browser, defeating the bounded-query goal and RBAC narrowing that's easier
to enforce once, server-side.

### Decision 4: Aggregation queries are bounded and organisation-scoped
Following `bound-unbounded-searchobjects-scans`: every query the report
issues MUST set an explicit `_limit` (or use `searchObjectsPaginated`) —
never an unbounded `searchObjects()` call. The report is scoped to one
organisation per request (selected in the UI, matching the existing
`application-lifecycle-tracking` roadmap's per-organisation pattern) so the
result set for any single call is bounded by that organisation's gebruik
count, with an explicit page-size ceiling on top as a second bound.
RBAC: the endpoint MUST scope results to organisations the requesting user
is authorised to see, using the current tenant-context / organisation-header
mechanism (`softwarecatalog-adopt-or-abstractions`'
`X-OpenRegister-Organisation`, `useTenantContext()`) and MUST deny (not
silently empty-return) a request for an organisation the user cannot see —
consistent with the fail-closed principle stated in the in-flight
`vendor-visibility-rbac` context brief. This change does not implement that
matrix; it plugs into whatever authorisation gate exists on gebruik reads at
implementation time and MUST NOT be merged as a change that bypasses it.

### Decision 5: CSV export is a format variant of the same endpoint, not a separate data path
`GET .../portfolio-report?organisation={uuid}&format=csv` returns the same
bounded, RBAC-scoped row set serialised as CSV instead of a second
unbounded export mechanism. This avoids a common trap where "export
everything" endpoints skip the bounds and RBAC scoping applied to the
paginated/report view.
**Alternatives considered:** a dedicated unbounded export endpoint —
rejected on both the bounded-query and RBAC-leak risks called out in the
proposal.

## Risks / Trade-offs
- [Report aggregation cost grows with organisation gebruik count] → Mitigate
  with an explicit page-size ceiling and by reusing existing indexed/facetable
  fields (`cloudDienstverleningsmodel` is already `facetable: true`); if an
  organisation's gebruik count exceeds the ceiling, the report SHALL show a
  "showing first N of M" indicator rather than silently truncating without
  disclosure.
- [Duplicating lifecycle/cost logic server-side vs. frontend risks drift] →
  Mitigate with shared test fixtures asserting identical phase/EOL/cost
  outputs for the same input dates across the existing frontend utility
  tests and the new backend aggregation tests.
- [RBAC scoping mechanism is still in flux (`vendor-visibility-rbac` in
  flight)] → Mitigate by not inventing a parallel mechanism; wire to
  whatever authorisation check gates other gebruik/contract reads today,
  and flag in tasks.md that the report's RBAC test MUST be re-run once
  `vendor-visibility-rbac` lands, in case the enforcement point moves.

## Migration Plan
No Nextcloud DB migration applies (ADR-001 — no app-owned tables). The
schema change is a targeted diff to
`lib/Settings/softwarecatalogus_register.json` adding the three
`gebruik` properties (never a full-file regeneration, to avoid dropping
concurrent register-config modifications), picked up by the existing
`ConfigurationService::importFromApp()` repair step on next app
upgrade/repair run. Rollback: revert the register-config diff (the three
properties are optional/additive, so existing gebruik objects are
unaffected either way) and remove the report page/controller/route.

## Open Questions
- Should `timeReviewDate` drive a scheduled notification (mirroring
  `eol-approaching` / `phaseout-approaching`)? Not in this change's scope —
  see proposal.md Open Questions / DEFERRED_QUESTIONS.
- Exact enforcement point for organisation-scoping (which service/middleware
  gates gebruik/contract reads today) needs confirmation against the
  softwarecatalog `lib/` codebase at apply time, since `vendor-visibility-rbac`
  is still only a context-brief and may change the enforcement point.
