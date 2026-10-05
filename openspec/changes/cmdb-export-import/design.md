# Design: cmdb-export-import

## Context

A municipality delivers its application landscape as a TOPdesk export (xlsx). The anonymised test export has ten sheets. The municipality's application manager exports AIA and APP from TOPdesk into the raw "Invoer AIA data" and "Invoer APP data" sheets; the CMDB sheets next to them are the overviews the municipality itself uses as "the CMDB", built from the raw sheets with formulas. Decided with the municipality on 2026-10-01: the import reads the two CMDB sheets, "Onbeh Applicaties CMDB" (35 columns, from AIA: applications **without** arranged maintenance) and "Beheerde Applicaties CMDB" (42 columns, from APP: **with** arranged maintenance). The "Invoer" sheets are not read; "Gearchiveerde Applicaties" is a follow-up (missing records). Both CMDB sheets carry formatted but empty rows below the data, and "Beheerde" also formula rows that reference empty "Invoer" rows. Every cell is a formula; the reader uses the cached values. Dates are Excel serial numbers. The file also contains document metadata, a SharePoint sensitivity label, an embedded Power Query package and an external data connection (`xl/connections.xml`).

The chain baseline on the local rig (OpenRegister 2.1.34-unstable, OpenCatalogi 2.1.17-unstable, Portaliq 0.2.8-unstable, stackiq 0.2.4-unstable) fixed what the import has to produce:

- OpenRegister's `POST /api/registers/{id}/import` cannot take this file. It maps sheet names to schema slugs and stops at the first unknown sheet. Migration packs work on CSV and JSON only, and one pack targets one schema.
- OpenCatalogi lists a stackiq `module` only through a catalogue that includes the stackiq register and `module` schema, and only when the module's `publicationDate` is set and not in the future.
- Portaliq shows an application to a municipality only through a `usage` whose `consumer` is the organisation in the account's `stackiq.organisationId` claim.

Stackiq already has two upload imports: `SbomController` with `SbomImportService`, and `SettingsController::importArchiMate` with `ArchiMateImportService`. Both write through `ObjectServiceInterface` and report progress through `ProgressTracker`. This change follows the same pattern.

## Goals / Non-Goals

**Goals**

- One admin action turns a TOPdesk export into modules, vendors, usages and owners for one municipality.
- Owner data is kept in stackiq and Nextcloud Contacts but is never publicly readable.
- Re-importing a newer export updates the same records and creates no duplicates.
- The mapping is data (JSON), not code, and runs through OpenRegister's mapping engine.
- An untrusted spreadsheet is read safely, and one bad row never breaks the import.

**Non-Goals**

- Connections, suites, hosting parties ("Hostingpartij"), "Leverancier", the archive sheet, the functional administrator as technical owner.
- Marking or removing records that disappeared from the export.
- A background job, a dry run, an `occ` command, a live TOPdesk connection.
- Writing OpenCatalogi catalogues or Portaliq accounts.

## Architecture Overview

```
Admin settings, "CMDB import" section (CmdbImport.vue)
  │  multipart: cmdbFile, municipalityUuid | municipalityName,
  │             updateExisting, missingRecords, operationId   (+ requesttoken)
  ▼
CmdbImportController::import()              admin-only, CSRF, size/type checks
  ▼
CmdbExportImportService::import()
  ├─ CmdbImportProfile        lib/Settings/cmdb-import/topdesk-profile.json + 5 packs
  │                           (packs checked with OR PackDefinitionValidator)
  ├─ CmdbWorkbookReader       PhpSpreadsheet Xlsx, read-data-only, profile sheets only,
  │                           header-name columns, allowlisted columns, empty rows dropped
  ├─ CmdbRowNormaliser        trim, placeholder → empty, Excel serial → Y-m-d, numeric ids → string
  ├─ OR MappingEngine::mapRow()   once per pack per row
  ├─ resolve per row, in order:
  │     municipality (once) → manufacturer → module → owners → usage
  │     via ObjectServiceInterface::searchObjects()/saveObject()
  │     and StackiqContactSyncService (OCP\Contacts\IManager)
  ├─ ProgressTracker          operation `cmdb_import`, per-row progress, cancel
  └─ report                   summary + one entry per counted row
  ▼
OpenRegister, register `stackiq`: module, organization, usage, contactPerson
  ├─▶ OpenCatalogi search   (module with publicationDate, via its catalogue)
  └─▶ Portaliq              (usage.consumer = the account's organisation)
```

## Decisions

### D1. A stackiq service, not OpenRegister's import endpoint

The import is a stackiq service plus controller.

- **Alternative: OpenRegister `/api/registers/{id}/import` with a migration pack.** Rejected. It does not accept a pack on xlsx, maps one sheet to one schema, and cannot link the objects it creates (usage.module, usage.consumer, module.provider).
- **Alternative: stackiq splits the file into one CSV per schema and runs four OpenRegister imports.** Rejected. Stackiq still has to split and link the rows, so the four pack runs add moving parts without taking work away.

