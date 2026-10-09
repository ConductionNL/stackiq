# Tasks: cmdb-import-archive-reconciliation

Spec: `openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md` (`SPEC` below). Design: `design.md` (the decision table in D5 is authoritative for what happens per application).

## Implementation Tasks

### Task 1: Schema opt-in and the import profile
- **spec_ref**: `SPEC#requirement-archived-applications-and-usages-shall-be-listable-in-stackiq-and-shall-stay-out-of-opencatalogi-and-portaliq-by-default-req-cmdb-017`, `SPEC#requirement-records-missing-from-a-newer-export-shall-be-archived-by-default-or-kept-on-request-req-cmdb-012`
- **files**: `lib/Settings/register.d/topdesk-cmdb-import.json`, `lib/Settings/cmdb-import/topdesk-profile.json`, `lib/Service/Cmdb/CmdbImportProfile.php`, `tests/Unit/Settings/TopdeskCmdbFragmentTest.php`, `tests/Unit/Settings/PublicationFieldRulesTest.php`, `tests/Unit/Service/Cmdb/CmdbImportProfileTest.php`
- **acceptance_criteria**:
  - GIVEN every register fragment merged in filename order THEN `module` is 0.3.9 and `usage` is 1.5.7, both with `configuration.x-openregister-archive.enabled` true, and the usage lifecycle of 1.5.6 is still there
  - GIVEN the profile THEN `archiveSheetName()` is "Gearchiveerde Applicaties", `readSheetNames()` lists the two CMDB sheets plus the archive sheet, and `missingRecordsModes()` is `["keep", "archive"]` without OpenRegister
- [x] Implement
- [x] Test

### Task 2: The reader reads the archive sheet's APPIDs
- **spec_ref**: `SPEC#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015`
- **files**: `lib/Service/Cmdb/CmdbWorkbookReader.php`, `tests/Unit/Service/Cmdb/CmdbWorkbookReaderTest.php`
- **acceptance_criteria**:
  - GIVEN the anonymised fixture THEN `read()` returns `archive.present` true and `archive.appIds` `["1198"]`, and the rows of the CMDB sheets are unchanged
  - GIVEN a workbook without the sheet, or with the sheet but without an APPID column THEN `archive.present` is false (and `keyColumnMissing` says which), and the CMDB rows are still read
  - GIVEN an archive sheet beyond the row limit THEN `TOO_MANY_ROWS` names it; only its APPID column is materialised
- [x] Implement
- [x] Test

### Task 3: Matching revives archived and deleted applications
- **spec_ref**: `SPEC#requirement-an-application-that-returns-to-the-export-shall-be-unarchived-or-restored-never-duplicated-req-cmdb-016`
- **files**: `lib/Service/CmdbExportImportService.php`, `lib/Service/Cmdb/CmdbImportReport.php`, `tests/Unit/Service/CmdbExportImportServiceTest.php`, `tests/Unit/Service/Cmdb/CmdbImportReportTest.php`
- **acceptance_criteria**:
  - GIVEN an archived module and usage for an APPID WHEN a row with that APPID is imported THEN both are unarchived before the update, no second module or usage exists, and the row's outcome is `unarchived`
  - GIVEN a soft-deleted module and usage WHEN the row returns THEN both are restored from the trash and the outcome is `restored`
  - GIVEN `updateExisting` false THEN the row is skipped as `exists` and nothing is revived
- [x] Implement
- [x] Test

### Task 4: Reconciliation after the rows
- **spec_ref**: `SPEC#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015`
- **files**: `lib/Service/CmdbExportImportService.php`, `lib/Service/ProgressTracker.php`, `lib/Exception/CmdbImportException.php`, `tests/Unit/Service/CmdbExportImportServiceTest.php`
- **acceptance_criteria**:
  - GIVEN APPIDs 1 and 7 imported WHEN an export with row 1 and 7 on the archive sheet is imported THEN module and usage of 7 are archived with a reason, the report row is `archived`, and the owner contact person is untouched
  - GIVEN APPID 7 on no sheet THEN module and usage are soft-deleted and the row is `deleted`; GIVEN the archive sheet absent THEN they are archived instead and the report warns
  - GIVEN `missingRecords` `keep`, a cancelled import, or a usage of another municipality THEN nothing is archived or deleted
  - GIVEN the archive of one application throws THEN that application is a `failed` row and the others are reconciled; GIVEN no ArchiveHandler THEN 503 `ARCHIVE_UNAVAILABLE` before reading
  - GIVEN a cancel during the last row THEN nothing is archived or deleted; GIVEN a cancel during the reconciliation THEN it stops before the next application and the report is cancelled
  - GIVEN the step runs THEN the operation is in phase `reconciling` and its progress advances per application
