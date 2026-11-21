# Tasks: architecture-round-trip-check

## Implementation tasks

### Task 1: Model comparator
- **spec_ref**: openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-001-stackiq-shall-compare-two-exchange-files-by-identifier-per-category
- **files**: `lib/Service/ArchiMateModelComparator.php`, `tests/Unit/Service/ArchiMateModelComparatorTest.php`
- **acceptance_criteria**:
  - GIVEN two files that differ in one relationship target WHEN they are compared THEN exactly one changed relationship is reported with the field target
  - GIVEN a file and itself in another order WHEN they are compared THEN no difference is reported
  - GIVEN more than 50 differences in a category WHEN they are compared THEN 50 examples and the full counts come back
- [x] Implement
- [x] Test (PHPUnit `ArchiMateModelComparatorTest`)

### Task 2: One conversion and one generation entry
- **spec_ref**: openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-002-the-check-shall-run-before-an-import-or-against-an-imported-model-without-writing
- **files**: `lib/Service/ArchiMateImportService.php`, `lib/Service/ArchiMateExportService.php`, `tests/Unit/Service/ArchiMateImportServiceConvertTest.php`
- **acceptance_criteria**:
  - GIVEN the fixture `lib/Settings/GEMMA_testdata_below_1_5mb.xml` WHEN it is converted by the optimised import with the save mocked and by `convertFileToObjects` THEN the object lists are equal
  - GIVEN `exportArchiMateXml` WHEN it runs THEN it calls `generateXmlFromObjects`, and the existing decomposition tests stay green