### D2. The mapping is a set of migration packs executed by OpenRegister's MappingEngine

Each target has one pack in OpenRegister's migration-pack format (`id`, `name`, `sourceFormat: excel`, `version`, `fieldMappings`, `idStrategy: {type: generate}`, optional `defaults`). The service validates each pack with `OCA\OpenRegister\Service\MigrationPack\PackDefinitionValidator` when an import starts, and maps rows with `MappingEngine::mapRow($pack, $row, $rowNumber)`. The engine supplies `trim`, `date`, `lookup`, `concat` and `const`, the "required" rule, the guard that an unmapped lookup value never passes through, and errors that name the row, the column and the transform.

Files, all in `lib/Settings/cmdb-import/`:

| File | Target | Notes |
|---|---|---|
| `topdesk-profile.json` | none | The two sheets, each with its `constants` (the `Beheer` value added to every row) and `absentColumns` (pack columns the sheet is known not to have), key column, required columns, date and id columns, `emptyValues` (placeholders that mean empty), the pack per target, create-only fields, size and row limits |
| `topdesk-module.json` | `module` | Mapping errors on `required` mappings skip the row; lookups for hosting model and BBN level |
| `topdesk-manufacturer.json` | `organization` (Supplier) | Empty "Vendor" means no provider |
| `topdesk-municipality.json` | `organization` (Municipality) | Maps the options row `{municipalityName}`, not a sheet row |
| `topdesk-usage.json` | `usage` | Lookups for status and TIME class; the maintenance note |
| `topdesk-business-owner.json` | owner identity | `name`, `role`; the service turns it into a contact and a `contactPerson` |

There is no technical-owner pack: the functional administrator (FB contactpersoon) is not imported (decided 2026-10-01).

Stackiq-specific settings live in the profile, not in the packs, so every pack stays a valid OpenRegister pack.

`MappingEngine` and `PackDefinitionValidator` are not part of OpenRegister's `Contract` namespace. The service resolves them from the container inside a guard. If either is missing, the import answers 503 `MAPPING_UNAVAILABLE` before it reads the file.

- **Alternative: OpenRegister's Twig-based `MappingService::executeMapping()` with `Mapping` entities shipped in the register's `components.mappings`.** Those mappings would be editable in OpenRegister's UI. Rejected for now: lookups and "required" would have to be written as Twig templates, and errors would come without row and column. Kept as an option if admins need to edit the mapping in a UI.
- **Follow-up (not built here):** before using the shipped file, look up a pack with the same `id` in OpenRegister's migration-pack store (`MigrationPackService::findByPackSlug()`). An admin could then override the mapping through `POST /api/migration-packs/import`, without a release.

### D3. Reading the workbook

`CmdbWorkbookReader` checks the upload, then reads it:

1. Before PhpSpreadsheet: the name ends in `.xlsx`, the first bytes are the ZIP signature `PK\x03\x04`, and `ZipArchive` lists `xl/workbook.xml`. Otherwise 400 `NOT_XLSX`.
2. `new \PhpOffice\PhpSpreadsheet\Reader\Xlsx()`, then `setReadDataOnly(true)` and `setLoadSheetsOnly([...profile sheet names that exist])`. The sheet names come from `listWorksheetNames()`. The class comes from OpenRegister's vendor directory, which is loaded whenever OpenRegister is enabled. It is checked with `class_exists`; if absent, 503 `READER_UNAVAILABLE`.
3. Row 1 holds the headers. Each header is normalised (trim, collapse whitespace, drop a trailing `:` or `⚡`, lower case) and matched to the column names the profile and the packs reference. Only those columns are kept. Every other cell, such as Personeelsnummer, phone numbers and group mailboxes, is never copied out of the reader.
4. For each cell the reader takes `getValue()`. For a formula cell (data type `f`) it takes `getOldCalculatedValue()`, the value Excel cached. It never calls `getCalculatedValue()` or `toArray()` with formula calculation. Every cell of the CMDB sheets is a formula, so this is the normal path. A formula without a cached value (no `<v>` in the file, for example a workbook written by a tool that does not calculate) is read as empty and its column is listed in the row's `uncached`; the service turns that into the row warning `Column "…": formula without a cached value, read as empty`. It never fails the row. A cached number `0` is what Excel stores for a reference to an empty cell, and is read as empty.
5. A row whose kept cells are all empty is dropped and not counted. With rule 4 this also drops the formula rows that reference empty "Invoer" rows.
6. Columns are resolved per sheet. A required column missing on a present sheet stops the import; an optional one gives one import-level warning, unless the profile lists it in that sheet's `absentColumns` (`Nickname` exists only on "Beheerde"). A source sheet with more than `maxRowsPerSheet` (10,000) non-empty rows stops the import with 422 `TOO_MANY_ROWS`.

