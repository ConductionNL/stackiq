# Tasks: sbom-import

## Implementation Tasks

### Task 1: Register schema — `sbomComponent` + `moduleVersie` provenance fields
- **spec_ref**: `openspec/changes/sbom-import/specs/sbom-import/spec.md#requirement-imported-components-persist-as-openregister-objects-scoped-to-a-moduleversie`
- **files**: `lib/Settings/softwarecatalogus_register.json`
- **acceptance_criteria**:
  - GIVEN the updated register definition WHEN it is imported via the repair step THEN `sbomComponent` exists with `moduleVersie` (required, related-object), `name` (required), `version`, `purl`, `licenses[]`, optional `hashes[]`/`type`/`bomRef`
  - GIVEN the updated `moduleVersie` schema WHEN existing `moduleVersie` objects are loaded THEN they remain valid with `sbomLastImportedAt`/`sbomFormat`/`sbomFileName` unset
- [ ] Implement
- [ ] Test

### Task 2: `SbomParserService` — pure CycloneDX 1.5/1.6 parser
- **spec_ref**: `openspec/changes/sbom-import/specs/sbom-import/spec.md#requirement-cyclonedx-sbom-files-are-parsed-into-a-normalized-component-list`
- **files**: `lib/Service/SbomParserService.php`, `lib/Exception/UnsupportedSbomFormatException.php`, `tests/Unit/SbomParserServiceTest.php`, `tests/fixtures/sbom/cyclonedx-1.6-valid.json`, `tests/fixtures/sbom/cyclonedx-1.5-valid.json`, `tests/fixtures/sbom/cyclonedx-invalid-format.json`, `tests/fixtures/sbom/cyclonedx-with-vex.json`
- **acceptance_criteria**:
  - GIVEN a well-formed CycloneDX 1.6 fixture WHEN `parse()` is called THEN it returns component records with name/version/purl/licenses and makes no OR or HTTP call
  - GIVEN a fixture with `bomFormat != CycloneDX` or unsupported `specVersion` WHEN `parse()` is called THEN it throws `UnsupportedSbomFormatException` and returns no partial list
  - GIVEN a fixture with a top-level `vulnerabilities[]` VEX block WHEN `parse()` is called THEN it also returns `{cveId, componentBomRef}` pairs
- [ ] Implement
- [ ] Test

### Task 3: `SbomImportService` — soft-delete-aware replace, bounded batches, progress
- **spec_ref**: `openspec/changes/sbom-import/specs/sbom-import/spec.md#requirement-re-import-replaces-the-previous-component-set-and-is-soft-delete-aware`
- **files**: `lib/Service/SbomImportService.php`, `tests/Unit/SbomImportServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a `moduleVersie` with an existing live component set WHEN a new SBOM is imported for it THEN the previous set is soft-deleted and only the new set is live afterwards, in bounded batches
  - GIVEN a version with an already-trashed prior set from an earlier replace WHEN a third import runs THEN the already-trashed rows are not re-queried or re-deleted
  - GIVEN a parsed set of more than 50 components WHEN import runs THEN a `progress-tracking` operation is started, updated per batch, and completed, with its id returned in the response
  - GIVEN a successful import WHEN it completes THEN `moduleVersie.sbomLastImportedAt`/`sbomFormat`/`sbomFileName` are set
- [ ] Implement
- [ ] Test

### Task 4: `SbomController` upload + status endpoints
- **spec_ref**: `openspec/changes/sbom-import/specs/sbom-import/spec.md#requirement-uploaded-sbom-files-are-bounded-in-size-and-json-only`
- **files**: `lib/Controller/SbomController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an upload exceeding the configured max size WHEN it is posted THEN the endpoint rejects it before the parser runs and no `sbomComponent` objects change
  - GIVEN a non-JSON upload WHEN it is posted THEN the endpoint responds 400 and the previous component set is unchanged
  - GIVEN a user without admin group membership or manage-ACL on the target module WHEN they attempt an import THEN the endpoint responds 403 and creates no objects
- [ ] Implement
- [ ] Test

### Task 5: Render-time vulnerability match util
- **spec_ref**: `openspec/changes/sbom-import/specs/sbom-import/spec.md#requirement-components-are-matched-against-existing-kwetsbaarheden-without-external-calls`
- **files**: `src/utils/sbomVulnerabilityMatch.js`, `tests/vitest/sbomVulnerabilityMatch.spec.js`
- **acceptance_criteria**:
  - GIVEN a component with a VEX-extracted CVE id equal to an existing `kwetsbaarheid.cveCode` WHEN matches are computed THEN that component gets a confirmed match, computed on the fly and not read from a stored field
  - GIVEN a `kwetsbaarheid` linked to the version's parent module whose `naam` case-insensitively contains a component's name WHEN matches are computed THEN that component gets a possible match; a same-name `kwetsbaarheid` NOT linked to that module produces no match
  - GIVEN the match computation runs WHEN inspected THEN it issues zero HTTP requests (no `fetch`/`axios`/network call in the util)
