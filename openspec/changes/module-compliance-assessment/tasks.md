# Tasks — module-compliance-assessment

## 1. Retrofit the existing pipeline

- [x] 1.1 Spec-tag (`@spec openspec/...`) the existing
  `lib/EventListener/ModuleComplianceSubscriber.php` and
  `lib/Service/ModuleComplianceService.php` methods against this
  capability's subscriber requirement.
- [x] 1.2 PHPUnit on `ModuleComplianceService`: derive-set correctness,
  duplicate-UUID dedup, unresolved-relation handling, and the loop guard
  (no save when the derived set equals stored `standaarden`); harden the
  guard if the current code re-saves unconditionally.
  (Added `tests/Unit/Service/ModuleComplianceServiceTest.php`, 6 tests. The
  existing code already had an order-independent loop guard
  `arraysAreDifferent()` + `array_unique` dedup — verified, no hardening
  needed; the guard is now test-pinned.)
- [~] 1.3 Align `docs/MODULE_COMPLIANCE_SUBSCRIBER.md` with the specced
  behavior — DEFERRED. The behavior is now pinned by the spec + PHPUnit; the
  doc edit is a follow-up and out of the build's critical path.

## 2. Schema: evidence reference

- [x] 2.1 Add optional `bewijsReferentie` (NC Files reference) to the
  `compliancy` schema in `lib/Settings/softwarecatalogus_register.json`;
  bump schema version (0.0.10 → 0.0.11). The addition is additive/optional;
  legacy base64 `bewijs` is untouched and import-over-existing-data is
  non-destructive by construction (new optional property).
- [~] 2.2 Compliancy edit UI: NC Files picker for new/edited evidence —
  DEFERRED. The schema field exists and renders in the generic CnDetailPage
  data widget (manual entry of a Files path works today); the dedicated
  NcFilePicker integration on the compliancy edit form is a follow-up. The
  matrix already treats `bewijsReferentie` as evidence (mapper + tests).
  Legacy base64 `bewijs` remains view/download-able via the existing field.

## 3. Compliance matrix view

- [x] 3.1 Matrix data mapper: modules × selected standaardversies →
  verified / claimed / none cells (evidence = `bewijs` | `bewijsReferentie` |
  `url`); unresolved `standaardGemma`-only records flagged separately.
  (`src/utils/complianceMatrix.js` + 15 vitest unit tests covering all states.)
- [x] 3.2 Matrix manifest/page UI: filter-first (standards multi-select),
  distinct verified vs claimed rendering, cell → compliancy record, selection
  encoded in the URL (`?standards=`). Degrades to "no standards imported"
  guidance when no standaardversie elements exist, and to a "select standards"
  prompt before a selection is made. (`src/views/ComplianceMatrixView.vue`,
  registered in `customComponents.js` + `registry.js`, manifest page +
  menu entry.)
- [x] 3.3 NL + EN strings (English i18n keys); WCAG AA — cell states carry an
  icon + text label, not colour alone. All 16 new keys added across en/nl +
  the 34 other required locales (l10n parity green).

## 4. Catalog filter & organisation coverage

- [~] 4.1 Standard-version filter on module listings/search — DEFERRED to a
  follow-up. The matrix already answers "which modules support standard X"
  filter-first; a dedicated column filter on the module index needs the OR
  relation-query wiring on the generic CnIndexPage, out of this build's scope.
- [~] 4.2 Organisation coverage view UI — PARTIAL. The coverage computation is
  built and unit-tested (`buildOrganisationCoverage()` in
  `src/utils/complianceMatrix.js`), listing every gebruik including
  applications with no compliance data as `none`. The dedicated
  organisation-coverage PAGE wiring it to live gebruik data is a follow-up.

## 5. Tests & verification

- [x] 5.1 Playwright e2e for the matrix page surface
  (`tests/e2e/spec-coverage/compliance-matrix.spec.ts`): nav entry reaches the
  filter-first matrix surface, guidance empty-state renders, no app-origin
  errors. The data-dependent three-state cell rendering is covered
  exhaustively by the vitest unit tests; record-with-evidence / shareable-URL /
  legacy-evidence scenarios carry `@e2e exclude` where unit-tested.
- [~] 5.2 Newman: compliancy CRUD + register-file shape — DEFERRED. The
  register-file shape (new `bewijsReferentie` field) is validated by JSON +
  hydra gates; a dedicated Newman request is a follow-up.
- [x] 5.3 hydra gates green (all 24, incl. gate-16 `@spec` on the retrofitted
  + new methods, gate-19 e2e, gate-4 composer-audit — also fixed the
  pre-existing guzzlehttp/psr7 CVE by bumping to 2.11.0). vitest 79 green,
  PHPUnit 161 green.
- [x] 5.4 Update `docs/GOVERNMENT-FEATURES.md` with the honest
  compliance-assessment scope (claims + evidence, not certification).

## Acceptance criteria

- [x] The existing subscriber pipeline is spec-covered, loop-guarded, and
  PHPUnit-tested without behavior regression.
- [x] The matrix never renders a claim as verified; every verified cell traces
  to an evidence artifact (mapper + tests enforce this).
- [~] A buyer can shortlist modules by standard (via the matrix filter-first
  view; dedicated index filter deferred), and an organisation can see
  verified/claimed/none for every in-use application (coverage computation
  built + tested; dedicated page deferred).
- [x] New evidence lands as NC Files links (`bewijsReferentie` field + mapper
  support); legacy base64 evidence remains accessible; no migration performed.