External connections, the Power Query package and hyperlinks are never resolved: PhpSpreadsheet does not follow them, and the reader gets no HTTP client.

### D4. Normalising a row before mapping

`CmdbRowNormaliser` turns reader output into the flat `column => string` row the engine expects:

- Values listed in the profile's `emptyValues` for their column become empty, compared case-insensitively before any conversion: `NB` in "BNN Classificatie" (the CMDB sheet's "niet bekend"). Dates are stored as the file has them: "End-of-Life Functioneel" `49675` (2036-01-01) is imported as that date, even though the CMDB sheet's formula writes it for an empty date or TOPdesk's 2099-12-31; the municipality decided to keep the file's value (2026-10-02).
- Columns listed in the profile's `dateColumns` ("Datum", "Referentie datum wijziging", "End-of-Life Functioneel"): a numeric value is converted with `PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject()` in UTC and written as `Y-m-d`. For example, `45111.38…` becomes `2023-07-04` and `53359` becomes `2046-02-01`. A non-numeric value stays as it is, so the pack's `date` transform (`sourceFormat: Y-m-d`) either accepts it or reports a warning.
- Columns listed in `idColumns` ("APPID"): a whole number becomes a string without a decimal part (`1234.0` becomes `"1234"`).
- The constants of the row's sheet are added before mapping (`Beheer` = `Beheer geregeld: nee` on "Onbeh", `ja` on "Beheerde"), so the usage pack can map the sheet like a column.
- Every value is trimmed. An empty string counts as empty.

The engine's `date` transform only parses formatted strings. Doing the serial conversion in the normaliser keeps the packs plain OpenRegister packs.

### D5. Matching key and upsert

The key is the TOPdesk APPID (the ICT Applicatienummer), scoped to the municipality: `externalKey = "topdesk:" + municipalityUuid + ":" + APPID`. Decided with the municipality on 2026-10-01: the Middel-ID (CMDB column "Applicatie Code") can be changed in TOPdesk, the APPID cannot; the first real import also showed the Middel-ID prefix in two spellings (`APP-` and `App-`). The Applicatie Code is stored as `externalId` for reference and updated like any mapped field. APPIDs are unique within one TOPdesk instance, not across municipalities; with the scope, two municipalities can each import an APPID `101` without colliding.

Per row:

1. A row without an APPID is skipped (`missing APPID`). An APPID already seen in this upload, on either sheet, is skipped (`duplicate APPID in file`). The CMDB sheets have no "Soort" column, so there is no row-kind filter.
2. Look up the module with `searchObjects` on the configured register and module schema, filtered on `externalKey`, with `_rbac: false` and `_multitenancy: false` (as `SbomImportService` does; the caller is an admin). The result is cached for the run.
3. No match: create the module from the mapped data, plus `externalKey`, the create-only defaults (`type: Application`), and `publicationDate` (D6).
4. Match and `updateExisting=false`: skip with reason `exists`.
5. Match: merge the mapped fields onto the stored object. Every field the pack does not map stays as it is. Create-only fields stay as they are, unless the stored value is empty. If the merged object equals the stored one, do not save, and report `unchanged`. Otherwise save, and report `updated`.

The APPID is also stored as `externalNumber`, so it is visible on the module.

- **Alternative: the Middel-ID ("Applicatie Code") as the key**, as in the first version of this change. Rejected on 2026-10-01: it can change in the source.
- **Alternative: OpenRegister's `idStrategy: sourceField` (APPID as the object id).** Rejected. Object ids are global uuids, and the APPID is neither a uuid nor unique across municipalities.
- **Alternative: put the key on `usage` (per municipality by nature).** Rejected for this change: the key was decided to live on `module`, and the usage is found from the module anyway (D7).

### D6. publicationDate

- New module: `publicationDate` = the import's start time (ISO 8601 with offset). This makes it visible to OpenCatalogi, given a catalogue that covers the stackiq `module` schema.
- Existing module: `publicationDate` and `depublicationDate` are never written, also when they are empty. An admin who depublished an imported module keeps it depublished.

### D7. Related objects and their order

Per row, in this order:

