# Tasks — contract-administration

## 1. Manifest surface

- [ ] 1.1 Fix `Contracten` index columns in `src/manifest.json` to real schema
  fields: `contractNummer`, `contractType`, `startDatum`, `eindDatum`,
  `kosten`, `status`; verify against the deployed register schema.
- [ ] 1.2 Add the expiring-soon filter to the `Contracten` index (query on
  `eindDatum` within `contract_expiry_window_days`, default 90; active
  contracts only; ordered by `eindDatum` ascending).
- [ ] 1.3 Add a Contracts tab to the application (gebruik/module) detail page
  listing contracts related via `gebruik`/`dienst`, with number, type, end
  date, annualised cost, status, and navigation to `ContractDetail`.
- [ ] 1.4 Annualised-cost display: derive `kosten` × periode factor
  (Maandelijks ×12, Jaarlijks ×1, Eenmalig → one-off marker) in the list and
  detail views; totals per application and per organisation. Prefer OR
  facet/aggregation over client-side summing where the renderer allows.
- [ ] 1.5 NL + EN strings (English i18n keys) for all new labels.

## 2. Status maintenance

- [ ] 2.1 Check whether the OR lifecycle engine can express the date-driven
  `Actief → Verlopen` transition declaratively on the `contract` schema; if
  yes, declare it in `softwarecatalogus_register.json` and skip 2.2.
- [ ] 2.2 (Fallback) `lib/BackgroundJob/ContractStatusJob.php` — daily
  TimedJob flipping `Actief → Verlopen` past `eindDatum` only; register via
  `IRegistrationContext::registerJob` in `Application::register()`; verify
  with `occ background-job:list`.
- [ ] 2.3 PHPUnit: past/future/absent `eindDatum`, `In onderhandeling`
  untouched, no reverse transitions, idempotent re-runs.

## 3. Notifications

- [ ] 3.1 Add app-config `contract_expiry_window_days` (default 90) and wire
  the index filter + notification window defaults to it.
- [ ] 3.2 Confirm OR notification engine support for `scheduled` date-window
  filtering on `eindDatum`; if supported, set the window filter on the
  `contract-expiry` rule in `lib/Settings/softwarecatalogus_register.json`
  and flip `enabled: true`; if not, file the OR engine gap issue and record
  the blocker here (rule stays disabled — no app-local dispatch).
- [ ] 3.3 Integration test: rule fires for an in-window `Actief` contract,
  silent for out-of-window / non-active contracts.

## 4. Documents

- [ ] 4.1 Ensure `documentReferentie` is presented as an NC Files link in
  `ContractDetail` (picker on edit, link-out on view); no document content in
  the register.

## 5. Tests & verification

- [ ] 5.1 Playwright e2e (UI scenarios): create contract, index columns render
  data, expiring-soon filter, application-detail Contracts tab + cost total,
  annualised cost rendering, document link.
- [ ] 5.2 Newman: contract CRUD via the OR objects API, rule-declaration shape
  check on the register file.
- [ ] 5.3 `composer check:strict` green; hydra gates green (`@spec` tags on
  any new PHP, gate-19 annotations on the spec scenarios).
- [ ] 5.4 Update README/docs: contract administration is now specced; document
  the SC-vs-shillinq CLM boundary (catalog metadata here, financial CLM
  there).

## Acceptance criteria

- Contracten index renders real field data; expiring-soon filter returns
  exactly the in-window active contracts.
- Application detail answers "which contracts, when do they expire, what do
  they cost per year" without leaving the page.
- An `Actief` contract with a past `eindDatum` becomes `Verlopen` within one
  scheduled run; nothing else is mutated.
- `contract-expiry` notification is enabled (or a concrete OR engine gap
  issue is linked explaining why not).
- No new contract controllers/services for CRUD; no stored derived costs.
