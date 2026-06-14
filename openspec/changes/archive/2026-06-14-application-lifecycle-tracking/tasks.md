# Tasks — application-lifecycle-tracking

## 1. Phase derivation

- [x] 1.1 Phase-derivation function (`src/utils/lifecyclePhase.js`
  `derivePhase`): most advanced phase whose start date ≤ now; none → `Onbekend`.
  16 vitest tests covering today/future-only/all-unset/out-of-order boundaries.
- [~] 1.2 Express the same derivation as OR query filters for listings — PARTIAL.
  The derivation is applied client-side in the roadmap (the page that groups by
  phase). A pure server-side OR query mirror needs date-range query support on
  the generic index that the current manifest index does not expose; the
  roadmap (the canonical phase view) renders derived phases consistently. The
  derivation function is exported and reusable for a future server filter.
- [x] 1.3 Phase shown as a badge/group on the roadmap and (derivable) on detail
  via the util. NL + EN strings (English keys; Dutch phase names preserved as
  domain terms).

## 2. End-of-support surfacing

- [x] 2.1 App-config `eol_warning_window_days` (default 180) seeded in the
  repair step (only when unset).
- [x] 2.2 EOL indicator: the roadmap shows "end of support passed" / "withdrawn"
  / "end of support approaching" badges per entry, with the dates
  (`endOfSupportState` util, unit-tested). EOL facts stay on `moduleVersie` —
  nothing copied onto `gebruik`.
- [~] 2.3 EOL-approaching FILTER on the standard application listing — DEFERRED.
  The `isEolApproaching` window function is built + unit-tested and used to flag
  roadmap entries; a dedicated filter control on a gebruik index needs a
  gebruik index page (none exists — applications live in the Organisaties
  custom view + the roadmap). The approaching-EOL window is also enforced
  server-side by the enabled `eol-approaching` notification rule.

## 3. Schema addition: planned replacement

- [x] 3.1 Added optional `geplandeVervanging` (related-object → `module`) and
  `geplandeVervangingsDatum` (date) to the `gebruik` schema; version bumped
  1.2.0 → 1.3.0.
- [x] 3.2 PHPUnit register-shape test (`LifecycleRegisterShapeTest`): the new
  fields are present + OPTIONAL (not in `required`), so import-over-existing is
  non-destructive; both notification rules are in the canonical dialect.
- [~] 3.3 Dedicated successor-module picker on a gebruik edit surface —
  PARTIAL. The fields are editable via the generic detail data widget; a
  bespoke clearable module-picker on a gebruik edit form needs the Organisaties
  custom-view edit flow, out of this change's scope. The roadmap renders the
  successor + planned date and links to the module.

## 4. Portfolio roadmap view

- [x] 4.1 Roadmap manifest custom page (`LifecycleRoadmapView.vue`):
  organisation selector, gebruiken grouped by derived phase (Onbekend group
  rendered first), ordered within group by nearest urgency date (EOL /
  phase-out / replacement). Registered in customComponents.js + registry.js +
  manifest page/menu entry.
- [x] 4.2 Successor module + planned date rendered on entries with
  `geplandeVervanging`; links to the module detail.
- [~] 4.3 Contract end dates on roadmap entries (soft, when
  contract-administration lands) — DEFERRED (cross-change render-only nicety;
  degrades silently when absent, as specified).

## 5. Notifications

- [x] 5.1 Declared `eol-approaching` (moduleVersie, `datumEindeOndersteuning`
  withinNext P180D) and `phaseout-approaching` (gebruik, `startDatumUitTeFaseren`
  withinNext P180D) rules in the `x-openregister-notifications` dialect with
  nl+en subjects and `software-catalog-admins` + object-ACL manage recipients.
- [x] 5.2 Confirmed the OR engine's `scheduled` date-window filtering
  (`ScheduledFilterEvaluator` `withinNext`, ISO-8601 duration) — so the rules
  ship `enabled: true` (no OR engine gap). moduleVersie 0.1.0 → 0.1.1.
- [~] 5.3 Integration test for in/out-of-window dispatch — DEFERRED. The
  `withinNext` evaluator is unit-tested upstream in OpenRegister; the rule
  shape is validated by gate-18 + the register-shape PHPUnit test.

## 6. Tests & verification

- [x] 6.1 Playwright e2e
  (`tests/e2e/spec-coverage/lifecycle-roadmap.spec.ts`): roadmap nav entry
  reaches the organisation-first surface, no app-origin errors.
- [x] 6.2 Register-file shape (new gebruik fields + both notification rules in
  the canonical dialect) validated by the PHPUnit register-shape test + JSON.
- [x] 6.3 hydra gates green (all 24, incl. gate-18 notification-dialect, gate-16
  + gate-19). vitest 80, PHPUnit 158 (this branch).
- [x] 6.4 Docs: lifecycle row added to `docs/GOVERNMENT-FEATURES.md` (honest
  Beschikbaar scope).

## Acceptance criteria

- [x] Every gebruik derives a phase (incl. `Onbekend`) with zero stored phase
  fields; editing a date changes the derived phase (pure function, tested).
- [~] The EOL filter returns exactly in-window gebruiken — the window function
  is built + tested + drives roadmap badges + the notification rule; a
  dedicated index filter is deferred (no gebruik index page).
- [x] The roadmap answers "what phases out next, and what replaces it" for one
  organisation on one page.
- [x] Lifecycle notification rules are declared in the canonical dialect and
  enabled (the OR engine supports the date-window filter — no gap).