1. **Municipality** (once per import): `municipalityUuid` must resolve to an `organization` of type `Municipality`. Otherwise 422 `MUNICIPALITY_INVALID`. With `municipalityName`, the service reuses an existing Municipality with the same normalised name, or creates one through the municipality pack.
2. **Manufacturer**: map "Vendor" (the maker of the software) through the manufacturer pack. "Leverancier" (where the municipality buys it) and "Hostingpartij" are not read (follow-up). The normalised name (trim, collapse whitespace, lower case) is looked up in the run cache, then among `organization` objects of type `Municipality`, then of type `Supplier`. A new Supplier is created only when none matches. The Municipality comes first because a municipality that builds its own applications names itself as Vendor (Rotterdam: "Gemeente Rotterdam" on 57 rows); before 2026-10-05 that created a second, Supplier organisation of the same name next to the municipality. The first real import (1,137 rows, 479 suppliers) showed no two names that differ only in case, spacing or a legal-form suffix (`B.V.`, `BV`, `Inc.` …), so the normalisation is not widened.
3. **Module** (D5), with `provider` = the manufacturer when there is one.
4. **Owners** (D8).
5. **Usage**: `searchObjects` on `consumer` = municipality and `module` = module uuid. Create or merge the usage pack's fields, plus `consumer`, `module`, `provider` = the manufacturer, and `businessOwner`. `interneAnnotation` ("Beheer geregeld: ja|nee / Cluster / Applicatie Eigenaar (Afdeling)", empty parts left out) is create-only, because it is a free-text note an admin may edit.

When step 3 succeeds and step 5 fails, the row is `failed` with the step named. The next import completes it, because every step is find-or-create.

### D8. The owner as contact person

Stackiq keeps a person's identity in Nextcloud Contacts. A `contactPerson` object holds only `contactsUid`, `role`, `organization` and `roles`. The owner is "Applicatie Eigenaar (Persoon)"; when TOPdesk has no owner the CMDB sheet shows the owner's function there instead, and the import uses that as the display name too. "Applicatie Eigenaar (Functie)" is the role; "Applicatie Eigenaar (Afdeling)" goes into the usage note (D7), because a contact person has no department field. The functional administrator (FB contactpersoon) is not imported, so there is no `technicalOwner`.

1. The CMDB sheets carry no e-mail address, so the service runs `searchContacts(name)` and accepts only an exact, case-insensitive display-name match; otherwise `StackiqContactSyncService::syncToContacts('contactPerson', ['voornaam' => …, 'achternaam' => …, 'role' => …])` creates the contact. This avoids creating a new contact on every import.
2. Find the `contactPerson` with that `contactsUid` and `organization` = the municipality (run cache, then `searchObjects`). If none exists, create it with `role` = "Applicatie Eigenaar (Functie)" when given.
3. Set `usage.businessOwner` to its uuid.

**Never public.** `contactPerson` and `usage` have read rules for named groups only, none for `public`, and a published `module` refers to them through `contactPerson` / `usages` relations. An anonymous OpenCatalogi search hit therefore carries at most ids, and OpenRegister's objects API returns no contact person or usage to an anonymous caller. `tests/Unit/Settings/CmdbPersonDataVisibilityTest.php` pins the read rules on the merged register; the e2e test checks the running stack anonymously.

The import never calls the user-provisioning paths (`ContactpersoonService::processContactpersoon`, `convertToUser`). The scheduled `OrganizationSyncService::performUserSync` provisions users for contact persons. A unit test asserts that a `contactPerson` written by the import does not meet its selection criteria, and the implementation task verifies that before shipping (see Risks). When Contacts is disabled, owners are skipped with a warning and the row is still imported.

### D9. Progress, cancel and the report

The import runs inside the upload request, as the SBOM and ArchiMate imports do. The client sends a fresh `operationId`. The service calls `startOperation('cmdb_import', ['total_items' => rowCount])`, then `updateProgress` after each row and `completeOperation($report)` at the end. The UI polls the existing `GET /api/progress/{operationId}`. `POST /api/cmdb-import/{operationId}/cancel` calls `setCancelRequested()`. The service checks `isCancelRequested()` between rows and returns the partial report with `cancelled: true`.

Report shape (contract.md is authoritative): `summary {rowsRead, created, updated, unchanged, skipped, failed, warnings}`, `importWarnings[]` (for example, a missing optional column), and `rows[] {sheet, row, appId, name, outcome, reasons[], warnings[], moduleUuid, usageUuid}`. Reasons name columns and values. They never name owners, e-mail addresses or other person data, and neither do log lines.

### D10. Controller and validation order

`CmdbImportController::import()` has neither `#[NoAdminRequired]` nor `#[NoCSRFRequired]`, so Nextcloud's middleware enforces admin and CSRF before the method runs. The method then checks, in this order:

1. A file is present: otherwise 400 `NO_FILE_UPLOADED`.
2. Size: otherwise 413 `FILE_TOO_LARGE`.
3. xlsx: otherwise 400 `NOT_XLSX`.
4. `missingRecords` is `keep`: otherwise 422 `MISSING_RECORDS_UNSUPPORTED`.
5. A municipality is given: otherwise 422 `MUNICIPALITY_REQUIRED`.
6. The service runs. It answers 503 `MAPPING_UNAVAILABLE` or `READER_UNAVAILABLE`, 422 `NO_SOURCE_SHEET`, `MISSING_COLUMN`, `TOO_MANY_ROWS` or `MUNICIPALITY_INVALID`, or 200 with the report.

