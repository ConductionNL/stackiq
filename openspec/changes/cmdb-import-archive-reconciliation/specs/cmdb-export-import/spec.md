# cmdb-export-import Specification

**Status**: in-progress
**Scope**: stackiq
**OpenSpec changes**:
- [cmdb-export-import](../../../archive/2026-10-05-cmdb-export-import/) _(archived 2026-10-05)_
- [cmdb-import-archive-reconciliation](../../)

## Purpose

A repeated CMDB import reconciles the applications a municipality uses with the newer export. An application whose APPID left the two CMDB sheets is archived in OpenRegister's archive state when the export's sheet "Gearchiveerde Applicaties" lists it, and soft-deleted into OpenRegister's trash when no sheet lists it any more; one that returns is unarchived or restored and updated as usual, never duplicated. Archived and deleted applications disappear from OpenCatalogi and Portaliq without a change there, and stackiq lists the archive on request (Jira WOO-587).

OpenRegister: `OCA\OpenRegister\Contract\ObjectServiceInterface` (`searchObjects()` with the `_archived`, `_includeDeleted` and `_ids` lenses, `deleteObject()`), `Service\Object\ArchiveHandler` (`archive()`, `unarchive()`) and `Db\MagicMapper::restoreObject()` (both resolved through the container behind a guard), and the schema annotation `x-openregister-archive`.

## ADDED Requirements

### Requirement: An application missing from the CMDB sheets SHALL be archived when the archive sheet lists it and soft-deleted when no sheet does (REQ-CMDB-015)

With `missingRecords` `archive`, after every row of an import that was not cancelled and did not fail, the import SHALL reconcile, under the register lock it already holds, every `usage` whose `consumer` is the imported municipality together with the `module` it points at, including archived and soft-deleted ones, and only those whose module carries this municipality's import key (`topdesk:<municipality uuid>:<APPID>`). It SHALL read the APPID column of the sheet "Gearchiveerde Applicaties" when the workbook has it, under the same size and row bounds as the CMDB sheets and without reading any other column of it. For an application whose APPID is on neither CMDB sheet: when the archive sheet lists the APPID, the import SHALL archive the usage and then the module through OpenRegister's archive state with a reason that names the import, restoring a soft-deleted one from the trash first; when no sheet lists it, the import SHALL soft-delete the usage and then the module through OpenRegister's trash. When the workbook has no archive sheet, or the sheet has no APPID column, the import SHALL archive and SHALL NOT soft-delete, and SHALL add a warning to the report. An application that is already in the state the export asks for SHALL be left alone and not reported. The import SHALL NOT touch contact persons, organisations, modules that carry another municipality's import key or no import key, or usages of another organisation. Each application SHALL be reconciled in its own error boundary: a failure SHALL be a `failed` report row naming the step and the exception class, and the other applications SHALL still be reconciled. The reconciliation SHALL report its progress as the phase `reconciling` of the import's operation. The report SHALL count `archived` and `deleted` in its summary and SHALL list each as a row with the APPID, the application name and both uuids, never person data. When OpenRegister's archive handler or object mapper is not available, an import with `missingRecords` `archive` SHALL be refused with 503 `ARCHIVE_UNAVAILABLE` before the file is read.

#### Scenario: An application that moved to the archive sheet is archived
@e2e tests/e2e/spec-coverage/cmdb-import.spec.ts

- **GIVEN** the modules with APPID `1234` and `2` were imported for "Gemeente Voorbeeldstad" with their usages
- **WHEN** an export is imported in which `1234` is only on "Gearchiveerde Applicaties" and `2` is on no sheet
- **THEN** the module and the usage of `1234` SHALL carry OpenRegister's archive state and the report row SHALL be `archived`
- **AND** the module and the usage of `2` SHALL be in OpenRegister's trash and the report row SHALL be `deleted`
- **AND** the Applications page SHALL list `1234` under the quick filter "Archived" and neither under "All"
- **AND** the owner contact person SHALL be unchanged

