# Test Plan: cmdb-export-import

Spec: `openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md` (abbreviated `spec.md` below). Fixture: `tests/fixtures/cmdb/topdesk-export-anonymised.xlsx` (one fake data row per source sheet, metadata removed). Municipality in every case: "Gemeente Voorbeeldstad". Environment: local rig (Deploy-target n.v.t.).

## Test Cases

### TC-1: Admin imports the export and sees a per-row report
- **spec_ref**: `spec.md#requirement-req-cmdb-011-each-row-shall-be-processed-in-isolation-and-reported-with-its-outcome`, `#requirement-req-cmdb-014-the-admin-settings-shall-offer-a-cmdb-import-section`, `#requirement-req-cmdb-004-every-import-shall-have-exactly-one-consuming-municipality-chosen-by-the-admin`
- **type**: functional
- **persona**: Noor Yilmaz (Municipal CISO / Functional Admin)
- **preconditions**: Nextcloud admin; "Gemeente Voorbeeldstad" exists as type Municipality; no imported modules
- **steps**: open stackiq admin settings, section "CMDB import", choose the municipality, choose the fixture, press Import
- **expected result**: progress bar during the run; summary 2 read / 2 created; report rows `Onbeh Applicaties CMDB` row 2 APPID `1234` and `Beheerde Applicaties CMDB` row 2 APPID `2`, each `created` and linking to its module; no empty rows listed
- **test command**: Playwright `tests/e2e/spec-coverage/cmdb-import.spec.ts`, `/test-functional`, `/test-persona-noor`

### TC-2: Re-import creates no duplicates
- **spec_ref**: `spec.md#requirement-req-cmdb-006-a-module-shall-be-matched-on-its-topdesk-appid-so-a-re-import-updates-instead-of-duplicating`, `#requirement-req-cmdb-009-each-imported-application-shall-have-one-usage-that-links-it-to-the-municipality`
- **type**: functional
- **persona**: Noor Yilmaz
- **preconditions**: TC-1 done; object counts of module, organization, usage, contactPerson recorded
- **steps**: import the same fixture again for the same municipality
- **expected result**: 0 created, 2 unchanged; all four counts equal to before
- **test command**: Playwright `cmdb-import.spec.ts`; PHPUnit `CmdbExportImportServiceTest`

### TC-3: Changed fields update, publicationDate and unmapped fields are kept
- **spec_ref**: `spec.md#requirement-req-cmdb-006-…`, `#requirement-req-cmdb-007-a-newly-created-module-shall-get-a-publicationdate-and-an-existing-one-shall-keep-its-own`
- **type**: api
- **preconditions**: modules imported; an admin set `website` on APPID `2` and depublished it
- **steps**: import rows where "Applicatie Naam" of APPID `2` is `naamtest124`, and where the "Applicatie Code" of APPID `42` changed
- **expected result**: same uuid, name `naamtest124`, `website` unchanged, `depublicationDate` unchanged, no new `publicationDate`; APPID `1234` keeps its original `publicationDate`; APPID `42` is the same module with the new `externalId`
- **test command**: PHPUnit `tests/Unit/Service/CmdbExportImportServiceTest.php`

### TC-4: Manufacturer dedup
- **spec_ref**: `spec.md#requirement-req-cmdb-008-a-manufacturer-shall-become-one-supplier-organisation-however-many-rows-name-it`
- **type**: api
- **preconditions**: an existing Supplier `Aangetekend B.V.`
- **steps**: import rows with "Vendor" `Fabfrikant`, `Fabfrikant `, `FABFRIKANT`, and the "Onbeh" row
- **expected result**: one new Supplier `Fabfrikant`; `Aangetekend B.V.` reused; module and usage `provider` set accordingly
- **test command**: PHPUnit `CmdbExportImportServiceTest`