Every expected service exception is translated to its status code in the controller (hydra gate controller-exception-translation). Only unexpected errors become 500, with a generic message and the detail logged.

### D11. The settings section

`src/views/settings/sections/CmdbImport.vue` sits next to `ArchiMateImportExport.vue` in `StackiqSettings.vue`, inside `AlwaysVisibleSection`, and is not added to the vue-router (hydra gate admin-router).

- Municipality: an `NcSelect` with a label. It lists organisations of type Municipality, read through the OpenRegister objects API, and has an option to type a new name.
- File: an `<input type="file" accept=".xlsx">` with a label.
- Options: an "Update existing records" checkbox.
- Import and Cancel buttons.
- During the import: `NcProgressBar` with a polite live region.
- Afterwards: summary counts and a `CnDataTable` report with an outcome filter and links to the modules.

Cell values are shown with text interpolation only, never `v-html`. Requests use `@nextcloud/axios`, which sends the CSRF token.

## Column mapping

Source columns of "Onbeh Applicaties CMDB" and "Beheerde Applicaties CMDB" and where they go, by header name. A column that one sheet lacks is optional there. Columns that are not listed are not read (D3).

| Column | Sheets | Target | Rule |
|---|---|---|---|
| APPID | both | module.externalNumber; part of module.externalKey | trim, required, numeric to string, match key (D5) |
| Applicatie Naam | both | module.name | trim, required |
| Applicatie Code | both | module.externalId | trim; reference only (the Middel-ID; can change in the source) |
| Roepnaam | both | module.shortDescription | trim; wins over Nickname |
| Nickname | Beheerde | module.shortDescription | trim; used when Roepnaam is empty; listed as absent on Onbeh |
| Functionele Omschrijving | both | module.longDescription | trim |
| Applicatiesoort | both | module.applicationType and module.cloudDienstverleningsmodel | applicationType: as is; hosting model lookup: `Saas`/`SaaS` → `["SaaS"]`, `PaaS` → `["PaaS"]`, `IaaS` → `["IaaS"]`, `On-premise(s)` → `["On-premises (self-managed)"]`; any other kind (`Webapplicatie`, `Client/server`, …) has default `null`: no hosting model, no warning |
| BNN Classificatie | both | module.bbnLevel | `NB` is empty; lookup `1`/`2`/`2+`/`3` and "BBN1"/"BBN 1"/"BNN1" etc. to `BBN1`/`BBN2`/`BBN2+`/`BBN3` (the fragment adds `BBN2+` to the enum); unknown value: warning |
| Datum | both | module.externalCreatedAt | Excel serial to date |
| Referentie datum wijziging | both | module.externalModifiedAt | Excel serial to date |
| Vendor | both | organization (Supplier) via module.provider and usage.provider | dedup on normalised name (D7) |
| Applicatie Status | both | usage.status | lookup: In productie → In production, In voorraad → Planned, In ontwikkeling → Acquisition, Uit te faseren and Moet verwijderd worden → To be phased out, Uitgefaseerd and Verwijderd → Phased out, Besteld and Wordt getest → Acquisition, Stand-by voor continuïteit → In production; unknown value: warning |
| Classificatie | both | usage.timeClassification | lookup Tolereren/Tolerate (also `1. Tolereren (wordt ingelezen)`), Investeren/Invest, Migreren/Migrate, Elimineren/Eliminate |
| End-of-Life Functioneel | both | usage.startDateOutPhased | Excel serial to date, stored as is (2036-01-01 included) |
| (sheet constant `Beheer`), Cluster, Applicatie Eigenaar (Afdeling) | both | usage.interneAnnotation | concat with " / ", empty parts dropped, create-only; `Beheer geregeld: nee` (Onbeh) or `ja` (Beheerde) |
| Applicatie Eigenaar (Persoon), Applicatie Eigenaar (Functie) | both | usage.businessOwner (contactPerson + Nextcloud contact; role = Functie) | D8; the person column may hold a function |
| Hostingpartij | both | not mapped | follow-up; "Leverancier" (where the municipality buys the software) is not on the CMDB sheets |
| Software Suite | both | not mapped (suite schema; needs a second pass) | follow-up |
| Applicatiecomponent, Bron, Datum Interface, Referentie element externe ID, Cloud, Rappeldatum, Rappelreden, Locatie BIOToets | both | not mapped | Cloud is derived from Applicatiesoort; Datum Interface is the export date |
| Beschikbaarheid, Integriteit, Vertrouwelijkheid, Applicatienut, Kwaliteit en betrouwbaarheid van leverancier, Flexibiliteit, Gebruikerstevredenheid, Reputatie risico | both | not mapped (no field on module or usage) | schema extension is out of scope |
| Standaard, Behandelgroep, End-of-life Technisch, End-of-support Technisch, Top5, COTS, Applicatie Nummer | Beheerde | not mapped | Applicatie Nummer repeats the APPID |
| every column of the "Invoer" sheets | – | never read | |

