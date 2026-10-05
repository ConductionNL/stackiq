# cmdb-export-import Specification

**Status**: in-progress
**Scope**: stackiq
**OpenSpec changes**:
- [cmdb-export-import](../../changes/cmdb-export-import/)

## Purpose

A Nextcloud admin, or a member of a group an admin delegated stackiq's admin settings to, imports a TOPdesk CMDB export (xlsx) into stackiq for one municipality. Every application row of the export's two CMDB sheets ("Onbeh Applicaties CMDB", applications without arranged maintenance, and "Beheerde Applicaties CMDB", with arranged maintenance) becomes, or updates, a `module` (schema:SoftwareApplication) with its vendor `organization` (schema:Organization), a `usage` that links the application to the municipality, and a `contactPerson` (schema:Person) for its owner, which is never publicly readable. All data is stored as OpenRegister objects (ADR-001). The column-to-field mapping is declarative JSON executed by OpenRegister's mapping engine (ADR-011, ADR-031), so the import can be repeated with a newer export without creating duplicates. OpenCatalogi lists the imported applications, and Portaliq shows them to the municipality.

Nextcloud OCP interfaces used: `OCP\IRequest` (multipart upload), `OCP\IUserSession` and `OCP\IGroupManager` (admin check), `OCP\Contacts\IManager` (owner identity, through `StackiqContactSyncService`), `OCP\ICacheFactory` (progress, through `ProgressTracker`), `OCP\IL10N` (messages). OpenRegister: `OCA\OpenRegister\Contract\ObjectServiceInterface` for every read and write.

## ADDED Requirements

### Requirement: The import endpoint SHALL accept only a bounded xlsx upload from a user with the stackiq admin settings (REQ-CMDB-001)

`POST /api/cmdb-import` SHALL be reachable only by Nextcloud admins and by members of the groups an admin delegated stackiq's admin settings to (`#[AuthorizedAdminSetting(StackiqAdmin)]`), and SHALL require Nextcloud's CSRF token. The endpoint SHALL NOT carry `#[NoAdminRequired]` or `#[NoCSRFRequired]`. It SHALL reject the upload before any parsing when the file is larger than the configured maximum (default 10 MB), when its name does not end in `.xlsx`, or when its content is not a ZIP package containing `xl/workbook.xml`. Macro-enabled (`.xlsm`), legacy (`.xls`) and CSV files SHALL be rejected. No object SHALL be written in any of these cases.

#### Scenario: A file that is not xlsx is rejected
@e2e tests/e2e/spec-coverage/cmdb-import.spec.ts

- **GIVEN** a Nextcloud admin on the CMDB import section
- **WHEN** they upload `applications.csv`, or a file named `export.xlsx` whose content is plain text
- **THEN** the endpoint SHALL answer 400 with error `NOT_XLSX`
- **AND** no `module`, `organization`, `usage` or `contactPerson` object SHALL be created or changed

#### Scenario: An oversized file is rejected before it is read
@e2e exclude Building a file over 10 MB in the browser adds nothing over the unit test; tests/Unit/Controller/CmdbImportControllerTest.php asserts 413 FILE_TOO_LARGE and that the reader is never called.

- **GIVEN** an xlsx upload of 10 MB plus one byte
- **WHEN** a Nextcloud admin posts it to `POST /api/cmdb-import`
- **THEN** the endpoint SHALL answer 413 with error `FILE_TOO_LARGE`
- **AND** the workbook reader SHALL NOT be invoked

#### Scenario: A user without the stackiq admin settings cannot import
@e2e tests/e2e/spec-coverage/cmdb-import.spec.ts asserts 403 on both routes for a signed-in user without the setting. tests/Unit/Controller/CmdbImportControllerTest.php (testBothRoutesRequireTheStackiqAdminSetting, testNeitherRouteDeclaresAnExemption) asserts both routes require the StackiqAdmin setting and declare no exemption, and the Newman collection asserts 403 for a software-catalog-admins member.

- **GIVEN** a signed-in user who is neither a Nextcloud admin nor a member of a group delegated stackiq's admin settings, for example a member of `software-catalog-admins` only
- **WHEN** they post an export to `POST /api/cmdb-import`
- **THEN** Nextcloud SHALL answer 403
- **AND** no object SHALL be written

#### Scenario: A request without a CSRF token is refused
@e2e exclude CSRF is enforced by Nextcloud's middleware; the Newman collection posts without a requesttoken and asserts 412.

- **GIVEN** a Nextcloud admin session
- **WHEN** a request to `POST /api/cmdb-import` arrives without a valid `requesttoken` header or parameter
- **THEN** Nextcloud SHALL refuse it with 412
- **AND** no object SHALL be written

### Requirement: The workbook SHALL be read as stored data, without evaluating formulas or following links (REQ-CMDB-002)