### TC-5: Upload validation (type, size, columns, sheets, options)
- **spec_ref**: `spec.md#requirement-req-cmdb-001-the-import-endpoint-shall-accept-only-a-bounded-xlsx-upload-from-a-nextcloud-admin`, `#requirement-req-cmdb-003-columns-shall-be-resolved-by-header-name-and-a-missing-required-column-shall-stop-the-import-with-422`, `#requirement-req-cmdb-012-records-missing-from-a-newer-export-shall-be-left-untouched`
- **type**: api
- **preconditions**: admin session
- **steps**: post `applications.csv`; a text file named `.xlsx`; a 10 MB + 1 byte file; `topdesk-missing-appid.xlsx`; a workbook with only "Blad1"; the fixture with `missingRecords=remove`; the fixture without a municipality
- **expected result**: 400 `NOT_XLSX` (twice), 413 `FILE_TOO_LARGE`, 422 `MISSING_COLUMN` naming `APPID` and `Beheerde Applicaties CMDB`, 422 `NO_SOURCE_SHEET`, 422 `MISSING_RECORDS_UNSUPPORTED`, 422 `MUNICIPALITY_REQUIRED`; no object written in any case
- **test command**: PHPUnit `CmdbImportControllerTest`, Newman (Postman collection), `/test-api`; the missing-column UI message also in Playwright

### TC-6: Authorisation and CSRF
- **spec_ref**: `spec.md#requirement-req-cmdb-001-…`, `#requirement-req-cmdb-013-a-running-import-shall-report-its-progress-and-shall-stop-when-cancelled`
- **type**: security
- **preconditions**: a non-admin user, also one in `software-catalog-admins`
- **steps**: post the fixture and the cancel route as that user; post as admin without `requesttoken`
- **expected result**: 403 for non-admins; 412 without CSRF token; no object written
- **test command**: Newman, `/test-security`

### TC-7: Safe reading (formulas, external connection, column order)
- **spec_ref**: `spec.md#requirement-req-cmdb-002-the-workbook-shall-be-read-as-stored-data-without-evaluating-formulas-or-following-links`, `#requirement-req-cmdb-003-…`
- **type**: security
- **preconditions**: fixtures `topdesk-formula-and-connection.xlsx`, `topdesk-shuffled-columns.xlsx`
- **steps**: read both through `CmdbWorkbookReader`
- **expected result**: formula cell yields the cached `Rekenmodel`, calculation engine never invoked; no network access; shuffled columns give identical rows; disallowed columns (Personeelsnummer, phones, group mailbox) are absent from the reader output
- **test command**: PHPUnit `tests/Unit/Service/Cmdb/CmdbWorkbookReaderTest.php`

### TC-8: Normalisation and declarative mapping
- **spec_ref**: `spec.md#requirement-req-cmdb-005-field-mapping-shall-be-declarative-and-executed-by-openregisters-mapping-engine`
- **type**: api
- **preconditions**: fixture rows; an alternate module pack mapping "Roepnaam" to `shortDescription`
- **steps**: normalise and map the rows through the real `MappingEngine`
- **expected result**: `2023-07-04`, `2026-07-29`, `2046-02-01`, `"1234"`; alternate pack yields `shortDescription`; an unknown "Status" drops only `status` with a warning; an invalid pack or a missing engine gives 503 `MAPPING_UNAVAILABLE`
- **test command**: PHPUnit `CmdbRowNormaliserTest`, `CmdbImportProfileTest`, `CmdbExportImportServiceTest`

### TC-9: Per-row isolation and cancel
- **spec_ref**: `spec.md#requirement-req-cmdb-011-…`, `#requirement-req-cmdb-013-…`
- **type**: regression
- **preconditions**: three rows; `saveObject()` throws for the second module; separately, cancel requested after row 1
- **steps**: run the import twice
- **expected result**: run 1: rows 1 and 3 created, row 2 failed naming the step, HTTP 200; run 2: 1 processed row, `cancelled: true`, row 1's module kept
- **test command**: PHPUnit `CmdbExportImportServiceTest`

### TC-10: Owners as contact persons, no user accounts
- **spec_ref**: `spec.md#requirement-req-cmdb-010-the-owner-shall-become-a-contact-person-of-the-municipality-through-nextcloud-contacts-never-a-user-account-and-shall-never-be-publicly-readable`
- **type**: security
- **preconditions**: Contacts enabled (test double); separately disabled
- **steps**: import a row twice and a second row with the same "Applicatie Eigenaar (Persoon)"; import the fixture, whose "Beheerde" owner is a function; run `performUserSync` selection on the result; then, not signed in, list contact persons and usages through OpenRegister and search OpenCatalogi for `naamtest123`
- **expected result**: one contactPerson per owner with the function as `role` and `organization` = municipality, set as `businessOwner`; no `technicalOwner`; no Nextcloud user created and the contactPerson not selected by the user sync; with Contacts disabled: no owners, a warning, modules and usages saved; report and log contain no owner name; anonymously: no contact person or usage from OpenRegister, and the OpenCatalogi hit holds no owner name and only ids in `contactPerson` / `usages`
- **test command**: PHPUnit `CmdbExportImportServiceTest`, `CmdbPersonDataVisibilityTest`, Playwright `cmdb-import.spec.ts` (anonymous test), `/test-security`

