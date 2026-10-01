# Tasks: cmdb-export-import

Spec: `openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md` (`SPEC` below). Contract: `contract.md` (authoritative for routes, request fields, report shape and error codes).

## Implementation Tasks

### Task 1: Sanitised test fixtures
- **spec_ref**: `SPEC#requirement-req-cmdb-002-the-workbook-shall-be-read-as-stored-data-without-evaluating-formulas-or-following-links` (cmdb-export-import#REQ-CMDB-002, also used by every other task)
- **files**: `tests/fixtures/cmdb/topdesk-export-anonymised.xlsx`, `tests/fixtures/cmdb/topdesk-missing-middel-id.xlsx`, `tests/fixtures/cmdb/topdesk-shuffled-columns.xlsx`, `tests/fixtures/cmdb/topdesk-formula-and-connection.xlsx`, `tests/fixtures/cmdb/README.md`, `tests/fixtures/cmdb/build-fixtures.py`
- **acceptance_criteria**:
  - GIVEN the anonymised test export from the WOO-586 plan folder WHEN it is copied to `topdesk-export-anonymised.xlsx` THEN `docProps/core.xml` has no creator or lastModifiedBy, and `docProps/custom.xml`, `customXml/` and `xl/connections.xml` are removed, with their entries in `[Content_Types].xml` and the rels files
  - GIVEN the sanitised fixture WHEN every shared string and cell value is scanned THEN no real person name, municipality domain, personnel number or phone number remains, only the placeholder values (`Achternaam, Voornaam`, `letter.achternaam@gemeente.nl`, `123456`)
  - GIVEN `build-fixtures.py` WHEN it runs (Python stdlib zipfile only) THEN it derives the three variant fixtures from the sanitised one: no "Middel-ID" header on "Invoer APP data"; shuffled columns with header `Groepseigenaar mail⚡`; a formula cell in "Naam" with cached value `Rekenmodel` plus a synthetic `xl/connections.xml`
  - The original export of the municipality is never used or committed
- [x] Implement
- [x] Test (the scan is a PHPUnit test `tests/Unit/Fixtures/CmdbFixtureHygieneTest.php` that fails on metadata or non-placeholder person data)

### Task 2: Register fragment with external-id properties and seed modules
- **spec_ref**: `SPEC#requirement-req-cmdb-006-a-module-shall-be-matched-on-its-topdesk-middel-id-so-a-re-import-updates-instead-of-duplicating` (cmdb-export-import#REQ-CMDB-006)
- **files**: `lib/Settings/register.d/topdesk-cmdb-import.json`, `tests/Unit/Settings/TopdeskCmdbFragmentTest.php`
- **acceptance_criteria**:
  - GIVEN all `register.d` fragments WHEN they are merged in filename order the way `SettingsService` does THEN `module.version` is `0.3.5` and `externalId`, `externalNumber`, `externalKey`, `externalCreatedAt`, `externalModifiedAt` exist, none required, with titles (hydra gate schema-property-titles)
  - GIVEN the fragment WHEN the register is imported on the rig THEN existing modules load and save unchanged, and the seed modules `voorbeeld-zaaksysteem`, `voorbeeld-afsprakenplanner` and `voorbeeld-belastingapplicatie` exist without `publicationDate` or `externalKey` (design.md, Seed Data)
- [x] Implement
- [x] Test

### Task 3: Import profile, mapping packs and their loader
- **spec_ref**: `SPEC#requirement-req-cmdb-005-field-mapping-shall-be-declarative-and-executed-by-openregisters-mapping-engine` (cmdb-export-import#REQ-CMDB-005)
- **files**: `lib/Settings/cmdb-import/topdesk-profile.json`, `lib/Settings/cmdb-import/topdesk-module.json`, `lib/Settings/cmdb-import/topdesk-manufacturer.json`, `lib/Settings/cmdb-import/topdesk-municipality.json`, `lib/Settings/cmdb-import/topdesk-usage.json`, `lib/Settings/cmdb-import/topdesk-business-owner.json`, `lib/Settings/cmdb-import/topdesk-technical-owner.json`, `lib/Service/Cmdb/CmdbImportProfile.php`, `lib/Exception/CmdbImportException.php`, `tests/Unit/Service/Cmdb/CmdbImportProfileTest.php`
- **acceptance_criteria**:
  - GIVEN the six packs WHEN each is passed to OpenRegister's `PackDefinitionValidator` THEN all are valid with `sourceFormat: excel` and `idStrategy: generate`, and they implement the column table in design.md
  - GIVEN a pack with an unknown transform, or no `MappingEngine` in the container WHEN the profile loads THEN it throws `CmdbImportException` with code `MAPPING_UNAVAILABLE` and status 503
  - GIVEN the profile WHEN its referenced columns are listed THEN Personeelsnummer, phone, group-owner and group-mailbox columns are not among them
- [x] Implement
- [x] Test

### Task 4: Workbook reader and row normaliser
- **spec_ref**: `SPEC#requirement-req-cmdb-002-…` and `SPEC#requirement-req-cmdb-003-columns-shall-be-resolved-by-header-name-and-a-missing-required-column-shall-stop-the-import-with-422` (cmdb-export-import#REQ-CMDB-002, #REQ-CMDB-003, #REQ-CMDB-005)
- **files**: `lib/Service/Cmdb/CmdbWorkbookReader.php`, `lib/Service/Cmdb/CmdbRowNormaliser.php`, `tests/Unit/Service/Cmdb/CmdbWorkbookReaderTest.php`, `tests/Unit/Service/Cmdb/CmdbRowNormaliserTest.php`
- **acceptance_criteria**:
  - GIVEN the sanitised fixture WHEN it is read THEN exactly one row per source sheet is returned (empty formatted rows dropped), keyed by profile column names, with only allowlisted columns
  - GIVEN the formula/connection fixture WHEN it is read THEN "Naam" is `Rekenmodel`, `getCalculatedValue()` is never called, and no HTTP client is involved
  - GIVEN the shuffled fixture WHEN it is read THEN rows equal those of the original; GIVEN the missing-column fixture THEN `MISSING_COLUMN` names `Middel-ID` and `Invoer APP data`; GIVEN only "Blad1" THEN `NO_SOURCE_SHEET`; GIVEN more than `maxRowsPerSheet` rows THEN `TOO_MANY_ROWS`
  - GIVEN a text file named `.xlsx`, or a `.xlsm` WHEN checked THEN `NOT_XLSX` before PhpSpreadsheet is touched; GIVEN PhpSpreadsheet absent THEN `READER_UNAVAILABLE`
  - GIVEN serials `45111.380322627316`, `46232.552113113423`, `53359` and id `1234.0` WHEN normalised THEN `2023-07-04`, `2026-07-29`, `2046-02-01` and `"1234"`
- [x] Implement
- [x] Test

### Task 5: Import service: municipality, manufacturer, module upsert, usage
- **spec_ref**: `SPEC#requirement-req-cmdb-004-every-import-shall-have-exactly-one-consuming-municipality-chosen-by-the-admin`, `SPEC#requirement-req-cmdb-006-…`, `SPEC#requirement-req-cmdb-007-a-newly-created-module-shall-get-a-publicationdate-and-an-existing-one-shall-keep-its-own`, `SPEC#requirement-req-cmdb-008-a-manufacturer-shall-become-one-supplier-organisation-however-many-rows-name-it`, `SPEC#requirement-req-cmdb-009-each-imported-application-shall-have-one-usage-that-links-it-to-the-municipality`, `SPEC#requirement-req-cmdb-012-records-missing-from-a-newer-export-shall-be-left-untouched`
- **files**: `lib/Service/CmdbExportImportService.php`, `tests/Unit/Service/CmdbExportImportServiceTest.php`
- **acceptance_criteria**:
  - GIVEN the sanitised fixture and "Gemeente Voorbeeldstad" WHEN imported THEN two modules with `externalKey` `topdesk:<uuid>:<Middel-ID>`, `publicationDate` = import start, `provider` set, and two usages with `consumer` = the municipality and `module` = the module
  - GIVEN the same import twice WHEN run THEN 0 created / 2 unchanged and no `saveObject()` call for unchanged objects; GIVEN a changed "Naam" THEN one module updated, `website`, `publicationDate` and `depublicationDate` untouched
  - GIVEN `municipalityName` twice THEN one Municipality; GIVEN the uuid of a Supplier THEN `MUNICIPALITY_INVALID`
  - GIVEN "Fabfrikant", "Fabfrikant " and "FABFRIKANT" THEN one Supplier; GIVEN an existing Supplier with the same name THEN it is reused
  - GIVEN `updateExisting=false` THEN matched rows are `skipped` (`exists`); GIVEN a second export without one Middel-ID THEN that module and usage are unchanged; GIVEN an unknown "Status" THEN `status` is dropped with a warning naming column and value
  - GIVEN the module pack mapping "Roepnaam" to shortDescription (test-only pack) THEN the module carries it, with no code change
  - Every new method carries `@spec openspec/changes/cmdb-export-import/tasks.md#task-5` (hydra gate spec-coverage)
- [x] Implement
- [x] Test

### Task 6: Owners as contact persons through Nextcloud Contacts
- **spec_ref**: `SPEC#requirement-req-cmdb-010-owners-shall-become-contact-persons-of-the-municipality-through-nextcloud-contacts-never-user-accounts` (cmdb-export-import#REQ-CMDB-010)
- **files**: `lib/Service/CmdbExportImportService.php`, `tests/Unit/Service/CmdbExportImportServiceTest.php`
- **acceptance_criteria**:
  - GIVEN the AIA row WHEN imported THEN `StackiqContactSyncService` resolves the contact by e-mail, one `contactPerson` exists with that `contactsUid`, `organization` = municipality and `role` `Afdelingshoofd`, and it is the usage's `businessOwner`
  - GIVEN "FB contactpersoon 1" without e-mail WHEN imported twice THEN one contact (exact display-name match) and one `contactPerson`
  - GIVEN Contacts disabled WHEN imported THEN modules and usages are saved, owners skipped with a warning
  - GIVEN an imported `contactPerson` WHEN `OrganizationSyncService::performUserSync`'s selection is applied THEN it is not selected, and no Nextcloud user is created (if it would be, add an exclusion marker before shipping)
  - GIVEN any import WHEN the report and log lines are inspected THEN no owner name or e-mail appears
- [x] Implement
- [x] Test

### Task 7: Row isolation, report, progress and cancel
- **spec_ref**: `SPEC#requirement-req-cmdb-011-each-row-shall-be-processed-in-isolation-and-reported-with-its-outcome`, `SPEC#requirement-req-cmdb-013-a-running-import-shall-report-its-progress-and-shall-stop-when-cancelled`
- **files**: `lib/Service/CmdbExportImportService.php`, `lib/Service/Cmdb/CmdbImportReport.php`, `tests/Unit/Service/CmdbExportImportServiceTest.php`
- **acceptance_criteria**:
  - GIVEN three rows where saving the second module throws WHEN imported THEN rows 1 and 3 are `created`, row 2 is `failed` naming the step, and the summary matches contract.md
  - GIVEN duplicate Middel-ID rows, a missing Middel-ID and a Soort `Hardware` THEN they are `skipped` with the reasons in the spec
  - GIVEN an `operationId` WHEN the import runs THEN a `cmdb_import` operation reports per-row progress, and after completion its statistics hold the report
  - GIVEN cancel requested after row 1 of three THEN one processed row, `cancelled: true`, row 1's objects kept
- [x] Implement
- [x] Test

### Task 8: Controller, routes and API tests
- **spec_ref**: `SPEC#requirement-req-cmdb-001-the-import-endpoint-shall-accept-only-a-bounded-xlsx-upload-from-a-nextcloud-admin` (cmdb-export-import#REQ-CMDB-001, #REQ-CMDB-012, #REQ-CMDB-013)
- **files**: `lib/Controller/CmdbImportController.php`, `appinfo/routes.php`, `tests/Unit/Controller/CmdbImportControllerTest.php`, `postman/stackiq-tests.json`, `openapi.json`
- **acceptance_criteria**:
  - GIVEN `cmdbImport#import` and `cmdbImport#cancel` WHEN their attributes are inspected THEN neither has `NoAdminRequired` or `NoCSRFRequired` (hydra gates route-auth, csrf-cochange, no-admin-idor)
  - GIVEN the validation order in design.md D10 THEN each error code from contract.md is returned with its status, and every service exception is translated (hydra gate controller-exception-translation)
  - GIVEN Newman WHEN run against the rig THEN 403 for a non-admin and for a `software-catalog-admins` member, 412 without requesttoken, 413 for an oversized file, 422 `MISSING_RECORDS_UNSUPPORTED`, and 200 with the report for the fixture
- [x] Implement
- [ ] Test

### Task 9: CMDB import section in admin settings, l10n and Playwright e2e
- **spec_ref**: `SPEC#requirement-req-cmdb-014-the-admin-settings-shall-offer-a-cmdb-import-section` (cmdb-export-import#REQ-CMDB-014, #REQ-CMDB-003, #REQ-CMDB-011)
- **files**: `src/views/settings/sections/CmdbImport.vue`, `src/views/settings/StackiqSettings.vue`, `l10n/en.json`, `l10n/en.js`, `l10n/nl.json`, `l10n/nl.js`, `tests/e2e/spec-coverage/cmdb-import.spec.ts`
- **acceptance_criteria**:
  - GIVEN a Nextcloud admin on stackiq's admin settings WHEN they choose "Gemeente Voorbeeldstad" and the sanitised fixture and press Import THEN a progress bar shows, then the summary (2 read, 2 created) and a `CnDataTable` report filterable by outcome with links to the modules
  - GIVEN a second import of the same file THEN the report shows 2 unchanged; GIVEN the missing-column fixture THEN the section shows column `Middel-ID` and sheet `Invoer APP data`; GIVEN a CSV THEN it shows the `NOT_XLSX` message
  - GIVEN the section WHEN the hydra gates run THEN admin-router, form-label-association, nc-input-labels, button-name, table-headers and modal-isolation pass, and no `v-html` renders report values
  - GIVEN a Dutch and an English locale THEN every new string, error message and report reason is translated
  - The e2e file references every `@e2e tests/e2e/spec-coverage/cmdb-import.spec.ts` scenario in the spec (hydra gate e2e-coverage)
- [x] Implement
- [ ] Test

### Task 10: Administrator documentation with screenshots
- **spec_ref**: `SPEC#purpose`
- **files**: `docs/features/cmdb-import.md`, `docs/images/cmdb-import-*.png`, `docs/features/README.md`
- **acceptance_criteria**:
  - GIVEN the docs page WHEN an administrator reads it THEN it covers the steps, the expected file structure (sheets, required and mapped columns, the column table), the error codes and what to do, repeat-import behaviour (match on Middel-ID per municipality, unchanged rows, records missing from the export stay, publicationDate rule), where owner contacts end up, and how to adjust the mapping JSON
  - GIVEN the prerequisites section THEN it explains the OpenCatalogi catalogue (registers `stackiq`, schema `module`) and the Portaliq account claim `stackiq.organisationId`, needed to see the data there
  - GIVEN Playwright MCP on the rig WHEN screenshots are taken of the empty section, a running import and a finished report (sanitised fixture only) THEN they are committed under `docs/images/`
- [ ] Implement
- [ ] Test (screenshots reviewed: no data other than the sanitised fixture visible)

## Quality checklist

- PHPUnit for all new business logic (`tests/Unit/`), at least 75% coverage of new code (ADR-009), using the sanitised xlsx fixtures (not mocked rows) for reader and service tests
- Newman/Postman for both new endpoints (Task 8); Playwright for the settings flow (Task 9)
- `composer test`, `newman run` and the Playwright spec pass on the local rig
- Test against OpenRegister on the rig: the saved objects pass schema validation (module 0.3.5, organization, usage, contactPerson)
- Hydra gates run locally (`scripts/run-hydra-gates.sh`); read the COVERAGE line and name any SKIPPED gate
- Dutch (`nl_NL`) and English (`en_US`) strings for every new user-facing string (ADR-005)
- Docs in `docs/features/cmdb-import.md` with screenshots (ADR-010)
- `openspec validate cmdb-export-import` passes