#### Scenario: Without the archive sheet a missing application is archived, never deleted
@e2e exclude Covered by the service test; tests/Unit/Service/CmdbExportImportServiceTest.php testWithoutTheArchiveSheetMissingApplicationsAreArchivedNotDeleted imports a workbook without the sheet and asserts the archive state, no delete, and the warning naming the sheet.

- **GIVEN** the module with APPID `7` and its usage were imported
- **WHEN** a workbook without "Gearchiveerde Applicaties" that no longer lists `7` is imported
- **THEN** the module and the usage of `7` SHALL be archived and SHALL NOT be deleted
- **AND** the report SHALL carry a warning that the sheet "Gearchiveerde Applicaties" was not found

#### Scenario: Keeping missing records, a cancelled import and another municipality leave everything as it is
@e2e exclude Covered by the service test; tests/Unit/Service/CmdbExportImportServiceTest.php testRecordsMissingFromTheExportStayWhenKept, testACancelledImportDoesNotReconcile and testAnotherMunicipalitysUsagesAreNeverReconciled assert no archive, no delete and no reconciliation row.

- **GIVEN** the modules with APPID `1` and `7` and their usages for "Gemeente Voorbeeldstad", and a usage of `7` for "Gemeente Anders"
- **WHEN** an export with only `1` is imported with `missingRecords` `keep`, or with `archive` but cancelled after row 1
- **THEN** no module or usage SHALL be archived or deleted
- **AND** when it is imported with `archive` and completes, only the usage of "Gemeente Voorbeeldstad" for `7` and the module SHALL be archived; the usage of "Gemeente Anders" SHALL be untouched

#### Scenario: One application that cannot be archived does not stop the others
@e2e exclude Fault injection; tests/Unit/Service/CmdbExportImportServiceTest.php testAFailingArchiveIsAFailedRowAndTheOthersAreReconciled makes the archive of one application throw and asserts a failed row naming the step, the other application archived, and the log line without a name.

- **GIVEN** the modules with APPID `7` and `8` left the export and are on the archive sheet
- **WHEN** archiving `7` throws
- **THEN** the report SHALL list `7` as `failed` with the step `archive` and the exception class
- **AND** `8` SHALL be archived

#### Scenario: Without the archive services an archiving import is refused before reading
@e2e exclude Environment condition; tests/Unit/Service/CmdbExportImportServiceTest.php testMissingArchiveServicesRefuseAnArchivingImport asserts 503 ARCHIVE_UNAVAILABLE with no read and no save, and that keep still imports; tests/Unit/Controller/CmdbImportControllerTest.php asserts the status and the translated message.

- **GIVEN** an OpenRegister without `ArchiveHandler`
- **WHEN** an export is imported with `missingRecords` `archive`
- **THEN** the endpoint SHALL answer 503 with error `ARCHIVE_UNAVAILABLE` and nothing SHALL be read or written
- **AND** the same export with `keep` SHALL be imported

### Requirement: An application that returns to the export SHALL be unarchived or restored, never duplicated (REQ-CMDB-016)

The import SHALL match a module by its import key, and a usage by consumer and module, among archived and soft-deleted objects as well as working ones. Before it updates a matched module or usage that is archived, it SHALL unarchive it through OpenRegister's archive state; before it updates one that is soft-deleted, it SHALL restore it from the trash. It SHALL then update the row as REQ-CMDB-006 and REQ-CMDB-009 describe, and SHALL NOT create a second module or usage. The row's outcome SHALL be `restored` when a module or usage was restored, else `unarchived` when one was unarchived, else the usual outcome; the summary SHALL count both. With `updateExisting` false the row SHALL be skipped as `exists` and nothing SHALL be revived. Organisations and contact persons SHALL keep matching among working objects only.

#### Scenario: An archived application returns to the export
@e2e tests/e2e/spec-coverage/cmdb-import.spec.ts

- **GIVEN** the module and the usage of APPID `1234` are archived and those of APPID `2` are in the trash
- **WHEN** an export that lists both on the CMDB sheets is imported
- **THEN** the report SHALL show `1234` as `unarchived` and `2` as `restored`
- **AND** there SHALL still be one module and one usage for each
- **AND** both SHALL be listed again without a lens