- [x] Implement
- [x] Test

### Task 5: Controller option
- **spec_ref**: `SPEC#requirement-records-missing-from-a-newer-export-shall-be-archived-by-default-or-kept-on-request-req-cmdb-012`
- **files**: `lib/Controller/CmdbImportController.php`, `tests/Unit/Controller/CmdbImportControllerTest.php`, `openapi.json`, `postman/stackiq-tests.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN no `missingRecords` THEN the service receives `archive`; GIVEN `keep` or `archive` THEN it is passed through; GIVEN `remove` or `mark` THEN 422 `MISSING_RECORDS_UNSUPPORTED` with `details.accepted` `["keep", "archive"]` and no import
  - GIVEN `ARCHIVE_UNAVAILABLE` from the service THEN 503 with its own translated message
- [x] Implement
- [x] Test

### Task 6: Settings section, report words and the archive quick filter
- **spec_ref**: `SPEC#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014`, `SPEC#requirement-archived-applications-and-usages-shall-be-listable-in-stackiq-and-shall-stay-out-of-opencatalogi-and-portaliq-by-default-req-cmdb-017`
- **files**: `src/views/settings/sections/CmdbImport.vue`, `src/utils/cmdbImport.js`, `src/utils/cmdbImport.spec.js`, `src/views/settings/sections/CmdbImport.spec.js`, `tests/vitest/cmdbArchiveQuickFilter.spec.js`, `src/manifest.json`, `src/manifest.d/usages.json`, `src/icons.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the section THEN a labelled choice "Applications missing from the export" offers Archive (default) and Keep with help text, and `buildImportForm()` sends the choice as `missingRecords`
  - GIVEN a report with the new outcomes THEN the summary shows their tiles, the outcome filter offers them, and a reconciliation row shows no row number
  - GIVEN the Applications and Applications in use pages THEN a quick filter "Archived" sends `_archived=true`
  - Every new string is in `l10n/en.json` and `l10n/nl.json`, and the generated `l10n/*.js` are current
- [x] Implement
- [x] Test

### Task 7: Fixture, docs and e2e
- **spec_ref**: `SPEC#requirement-an-application-missing-from-the-cmdb-sheets-shall-be-archived-when-the-archive-sheet-lists-it-and-soft-deleted-when-no-sheet-does-req-cmdb-015`
- **files**: `tests/fixtures/cmdb/build-fixtures.py`, `tests/fixtures/cmdb/topdesk-archived-applications.xlsx`, `tests/fixtures/cmdb/README.md`, `tests/Unit/Fixtures/CmdbFixtureHygieneTest.php`, `tests/Unit/Service/Cmdb/CmdbWorkbookReaderTest.php`, `tests/Unit/Service/CmdbExportImportServiceTest.php`, `tests/e2e/spec-coverage/cmdb-import.spec.ts`, `docs/features/cmdb-import.md`, `docs/features/README.md`, `CHANGELOG.md`
- **acceptance_criteria**:
  - GIVEN `build-fixtures.py` THEN it derives `topdesk-archived-applications.xlsx`: APPID 1234 on the archive sheet only, APPID 2 on no sheet, both CMDB sheets present and empty; the hygiene test passes on it
  - GIVEN the e2e THEN after the first import, importing the variant archives 1234 and soft-deletes 2, the Applications page lists 1234 under "Archived", and importing the original again reports 1234 `unarchived` and 2 `restored` with the object counts back to the first import's
  - GIVEN the docs THEN "Repeat imports" describes archive, soft delete, the archive sheet, the option and the return of an application, and the error table has `ARCHIVE_UNAVAILABLE` and the new meaning of `MISSING_RECORDS_UNSUPPORTED`
- [x] Implement
- [ ] Test
  - Status: PHPUnit covers the fixture round trip (testTheArchivedApplicationsFixtureArchivesDeletesAndBringsBack); the Playwright test is written and not run yet, because no Nextcloud was reachable from the worktree.

## Quality checklist

- PHPUnit for every new rule in the service, the reader and the controller; the fixture hygiene test covers the new variant
- Jest for the section's request and the report words; Playwright for the re-import with the archive sheet
- `composer test:unit`, `composer phpcs`, `composer lint`, `npm run test:unit`, `npm run lint`, `npm run format`, `npm run test:l10n`, `npm run check:l10n-js`, `npm run check:manifest`, `npm run check:schema-l10n` and `npm run check:vue-demi` pass