- [x] Implement
- [x] Test (PHPUnit `ArchiMateImportServiceConvertTest`, `ArchiMateImportServiceDecompositionTest`, `ArchiMateExportServiceDecompositionTest`)
- Built 21 Nov: `ArchiMateImportService::convertFileToObjects()` (parse, model identifier, transform, with the import's parsing and analyzing phases and both cancel checks; returns null when cancelled) is what `importArchiMateFileFromPathOptimized()` now calls, so its timings, phases and the recorded import goldens are unchanged. `ArchiMateExportService::generateXmlFromObjects()` (the former private `generateXmlDirectly()`) is what `exportArchiMateXml()` calls. `ArchiMateImportServiceConvertTest` uses `tests/data/archimate_golden.xml`: the 1.5 MB GEMMA fixture takes minutes through the mocked import, too slow for the unit suite.

### Task 3: Round-trip service with two modes
- **spec_ref**: openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-002-the-check-shall-run-before-an-import-or-against-an-imported-model-without-writing
- **files**: `lib/Service/ArchiMateRoundTripService.php`, `tests/Unit/Service/ArchiMateRoundTripServiceTest.php`
- **acceptance_criteria**:
  - GIVEN the before-import mode WHEN it runs on the fixture THEN no save method is called and every category is reported
  - GIVEN the against-imported mode and stored objects of the model WHEN it runs THEN it compares only objects with that model identifier
  - GIVEN no stored object with the model identifier WHEN the against-imported mode runs THEN it answers that the model is not imported
- [x] Implement
- [x] Test (PHPUnit `ArchiMateRoundTripServiceTest`)
- Built 21 Nov: `ArchiMateRoundTripService::checkBeforeImport()` and `checkAgainstImported($filePath, $storedObjects)`; the caller (task 4) hands it the AMEF register's stored objects, and only those whose `model_identifier` is the file's take part.

### Task 4: Admin route and removal of the old round trip
- **spec_ref**: openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-003-only-an-admin-shall-run-the-check-and-the-old-round-trip-endpoint-shall-be-removed
- **files**: `lib/Controller/SettingsController.php`, `appinfo/routes.php`, `lib/Service/ArchiMateService.php`, `src/store/modules/settings.js`, `tests/Unit/Controller/SettingsControllerRoundTripCheckTest.php`, `tests/Unit/Controller/SettingsControllerEmailArchiMateContractTest.php`, `tests/Unit/SettingsRouteTableTest.php`
- **acceptance_criteria**:
  - GIVEN a non-admin WHEN they post to the check route THEN the answer is 403 before the service runs
  - GIVEN a body with `file_path` WHEN it is posted THEN the answer is 400
  - GIVEN the route table WHEN it is read THEN `settings#testArchiMateRoundTrip` is gone and `settings#checkArchiMateRoundTrip` exists
  - GIVEN the source WHEN it is searched THEN `testRoundTrip`, `createTestArchiMateXml` and `createTempFile` are gone
- [x] Implement
- [x] Test (PHPUnit `SettingsControllerRoundTripCheckTest`, `SettingsRouteTableTest`, updated `SettingsControllerEmailArchiMateContractTest`)
- Built 21 Nov: `POST /api/archimate/round-trip-check` (`settings#checkArchiMateRoundTrip`): admin refusal first, 400 on `file_path`, on an unknown mode and on no upload; `ArchiMateService::checkRoundTrip()` reads the AMEF register's stored objects for the against-imported mode (`ArchiMateServiceRoundTripTest`). The old round trip, its route and the store action were already gone (`SettingsRouteTableTest::testTheOldArchiMateRoundTripIsNotRoutedOrCallable`), so `SettingsControllerEmailArchiMateContractTest` needed no change.

### Task 5: Report on the admin settings page
- **spec_ref**: openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-004-the-admin-settings-page-shall-show-the-round-trip-report
- **files**: `src/views/settings/sections/ArchiMateRoundTripCheck.vue`, `src/views/settings/sections/ArchiMateImportExport.vue`, `src/store/modules/settings.js`, `tests/e2e/workflows/archimate-round-trip-check.spec.ts`
- **acceptance_criteria**:
  - GIVEN the admin settings page WHEN the ArchiMate section renders THEN Check a model file offers a file picker, the two modes and a Check button
  - GIVEN a result WHEN it renders THEN a row per category shows the counts, examples expand per category, and the summary names the categories with losses or reads "No losses found"
- [x] Implement
- [ ] Test (Playwright `archimate-round-trip-check.spec.ts`)
- Built 21 Nov: `ArchiMateRoundTripCheck.vue` under the ArchiMate import and export (file picker, the two modes, Check, the table per category, examples per category with losses, the summary) over `src/utils/archiMateRoundTrip.js` (the upload request, rows and summary). Tests: `tests/vitest/archiMateRoundTrip.spec.js` and `tests/vitest/archiMateRoundTripCheck.spec.js`. The request lives in the util, not in `src/store/modules/settings.js`, because the section is its only reader. The Playwright spec `tests/e2e/workflows/archimate-round-trip-check.spec.ts` is written and not yet run against a live instance. English and Dutch strings are in `l10n/en.json` and `l10n/nl.json`.

### Task 6: Documentation and translations
- **spec_ref**: openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-004-the-admin-settings-page-shall-show-the-round-trip-report
- **files**: `docs/features/archimate-import-export.md`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the feature page WHEN it is read THEN it explains both modes and shows a report in a screenshot
  - GIVEN a Dutch instance WHEN the check renders THEN every label and summary reads in Dutch
- [ ] Implement
- [ ] Test (`tests/l10n` key parity, screenshot captured with Playwright)

Built: Tasks 1 to 4. `ArchiMateModelComparator::compare()` with the file reading in `ArchiMateModelReader`. Open: tasks 5 and 6. Task 2 has to keep the import's per-step timings, progress phases and cancellation checks while moving the conversion into one method, so it gets its own pass.

## Verification

- `openspec validate architecture-round-trip-check --type change --strict`
- PHPUnit: `ArchiMateModelComparatorTest`, `ArchiMateImportServiceConvertTest`, `ArchiMateRoundTripServiceTest`, `SettingsControllerRoundTripCheckTest`, `SettingsRouteTableTest`, and the existing ArchiMate decomposition tests
- Playwright: `tests/e2e/workflows/archimate-round-trip-check.spec.ts`
- Documentation in `docs/features/archimate-import-export.md` with a screenshot (ADR-010)
- English and Dutch strings for every new label (ADR-005)