#### Scenario: A returning application is not revived when existing records are not updated
@e2e exclude Covered by the service test; tests/Unit/Service/CmdbExportImportServiceTest.php testUpdateExistingFalseLeavesAnArchivedMatchArchived asserts the row is skipped as `exists`, the archive state stays and no object is created.

- **GIVEN** the module and the usage of APPID `7` are archived
- **WHEN** a row with APPID `7` is imported with `updateExisting` false
- **THEN** the row SHALL be skipped with reason `exists`
- **AND** both SHALL stay archived, and no module or usage SHALL be created

### Requirement: Archived applications and usages SHALL be listable in stackiq and SHALL stay out of OpenCatalogi and Portaliq by default (REQ-CMDB-017)

The `module` and `usage` schemas SHALL declare `x-openregister-archive` with `enabled` true through the register fragment `topdesk-cmdb-import.json`, as `module` 0.3.9 and `usage` 1.5.7, so OpenRegister offers the archive state on both. Stackiq's Applications and Applications in use pages SHALL offer a quick filter "Archived" that lists the archive alone (`_archived=true`). No change SHALL be made to OpenCatalogi or Portaliq: both list through OpenRegister's default lens, so an archived or deleted application is not in OpenCatalogi's search results or in Portaliq's "Software we use", and an OpenCatalogi caller finds archived applications with `_archived=true` on the existing routes.

#### Scenario: The merged register offers the archive state on both schemas
@e2e exclude Configuration; tests/Unit/Settings/TopdeskCmdbFragmentTest.php testTheMergedSchemasOfferTheArchiveState asserts the versions and the annotation after merging every fragment.

- **GIVEN** every register fragment merged in filename order
- **THEN** `module` SHALL be version 0.3.9 and `usage` 1.5.7
- **AND** both SHALL declare `configuration.x-openregister-archive.enabled` true, and the usage lifecycle of 1.5.6 SHALL still be declared

#### Scenario: The Applications page lists the archive on request
@e2e tests/e2e/spec-coverage/cmdb-import.spec.ts

- **GIVEN** the module of APPID `1234` is archived
- **WHEN** an admin opens the Applications page
- **THEN** the module SHALL NOT be listed
- **AND** under the quick filter "Archived" it SHALL be listed

## MODIFIED Requirements

### Requirement: Records missing from a newer export SHALL be archived by default or kept on request (REQ-CMDB-012)

The import SHALL accept `missingRecords` with the values `keep` and `archive`; `archive` is the default. With `keep` it SHALL NOT change, archive, depublish or delete a module, usage, organisation or contact person because its APPID is absent from the upload. With `archive` it SHALL reconcile as REQ-CMDB-015 describes. Any other value, including the reserved `mark` and `remove`, SHALL be refused with 422 `MISSING_RECORDS_UNSUPPORTED`, naming both accepted values in `details.accepted`. The accepted values SHALL be declared in the import profile.

(Previously: `keep` was the only accepted value and the default; records missing from a newer export were always left untouched.)

#### Scenario: An application dropped from the export stays when records are kept
@e2e exclude Covered by the service test; tests/Unit/Service/CmdbExportImportServiceTest.php testRecordsMissingFromTheExportStayWhenKept imports two rows, then one with `missingRecords` `keep`, and asserts the other module and usage are unchanged.

- **GIVEN** the modules with APPID `1` and `7` were imported for "Gemeente Voorbeeldstad"
- **WHEN** a newer export that only contains APPID `1` is imported with `missingRecords` `keep`
- **THEN** the module with APPID `7` and its usage SHALL be unchanged

#### Scenario: Without the option a missing application is reconciled
@e2e exclude Validation; tests/Unit/Controller/CmdbImportControllerTest.php testMissingRecordsDefaultsToArchive asserts the service receives `archive` when the field is absent and the sent value otherwise.

- **GIVEN** a valid export
- **WHEN** a Nextcloud admin posts it without `missingRecords`
- **THEN** the import SHALL run with `archive`

