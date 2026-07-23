# Tasks: bio-compliance-assessment

## Implementation Tasks

### Task 1: Register schema — bioMaatregel catalog + compliancy extension
- **spec_ref**: `openspec/changes/bio-compliance-assessment/specs/bio-compliance-assessment/spec.md#requirement-bio-measures-form-a-seedable-reference-catalog`, `openspec/changes/bio-compliance-assessment/specs/module-compliance-assessment/spec.md#requirement-compliance-records-link-modules-to-standard-versions-with-evidence`
- **files**: `lib/Settings/softwarecatalogus_register.json`
- **acceptance_criteria**:
  - GIVEN the register file WHEN a new `bioMaatregel` schema is added (code, naam, omschrijving, thema, bioVersie, bbnNiveau, bron; `authorization.read: ["public"]`) THEN it validates as OpenAPI 3.0.0 and imports cleanly
  - GIVEN the `compliancy` schema WHEN an optional `bioMaatregel` relation is added (parallel to `standaardversie`, `objectConfiguration.handling: related-object`) THEN existing `compliancy` objects remain valid (no new `required` fields)
  - Diff the edit against the current merge base before committing — a naive JSON union-merge can silently drop unrelated prior modifications to this file
- [x] Implement
- [x] Test

### Task 2: Register schema — module BBN/DPIA/verwerkingsregister fields + overdue-DPIA notification rule
- **spec_ref**: `openspec/changes/bio-compliance-assessment/specs/bio-compliance-assessment/spec.md#requirement-each-application-records-a-bbn-level`, `openspec/changes/bio-compliance-assessment/specs/bio-compliance-assessment/spec.md#requirement-each-application-tracks-dpia-status-and-review-dates`, `openspec/changes/bio-compliance-assessment/specs/bio-compliance-assessment/spec.md#requirement-application-references-its-register-van-verwerkingen-entry`, `openspec/changes/bio-compliance-assessment/specs/bio-compliance-assessment/spec.md#requirement-overdue-dpia-reviews-trigger-a-notification`
- **files**: `lib/Settings/softwarecatalogus_register.json`
- **acceptance_criteria**:
  - GIVEN the `module` schema WHEN `bbnLevel` (enum BBN1/BBN2/BBN3, `facetable: true`), `dpiaStatus`, `dpiaDate`, `dpiaVolgendeBeoordeling`, `dpiaDocumentRef`, `verwerkingsregisterRef` are added THEN all six are optional and existing `module` objects remain valid
  - GIVEN the `module` schema WHEN the `dpia-review-overdue` `x-openregister-notifications` rule is added (`scheduled` trigger, filter `dpiaStatus: executed` + `dpiaVolgendeBeoordeling: {operator: withinNext, value: "P0D"}`, channels `nc-notification`+`email`, recipients `softwarecatalog-admins` group + object-acl manage, nl/en subjects) THEN it passes `hydra-gate-notification-dialect`
  - Diff against the current merge base before committing (same union-merge trap as Task 1)
- [x] Implement
- [x] Test

### Task 3: Seed the BIO measure catalog
- **spec_ref**: `openspec/changes/bio-compliance-assessment/specs/bio-compliance-assessment/spec.md#requirement-bio-measures-form-a-seedable-reference-catalog`
- **files**: `lib/Settings/softwarecatalogus_register.json` (or repair-step seed data alongside it, following the existing `element`/GEMMA seed pattern), `lib/Migration/InitializeSettings.php` (repair step, if seed objects are inserted there rather than via register defaults)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the repair step runs THEN the `bioMaatregel` catalog contains the seeded BIO 2.0 measures (see design.md Seed Data)
  - GIVEN an upgrade on an existing install WHEN the repair step re-runs THEN seeding is idempotent (no duplicate entries)
- [x] Implement
- [x] Test

### Task 4: Frontend — BBN/DPIA/verwerkingsregister fields on the module form
- **spec_ref**: `openspec/changes/bio-compliance-assessment/specs/bio-compliance-assessment/spec.md#requirement-each-application-records-a-bbn-level`, `openspec/changes/bio-compliance-assessment/specs/bio-compliance-assessment/spec.md#requirement-each-application-tracks-dpia-status-and-review-dates`, `openspec/changes/bio-compliance-assessment/specs/bio-compliance-assessment/spec.md#requirement-application-references-its-register-van-verwerkingen-entry`
- **files**: `src/manifest.json` (ModuleDetail data widget `include` list)
- **acceptance_criteria**:
  - GIVEN a vendor editing an application WHEN they set `bbnLevel`, `dpiaStatus`, `dpiaDate`, `dpiaVolgendeBeoordeling`, a `dpiaDocumentRef` via NC Files, and `verwerkingsregisterRef` THEN all six persist and render on the application's detail view
- [x] Implement
- [x] Test

