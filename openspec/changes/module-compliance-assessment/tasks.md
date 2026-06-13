# Tasks — module-compliance-assessment

## 1. Retrofit the existing pipeline

- [ ] 1.1 Spec-tag (`@spec openspec/...`) the existing
  `lib/EventListener/ModuleComplianceSubscriber.php` and
  `lib/Service/ModuleComplianceService.php` methods against this
  capability's subscriber requirement.
- [ ] 1.2 PHPUnit on `ModuleComplianceService`: derive-set correctness,
  duplicate-UUID dedup, unresolved-relation handling, and the loop guard
  (no save when the derived set equals stored `standaarden`); harden the
  guard if the current code re-saves unconditionally.
- [ ] 1.3 Align `docs/MODULE_COMPLIANCE_SUBSCRIBER.md` with the specced
  behavior (it currently documents implementation, not contract).

## 2. Schema: evidence reference

- [ ] 2.1 Add optional `bewijsReferentie` (NC Files reference) to the
  `compliancy` schema in `lib/Settings/softwarecatalogus_register.json`;
  bump schema version; import-over-existing-data test (legacy `bewijs`
  untouched).
- [ ] 2.2 Compliancy edit UI: NC Files picker for new/edited evidence; render
  legacy base64 `bewijs` view/download unchanged.

## 3. Compliance matrix view

- [ ] 3.1 Matrix data mapper: modules × selected standaardversies →
  verified / claimed / none cells (evidence = `bewijs` | `bewijsReferentie` |
  `url`); unresolved `standaardGemma`-only records flagged separately. Unit
  tests for all states.
- [ ] 3.2 Matrix manifest/page UI: filter-first (standards multi-select,
  optional module subset / organisation scope), distinct verified vs claimed
  rendering, cell → compliancy record + evidence, selection encoded in the
  URL. Degrade to "no standards imported — run ArchiMate import" guidance
  when no standaardversie elements exist.
- [ ] 3.3 NL + EN strings (English i18n keys); WCAG AA — cell states must not
  rely on color alone.

## 4. Catalog filter & organisation coverage

- [ ] 4.1 Standard-version filter on module listings/search (OR query over
  compliancy relations), with verified/claimed indicator per result.
- [ ] 4.2 Organisation coverage view: organisation + standard selection →
  per-gebruik application list with verified / claimed / none; applications
  without compliance data listed as none.

## 5. Tests & verification

- [ ] 5.1 Playwright e2e: record compliance with NC Files evidence, matrix
  three states + evidence open + shareable URL, catalog standard filter,
  organisation coverage view, legacy evidence access.
- [ ] 5.2 Newman: compliancy CRUD via the OR objects API, register-file shape
  (new `bewijsReferentie` field).
- [ ] 5.3 `composer check:strict` + hydra gates green (gate-16 `@spec` on the
  retrofitted methods, gate-19 annotations).
- [ ] 5.4 Update `docs/GOVERNMENT-FEATURES.md` and FEATURES.md with the
  honest compliance-assessment scope (claims + evidence, not certification).

## Acceptance criteria

- The existing subscriber pipeline is spec-covered, loop-guarded, and
  PHPUnit-tested without behavior regression.
- The matrix never renders a claim as verified; every verified cell traces
  to an evidence artifact.
- A buyer can shortlist modules by standard, and an organisation can see
  verified/claimed/none for every in-use application in one view.
- New evidence lands as NC Files links; legacy base64 evidence remains
  accessible; no migration performed.