#### Scenario: A reserved value is refused
@e2e exclude Validation; tests/Unit/Controller/CmdbImportControllerTest.php testAReservedMissingRecordsValueIsRefused.

- **GIVEN** a valid export
- **WHEN** a Nextcloud admin posts it with `missingRecords=remove`
- **THEN** the endpoint SHALL answer 422 with error `MISSING_RECORDS_UNSUPPORTED` and `details.accepted` `["keep", "archive"]`
- **AND** no object SHALL be written

### Requirement: The admin settings SHALL offer a CMDB import section (REQ-CMDB-014)

Stackiq's admin settings page SHALL show a section "CMDB import", rendered by the settings page and not registered as an in-app route. The section SHALL let the admin choose an existing municipality or type the name of a new one, choose an `.xlsx` file, choose what happens to applications missing from the export (archive, the default, or keep) with help text that says what each does, and start the import. While the import runs it SHALL show a progress bar and a Cancel button. Afterwards it SHALL show the summary, including the archived, unarchived, deleted and restored counts when they are not zero, and a report table that can be filtered by outcome, including the four new ones; a reconciliation row SHALL show no row number. Every control SHALL have a visible label, and every string SHALL be translatable.

(Previously: the section had no choice for missing records; the request always sent `keep`.)

#### Scenario: The admin runs an import from the settings page
@e2e tests/e2e/spec-coverage/cmdb-import.spec.ts

- **GIVEN** a Nextcloud admin on stackiq's admin settings page
- **WHEN** they choose "Gemeente Voorbeeldstad", choose the anonymised export and press "Import"
- **THEN** a progress bar SHALL appear while the import runs
- **AND** afterwards the summary and the report table SHALL be shown
- **AND** filtering the table on `created` SHALL show the two imported rows

#### Scenario: The admin chooses to keep missing applications
@e2e exclude Covered by the Jest test; src/utils/cmdbImport.spec.js asserts buildImportForm() sends `archive` by default and `keep` when chosen, and src/views/settings/sections/CmdbImport.spec.js asserts the section's default.

- **GIVEN** the section
- **WHEN** the admin selects "Keep them as they are" and starts the import
- **THEN** the request SHALL carry `missingRecords=keep`
- **AND** without a choice it SHALL carry `missingRecords=archive`

## Non-Functional Requirements

- **Performance:** the reconciliation SHALL page the municipality's usages with the import's page size and load their modules in one search per page, so an export of 1,100 rows adds a handful of searches, and SHALL make no write for an application that is already in the state the export asks for.
- **Security:** unchanged from the first change; the archive sheet is read under the same bounds, APPID column only; the scope is the imported municipality's usages; nothing is hard-deleted; every state change is an OpenRegister audit entry.
- **Privacy:** reconciliation rows and log lines carry APPIDs, module names and uuids only; contact persons are never touched.
- **Accessibility:** Target WCAG 2.2 AA. The new choice is a labelled radio group (SC 1.3.1, 3.3.2; gates `form-label-association`, `nc-input-labels`) with help text; the summary tiles and the outcome filter follow the existing pattern. New in 2.2: 2.4.11, 2.5.8 and 3.3.7 apply as before; 2.5.7, 3.2.6 and 3.3.8 do not apply (no dragging, no help mechanism, no authentication).
- **Internationalization:** Dutch and English MUST be supported (ADR-005) for the choice, the outcomes, the warning and the error message.

## Acceptance Criteria

- [ ] A re-import with the archive sheet archives the application that moved there and soft-deletes the one on no sheet; the report shows `archived` and `deleted`.
- [ ] Importing the original export again unarchives and restores them; no duplicate is created.
- [ ] With "Keep them as they are" nothing is archived or deleted.
- [ ] The Applications and Applications in use pages list archived records only under "Archived".
- [ ] OpenCatalogi's search and Portaliq's "Software we use" no longer show an archived application; `_archived=true` on OpenCatalogi's route finds it.

## Notes

- The decision table per application is in the change's [design.md](../../design.md), D5.
- The library does not render `@self.archived` on detail pages yet; a follow-up for `@conduction/nextcloud-vue`.