### Task 5: Frontend — BIO measures catalog pages + BIO measure compliance on compliancy form
- **spec_ref**: `openspec/changes/bio-compliance-assessment/specs/bio-compliance-assessment/spec.md#requirement-bio-measures-form-a-seedable-reference-catalog`, `openspec/changes/bio-compliance-assessment/specs/module-compliance-assessment/spec.md#requirement-compliance-records-link-modules-to-standard-versions-with-evidence`
- **files**: `src/manifest.json` (new `BioMaatregelen` index + `BioMaatregelDetail` detail pages, following the `element`/`Standaarden` pattern; `KompliantieDetail` widget updates for the `bioMaatregel` relation)
- **acceptance_criteria**:
  - GIVEN a user opens the BIO measures catalog THEN each entry shows code, title, theme, BIO version, applicable BBN level(s), and its linked compliance claims
  - GIVEN a user creates or edits a compliancy record THEN they can link it to a `bioMaatregel` instead of a `standaardversie`, with the same evidence fields
- [x] Implement
- [x] Test

### Task 6: Frontend — BIO coverage report (extends ComplianceMatrixView)
- **spec_ref**: `openspec/changes/bio-compliance-assessment/specs/bio-compliance-assessment/spec.md#requirement-organisation-bio-coverage-is-reportable`, `openspec/changes/bio-compliance-assessment/specs/module-compliance-assessment/spec.md#requirement-compliance-matrix-distinguishes-verified-from-claimed`
- **files**: `src/views/ComplianceMatrixView.vue`, `src/utils/complianceMatrix.js`
- **acceptance_criteria**:
  - GIVEN a user selects a BIO column source and an organisation scope in the matrix THEN each in-use application row shows its BBN level, DPIA status, and verified/claimed/none for each selected BIO measure
  - GIVEN an in-use application has no BBN level, DPIA data, or BIO measure compliance THEN it is listed with an explicit "none" state, never omitted
  - GIVEN a matrix URL with an encoded BIO selection THEN opening it renders the same selection without re-picking filters
- [x] Implement
- [x] Test

### Task 7: Frontend — catalog filter for BBN level / DPIA status
- **spec_ref**: `openspec/changes/bio-compliance-assessment/specs/bio-compliance-assessment/spec.md#requirement-catalog-can-be-filtered-by-bbn-level-and-dpia-status`
- **files**: `src/manifest.json` (Modules index filter config), module catalog/search filter component
- **acceptance_criteria**:
  - GIVEN a user applies the "without DPIA at BBN2+" filter THEN only modules with `bbnLevel` BBN2/BBN3 and `dpiaStatus` not executed are listed
  - GIVEN a user filters by `bbnLevel` alone THEN only modules with that level are listed
- [x] Implement
- [x] Test

### Task 8: i18n — Dutch and English strings
- **spec_ref**: `openspec/changes/bio-compliance-assessment/specs/bio-compliance-assessment/spec.md` (all requirements — new labels), `openspec/changes/bio-compliance-assessment/specs/module-compliance-assessment/spec.md`
- **files**: `l10n/nl.json`, `l10n/en.json` (or the app's existing i18n resource files)
- **acceptance_criteria**:
  - GIVEN the new field labels, filter labels, BIO measures catalog page, coverage report labels, and the `dpia-review-overdue` subject strings THEN both `nl_NL` and `en_US` translations exist and no new user-facing string is hardcoded
- [x] Implement
- [x] Test

### Task 9: Tests and documentation
- **spec_ref**: all requirements in both delta specs
- **files**: `tests/Unit/` (register import / schema validation, `complianceMatrix.js` mapper tests for the `bioMaatregel` column source), `docs/features/bio-compliance-assessment.md` + screenshots
- **acceptance_criteria**:
  - GIVEN the register import test suite WHEN it runs against the extended register THEN `bioMaatregel`, the `compliancy` extension, and the `module` field additions all validate
  - GIVEN the matrix mapper unit tests WHEN a `bioMaatregel` column source is used THEN verified/claimed/none states compute identically to the `standaardversie` path
  - GIVEN the notification rule WHEN validated against `hydra-gate-notification-dialect` THEN it passes
  - GIVEN the feature docs WHEN published THEN they include Playwright MCP screenshots of the module BBN/DPIA fields, the BIO measures catalog, the BIO coverage report, and the DPIA filter
  - New/changed business logic reaches ≥75% coverage (ADR-009)
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`), minimum 75% coverage for new code (ADR-009)
- New/changed API surface covered by Newman/Postman tests if any backend endpoint changes (none expected — this app queries OpenRegister directly from the frontend)
- UI changes covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`)
- Feature documentation updated in `docs/features/` with Playwright MCP screenshots (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for every new user-facing string (ADR-005)
- Register JSON edits (Tasks 1–3) are diffed against the merge base, not produced by a naive union-merge, before committing
- `openspec validate --change bio-compliance-assessment` passes