- [ ] Implement
- [ ] Test

### Task 6: Components tab UI — `SbomComponentsPanel` + manifest wiring
- **spec_ref**: `openspec/changes/sbom-import/specs/sbom-import/spec.md#requirement-the-module-version-detail-page-shows-imported-components-with-summary-counts`
- **files**: `src/components/SbomComponentsPanel.vue`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a `moduleVersie` with an imported component set WHEN its Components tab is opened THEN the component list (name/version/purl/licenses) and summary counts (total, distinct licenses, matched vulnerabilities) render via `CnDataTable`
  - GIVEN a `moduleVersie` with no imported set WHEN its Components tab is opened THEN an empty state with an upload control renders and no summary counts show as non-zero
- [ ] Implement
- [ ] Test

### Task 7: i18n strings
- **spec_ref**: `openspec/changes/sbom-import/specs/sbom-import/spec.md#non-functional-requirements`
- **files**: `l10n/en.js`, `l10n/en.json`, `l10n/nl.js`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the Components tab, upload control, and confirmed/possible match badges WHEN rendered in Dutch or English THEN every new user-facing string resolves to a translated key in both locales (English source keys, per i18n convention)
- [ ] Implement
- [ ] Test

### Task 8: Optional SPDX JSON support
- **spec_ref**: `openspec/changes/sbom-import/specs/sbom-import/spec.md#notes`
- **files**: `lib/Service/SbomParserService.php`, `tests/fixtures/sbom/spdx-2.3-valid.json`, `tests/Unit/SbomParserServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a valid SPDX 2.3 JSON fixture WHEN `parseSpdx()` is called THEN it returns component records in the same DTO shape as `parse()` (name/version/purl/licenses)
  - GIVEN SPDX parsing proves non-trivial to share cleanly with the CycloneDX path WHEN this task is assessed THEN it is deferred to a follow-up change and this task is marked deferred with a reason, per the proposal's open question — the CycloneDX path (Tasks 1-7) already satisfies every MUST requirement
- [ ] Implement
- [ ] Test

### Task 9: Docs + traceability
- **spec_ref**: `openspec/changes/sbom-import/specs/sbom-import/spec.md#purpose`
- **files**: `docs/features/sbom-import.md`, `docs/images/sbom-import-*.png`
- **acceptance_criteria**:
  - GIVEN the Components tab is implemented WHEN documented THEN `docs/features/sbom-import.md` describes upload, replace-on-reimport, and confirmed/possible matching with Playwright-captured screenshots
  - GIVEN new/changed backend and frontend methods for this change WHEN inspected THEN each carries `@spec openspec/changes/sbom-import/specs/sbom-import/spec.md` (or a reason-bearing `@spec exclude`)
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`), including `SbomParserService` and `SbomImportService`, using real small CycloneDX fixtures (not mocked JSON shapes)
- New/changed API endpoints (`importSbom`, `getSbomImportStatus`) covered by Newman/Postman tests
- UI changes (Components tab, upload flow) covered by Playwright browser tests
- All tests pass (`composer test`, `newman run`); overall new-code coverage ≥ 75% (ADR-009)
- Feature documentation updated in `docs/features/sbom-import.md` with screenshots (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for every new user-facing string (ADR-005)
- `openspec validate --change sbom-import` passes
- No outbound HTTP call exists anywhere in the parse/import/match path (verified by the Task 2/5 tests and a structural check that neither service is given an HTTP client dependency)