The reader SHALL open the workbook with PhpSpreadsheet's Xlsx reader in read-data-only mode, SHALL load only the sheets named in the import profile, and SHALL read each cell's stored value. For a formula cell it SHALL use the value cached in the file and SHALL NOT evaluate the formula. It SHALL NOT contact external data connections, linked workbooks or URLs found in the file. A formula cell without a cached value SHALL be read as empty and SHALL add a row warning naming the column; it SHALL NOT fail the row or the import. A formula whose cached value is the number 0 (Excel's result for a reference to an empty cell) SHALL be read as empty. It SHALL stop with 422 `TOO_MANY_ROWS` when a source sheet holds more data rows than the profile's limit (default 10,000).

#### Scenario: A formula cell yields its cached value and is not evaluated
@e2e exclude Reader behaviour; tests/Unit/Service/Cmdb/CmdbWorkbookReaderTest.php reads a fixture whose source sheet has a formula cell and asserts the cached value is returned and the calculation engine is never invoked.

- **GIVEN** a CMDB sheet where column "Applicatie Naam" in row 2 holds a formula with a cached value `Rekenmodel`
- **WHEN** the workbook is read
- **THEN** the row SHALL carry `Applicatie Naam = Rekenmodel`
- **AND** the formula SHALL NOT be evaluated

#### Scenario: A formula without a cached value is read as empty with a warning
@e2e exclude Reader and service behaviour; tests/Unit/Service/Cmdb/CmdbWorkbookReaderTest.php reads a fixture whose "Roepnaam" formula has no cached value, and tests/Unit/Service/CmdbExportImportServiceTest.php asserts the row warning.

- **GIVEN** a CMDB sheet where column "Roepnaam" in row 2 holds a formula without a cached value
- **WHEN** the workbook is imported
- **THEN** the row SHALL be imported with an empty "Roepnaam"
- **AND** the row's report entry SHALL carry the warning `Column "Roepnaam": formula without a cached value, read as empty`

#### Scenario: An external data connection in the workbook is never contacted
@e2e exclude Network isolation; tests/Unit/Service/Cmdb/CmdbWorkbookReaderTest.php reads a fixture that declares an external connection, with a reader that has no HTTP client, and asserts the read succeeds.

- **GIVEN** an export that contains `xl/connections.xml` with an external data connection
- **WHEN** the workbook is read
- **THEN** no network request SHALL be made
- **AND** the source sheets SHALL be read normally

### Requirement: Columns SHALL be resolved by header name, and a missing required column SHALL stop the import with 422 (REQ-CMDB-003)

The source sheets SHALL be the CMDB sheets "Onbeh Applicaties CMDB" and "Beheerde Applicaties CMDB". Of the "Invoer" sheet each source sheet is derived from (the profile's `lookup`: "Invoer AIA data" for Onbeh, "Invoer APP data" for Beheerde), the reader SHALL read only "Middel-ID", "Eigenaar e-mail" and "Eigenaar mobiel nummer", and SHALL add the last two to the source row whose "Applicatie Code" equals that "Middel-ID" (trimmed, case-insensitive), filling only cells the source row leaves empty. A lookup sheet or key column the workbook lacks SHALL produce one import-level warning and no error; no other "Invoer" column SHALL be read. The reader SHALL take the first row of each source sheet as headers and SHALL match them to the profile's column names per sheet, case-insensitively, after trimming whitespace and dropping a trailing `:` or `⚡`. Column order SHALL NOT matter. When a present source sheet lacks a column the profile marks as required (`APPID`, `Applicatie Naam`), the endpoint SHALL answer 422 with error `MISSING_COLUMN`, naming the column and the sheet, before any object is written. When neither source sheet exists, the endpoint SHALL answer 422 with error `NO_SOURCE_SHEET`, naming both expected sheets. A missing optional column SHALL produce one import-level warning and no row error, except for a column the profile lists as absent on that sheet (`Nickname` on "Onbeh Applicaties CMDB").

#### Scenario: A missing required column is named in the 422 response
@e2e tests/e2e/spec-coverage/cmdb-import.spec.ts

- **GIVEN** an export whose sheet "Beheerde Applicaties CMDB" has no column "APPID"
- **WHEN** a Nextcloud admin uploads it
- **THEN** the endpoint SHALL answer 422 with error `MISSING_COLUMN`, column `APPID` and sheet `Beheerde Applicaties CMDB`
- **AND** the section SHALL show that column and sheet name to the admin
- **AND** no object SHALL be written

#### Scenario: Columns in a different order map the same
@e2e exclude Reader behaviour; tests/Unit/Service/Cmdb/CmdbWorkbookReaderTest.php reads a fixture with shuffled columns and asserts identical rows.

- **GIVEN** an export where "Applicatie Naam" comes before "APPID" and the header reads `Vendor⚡`
- **WHEN** the workbook is read
- **THEN** every row SHALL carry the same values under the profile's column names as in the original order

#### Scenario: A workbook without either source sheet is refused
@e2e exclude Same error path as the missing column; tests/Unit/Service/Cmdb/CmdbWorkbookReaderTest.php asserts NO_SOURCE_SHEET naming both sheets.

- **GIVEN** an xlsx that contains only a sheet "Blad1"
- **WHEN** a Nextcloud admin uploads it
- **THEN** the endpoint SHALL answer 422 with error `NO_SOURCE_SHEET` naming "Onbeh Applicaties CMDB" and "Beheerde Applicaties CMDB"

### Requirement: Every import SHALL have exactly one consuming municipality, chosen by the admin (REQ-CMDB-004)

The request SHALL carry either `municipalityUuid`, the uuid of an existing stackiq `organization` of type `Municipality`, or `municipalityName`, a name for a new one. With a name, the service SHALL reuse an existing organisation of type `Municipality` with the same normalised name, or create one through the municipality pack (type `Municipality`, status `Active`). It SHALL answer 422 `MUNICIPALITY_REQUIRED` when neither is given, and 422 `MUNICIPALITY_INVALID` when the uuid does not resolve to an organisation of type `Municipality`. Every `usage` and `contactPerson` the import writes SHALL reference that organisation.

#### Scenario: The admin picks an existing municipality
@e2e tests/e2e/spec-coverage/cmdb-import.spec.ts

- **GIVEN** an organisation "Gemeente Voorbeeldstad" of type `Municipality`
- **WHEN** a Nextcloud admin selects it and imports the anonymised export
- **THEN** both imported usages SHALL have `consumer` = the uuid of "Gemeente Voorbeeldstad"
- **AND** no new organisation of type `Municipality` SHALL be created

#### Scenario: A new municipality is created once from a typed name
@e2e tests/e2e/spec-coverage/cmdb-import.spec.ts

- **GIVEN** no organisation named "Gemeente Voorbeeldstad"
- **WHEN** a Nextcloud admin imports with `municipalityName` "Gemeente Voorbeeldstad", and later imports again with the same name
- **THEN** exactly one organisation "Gemeente Voorbeeldstad" of type `Municipality` and status `Active` SHALL exist

#### Scenario: An import without a municipality is refused
@e2e exclude Validation; tests/Unit/Controller/CmdbImportControllerTest.php asserts 422 MUNICIPALITY_REQUIRED, and 422 MUNICIPALITY_INVALID for the uuid of a Supplier organisation.

- **GIVEN** a valid export
- **WHEN** a Nextcloud admin posts it with neither `municipalityUuid` nor `municipalityName`
- **THEN** the endpoint SHALL answer 422 with error `MUNICIPALITY_REQUIRED`
- **AND** no object SHALL be written

### Requirement: Field mapping SHALL be declarative and executed by OpenRegister's mapping engine (REQ-CMDB-005)

The service SHALL map each normalised row with OpenRegister's `MigrationPack\MappingEngine::mapRow()`, once per target pack: module, manufacturer, municipality, usage, business owner. The packs and the import profile SHALL ship as JSON under `lib/Settings/cmdb-import/`. Each pack SHALL pass OpenRegister's `PackDefinitionValidator` when the import starts; an invalid pack, or a missing `MappingEngine`, SHALL stop the import with 503 `MAPPING_UNAVAILABLE` before any row is read. Before mapping, the service SHALL convert the cells of the profile's date columns from Excel serial numbers to `Y-m-d`, SHALL turn numeric id cells into strings without a decimal part, SHALL read a value the profile lists as empty for its column (`NB` in "BNN Classificatie"; dates, "End-of-Life Functioneel" included, are kept as the file has them) as empty, and SHALL add the constants of the row's sheet (`Beheer` = `Beheer geregeld: nee` or `ja`). A mapping error on a mapping marked `required` in the module pack SHALL skip the row. In the manufacturer and owner packs it SHALL mean the row has no manufacturer or no such owner, without a warning. A mapping error on any other mapping SHALL drop only that field and add a row warning naming the column and the value. The reader SHALL keep only the columns that the profile or a pack references, and SHALL discard every other cell when it reads the row.

#### Scenario: Excel serial dates are converted before mapping
@e2e exclude Pure transformation; tests/Unit/Service/Cmdb/CmdbRowNormaliserTest.php asserts the conversions below.

- **GIVEN** the "Onbeh" row of the anonymised export with "Datum" = `45111.380322627316` and "Referentie datum wijziging" = `46232.552113113423`, and the "Beheerde" row with "End-of-Life Functioneel" = `53359`
- **WHEN** the rows are normalised
- **THEN** "Datum" SHALL be `2023-07-04`, "Referentie datum wijziging" SHALL be `2026-07-29`, and "End-of-Life Functioneel" SHALL be `2046-02-01`
- **AND** "APPID" `1234` SHALL be the string `"1234"`
- **AND** "BNN Classificatie" `NB` SHALL be empty
- **AND** "End-of-Life Functioneel" `49675` SHALL be `2036-01-01`

#### Scenario: Changing a pack changes the mapping without code
@e2e exclude Configuration behaviour; tests/Unit/Service/CmdbExportImportServiceTest.php loads an alternate module pack that maps "Software Suite" to licentietype and asserts the mapped module.

- **GIVEN** the module pack is edited to add a mapping from "Software Suite" to `licentietype`
- **WHEN** an export is imported whose row has "Software Suite" = `Suite`
- **THEN** the created module SHALL have `licentietype` = `Suite`
- **AND** no PHP code SHALL have changed

#### Scenario: An unknown status value drops only that field
@e2e exclude Mapping behaviour; tests/Unit/Service/CmdbExportImportServiceTest.php asserts the row outcome and warning.

- **GIVEN** a row whose "Applicatie Status" is `Onbekende status`, which the usage pack's lookup does not contain
- **WHEN** the row is imported
- **THEN** the module and the usage SHALL be saved without a status from the export
- **AND** the row's report entry SHALL carry a warning naming column "Applicatie Status" and value `Onbekende status`

#### Scenario: The classifications map to the stackiq fields
@e2e exclude Mapping behaviour; tests/Unit/Service/CmdbExportImportServiceTest.php imports the fixture and asserts the fields.

- **GIVEN** the "Beheerde" row of the anonymised export with "Applicatiesoort" `Saas`, "BNN Classificatie" `BBN2`, "Classificatie" `Tolereren` and "End-of-Life Functioneel" `53359`
- **WHEN** it is imported
- **THEN** the module SHALL have `applicationType` = `Saas`, `cloudDienstverleningsmodel` = `["SaaS"]` and `bbnLevel` = `BBN2`
- **AND** the usage SHALL have `timeClassification` = `Tolerate` and `startDateOutPhased` = `2046-02-01`
- **AND** the "Onbeh" row's "Applicatiesoort" `Webapplicatie`, which is not a hosting model, SHALL be stored as `applicationType` and SHALL leave `cloudDienstverleningsmodel` empty without a warning
- **AND** "BNN Classificatie" `1`, `2`, `2+` SHALL be `BBN1`, `BBN2`, `BBN2+`

### Requirement: A module SHALL be matched on its TOPdesk APPID, so a re-import updates instead of duplicating (REQ-CMDB-006)

For each row the service SHALL compute `externalKey` = `topdesk:<municipality uuid>:<APPID>` (the APPID is TOPdesk's ICT Applicatienummer; the Applicatie Code, or Middel-ID, can change in TOPdesk and is stored as `externalId` for reference only) and look up a `module` with that `externalKey`. When none exists it SHALL create one. When one exists it SHALL update only the fields the module pack maps and SHALL leave every other field as it is. When the mapped fields equal the stored values it SHALL NOT save the module and SHALL report the row as `unchanged`. With `updateExisting=false` a matched row SHALL be reported as `skipped` with reason `exists`, without changes. A row without an APPID SHALL be skipped with reason `missing APPID`. When an APPID occurs more than once in one upload, across both sheets, the first occurrence SHALL be imported and every later one SHALL be skipped with reason `duplicate APPID in file`.

#### Scenario: Re-importing the same export creates no duplicates
@e2e tests/e2e/spec-coverage/cmdb-import.spec.ts

- **GIVEN** the anonymised export was imported once for "Gemeente Voorbeeldstad", which created the modules with APPID `1234` and `2`
- **WHEN** the same export is imported again for the same municipality
- **THEN** the report SHALL show 0 created and 2 unchanged rows
- **AND** the number of modules, organisations, usages and contact persons in the register SHALL be the same as after the first import

#### Scenario: A changed field is updated on re-import
@e2e exclude Covered by the service test; tests/Unit/Service/CmdbExportImportServiceTest.php imports a row, changes "Applicatie Naam" of APPID 2 to `naamtest124` in the row data, imports again, and asserts one module with the new name.

- **GIVEN** the module with APPID `2` was imported with name `naamtest123`, and an admin has since set its `website`
- **WHEN** a newer export where "Applicatie Naam" for APPID `2` is `naamtest124` is imported
- **THEN** the same module SHALL now have name `naamtest124`
- **AND** its `website` SHALL be unchanged
- **AND** the report SHALL show the row as `updated`

#### Scenario: An APPID that occurs twice in one file is imported once
@e2e exclude Covered by the service test; tests/Unit/Service/CmdbExportImportServiceTest.php feeds two rows with the same APPID, also across both sheets.

- **GIVEN** an upload where APPID `2` appears in row 2 and row 7 of "Beheerde Applicaties CMDB"
- **WHEN** it is imported
- **THEN** row 2 SHALL be imported
- **AND** row 7 SHALL be reported as `skipped` with reason `duplicate APPID in file`

#### Scenario: A changed Applicatie Code keeps the same module
@e2e exclude Covered by the service test; tests/Unit/Service/CmdbExportImportServiceTest.php imports APPID 42 with two different codes.

- **GIVEN** the module with APPID `42` was imported with "Applicatie Code" `APP-Oud`
- **WHEN** a newer export has APPID `42` with "Applicatie Code" `App-Nieuw`
- **THEN** the same module SHALL be updated, with `externalId` = `App-Nieuw` and the same `externalKey`

### Requirement: A newly created module SHALL get a publicationDate, and an existing one SHALL keep its own (REQ-CMDB-007)

When the service creates a `module` it SHALL set `publicationDate` to the time the import started, as an ISO 8601 date-time, so OpenCatalogi lists the module. When it updates an existing `module` it SHALL NOT change `publicationDate` or `depublicationDate`, also when they are empty.

#### Scenario: OpenCatalogi can list an imported application
@e2e exclude Crosses into OpenCatalogi, whose catalogue configuration is outside this change; tests/Unit/Service/CmdbExportImportServiceTest.php asserts publicationDate on created modules, and the manual test plan checks the search in OpenCatalogi.

- **GIVEN** an OpenCatalogi catalogue that includes the stackiq register's `module` schema
- **WHEN** the anonymised export is imported
- **THEN** each created module SHALL have a `publicationDate` that is not later than the moment the import finished
- **AND** a search in OpenCatalogi for `Aangetekend Mailen` SHALL find the module

#### Scenario: Re-import preserves publicationDate
@e2e exclude Covered by the service test; tests/Unit/Service/CmdbExportImportServiceTest.php testTheFixtureCreatesModulesUsagesAndSuppliers asserts publicationDate on created modules, and testAnUpdateNeverWritesPublicationDate asserts an update leaves it as it was.

- **GIVEN** the module with APPID `1234` was imported with `publicationDate` 2026-10-01T09:00:00+00:00, and the module with APPID `2` was later depublished by an admin
- **WHEN** a newer export is imported that changes both modules' names
- **THEN** the module with APPID `1234` SHALL keep `publicationDate` 2026-10-01T09:00:00+00:00
- **AND** the module with APPID `2` SHALL keep its `depublicationDate` and SHALL NOT get a new `publicationDate`

### Requirement: A manufacturer SHALL become one supplier organisation, however many rows name it (REQ-CMDB-008)

The service SHALL map "Vendor" (the maker of the software) through the manufacturer pack to an `organization`. It SHALL match names after trimming, collapsing whitespace and ignoring case, first against the organisations it has already resolved during this import, then against existing organisations of type `Municipality`, then of type `Supplier`, and SHALL create one of type `Supplier` only when none matches. A Vendor that is the municipality's own name SHALL therefore reference the municipality, so one municipality is never also a second, Supplier organisation. The imported module's `provider` and the usage's `provider` SHALL reference that organisation. A row with an empty "Vendor" SHALL be imported without a provider. "Leverancier" and "Hostingpartij" SHALL NOT be read.

#### Scenario: Rows with the same manufacturer share one organisation
@e2e exclude Covered by the service test; tests/Unit/Service/CmdbExportImportServiceTest.php feeds three rows with "Fabfrikant", "Fabfrikant " and "FABFRIKANT".

- **GIVEN** three rows whose "Vendor" is `Fabfrikant`, `Fabfrikant ` and `FABFRIKANT`
- **WHEN** they are imported
- **THEN** exactly one organisation `Fabfrikant` of type `Supplier` SHALL exist
- **AND** all three modules SHALL have `provider` = its uuid

#### Scenario: An existing supplier is reused
@e2e exclude Covered by the service test; tests/Unit/Service/CmdbExportImportServiceTest.php testAVendorIsOneSupplier seeds the Supplier "Aangetekend B.V." and asserts that the module of its row gets it as provider and no second supplier is created.

- **GIVEN** an existing organisation `Aangetekend B.V.` of type `Supplier`
- **WHEN** the "Onbeh" row with "Vendor" `Aangetekend B.V.` is imported
- **THEN** no new organisation SHALL be created
- **AND** the module with APPID `1234` SHALL have `provider` = the existing organisation's uuid

#### Scenario: A municipality that builds its own applications stays one organisation
@e2e exclude Covered by the service test; tests/Unit/Service/CmdbExportImportServiceTest.php testAVendorNamedAsTheMunicipalityIsTheMunicipality seeds a Municipality and a Supplier of the same name and asserts both rows get the Municipality as provider and no organisation is created.

- **GIVEN** the municipality `Gemeente Voorbeeldstad` and rows whose "Vendor" is `Gemeente Voorbeeldstad`
- **WHEN** they are imported
- **THEN** their modules SHALL have `provider` = the municipality's uuid
- **AND** no organisation of type `Supplier` named `Gemeente Voorbeeldstad` SHALL be created

### Requirement: Each imported application SHALL have one usage that links it to the municipality (REQ-CMDB-009)

For each imported module the service SHALL keep exactly one `usage` with `consumer` = the municipality and `module` = the module, found by those two references and created when missing. The usage pack SHALL map "Applicatie Status" to `status` and "Classificatie" to `timeClassification` through lookups, "End-of-Life Functioneel" to `startDateOutPhased`, and the sheet's `Beheer` constant, "Cluster" and "Applicatie Eigenaar (Afdeling)" to `interneAnnotation`, so the note records whether maintenance is arranged (`Beheer geregeld: nee` for "Onbeh Applicaties CMDB", `ja` for "Beheerde Applicaties CMDB"). Empty parts SHALL be left out of the note. `interneAnnotation` SHALL be written only when the usage is created or the field is empty, so a note an admin wrote is never overwritten.

#### Scenario: The usage records whether maintenance is arranged
@e2e exclude Covered by the service test; tests/Unit/Service/CmdbExportImportServiceTest.php imports a row from each sheet.

- **GIVEN** a row on "Onbeh Applicaties CMDB" with "Cluster" `H10` and "Applicatie Eigenaar (Afdeling)" `H10 Accounting`
- **WHEN** it is imported
- **THEN** its usage SHALL have `interneAnnotation` = `Beheer geregeld: nee / H10 / H10 Accounting`
- **AND** a row on "Beheerde Applicaties CMDB" without a cluster SHALL get `Beheer geregeld: ja / <department>`

#### Scenario: Portaliq can show the application to the municipality
@e2e exclude Crosses into Portaliq, whose account claim is outside this change; tests/Unit/Service/CmdbExportImportServiceTest.php asserts the usage references, and the manual test plan checks Portaliq's "Software we use".

- **GIVEN** a Portaliq account with claim `stackiq.organisationId` = the uuid of "Gemeente Voorbeeldstad"
- **WHEN** the anonymised export is imported for "Gemeente Voorbeeldstad"
- **THEN** a usage SHALL exist for each imported module with `consumer` = that uuid and `module` = the module's uuid
- **AND** that account SHALL see `Aangetekend Mailen` and `naamtest123` under "Software we use"

#### Scenario: A re-import does not add a second usage
@e2e exclude Covered by the service test; tests/Unit/Service/CmdbExportImportServiceTest.php testReimportingTheSameExportChangesNothing imports twice and asserts unchanged object counts, usages included.

- **GIVEN** the module with APPID `2` already has a usage for "Gemeente Voorbeeldstad"
- **WHEN** a newer export is imported for the same municipality
- **THEN** the module with APPID `2` SHALL still have exactly one usage for "Gemeente Voorbeeldstad"

### Requirement: The owner SHALL become a contact person of the municipality through Nextcloud Contacts, never a user account, and SHALL never be publicly readable (REQ-CMDB-010)

The business owner pack SHALL map "Applicatie Eigenaar (Persoon)" (the display name, which may be a function instead of a person's name) and "Applicatie Eigenaar (Functie)" (the role). No technical owner SHALL be imported; the functional administrator columns SHALL NOT be read. The pack SHALL also map "Eigenaar e-mail" and "Eigenaar mobiel nummer", which the reader looks up on the "Invoer" sheet; they SHALL be written to the Nextcloud contact only, never to a stackiq object. For the owner the service SHALL resolve a Nextcloud contact through `StackiqContactSyncService` by e-mail address, else by an exact match on the display name (with an e-mail address, only a contact without one), and otherwise by creating one. A resolved contact SHALL get the e-mail address and phone number it lacks; a value it has SHALL NOT be replaced. It SHALL then reuse or create one `contactPerson` with that `contactsUid`, `organization` = the municipality and `role` = "Applicatie Eigenaar (Functie)" when given, and SHALL set `usage.businessOwner` to it. The import SHALL NOT create Nextcloud user accounts. When Nextcloud Contacts is unavailable, the row SHALL be imported without an owner and SHALL carry a warning. `contactPerson` and `usage` SHALL have no public read rule, so the owner is never readable by an anonymous visitor; a published module SHALL refer to them by relation only.

#### Scenario: The owner becomes the business owner
@e2e exclude Needs a Contacts address book; tests/Unit/Service/CmdbExportImportServiceTest.php asserts the calls to a StackiqContactSyncService test double and the saved contactPerson.

- **GIVEN** the "Onbeh" row with "Applicatie Eigenaar (Persoon)" `Achternaam, Voornaam` and "Applicatie Eigenaar (Functie)" `Afdelingshoofd`, and the "Beheerde" row whose person column holds the function `Teamleider Applicatiebeheer`
- **WHEN** they are imported for "Gemeente Voorbeeldstad"
- **THEN** one `contactPerson` SHALL exist per owner with the resolved `contactsUid`, `organization` = "Gemeente Voorbeeldstad" and the function as `role`
- **AND** each usage SHALL have `businessOwner` = its owner's contact person and no `technicalOwner`
- **AND** no Nextcloud user account SHALL be created

#### Scenario: Imported owners are never readable anonymously
@e2e tests/e2e/spec-coverage/cmdb-import.spec.ts

- **GIVEN** the anonymised export was imported, creating contact persons for its owners
- **WHEN** a visitor who is not signed in lists the `contactPerson` and `usage` objects through OpenRegister, or searches OpenCatalogi for an imported application
- **THEN** OpenRegister SHALL return no contact person and no usage
- **AND** the OpenCatalogi search hit SHALL carry no owner name, and its `contactPerson` and `usages` SHALL be empty or ids only

#### Scenario: An owner known by name gets the e-mail address and phone number from the Invoer sheet
@e2e exclude Needs a Contacts address book; tests/Unit/Service/CmdbExportImportServiceTest.php testAnOwnerKnownByNameGetsTheEmailAndPhoneItLacks and testANamesakeWithAnotherEmailIsNotTaken, and tests/Unit/Service/Cmdb/CmdbWorkbookReaderTest.php testALookupAddsOnlyItsColumnsByKey.

- **GIVEN** a contact `Voornaam Achternaam` without e-mail address or phone number, and an "Invoer" row with the row's Middel-ID, an "Eigenaar e-mail" and an "Eigenaar mobiel nummer"
- **WHEN** the row with owner `Achternaam, Voornaam` is imported
- **THEN** that contact SHALL get the e-mail address and phone number, and no second contact SHALL be created
- **AND** no `contactPerson`, `usage` or `module` SHALL hold the e-mail address or phone number

#### Scenario: The same owner on two rows is one contact person
@e2e exclude Covered by the service test; tests/Unit/Service/CmdbExportImportServiceTest.php testTheSameOwnerOnTwoRowsIsOneContactPerson.

- **GIVEN** two rows with the same "Applicatie Eigenaar (Persoon)"
- **WHEN** they are imported
- **THEN** exactly one `contactPerson` for that contact SHALL exist for the municipality, referenced by both usages

#### Scenario: Contacts disabled does not block the import
@e2e exclude Environment condition; tests/Unit/Service/CmdbExportImportServiceTest.php sets isAvailable() to false.

- **GIVEN** the Nextcloud Contacts app is disabled
- **WHEN** the anonymised export is imported
- **THEN** both modules and usages SHALL be saved without owners
- **AND** each row with an owner SHALL carry the warning that owners were skipped because Contacts is unavailable

### Requirement: Each row SHALL be processed in isolation and reported with its outcome (REQ-CMDB-011)

The service SHALL process every non-empty row in its own error boundary. An exception in one row SHALL mark that row `failed` with the reason and SHALL NOT stop the import or change the outcome of other rows. Rows whose cells are all empty SHALL be ignored and not counted. The response SHALL contain a summary (rows read, created, updated, unchanged, skipped, failed, warnings) and one entry per counted row with sheet, row number, APPID, application name, outcome, reasons, warnings and the uuids of the module and usage. Report entries and log lines SHALL NOT contain owner names, e-mail addresses or other person data. The section SHALL render report values as text, never as HTML.

#### Scenario: Upload with a per-row report
@e2e tests/e2e/spec-coverage/cmdb-import.spec.ts

- **GIVEN** a Nextcloud admin, "Gemeente Voorbeeldstad" selected, and the anonymised export
- **WHEN** they start the import and it finishes
- **THEN** the section SHALL show 2 rows read and 2 created
- **AND** the report SHALL list `Onbeh Applicaties CMDB` row 2 APPID `1234` and `Beheerde Applicaties CMDB` row 2 APPID `2`, each with outcome `created` and a link to its module
- **AND** the hundreds of formatted but empty rows in both sheets SHALL NOT appear in the report

#### Scenario: One bad row does not stop the others
@e2e exclude Fault injection; tests/Unit/Service/CmdbExportImportServiceTest.php makes saveObject() throw for one row of three.

- **GIVEN** an export with three rows, where saving the module of the second row fails in OpenRegister
- **WHEN** it is imported
- **THEN** rows 1 and 3 SHALL be `created`
- **AND** row 2 SHALL be `failed` with a reason naming the step that failed
- **AND** the response SHALL be 200 with that summary

#### Scenario: A row without a name is skipped with its reason
@e2e exclude Covered by the service test; tests/Unit/Service/CmdbExportImportServiceTest.php testRowsAreSkippedWithTheirReasons asserts the row is skipped with `missing Applicatie Naam`.

- **GIVEN** a row on "Beheerde Applicaties CMDB" with an APPID but an empty "Applicatie Naam"
- **WHEN** it is imported
- **THEN** it SHALL be `skipped` with reason `missing Applicatie Naam`

### Requirement: Records missing from a newer export SHALL be left untouched (REQ-CMDB-012)

The import SHALL accept `missingRecords` with the value `keep`, which is also the default. It SHALL NOT change, depublish or delete a module, usage, organisation or contact person because its APPID is absent from the upload. Any other value, including the reserved `mark` and `remove`, SHALL be refused with 422 `MISSING_RECORDS_UNSUPPORTED`.

#### Scenario: An application dropped from the export stays
@e2e exclude Covered by the service test; tests/Unit/Service/CmdbExportImportServiceTest.php imports two rows, then one, and asserts the other module and usage are unchanged.

- **GIVEN** the modules with APPID `1` and `7` were imported for "Gemeente Voorbeeldstad"
- **WHEN** a newer export that only contains APPID `1` is imported
- **THEN** the module with APPID `7` and its usage SHALL be unchanged

#### Scenario: A reserved value is refused
@e2e exclude Validation; tests/Unit/Controller/CmdbImportControllerTest.php testAReservedMissingRecordsValueIsRefused.

- **GIVEN** a valid export
- **WHEN** a Nextcloud admin posts it with `missingRecords=remove`
- **THEN** the endpoint SHALL answer 422 with error `MISSING_RECORDS_UNSUPPORTED`
- **AND** no object SHALL be written

### Requirement: A running import SHALL report its progress and SHALL stop when cancelled (REQ-CMDB-013)

The import SHALL run as a `ProgressTracker` operation of type `cmdb_import` under the `operationId` the client sends, and SHALL update the processed row count after every row, readable through the existing `GET /api/progress/{operationId}`. `POST /api/cmdb-import/{operationId}/cancel`, open to the same users as the import and CSRF-protected, SHALL request cancellation. The service SHALL check for cancellation between rows, SHALL keep the rows already processed, and SHALL return the report with `cancelled: true`. The final report SHALL also be stored with the operation, so it can be read again within the tracker's lifetime.

#### Scenario: The admin follows and cancels a running import
@e2e tests/e2e/spec-coverage/cmdb-import.spec.ts covers the section: the progress, the cancel request for the page's operation and the cancelled report. A two-row import finishes before a cancel can land between rows, so the server's stop before the next row is asserted by tests/Unit/Service/CmdbExportImportServiceTest.php testACancelStopsBetweenRows (cancel after row 1 of three: one processed row, cancelled true).

- **GIVEN** an import of three rows that is running
- **WHEN** the admin presses Cancel after the first row is done
- **THEN** the service SHALL stop before the second row
- **AND** the report SHALL show 1 processed row and `cancelled: true`
- **AND** the module created for the first row SHALL stay

### Requirement: The admin settings SHALL offer a CMDB import section (REQ-CMDB-014)

Stackiq's admin settings page SHALL show a section "CMDB import", rendered by the settings page and not registered as an in-app route. The section SHALL let the admin choose an existing municipality or type the name of a new one, choose an `.xlsx` file, and start the import. While the import runs it SHALL show a progress bar and a Cancel button. Afterwards it SHALL show the summary and a report table that can be filtered by outcome. Every control SHALL have a visible label, and every string SHALL be translatable.

#### Scenario: The admin runs an import from the settings page
@e2e tests/e2e/spec-coverage/cmdb-import.spec.ts

- **GIVEN** a Nextcloud admin on stackiq's admin settings page
- **WHEN** they choose "Gemeente Voorbeeldstad", choose the anonymised export and press "Import"
- **THEN** a progress bar SHALL appear while the import runs
- **AND** afterwards the summary and the report table SHALL be shown
- **AND** filtering the table on `created` SHALL show the two imported rows

## Non-Functional Requirements

- **Performance:** an export of 1,100 rows SHALL import on the local rig without exceeding PHP's default memory limit, by loading only the source sheets in read-data-only mode. A re-import of an unchanged export SHALL make no `saveObject()` call for unchanged modules and usages. Lookups of organisations, modules and contact persons SHALL be cached per import run, so each distinct vendor, APPID and contact is looked up at most once.
- **Security:** an uploaded third-party file is input: xlsx only, bounded size and row count, no formula evaluation (cached values only), no external links, header-name resolution, per-row isolation, admin-only routes with CSRF (REQ-CMDB-001 to 003, 011). No cell value is ever rendered as HTML.
- **Privacy:** only the owner columns named in REQ-CMDB-010 are read into stackiq, and the objects holding them are never publicly readable. The report and the logs contain no person data. Test fixtures are anonymised and carry no document metadata naming real people.
- **Accessibility:** Target WCAG 2.2 AA. The section uses Nextcloud and `@conduction/nextcloud-vue` components: labelled file input and municipality select (SC 1.3.1, 3.3.2; gates `form-label-association`, `nc-input-labels`), a labelled Cancel button (SC 4.1.2; gate `button-name`), a progress bar and summary announced through a polite live region (SC 4.1.3; `axe`), and a report table with header cells (SC 1.3.1; gate `table-headers`). New in 2.2: 2.4.11 Focus Not Obscured applies (the report must not hide focus behind sticky headers); 2.5.7 Dragging Movements does not apply (the file input works without drag and drop); 2.5.8 Target Size applies to the buttons (Nextcloud defaults); 3.2.6 Consistent Help does not apply (no help mechanism added); 3.3.7 Redundant Entry applies (the chosen municipality stays selected after an import); 3.3.8 Accessible Authentication does not apply (no authentication step).
- **Internationalization:** Dutch and English MUST be supported (ADR-005) for the section, the error messages and the report reasons.

## Acceptance Criteria

- [ ] A Nextcloud admin imports the anonymised TOPdesk export for a chosen municipality, and the report lists both data rows as created.
- [ ] Importing the same export again creates no object, and reports both rows as unchanged.
- [ ] A changed "Applicatie Naam" in a newer export updates the same module (matched on APPID); `publicationDate` and fields the export does not map stay as they were.
- [ ] Rows with the same "Vendor" share one supplier organisation.
- [ ] Every imported module has one usage whose consumer is the municipality.
- [ ] A missing "APPID" or "Applicatie Naam" column stops the import with 422 naming the column and sheet; a non-xlsx or oversized file is rejected before reading.
- [ ] One failing row is reported as failed while the other rows are imported.
- [ ] The imported owner is not readable without signing in.
- [ ] Imported modules are found by OpenCatalogi's search, and appear in Portaliq's "Software we use" for the municipality's account, once both apps are configured as the docs describe.

## Notes

- Mapping decisions per column, including the columns that are not mapped because the target schema has no field, are listed in design.md.
- Connections, suites, hosting parties ("Hostingpartij"), "Leverancier", the archive sheet "Gearchiveerde Applicaties" and `missingRecords: mark|remove` are follow-ups (proposal, Out of Scope).
- Related: stackiq#373 (live TOPdesk connector), stackiq#1127 (record reconciliation), stackiq#1134 (ITSM exchange, the opposite direction), sbom-import and archimate-import (the upload patterns this follows).
