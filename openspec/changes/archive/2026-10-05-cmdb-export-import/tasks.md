# Tasks: cmdb-export-import

Spec: `openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md` (`SPEC` below). Contract: `contract.md` (authoritative for routes, request fields, report shape and error codes).

## Implementation Tasks

### Task 1: Sanitised test fixtures
- **spec_ref**: `SPEC#requirement-the-workbook-shall-be-read-as-stored-data-without-evaluating-formulas-or-following-links-req-cmdb-002` (cmdb-export-import#REQ-CMDB-002, also used by every other task)
- **files**: `tests/fixtures/cmdb/topdesk-export-anonymised.xlsx`, `tests/fixtures/cmdb/topdesk-missing-appid.xlsx`, `tests/fixtures/cmdb/topdesk-shuffled-columns.xlsx`, `tests/fixtures/cmdb/topdesk-formula-and-connection.xlsx`, `tests/fixtures/cmdb/README.md`, `tests/fixtures/cmdb/build-fixtures.py`
- **acceptance_criteria**:
  - GIVEN an export that is already anonymised WHEN `build-fixtures.py --source` sanitises it into `topdesk-export-anonymised.xlsx` THEN the document properties in `docProps/core.xml` and `docProps/app.xml` are empty, both dates are a fixed neutral date, the revision GUIDs and the filter ranges of the original data are gone, and `docProps/custom.xml`, `customXml/`, `xl/connections.xml` and `xl/printerSettings/` are removed, with their entries in `[Content_Types].xml` and the rels files
  - GIVEN the sanitised fixture WHEN every shared string and cell value is scanned THEN no real person name, municipality domain, personnel number or phone number remains, only the placeholder values (`Achternaam, Voornaam`, `letter.achternaam@gemeente.nl`, `123456`)
  - GIVEN `build-fixtures.py` WHEN it runs (Python stdlib zipfile only) THEN it writes placeholder cached values into the formula cells of the mapped CMDB columns (idempotent) and derives the variant fixtures: no "APPID" header on "Beheerde Applicaties CMDB"; both CMDB sheets with shuffled columns and header `Vendor⚡`; on "Beheerde" a formula in "Applicatie Naam" with cached value `Rekenmodel`, a "Roepnaam" formula without a cached value, plus a synthetic `xl/connections.xml`
  - The original export of the municipality is never used or committed
- [x] Implement
- [x] Test (the scan is a PHPUnit test `tests/Unit/Fixtures/CmdbFixtureHygieneTest.php` that fails on metadata or non-placeholder person data)

### Task 2: Register fragment with external-id properties and seed modules
- **spec_ref**: `SPEC#requirement-a-module-shall-be-matched-on-its-topdesk-appid-so-a-re-import-updates-instead-of-duplicating-req-cmdb-006` (cmdb-export-import#REQ-CMDB-006)
- **files**: `lib/Settings/register.d/topdesk-cmdb-import.json`, `tests/Unit/Settings/TopdeskCmdbFragmentTest.php`
- **acceptance_criteria**:
  - GIVEN all `register.d` fragments WHEN they are merged in filename order the way `SettingsService` does THEN `module.version` is `0.3.8` and `externalId`, `externalNumber`, `externalKey`, `externalCreatedAt`, `externalModifiedAt`, `applicationType` exist, none required, with titles (hydra gate schema-property-titles), `bbnLevel` allows `BBN2+`, and `externalKey` carries `authorization.update: ["admin"]`
  - GIVEN the fragment WHEN the register is imported on the rig THEN existing modules load and save unchanged, and the seed modules `voorbeeld-zaaksysteem`, `voorbeeld-afsprakenplanner` and `voorbeeld-belastingapplicatie` exist without `publicationDate` or `externalKey` (design.md, Seed Data)
- [x] Implement
- [x] Test

### Task 3: Import profile, mapping packs and their loader
- **spec_ref**: `SPEC#requirement-field-mapping-shall-be-declarative-and-executed-by-openregisters-mapping-engine-req-cmdb-005` (cmdb-export-import#REQ-CMDB-005)
- **files**: `lib/Settings/cmdb-import/topdesk-profile.json`, `lib/Settings/cmdb-import/topdesk-module.json`, `lib/Settings/cmdb-import/topdesk-manufacturer.json`, `lib/Settings/cmdb-import/topdesk-municipality.json`, `lib/Settings/cmdb-import/topdesk-usage.json`, `lib/Settings/cmdb-import/topdesk-business-owner.json`, `lib/Service/Cmdb/CmdbImportProfile.php`, `lib/Exception/CmdbImportException.php`, `tests/Unit/Service/Cmdb/CmdbImportProfileTest.php`
- **acceptance_criteria**:
  - GIVEN the five packs WHEN each is passed to OpenRegister's `PackDefinitionValidator` THEN all are valid with `sourceFormat: excel` and `idStrategy: generate`, and they implement the column table in design.md
  - GIVEN a pack with an unknown transform, or no `MappingEngine` in the container WHEN the profile loads THEN it throws `CmdbImportException` with code `MAPPING_UNAVAILABLE` and status 503
  - GIVEN the profile WHEN its referenced columns are listed THEN no person or group column other than "Applicatie Eigenaar (Persoon)" / "(Functie)" is among them, and neither are the sheet constants
- [x] Implement
- [x] Test

### Task 4: Workbook reader and row normaliser
- **spec_ref**: `SPEC#requirement-the-workbook-shall-be-read-as-stored-data-without-evaluating-formulas-or-following-links-req-cmdb-002` and `SPEC#requirement-columns-shall-be-resolved-by-header-name-and-a-missing-required-column-shall-stop-the-import-with-422-req-cmdb-003` (cmdb-export-import#REQ-CMDB-002, #REQ-CMDB-003, #REQ-CMDB-005)
- **files**: `lib/Service/Cmdb/CmdbWorkbookReader.php`, `lib/Service/Cmdb/CmdbRowNormaliser.php`, `tests/Unit/Service/Cmdb/CmdbWorkbookReaderTest.php`, `tests/Unit/Service/Cmdb/CmdbRowNormaliserTest.php`
- **acceptance_criteria**:
  - GIVEN the sanitised fixture WHEN it is read THEN exactly one row per CMDB sheet is returned (empty formatted rows and formula rows that cached `0` dropped), keyed by profile column names, with only allowlisted columns
  - GIVEN the formula/connection fixture WHEN it is read THEN "Applicatie Naam" is `Rekenmodel`, "Roepnaam" is empty and listed in the row's `uncached`, `getCalculatedValue()` is never called, and no HTTP client is involved
  - GIVEN the shuffled fixture WHEN it is read THEN rows equal those of the original; GIVEN the missing-column fixture THEN `MISSING_COLUMN` names `APPID` and `Beheerde Applicaties CMDB`; GIVEN only "Blad1" THEN `NO_SOURCE_SHEET`; GIVEN more than `maxRowsPerSheet` rows THEN `TOO_MANY_ROWS`
  - GIVEN a text file named `.xlsx`, or a `.xlsm` WHEN checked THEN `NOT_XLSX` before PhpSpreadsheet is touched; GIVEN PhpSpreadsheet absent THEN `READER_UNAVAILABLE`
  - GIVEN serials `45111.380322627316`, `46232.552113113423`, `53359` and id `1234.0` WHEN normalised THEN `2023-07-04`, `2026-07-29`, `2046-02-01` and `"1234"`; GIVEN "BNN Classificatie" `NB` THEN it is empty; GIVEN "End-of-Life Functioneel" `49675` THEN it is `2036-01-01`
- [x] Implement
- [x] Test

### Task 5: Import service: municipality, manufacturer, module upsert, usage
- **spec_ref**: `SPEC#requirement-every-import-shall-have-exactly-one-consuming-municipality-chosen-by-the-admin-req-cmdb-004`, `SPEC#requirement-a-module-shall-be-matched-on-its-topdesk-appid-so-a-re-import-updates-instead-of-duplicating-req-cmdb-006`, `SPEC#requirement-a-newly-created-module-shall-get-a-publicationdate-when-the-admin-publishes-and-an-existing-one-shall-keep-its-own-req-cmdb-007`, `SPEC#requirement-a-manufacturer-shall-become-one-supplier-organisation-however-many-rows-name-it-req-cmdb-008`, `SPEC#requirement-each-imported-application-shall-have-one-usage-that-links-it-to-the-municipality-req-cmdb-009`, `SPEC#requirement-records-missing-from-a-newer-export-shall-be-left-untouched-req-cmdb-012`
- **files**: `lib/Service/CmdbExportImportService.php`, `tests/Unit/Service/CmdbExportImportServiceTest.php`
- **acceptance_criteria**:
  - GIVEN the sanitised fixture and "Gemeente Voorbeeldstad" WHEN imported THEN two modules with `externalKey` `topdesk:<uuid>:<APPID>`, `publicationDate` = import start, `provider` set, and two usages with `consumer` = the municipality and `module` = the module
  - GIVEN the same import twice WHEN run THEN 0 created / 2 unchanged and no `saveObject()` call for unchanged objects; GIVEN a changed "Applicatie Naam" THEN one module updated; GIVEN a changed "Applicatie Code" for the same APPID THEN the same module updated, `website`, `publicationDate` and `depublicationDate` untouched
  - GIVEN `municipalityName` twice THEN one Municipality; GIVEN the uuid of a Supplier THEN `MUNICIPALITY_INVALID`
  - GIVEN "Vendor" "Fabfrikant", "Fabfrikant " and "FABFRIKANT" THEN one Supplier; GIVEN an existing Supplier with the same name THEN it is reused
  - GIVEN `publish=false` THEN created modules have no `publicationDate` and `summary.unpublished` counts them, and an updated module keeps its `publicationDate`; GIVEN `publish=true` or no `publish` THEN created modules get the import start
  - GIVEN `updateExisting=false` THEN matched rows are `skipped` (`exists`); GIVEN a second export without one APPID THEN that module and usage are unchanged; GIVEN an unknown "Applicatie Status" THEN `status` is dropped with a warning naming column and value
  - GIVEN the module pack mapping "Software Suite" to licentietype (test-only pack) THEN the module carries it, with no code change
  - GIVEN a row from each sheet THEN the usage note starts with `Beheer geregeld: nee` (Onbeh) or `ja` (Beheerde), followed by the non-empty Cluster and Afdeling
  - Every new method carries `@spec openspec/changes/cmdb-export-import/tasks.md#task-5` (hydra gate spec-coverage)
- [x] Implement
- [x] Test

### Task 6: Owners as contact persons through Nextcloud Contacts
- **spec_ref**: `SPEC#requirement-the-owner-shall-become-a-contact-person-of-the-municipality-through-nextcloud-contacts-never-a-user-account-and-shall-never-be-publicly-readable-req-cmdb-010` (cmdb-export-import#REQ-CMDB-010)
- **files**: `lib/Service/CmdbExportImportService.php`, `tests/Unit/Service/CmdbExportImportServiceTest.php`
- **acceptance_criteria**:
  - GIVEN the fixture WHEN imported THEN each row's "Applicatie Eigenaar (Persoon)" (a name, or a function) resolves to a contact by display name, one `contactPerson` per owner exists with that `contactsUid`, `organization` = municipality and `role` = "Applicatie Eigenaar (Functie)", and it is the usage's `businessOwner`; no `technicalOwner` is written
  - GIVEN an owner imported twice THEN one contact (exact display-name match) and one `contactPerson`
  - GIVEN the merged register THEN `usage` and `contactPerson` have no public read rule and a `module` refers to them by relation only (`tests/Unit/Settings/CmdbPersonDataVisibilityTest.php`); GIVEN the rig THEN an anonymous OpenCatalogi search hit carries no owner and OpenRegister returns no contact person or usage anonymously (e2e)
  - GIVEN Contacts disabled WHEN imported THEN modules and usages are saved, owners skipped with a warning
  - GIVEN an imported `contactPerson` WHEN `OrganizationSyncService::performUserSync`'s selection is applied THEN it is not selected, and no Nextcloud user is created (if it would be, add an exclusion marker before shipping)
  - GIVEN any import WHEN the report and log lines are inspected THEN no owner name or e-mail appears
- [x] Implement
- [x] Test

### Task 7: Row isolation, report, progress and cancel
- **spec_ref**: `SPEC#requirement-each-row-shall-be-processed-in-isolation-and-reported-with-its-outcome-req-cmdb-011`, `SPEC#requirement-a-running-import-shall-report-its-progress-and-shall-stop-when-cancelled-req-cmdb-013`
- **files**: `lib/Service/CmdbExportImportService.php`, `lib/Service/Cmdb/CmdbImportReport.php`, `tests/Unit/Service/CmdbExportImportServiceTest.php`
- **acceptance_criteria**:
  - GIVEN three rows where saving the second module throws WHEN imported THEN rows 1 and 3 are `created`, row 2 is `failed` naming the step, and the summary matches contract.md
  - GIVEN duplicate APPID rows (also across both sheets), a missing APPID and a missing "Applicatie Naam" THEN they are `skipped` with the reasons in the spec; GIVEN a formula without a cached value THEN the row is imported with a warning naming the column
  - GIVEN an `operationId` WHEN the import runs THEN a `cmdb_import` operation reports per-row progress, and after completion its statistics hold the report
  - GIVEN cancel requested after row 1 of three THEN one processed row, `cancelled: true`, row 1's objects kept
- [x] Implement
- [x] Test

### Task 8: Controller, routes and API tests
- **spec_ref**: `SPEC#requirement-the-import-endpoint-shall-accept-only-a-bounded-xlsx-upload-from-a-nextcloud-admin-req-cmdb-001` (cmdb-export-import#REQ-CMDB-001, #REQ-CMDB-012, #REQ-CMDB-013)
- **files**: `lib/Controller/CmdbImportController.php`, `appinfo/routes.php`, `tests/Unit/Controller/CmdbImportControllerTest.php`, `postman/stackiq-tests.json`, `openapi.json`
- **acceptance_criteria**:
  - GIVEN `cmdbImport#import` and `cmdbImport#cancel` WHEN their attributes are inspected THEN neither has `AuthorizedAdminSetting`, `NoAdminRequired` or `NoCSRFRequired` (hydra gates route-auth, csrf-cochange, no-admin-idor)
  - GIVEN the validation order in design.md D10 THEN each error code from contract.md is returned with its status, and every service exception is translated (hydra gate controller-exception-translation)
  - GIVEN Newman WHEN run against the rig THEN 403 for a non-admin and for a `software-catalog-admins` member, 412 without requesttoken, 413 for an oversized file, 422 `MISSING_RECORDS_UNSUPPORTED`, and 200 with the report for the fixture
- [x] Implement
- [ ] Test
  - Status: the PHPUnit controller tests pass. The Newman folder "12 - CMDB import" has not been run against the current revision, so the Newman criterion above is open.

### Task 9: CMDB import section in admin settings, l10n and Playwright e2e
- **spec_ref**: `SPEC#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014` (cmdb-export-import#REQ-CMDB-014, #REQ-CMDB-003, #REQ-CMDB-011)
- **files**: `src/views/settings/sections/CmdbImport.vue`, `src/views/settings/StackiqSettings.vue`, `l10n/en.json`, `l10n/en.js`, `l10n/nl.json`, `l10n/nl.js`, `tests/e2e/spec-coverage/cmdb-import.spec.ts`
- **acceptance_criteria**:
  - GIVEN a Nextcloud admin on stackiq's admin settings WHEN they choose "Gemeente Voorbeeldstad" and the sanitised fixture and press Import THEN a progress bar shows, then the summary (2 read, 2 created) and a `CnDataTable` report filterable by outcome with links to the modules
  - GIVEN a second import of the same file THEN the report shows 2 unchanged; GIVEN the missing-column fixture THEN the section shows column `APPID` and sheet `Beheerde Applicaties CMDB`; GIVEN a CSV THEN it shows the `NOT_XLSX` message
  - GIVEN the section WHEN the hydra gates run THEN admin-router, form-label-association, nc-input-labels, button-name, table-headers and modal-isolation pass, and no `v-html` renders report values
  - GIVEN a Dutch and an English locale THEN every new string, error message and report reason is translated
  - The e2e file references every `@e2e tests/e2e/spec-coverage/cmdb-import.spec.ts` scenario in the spec (hydra gate e2e-coverage)
- [x] Implement
- [ ] Test
  - Status: the jest tests of `src/utils/cmdbImport.js` pass. The Playwright file has not been run against the current revision (it now also covers the typed municipality, cancel and the refusal of a user who is not a Nextcloud admin).

### Task 10: Administrator documentation with screenshots
- **spec_ref**: `SPEC#purpose`
- **files**: `docs/features/cmdb-import.md`, `docs/images/cmdb-import-*.png`, `docs/features/README.md`
- **acceptance_criteria**:
  - GIVEN the docs page WHEN an administrator reads it THEN it covers the steps, the expected file structure (sheets, required and mapped columns, the column table), the error codes and what to do, repeat-import behaviour (match on APPID per municipality, unchanged rows, records missing from the export stay, publicationDate rule), where owner contacts end up, and how to adjust the mapping JSON
  - GIVEN the prerequisites section THEN it explains the OpenCatalogi catalogue (registers `stackiq`, schema `module`) and the Portaliq account claim `stackiq.organisationId`, needed to see the data there
  - GIVEN Playwright MCP on the rig WHEN screenshots are taken of the empty section, a running import and a finished report (sanitised fixture only) THEN they are committed under `docs/images/`
- [ ] Implement
  - Status: the page text (first two criteria) is written. The three screenshots are not taken yet, because they need a running instance; the page carries no placeholder for them until they exist.
- [ ] Test (screenshots reviewed: no data other than the sanitised fixture visible)

### Task 11: Rework to the CMDB sheets (decisions of 2026-10-01)
- **spec_ref**: `SPEC#requirement-columns-shall-be-resolved-by-header-name-and-a-missing-required-column-shall-stop-the-import-with-422-req-cmdb-003`, `SPEC#requirement-a-module-shall-be-matched-on-its-topdesk-appid-so-a-re-import-updates-instead-of-duplicating-req-cmdb-006`, `SPEC#requirement-the-owner-shall-become-a-contact-person-of-the-municipality-through-nextcloud-contacts-never-a-user-account-and-shall-never-be-publicly-readable-req-cmdb-010`
- **files**: `lib/Settings/cmdb-import/*.json`, `lib/Service/Cmdb/*`, `lib/Service/CmdbExportImportService.php`, `lib/Settings/register.d/topdesk-cmdb-import.json`, `src/views/settings/sections/CmdbImport.vue`, `src/utils/cmdbImport.js`, `l10n/*`, `tests/fixtures/cmdb/*`, `tests/Unit/**/Cmdb*`, `tests/Unit/Settings/CmdbPersonDataVisibilityTest.php`, `tests/e2e/spec-coverage/cmdb-import.spec.ts`, `docs/features/cmdb-import.md`, `openapi.json`, `postman/stackiq-tests.json`
- **acceptance_criteria**:
  - The source sheets are "Onbeh Applicaties CMDB" and "Beheerde Applicaties CMDB"; the "Invoer" sheets are not read; columns resolve by header name per sheet
  - `externalKey` = `topdesk:<municipality uuid>:<APPID>`; "Applicatie Code" → `externalId`, APPID → `externalNumber`; rows without APPID are skipped (`missing APPID`); the report row field is `appId`
  - The column table of design.md is implemented, including `cloudDienstverleningsmodel`, `bbnLevel`, `timeClassification`, `startDateOutPhased` and the maintenance note; the technical-owner pack is removed
  - Formula cells give their cached value; no cached value gives an empty cell and a row warning, never a failure
  - The rig data of the first import is removed and the fixture re-imported twice (created, then unchanged); the owner is not readable anonymously
- [x] Implement
- [x] Test

## Quality checklist

- PHPUnit for all new business logic (`tests/Unit/`), at least 75% coverage of new code (ADR-009), using the sanitised xlsx fixtures (not mocked rows) for reader and service tests
- Newman/Postman for both new endpoints (Task 8); Playwright for the settings flow (Task 9)
- `composer test`, `newman run` and the Playwright spec pass on the local rig
- Test against OpenRegister on the rig: the saved objects pass schema validation (module 0.3.8, organization, usage, contactPerson)
- Hydra gates run locally (`scripts/run-hydra-gates.sh`); read the COVERAGE line and name any SKIPPED gate
- Dutch (`nl_NL`) and English (`en_US`) strings for every new user-facing string (ADR-005)
- Docs in `docs/features/cmdb-import.md` with screenshots (ADR-010)
- `openspec validate cmdb-export-import` passes