## API Design

The authoritative interface is in `contract.md`. In short:

- `POST /api/cmdb-import`: multipart `cmdbFile`, plus `municipalityUuid` or `municipalityName`, `updateExisting` (default `true`), `missingRecords` (default `keep`) and `operationId`. Admin, CSRF. Answers 200 with the report, or one of the errors in D10.
- `POST /api/cmdb-import/{operationId}/cancel`: admin, CSRF. Answers 200 `{cancelRequested: true}`.
- `GET /api/progress/{operationId}`: the existing route, unchanged.

## Database Changes

No tables or Nextcloud migrations (ADR-001). The `module` schema gains five optional properties through a register fragment (see Mixed-spec rationale and `migration.md`).

## Mixed-spec rationale (ADR-032)

The change is `kind: code`. Its weight is the import service, controller, reader and settings section. It also contains a thin schema delta: `lib/Settings/register.d/topdesk-cmdb-import.json` adds `externalId`, `externalNumber`, `externalKey`, `externalCreatedAt` and `externalModifiedAt` to `module`, all optional strings or dates, and seeds three example modules. This is not the ADR-032 `mixed` anti-pattern:

1. The delta is additive glue that exists only for this code. Nothing else reads the properties, and the import cannot be idempotent without a stored key (no existing `module` property can hold a TOPdesk id).
2. It follows the app's fragment convention (ADR-037), so it touches no other change's file.
3. It is deployed by the existing register import in the repair step, without a migration class.

The fragment bumps `module` to `0.3.5`. Fragments are merged in filename order and a scalar `version` is overwritten by the last fragment that sets it. `maintenance-and-roadmap.json` sets `module` to `0.3.4`, so a fragment that sorts before it would have its bump overwritten, and the new properties would never deploy. The file is therefore named `topdesk-cmdb-import.json`, which sorts after it, and a unit test asserts that the merged register declares `module` version `0.3.5` with the five properties.

## Declarative-vs-imperative decision (ADR-031)

- **Imperative, because it is an external integration:** reading an uploaded third-party file, splitting a row into four linked objects, resolving contacts in Nextcloud Contacts, progress and cancel. These are not object lifecycle, aggregation, notification or relation rules that an `x-openregister-*` block can express. This is the external-integration exception: the service is imperative glue around the file.
- **Declarative:** what each column becomes (target property, transform, lookup, required) is JSON in OpenRegister's migration-pack format, executed by OpenRegister's `MappingEngine`. Changing the mapping changes no PHP.
- **Matching rule (stated once, enforced in code):** a module matches when its `externalKey` equals `topdesk:<municipality uuid>:<APPID>`. A usage matches on (`consumer`, `module`). A manufacturer matches on its normalised name, a `Municipality` before a `Supplier`. A contact person matches on (`contactsUid`, `organization`).
- **publicationDate rule (stated once, enforced in code):** set to the import's start time on create; never written on update.
- No `x-openregister-*` block is added or changed. The usage name keeps coming from the schema's existing name template.

## Nextcloud Integration

- Controllers: `CmdbImportController` (`import`, `cancel`), admin-only with CSRF, no `NoAdminRequired` / `NoCSRFRequired`.
- Services: `CmdbExportImportService` (orchestration), `Cmdb\CmdbWorkbookReader`, `Cmdb\CmdbRowNormaliser`, `Cmdb\CmdbImportProfile` (loads and validates the profile and packs). They reuse `ProgressTracker`, `SettingsService` (register and schema ids) and `StackiqContactSyncService`.
- OCP: `IRequest::getUploadedFile()`, `IUserSession`, `IL10N`, `OCP\Contacts\IManager` (through `StackiqContactSyncService`), `ICacheFactory` (through `ProgressTracker`).
- OpenRegister: `ObjectServiceInterface::searchObjects()` / `saveObject()` (contract), `MigrationPack\MappingEngine` and `PackDefinitionValidator` (container, guarded), PhpSpreadsheet (guarded).
- Mappers/Entities: none (ADR-001, ADR-008: Controller → Service → OpenRegister).
- Events/Hooks: none. Saves go through `saveObject()`, so the existing `ModuleRegistrationSubscriber` and `ModuleComplianceSubscriber` run as they do for any module save.

## Security Considerations

