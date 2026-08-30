# Proposal: portfolio-rationalization-time

## Summary
Adds Gartner TIME classification (Tolerate / Invest / Migrate / Eliminate) to
each application-in-use (`gebruik`) — a rationale, a review date, and an
organisation-scoped **portfolio rationalization report** that combines TIME
quadrant counts with existing EOL exposure (`application-lifecycle-tracking`),
cloud-transition share (the existing `cloudDienstverleningsmodel` field), and
annualised cost overlay (`contract-administration`). The report ships a
quadrant chart, supporting tables, and a CSV export, following the
`dashboard-views-api` page + data-endpoint pattern.

## Motivation
VNG Softwarecatalogus issue #54 asks for portfolio statistics including EOL
and cloud-transition exposure. No OSS competitor in this category offers a
TIME-style rationalization view; SAP LeanIX sells the same model at
per-application pricing that is out of reach for municipalities. The
underlying evidence base (109 reporting requirements across mapped tenders,
Specter canonical feature `portfolio-rationalization-time` at `should`/demand
9, and academic grounding for applying APR to small municipalities) shows
sustained demand for exactly this "which applications do we tolerate, invest
in, migrate, or eliminate" decision-support view. The lifecycle, gebruik, and
contract building blocks already exist — this change composes them into a
management-facing report rather than introducing new data-collection
machinery.

## Affected Projects
- [x] Project: `softwarecatalog` — `gebruik` schema gains TIME classification
  fields; new portfolio rationalization report page + bounded, RBAC-scoped
  data endpoint; CSV export; edit UI on the gebruik detail/modal.

## Scope

### In Scope
- `gebruik` schema fields: `timeClassification` (enum: Tolerate / Invest /
  Migrate / Eliminate), `timeRationale` (free text), `timeReviewDate` (date).
- Confirming `cloudDienstverleningsmodel` (existing Hosting field: On-premises
  (self-managed) / IaaS / PaaS / SaaS) as the deployment-model source for the
  cloud-transition share metric — no new field, since it already exists on
  `gebruik`.
- Edit UI for the three new TIME fields on the existing gebruik detail/modal,
  carrying all other gebruik fields forward on save (OR `saveObject` is
  PUT-semantic).
- A new portfolio rationalization report: a per-organisation page with a TIME
  quadrant chart (apexcharts, via `@conduction/nextcloud-vue`), supporting
  tables, and aggregate figures — TIME quadrant counts, EOL exposure (reusing
  `application-lifecycle-tracking` derivation), cloud-transition share (from
  `cloudDienstverleningsmodel`), and annualised cost overlay (reusing
  `contract-administration` cost derivation), each figure bounded and scoped
  to the requesting user's visible organisation(s).
- CSV export of the report's underlying rows.
- i18n (NL/EN), tests (>=75% coverage on new code), docs with Playwright
  screenshots.

### Out of Scope
- Automated / AI-assisted TIME classification suggestions — deferred to VNG
  issue #53.
- Cross-organisation benchmarking of TIME distributions.
- Budgeting or cost forecasting beyond the existing annualised-cost figure.
- Building a new RBAC visibility matrix — this change consumes whatever
  organisation-scoping mechanism is current at implementation time and MUST
  NOT regress or conflict with the in-flight `vendor-visibility-rbac` change;
  it does not itself define role × object-type visibility rules.

## Approach
Extend the `gebruik` schema (register-config diff, not a full-file replace)
with the three TIME fields, matching the existing `status` field's
enum-on-string convention. Add the fields to the gebruik detail/edit
component, reading the full object before PUT so untouched fields are carried
forward unchanged. Add a `PortfolioReportController` (or extend
`DashboardController`) that resolves the active register/schema via the
existing resolver pattern, runs bounded `searchObjectsPaginated`/aggregate
queries scoped to the caller's organisation, and returns a report payload
plus a CSV-format variant. Add a manifest report page reusing
`CnDashboardPage` composition patterns with an apexcharts quadrant chart.
Design specifics (endpoint shape, aggregation queries, RBAC integration
point) belong in `design.md`.

## New Dependencies
None — apexcharts is already an approved shared dependency exposed via
`@conduction/nextcloud-vue`; no new package is introduced.

## Impact
- `lib/Settings/softwarecatalogus_register.json` — `gebruik` schema gains
  three properties.
- Gebruik detail/edit Vue component(s) — new fields on the form.
- A new backend controller/service for the report data endpoint and CSV
  export, plus a new manifest report page and its Vue component(s).
- Existing `application-lifecycle-tracking` phase/EOL derivation and
  `contract-administration` cost derivation are read, not modified.

## Cross-Project Dependencies
None — self-contained within `softwarecatalog`. It reuses (without
modifying) OpenRegister's `ObjectService::searchObjectsPaginated` and the
existing tenant-organisation header contract from
`softwarecatalog-adopt-or-abstractions`.

## Risks

### Risk 1: Report aggregation query becomes an unbounded full-register scan
**Severity:** High — **Mitigation:** Follow the pattern established by the
`bound-unbounded-searchobjects-scans` change: every aggregate/report query
MUST set an explicit `_limit` or use `searchObjectsPaginated`, never an
unbounded `searchObjects()` call. Cap report rows and document the ceiling.

### Risk 2: Report or CSV export leaks another organisation's portfolio data
**Severity:** High — **Mitigation:** Scope every aggregate query and the CSV
export to the requesting user's organisation using the same tenant/RBAC
mechanism as other gebruik reads; deny-by-default; add a negative test
proving cross-organisation access is rejected. Track alignment with the
in-flight `vendor-visibility-rbac` change rather than inventing a parallel
mechanism.

### Risk 3: TIME field edit overwrites unrelated gebruik fields
**Severity:** Medium — **Mitigation:** OR `saveObject` is PUT-semantic; the
edit flow MUST read the full current gebruik object and carry all existing
fields forward, adding only the changed TIME fields. Add a test asserting an
unrelated field (e.g. `startDatumInProductie`) survives a TIME-only edit.

### Risk 4: Register schema edit collides with concurrent register-config changes
**Severity:** Low — **Mitigation:** Diff `softwarecatalogus_register.json`
against the merge base and apply as a targeted patch (add three properties),
not a wholesale regeneration, per the project's union-merge-drops-changes
lesson.

## Rollback Strategy
The three new `gebruik` fields are additive and optional; existing gebruik
objects remain valid without them (same pattern as the lifecycle change's
`geplandeVervanging` addition). Reverting means: drop the report page from
the manifest, remove the report controller/route, and remove the three
schema properties (or leave them unused/hidden) — no destructive data
migration is required since no existing field is altered.

## Open Questions
- Should `timeReviewDate` drive a scheduled notification (mirroring the
  `phaseout-approaching` / `eol-approaching` rules), or is that deferred with
  the AI-advisering follow-up (VNG #53)? Deferred to DEFERRED_QUESTIONS —
  proceeding without a notification rule in this change; see design.md.
