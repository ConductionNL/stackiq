# Tasks — contract-administration

## 1. Manifest surface

- [x] 1.1 Fix `Contracten` index columns to real schema fields:
  `contractNummer`, `contractType`, `startDatum`, `eindDatum`, `kosten`,
  `status` (replacing drifted `naam`/`leverancier`/`ingangsdatum`/`einddatum`).
- [x] 1.2 Expiring/status quick-filter tabs on the `Contracten` index (All /
  Active / Expiring-expired / In negotiation), merged into the OR list fetch.
  Note: the precise relative-date window ("eindDatum within N days") is NOT
  expressible as a static manifest filter literal (the OR list-query filter
  map has no relative-now operator); that window IS applied server-side by the
  enabled `contract-expiry` notification rule (`withinNext P90D`). The index
  offers the status tabs.
- [~] 1.3 Contracts tab on the application (gebruik/module) detail page —
  DEFERRED. softwarecatalog has no standalone gebruik/module manifest detail
  page (applications are managed inside the Organisaties custom view); adding
  a per-application Contracts tab needs custom-view work out of this change's
  scope. The annualised-cost utility (`totalAnnualisedCost`) that such a tab
  would consume is built + unit-tested.
- [x] 1.4 Annualised-cost derivation (`src/utils/contractCost.js`):
  Maandelijks ×12, Jaarlijks ×1, Eenmalig as a separate one-off; per-set
  totals via `totalAnnualisedCost`. Never persisted. 10 vitest tests.
- [x] 1.5 NL + EN strings (English i18n keys) for the new quick-filter labels,
  across en/nl + 34 required locales (parity green).

## 2. Status maintenance

- [x] 2.1 Checked the OR lifecycle engine: `TransitionEngine` only expresses
  guarded MANUAL transitions (caller must hold `update` permission) and has no
  scheduled date-driven path — so the declarative option is unavailable.
  Proceeded with 2.2.
- [x] 2.2 `lib/BackgroundJob/ContractStatusJob.php` (daily TimedJob) +
  `lib/Service/ContractStatusService.php` (decision + OR I/O), registered via
  `appinfo/info.xml` `<background-jobs>` + `Application::register()`. Only
  performs `Actief → Verlopen` past `eindDatum`.
- [x] 2.3 PHPUnit (`ContractStatusServiceTest`, 7 tests): past/future/absent/
  unparseable `eindDatum`, `In onderhandeling` untouched, already-`Verlopen`
  not reprocessed (no reverse), degrade-without-OpenRegister.

## 3. Notifications

- [x] 3.1 App-config `contract_expiry_window_days` (default 90) seeded in the
  repair step (only when unset, so admin overrides survive upgrades).
- [x] 3.2 Confirmed OR notification engine `scheduled` date-window support
  (`ScheduledFilterEvaluator` `withinNext` operator, ISO-8601 duration, wired
  through `ScheduledNotificationJob`). Enabled the `contract-expiry` rule:
  flipped `enabled: true`, FIXED the rule's mis-shaped filter key
  (`op` → `operator`, which the evaluator requires), and added the
  `eindDatum: { operator: withinNext, value: P90D }` window. Schema version
  bumped 0.1.0 → 0.1.1.
- [~] 3.3 Integration test for in/out-of-window dispatch — DEFERRED. The OR
  engine's `withinNext` evaluator is already unit-tested upstream in
  OpenRegister; the rule-declaration shape is validated by gate-18
  (notification-dialect, green) + JSON validity.

## 4. Documents

- [x] 4.1 `documentReferentie` is presented in `ContractDetail` via the
  generic data widget (link field). The schema field is an NC Files reference;
  no document content is stored in the register.

## 5. Tests & verification

- [x] 5.1 Playwright e2e
  (`tests/e2e/spec-coverage/contract-administration.spec.ts`): Contracten index
  renders real-field columns + status quick-filters, no app-origin errors.
- [~] 5.2 Newman: contract CRUD + rule-declaration shape — DEFERRED. The rule
  shape is validated by gate-18 + JSON; CRUD rides the generic OR objects API.
- [x] 5.3 hydra gates green (all 24, incl. gate-18 notification-dialect on the
  enabled rule, gate-16 `@spec` on new PHP/JS). vitest 74, PHPUnit 162.
- [x] 5.4 Updated `docs/GOVERNMENT-FEATURES.md`: contract administration is now
  specced + the SC-vs-shillinq CLM boundary (catalog metadata here, financial
  CLM there).

## Acceptance criteria

- [x] Contracten index renders real field data; status quick-filters work; the
  date-window "expiring soon" is enforced by the notification rule.
- [~] Application detail "which contracts, when, what per year" — the cost
  utility is built + tested; the per-application Contracts tab is deferred
  (no gebruik/module manifest detail page exists).
- [x] An `Actief` contract with a past `eindDatum` becomes `Verlopen` within
  one scheduled run (ContractStatusJob + service); nothing else is mutated.
- [x] `contract-expiry` notification is enabled with a confirmed OR engine
  date-window filter (no OR engine gap — the engine supports `withinNext`).
- [x] No new contract controllers/services for CRUD; no stored derived costs.