- **Auth and CSRF:** both routes are admin-only through Nextcloud's middleware, with CSRF required. This is stricter than `SbomController` and `importArchiMate`, which carry `NoCSRFRequired`. The admin check happens before the body is read.
- **File checks before parsing:** size limit (10 MB, profile), `.xlsx` extension, ZIP signature and `xl/workbook.xml`. `.xlsm` and `.xls` are rejected. The upload is read from PHP's temporary upload file and never written into Nextcloud Files.
- **No evaluation, no fetching:** read-data-only, profile sheets only, cached values for formula cells, no `getCalculatedValue()`, no HTTP client in the reader. External connections, Power Query packages and hyperlinks are inert.
- **Resource bounds:** row cap per sheet, and only allowlisted columns are kept. Memory is bounded by loading only the two CMDB sheets.
- **Injection:** every value is a string that goes through OpenRegister's schema validation on save, and is never used in SQL, file paths or templates. The UI renders values as text only.
- **Isolation:** every row runs in its own try/catch. Errors are reported per row, and the import continues.
- **Privacy:** the column allowlist keeps every person column except the owner out of memory; the "Invoer" sheets, which hold personnel numbers, phones and group mailboxes, are not read at all. Owner identity goes only to Nextcloud Contacts, and `contactPerson` and `usage` are never publicly readable (D8). Reports and logs carry no person data.
- **Fixture hygiene:** the test fixture is the anonymised export with document metadata, the custom properties (sensitivity label), `customXml/` (including the Power Query package) and `xl/connections.xml` removed. One small synthetic connection part is added back for the external-connection test.

## NL Design System

Nextcloud and `@conduction/nextcloud-vue` components only (ADR-012): `NcSelect`, `NcButton`, `NcCheckboxRadioSwitch`, `NcProgressBar`, `NcNoteCard` for errors, and `CnDataTable` for the report. The file input follows the label pattern of `ArchiMateImportExport.vue`. Colours and spacing come from Nextcloud CSS variables (ADR-003). Outcome badges reuse the existing status-tag styling.

## File Structure

```
appinfo/
  routes.php                                  (+ cmdbImport#import, cmdbImport#cancel)
lib/
  Controller/
    CmdbImportController.php
  Service/
    CmdbExportImportService.php
    Cmdb/
      CmdbImportProfile.php
      CmdbWorkbookReader.php
      CmdbRowNormaliser.php
      CmdbImportReport.php
  Exception/
    CmdbImportException.php                   (carries error code + HTTP status)
  Settings/
    cmdb-import/
      topdesk-profile.json
      topdesk-module.json
      topdesk-manufacturer.json
      topdesk-municipality.json
      topdesk-usage.json
      topdesk-business-owner.json
    register.d/
      topdesk-cmdb-import.json                (module 0.3.5: five properties + seed modules)
src/views/settings/
  StackiqSettings.vue                         (registers the section)
  sections/CmdbImport.vue
tests/
  fixtures/cmdb/
    topdesk-export-anonymised.xlsx            (sanitised copy of the test export)
    topdesk-missing-appid.xlsx
    topdesk-shuffled-columns.xlsx
    topdesk-formula-and-connection.xlsx
  Unit/Service/CmdbExportImportServiceTest.php
  Unit/Service/Cmdb/CmdbWorkbookReaderTest.php
  Unit/Service/Cmdb/CmdbRowNormaliserTest.php
  Unit/Service/Cmdb/CmdbImportProfileTest.php
  Unit/Controller/CmdbImportControllerTest.php
  Unit/Settings/TopdeskCmdbFragmentTest.php
  Unit/Settings/CmdbPersonDataVisibilityTest.php
  e2e/spec-coverage/cmdb-import.spec.ts
docs/features/
  cmdb-import.md
l10n/
  en.json, en.js, nl.json, nl.js
```

## Seed Data

Placeholders: uuids are nil-style (`00000000-0000-0000-0000-00000000000N`). All names are fictional ("Gemeente Voorbeeldstad", "Voorbeeld Software B.V."). No real people, addresses or numbers appear.

### Schema: `module` (modified; seeded through the fragment's `components.objects`)

The seeds show the new properties in a fresh install. They carry no `publicationDate`, so they are not published as open data, and no `externalKey`, because the key holds a municipality uuid that only exists at run time.

| Field | Object 1 | Object 2 | Object 3 |
|---|---|---|---|
| @self | register `stackiq`, schema `module`, slug `voorbeeld-zaaksysteem` | slug `voorbeeld-afsprakenplanner` | slug `voorbeeld-belastingapplicatie` |
| name | Voorbeeld Zaaksysteem | Voorbeeld Afsprakenplanner | Voorbeeld Belastingapplicatie |
| type | Application | Application | Application |
| longDescription | Registreert en volgt zaken van intake tot archivering. | Laat inwoners online een afspraak maken bij de balie. | Berekent en verstuurt gemeentelijke belastingaanslagen. |
| externalId | APP-00001 | APP-00002 | AIA-00003 |
| externalNumber | 101 | 102 | 103 |
| externalCreatedAt | 2023-07-04 | 2022-03-16 | 2024-01-15 |
| externalModifiedAt | 2026-07-29 | 2026-09-01 | 2026-05-20 |
| bbnLevel | BBN2 | BBN1 | BBN2 |