### TC-11: OpenCatalogi finds an imported application
- **spec_ref**: `spec.md#requirement-req-cmdb-007-…`
- **type**: functional
- **persona**: Sem de Jong (Young Digital Native; anonymous search)
- **preconditions**: OpenCatalogi catalogue with registers `[stackiq]`, schemas `[module]`, listed and published (docs, prerequisites); TC-1 done
- **steps**: anonymous `GET /apps/opencatalogi/api/search?_search=Aangetekend`
- **expected result**: one hit `Aangetekend Mailen`
- **test command**: manual on the rig (USER MANUAL TEST, WOO-586 Stap 6b), `/test-functional`

### TC-12: Portaliq shows the applications to the municipality
- **spec_ref**: `spec.md#requirement-req-cmdb-009-…`
- **type**: persona
- **persona**: Noor Yilmaz (Municipal CISO / Functional Admin)
- **preconditions**: Portaliq account with claim `stackiq.organisationId` = uuid of "Gemeente Voorbeeldstad", audience participant-org; TC-1 done
- **steps**: sign in to the portal, open "Software we use"
- **expected result**: `Aangetekend Mailen` and `naamtest123` listed; an account for another organisation sees neither
- **test command**: manual on the rig, `/test-persona-noor`

### TC-13: Accessibility of the section
- **spec_ref**: `spec.md#requirement-req-cmdb-014-…`
- **type**: accessibility
- **preconditions**: section rendered with a finished report
- **steps**: keyboard-only run of TC-1; axe scan; screen-reader check of progress and summary
- **expected result**: every control labelled and reachable; progress and summary announced through a polite live region; report table has header cells; no serious/critical axe violations
- **test command**: `/test-accessibility`, hydra gates `form-label-association`, `nc-input-labels`, `button-name`, `table-headers`, `axe`

### TC-14: Register fragment deploys the module properties
- **spec_ref**: `spec.md#requirement-req-cmdb-006-…` (stored key), design.md Mixed-spec rationale
- **type**: regression
- **preconditions**: all `register.d` fragments present
- **steps**: merge the register as `SettingsService` does; run the repair step on the rig
- **expected result**: merged `module.version` is `0.3.5` with the five optional properties; existing modules still load and save; seed module `voorbeeld-zaaksysteem` present without `publicationDate`
- **test command**: PHPUnit `tests/Unit/Settings/TopdeskCmdbFragmentTest.php`, `/test-regression`

## Coverage Summary

| Requirement | Covered by |
|---|---|
| REQ-CMDB-001 upload bounds, admin, CSRF | TC-5, TC-6 |
| REQ-CMDB-002 safe reading | TC-7 |
| REQ-CMDB-003 header-name columns, 422 | TC-5, TC-7 |
| REQ-CMDB-004 one municipality | TC-1, TC-5, PHPUnit (created once) |
| REQ-CMDB-005 declarative mapping, dates | TC-8 |
| REQ-CMDB-006 upsert on APPID | TC-2, TC-3, TC-14 |
| REQ-CMDB-007 publicationDate rule | TC-3, TC-11 |
| REQ-CMDB-008 manufacturer dedup | TC-4 |
| REQ-CMDB-009 usage per municipality | TC-2, TC-12 |
| REQ-CMDB-010 owner via Contacts, never public | TC-10 |
| REQ-CMDB-011 per-row isolation and report | TC-1, TC-9 |
| REQ-CMDB-012 missing records kept | TC-5, PHPUnit (dropped row stays) |
| REQ-CMDB-013 progress and cancel | TC-6, TC-9 |
| REQ-CMDB-014 settings section | TC-1, TC-13 |

All requirements are covered. After implementation, TC-1, TC-2 and TC-11/12 are candidates for `/test-scenario-create` (key user flow and cross-app chain).

## Out of Scope

- Performance on the real 1,100-row export: the real file never enters a repo or a test run. It is measured once, manually, on the rig, and only the timing is recorded.
- The archive sheet, connections, suites and hosting parties are not built, so they are not tested.
