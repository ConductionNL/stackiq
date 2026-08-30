# Proposal: bio-compliance-assessment

## Summary
Extends the existing GEMMA compliance model with the Dutch government
security/privacy stack: a seedable **BIO maatregelen** (BIO 2.0 measures)
catalog, a per-application **BBN level** (BBN1/BBN2/BBN3), **DPIA**
tracking (status, execution date, next-review date, document reference),
a reference to the organisation's **register van verwerkingen** entry, a
per-organisation BIO coverage report that extends the existing compliance
matrix, catalog filters (e.g. applications without a DPIA at BBN2+), and a
declarative notification rule for overdue DPIA reviews. The change reuses
the `compliancy` record model (module ↔ standard/measure, evidence,
verified-vs-claimed) rather than inventing a parallel compliance
mechanism.

## Motivation
BIO/BBN/DPIA/AVG compliance is the single largest demand theme in the
mapped tender corpus — 561 compliance requirements across 301 tenders —
and the current catalog has no way to record it. VNG Softwarecatalogus
issues #44 (monitor BIO), #45/#47 (register measures), #46 (BBN), #67
(DPIA), #82 (register van verwerkingen), plus 10 IBD-labelled issues, all
ask for this. BIO 2.0 is mandatory for Dutch government bodies and the
NIS2/BIO2 transition is active in 2026, so buyers increasingly shortlist
on BIO/BBN/DPIA posture the same way they already do on GEMMA standards.
Building it now, as an extension of the proven `compliancy` model, avoids
a second parallel compliance mechanism from emerging later under time
pressure.

## Affected Projects
- [x] Project: `softwarecatalog` — new/extended OR register schemas
  (`bioMaatregel` catalog, `compliancy` extension, `module` BBN/DPIA
  fields), BIO coverage report UI, DPIA/BBN catalog filters, a
  `dpia-review-overdue` notification rule, i18n, tests, docs.

## Scope

### In Scope
- New `bioMaatregel` catalog schema (BIO 2.0 measures: code, title,
  description, theme, applicable BBN level(s), source reference),
  seedable from the published BIO measure list.
- `compliancy` schema extended with an optional `bioMaatregel` relation
  (parallel to the existing `standaardversie` relation) so a compliance
  record can assert measure-level BIO compliance using the same
  verified/claimed/evidence model as standards compliance.
- `module` schema extended with `bbnLevel` (BBN1/BBN2/BBN3),
  `dpiaStatus`, `dpiaDate`, `dpiaVolgendeBeoordeling` (next DPIA review
  due date), `dpiaDocumentRef` (NC Files link, link-don't-store), and
  `verwerkingsregisterRef` (reference to the register van verwerkingen
  entry).
- CRUD for the new fields/schema via manifest pages, following existing
  patterns (`ComplianceMatrixView`, the `element`/`compliancy` catalog
  pages).
- A BIO coverage report per organisation, extending the existing
  compliance matrix/coverage view, reporting per in-use application:
  BBN level, DPIA status, and BIO measure compliance
  (verified/claimed/none).
- Catalog filters on BBN level and DPIA status (e.g. "applications
  without a DPIA at BBN2 or higher").
- A declarative `x-openregister-notifications` rule on `module` for
  overdue DPIA reviews (canonical dialect; scheduled trigger).
- i18n (NL + EN), tests (≥75% coverage for new code), docs with
  screenshots.

### Out of Scope
- Automated BIO evidence collection (scanning, tooling integration).
- ISMS workflows (risk registers, control testing schedules beyond the
  DPIA review date).
- NIS2 incident reporting.
- Audit certification flows (ENSIA, DigiD assessments, etc.).
- Building a full register van verwerkingen module — this change only
  stores a reference to an existing entry (a link/id), not the register
  itself.

## Approach
Follow the same pattern `module-compliance-assessment` already
established for GEMMA standards: `compliancy` records are the source of
truth for measure-level assertions (module ↔ `bioMaatregel`, optional
evidence, verified/claimed states), and the existing matrix/coverage
machinery is extended to also key on `bioMaatregel` rather than forking a
parallel "BIO assessment" object. Application-level attributes that are
not measure-specific (BBN level, DPIA status/dates/document,
verwerkingsregister reference) live directly on `module`, since they
describe the application as a whole rather than a per-measure claim. The
overdue-DPIA notification is declared on the `module` schema using the
canonical `x-openregister-notifications` dialect (scheduled trigger +
`withinNext`/`equals` filter operators, matching the working precedent
already in `contract`, `gebruik`, and `moduleVersie`), not a bespoke PHP
notification service (ADR-031).

## New Dependencies
None.

## Impact
- `lib/Settings/softwarecatalogus_register.json` — new `bioMaatregel`
  schema; `compliancy` and `module` schema extensions; new
  `x-openregister-notifications` rule on `module`.
- `src/manifest.json` — new catalog pages for `bioMaatregel`, extended
  `module` create/edit form fields, extended `ComplianceMatrixView`
  (or an added BIO scope on it) for the BIO coverage report, catalog
  filter additions.
- `src/utils/complianceMatrix.js` (or a sibling util) — extended to key
  on `bioMaatregel` alongside `standaardversie`.
- Seed data for the `bioMaatregel` catalog (repair-step import, per the
  `repair-init` pattern).
- i18n resource files (`nl_NL`, `en_US`).

## Cross-Project Dependencies
None. This change is self-contained within `softwarecatalog`; no other
Conduction app consumes these schemas or endpoints.

## Risks

### Risk 1: Register JSON union-merge can silently drop concurrent edits
**Severity:** Medium — **Mitigation:** `lib/Settings/softwarecatalogus_register.json`
is edited directly against its current merge base (not through a naive
JSON union-merge tool); every edit is diffed against the merge base
before committing, per the project's known union-merge trap.

### Risk 2: Notification dialect drift
**Severity:** Medium — **Mitigation:** the `dpia-review-overdue` rule
uses only the trigger types and filter operators already proven working
in this register (`scheduled` + `equals`/`withinNext`) and is validated
against `hydra-gate-notification-dialect` before merge; the legacy
dialect is never used.

### Risk 3: BIO measure catalog content is DIY-published, not a stable API
**Severity:** Low — **Mitigation:** the `bioMaatregel` catalog is seeded
from the published BIO measure list as static seed data (like the
GEMMA `element` catalog), not fetched live from an external source;
re-seeding on a new BIO version is a manual, reviewable data update.

## Rollback Strategy
Revert the register JSON patch (schema fields, `bioMaatregel` schema, and
the notification rule) and the corresponding manifest/UI changes. Because
the new fields are additive and optional (no `required` constraints
added to `module` or `compliancy`), existing objects remain valid without
migration; a revert leaves previously-entered BIO/DPIA data orphaned in
the database but does not break existing GEMMA compliance functionality.

## Open Questions
- What DPIA review interval should the catalog assume when computing
  `dpiaVolgendeBeoordeling` defaults (BIO/AVG guidance suggests periodic
  re-assessment, but no fixed interval is mandated) — left as a
  user-set field rather than a computed default in this change; see
  DEFERRED_QUESTIONS.
- Whether `verwerkingsregisterRef` should eventually become a typed
  relation into a future register-van-verwerkingen module, versus
  staying a free-text/URL reference — deferred until that module is
  scoped.