**Related items per object:** none seeded. Files, notes, tasks and contacts are not used by these modules. Provider and usages come from a real import, not from seeds.

### Objects an import writes (not seeded; reference shapes for tests and docs)

`organization` (municipality, created from `municipalityName`):

| Field | Value |
|---|---|
| uuid | 00000000-0000-0000-0000-000000000001 |
| name | Gemeente Voorbeeldstad |
| type | Municipality |
| status | Active |

`organization` (manufacturer):

| Field | Object A | Object B |
|---|---|---|
| uuid | 00000000-0000-0000-0000-000000000002 | 00000000-0000-0000-0000-000000000003 |
| name | Voorbeeld Software B.V. | Fabfrikant |
| type | Supplier | Supplier |
| status | Active | Active |

`module` (as written by the import):

| Field | Value |
|---|---|
| uuid | 00000000-0000-0000-0000-000000000004 |
| name | naamtest123 |
| externalId | APP-test123 |
| externalNumber | 2 |
| externalKey | topdesk:00000000-0000-0000-0000-000000000001:2 |
| shortDescription | Naamtest |
| longDescription | Accomodatieplanning. |
| cloudDienstverleningsmodel | ["SaaS"] |
| bbnLevel | BBN2 |
| provider | 00000000-0000-0000-0000-000000000003 |
| publicationDate | 2026-10-01T10:00:00+00:00 |

`usage`:

| Field | Value |
|---|---|
| uuid | 00000000-0000-0000-0000-000000000005 |
| consumer | 00000000-0000-0000-0000-000000000001 |
| module | 00000000-0000-0000-0000-000000000004 |
| provider | 00000000-0000-0000-0000-000000000003 |
| status | In production |
| timeClassification | Tolerate |
| startDateOutPhased | 2046-02-01 |
| interneAnnotation | Beheer geregeld: ja / B10 / B10 Maatschappelijke Ontwikkeling |
| businessOwner | 00000000-0000-0000-0000-000000000006 |

`contactPerson`:

| Field | Value |
|---|---|
| uuid | 00000000-0000-0000-0000-000000000006 |
| contactsUid | `<PLACEHOLDER-CONTACTS-UID>` |
| organization | 00000000-0000-0000-0000-000000000001 |
| role | Teamleider Applicatiebeheer |

## Risks / Trade-offs

- [The user sync might provision accounts for imported contact persons] → The import writes contact persons without e-mail or user fields on the OpenRegister object. A unit test runs `performUserSync`'s selection against an imported `contactPerson`. If the selection would pick it up, the implementation adds an explicit marker that excludes it before shipping, and does not ship otherwise.
- [Owner contacts land in the importing admin's address book] → `StackiqContactSyncService` writes to the first writable address book of the acting user, the same as every other stackiq contact path. The docs say so. A dedicated system address book is a follow-up.
- [Long synchronous request] → Per-row progress, cancel, and "unchanged" rows skip the save. About 1,100 rows is expected to fit. A background job is a follow-up if it does not.
- [OpenRegister internals (`MappingEngine`, `PackDefinitionValidator`, PhpSpreadsheet) change shape] → Guarded resolution with 503, and a contract test that maps the fixture through the real engine in the dev environment.
- [Lookups from the real export] → The maps hold the values the municipality's export of 2026-09-22 contains (2026-10-02 import report): "Applicatiesoort" is an application kind, kept as is in `applicationType`; "BNN Classificatie" holds `NB`, `1`, `2` and `2+`; "Applicatie Status" adds five Dutch statuses; "Classificatie" one numbered form. A new value is a warning, never a wrong value, and is added to the JSON map with no code change. A lookup `default` of `null` means "known, no value": the service leaves the field out.
- [CMDB placeholders] → "BNN Classificatie" `NB` is read as empty. "End-of-Life Functioneel" and "Classificatie" are stored as the file has them: the sheet's 2036-01-01 (written for an empty date) is imported as that date, and the `Tolereren` the "Beheerde" formula writes when TOPdesk has none is imported as Tolerate.
- [An application moves between the sheets] → Same APPID, so the same module and usage; the usage note (create-only) keeps its old `Beheer geregeld` line when it is not empty.
- [An unknown status on create falls back to the usage schema's default "In production"] → Accepted. The warning in the report makes it visible.
- [The fragment version is overwritten by merge order] → Filename ordering plus a unit test on the merged version (Mixed-spec rationale).

## Migration Plan

No data migration. The register fragment deploys with the existing repair-step register import (see `migration.md`). Rollback is a revert of the PR. The optional `module` properties may stay deployed without harm.

## Open Questions

- Should the maintenance status update the usage note on re-import (it is create-only today), or get a field of its own?
- Should OpenRegister promote `MigrationPack\MappingEngine` to its `Contract` namespace?
