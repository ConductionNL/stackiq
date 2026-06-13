# Tasks — application-lifecycle-tracking

## 1. Phase derivation

- [ ] 1.1 Implement the phase-derivation function once (shared FE util):
  most advanced phase whose start date ≤ today; none → `Onbekend`. Unit
  tests for every boundary (today, future-only, all-unset, out-of-order
  dates).
- [ ] 1.2 Express the same derivation as OR query filters for listings/roadmap
  grouping (phase = date-range conditions), so server-side filtering matches
  the rendered phase.
- [ ] 1.3 Show the derived phase as a column/badge on application (gebruik)
  listings and detail views. NL + EN strings (English i18n keys; Dutch phase
  names preserved as the domain terms).

## 2. End-of-support surfacing

- [ ] 2.1 Add app-config `eol_warning_window_days` (default 180).
- [ ] 2.2 EOL indicator on gebruik list entries and detail view when the
  linked moduleVersie has passed `datumEindeOndersteuning` or has
  `datumTeruggetrokken` set; show the dates in detail.
- [ ] 2.3 EOL-approaching filter on application listings (linked version's
  `datumEindeOndersteuning` within the window), ordered ascending.

## 3. Schema addition: planned replacement

- [ ] 3.1 Add optional `geplandeVervanging` (related-object → `module`) and
  `geplandeVervangingsDatum` (date) to the `gebruik` schema in
  `lib/Settings/softwarecatalogus_register.json`; bump schema version.
- [ ] 3.2 Verify import-over-existing-data is non-destructive (PHPUnit
  register-import test; existing gebruiken load/save unchanged).
- [ ] 3.3 Edit UI: successor-module picker + date on the gebruik detail/edit
  surface; clearable.

## 4. Portfolio roadmap view

- [ ] 4.1 Add a roadmap manifest page: organisation selector, gebruiken
  grouped by derived phase (including `Onbekend` group, rendered first),
  ordered by nearest urgency date (EOL / phase-out / replacement date).
- [ ] 4.2 Render successor module + planned date on entries with
  `geplandeVervanging`; link to the module detail.
- [ ] 4.3 (Soft, render-only) When `contract-administration` has landed, show
  contract end dates on roadmap entries; degrade silently when absent.

## 5. Notifications

- [ ] 5.1 Declare `eol-approaching` (moduleVersie) and `phaseout-approaching`
  (gebruik) rules in `x-openregister-notifications` (scheduled triggers,
  window filters, `nl`+`en` subjects, `softwarecatalog-admins` +
  object-ACL manage recipients), `enabled: false`.
- [ ] 5.2 Confirm scheduled date-window filter support in the OR engine
  (shared check with `contract-administration` task 3.2 — one OR gap issue
  if unsupported, referenced from both changes); flip `enabled: true` when
  confirmed.
- [ ] 5.3 Integration test for in-window dispatch and out-of-window silence
  (once enabled).

## 6. Tests & verification

- [ ] 6.1 Playwright e2e: phase badge on listings, EOL indicator + filter,
  roadmap grouping/ordering, replacement set/clear + roadmap rendering,
  unknown-phase visibility.
- [ ] 6.2 Newman: register-file shape (new gebruik fields, both notification
  rules in the canonical dialect), listing filters via the OR API.
- [ ] 6.3 `composer check:strict` + hydra gates green (gate-18
  notification-dialect, gate-19 annotations, `@spec` tags).
- [ ] 6.4 Docs: portfolio/rationalisation workflow section; align
  `docs/GOVERNMENT-FEATURES.md` if a lifecycle row exists or add one
  honestly (Gepland → Beschikbaar only after this lands).

## Acceptance criteria

- Every gebruik shows a derived phase (incl. `Onbekend`) with zero stored
  phase fields; editing a date immediately changes the derived phase.
- The EOL filter returns exactly the gebruiken whose linked version ends
  support inside the window; passed-EOL usage is visibly flagged.
- The roadmap answers "what phases out next, and what replaces it" for one
  organisation on one page.
- Lifecycle notification rules are declared in the canonical dialect and
  enabled, or a concrete OR engine gap issue is linked.
