---
kind: code
depends_on: []
---

# Proposal: cmdb-export-import

## Summary

A Nextcloud admin uploads a TOPdesk CMDB export (xlsx) in stackiq's admin settings, picks the municipality the export belongs to, and stackiq turns every application row into OpenRegister objects in the `stackiq` register: a `module` (the application), an `organization` for its manufacturer, a `usage` that links the application to the municipality, and `contactPerson` objects for the business and technical owner. Rows are matched on TOPdesk's `Middel-ID`, so a second import of a newer export updates the same records instead of duplicating them. The column-to-field mapping is declarative JSON executed by OpenRegister's migration-pack mapping engine. The admin follows the import live and gets a per-row report: created, updated, unchanged, skipped or failed, with the reason.

## Motivation

A municipality wants to search all its applications in one place in OpenCatalogi and see the ones it uses in Portaliq. Its CMDB is the source of that list, but a live API connection is not possible yet (calls must come from the municipality's own IP range), so the municipality delivers a periodic TOPdesk export instead (Jira WOO-586, epic WOO-281).

The existing paths cannot read this file. A chain baseline on a local rig (2026-10-01) showed:

- `POST /api/registers/{id}/import` in OpenRegister treats every xlsx sheet as a schema named after the sheet, and stops at the first sheet: `Schema not found (id='Invoer AIA data')`.
- OpenRegister migration packs apply to CSV and JSON imports only, and one pack maps one sheet to one schema. One TOPdesk row has to become a module, a manufacturer organisation, a usage and up to two contact persons, linked to each other.
- OpenCatalogi only lists a stackiq module that has a `publicationDate`. Portaliq only shows an application to a municipality through a `usage` whose `consumer` is that municipality. An import that writes modules alone leaves both apps empty.

Stackiq already has two upload-and-import flows (SBOM, ArchiMate). This change adds a third one for CMDB exports, built the same way.

## Capabilities

### New Capabilities

- `cmdb-export-import`: an admin uploads a TOPdesk CMDB export (xlsx) and stackiq creates or updates modules, manufacturer organisations, usages and owner contact persons for one municipality, matched on the TOPdesk Middel-ID, with live progress and a per-row report.

### Modified Capabilities

None. The `module` schema gains five optional properties through a register fragment (see design.md, Mixed-spec rationale). No existing requirement changes.

## Affected Projects

- [ ] Project: `stackiq`: import service, controller and routes, a "CMDB import" section in admin settings, declarative mapping and import-profile JSON under `lib/Settings/cmdb-import/`, a register fragment that adds external-id properties to `module`, tests, administrator docs and translations.

## Scope

### In Scope

- Upload endpoint for `.xlsx` files only, with a size limit, admin-only and CSRF-protected.
- Reading the two raw TOPdesk sheets, "Invoer AIA data" (Soort = Application Inventory) and "Invoer APP data" (Soort = Applicatie). Columns are found by header name, not position. Values are read as stored; formulas are never evaluated.
- One municipality per import, chosen by the admin from existing stackiq organisations of type Municipality, or created from a name the admin types.
- Per row: upsert the `module` on Middel-ID, find or create the manufacturer `organization` from the "Fabrikant" column (one organisation per distinct manufacturer), upsert the `usage` (consumer = municipality, module = the application), and find or create `contactPerson` objects for "Eigenaar" (business owner) and "FB contactpersoon 1" (technical owner) through Nextcloud Contacts.
- Declarative mapping: one migration-pack JSON per target (module, manufacturer, municipality, usage, business owner, technical owner) plus one import profile (sheets, required columns, key column, date columns), executed through OpenRegister's `MappingEngine::mapRow()`.
- Excel serial dates converted to ISO dates before mapping.
- `publicationDate` set to the import time on newly created modules, never changed on update.
- Repeatable import: matched records are updated, records missing from a newer export are left alone (`missingRecords: keep`, the only accepted value for now).
- Per-row error isolation, live progress and cancel through the existing `ProgressTracker`, and a per-row report.
- PHPUnit tests using the anonymised test export as a fixture, a Playwright e2e for the admin flow, an administrator docs page, and Dutch and English strings.

### Out of Scope

- The sheet "Invoer gearchiveerde appl" and the derived "*CMDB" sheets (deferred until the municipality confirms what they hold).
- Connections between applications from the "Ouders" / "Kind-middelen" columns (a second pass after all modules exist, as a follow-up change).
- Suites from "Software suite", hosting parties from "ICT Hostingspartij", and "Leverancier" as a second supplier source. The mapping can take them later without code once a target is agreed.
- Marking or removing records that disappeared from a newer export (`missingRecords: mark|remove`, reserved values; belongs with operations-record-reconciliation, stackiq#1127).
- A live TOPdesk or ServiceNow connection (stackiq#373, stackiq#1134), a dry-run mode, an `occ` command, and running the import as a background job.
- Configuring OpenCatalogi catalogues or Portaliq account claims. The docs describe both prerequisites; the import does not write to those apps.

## Approach

A `CmdbImportController` accepts the upload and options, validates the file before parsing, and hands it to `CmdbExportImportService`. The service reads the two sheets with PhpSpreadsheet's Xlsx reader in read-data-only mode, resolves columns by header name from the import profile, normalises each row (trim, Excel serial to ISO date, numeric ids to strings), and maps it with OpenRegister's migration-pack `MappingEngine` once per target pack. It then resolves the related objects in a fixed order (manufacturer, module, contact persons, usage) and saves each through OpenRegister's `ObjectServiceInterface`. Each row runs in its own try/catch and its outcome goes into the report. Progress and cancel use the existing `ProgressTracker` and `/api/progress/{operationId}` route, as the ArchiMate import does. A new admin-settings section uploads the file, polls progress and shows the report. Details are in design.md.

## New Dependencies

None for stackiq's `composer.json` or `package.json`. The xlsx reader (`phpoffice/phpspreadsheet`) and the mapping engine come from OpenRegister, which stackiq already requires. Design.md describes the guard for when either class is not available.

## Impact

- **New backend**: `lib/Service/CmdbExportImportService.php` (plus small helpers for sheet reading and row normalisation), `lib/Controller/CmdbImportController.php`, two routes in `appinfo/routes.php`.
- **New configuration**: `lib/Settings/cmdb-import/topdesk-profile.json` and six pack files `lib/Settings/cmdb-import/topdesk-*.json`.
- **Schema**: `lib/Settings/register.d/topdesk-cmdb-import.json` adds `externalId`, `externalNumber`, `externalKey`, `externalCreatedAt` and `externalModifiedAt` to `module` (all optional), with a version bump so the register import deploys them.
- **New frontend**: `src/views/settings/sections/CmdbImport.vue`, registered in `src/views/settings/StackiqSettings.vue`.
- **Data**: imports write `module`, `organization`, `usage` and `contactPerson` objects, and Nextcloud Contacts cards for owners. No existing object is deleted.

## Cross-Project Dependencies

- **openregister** (consumed, not changed): `ObjectServiceInterface` (public contract), `MigrationPack\MappingEngine` and `PackDefinitionValidator` (not yet a public contract), and the PhpSpreadsheet library it ships.
- **opencatalogi** and **portaliq** (consumers, not changed): they show the imported data once their own configuration is in place, namely a catalogue that includes the stackiq register's `module` schema, and a portal account with claim `stackiq.organisationId` for the municipality. Portaliq's contribution is stackiq's existing `usage` contribution (hydra ADR-046); this change adds no new portal contribution.

## Risks

### Risk 1: Personal data from a third party's export
**Severity:** High — **Mitigation:** only the owner columns the import maps ("Eigenaar", "Eigenaar e-mail", "Eigenaar functie", "FB contactpersoon 1") are read into stackiq, and they go to Nextcloud Contacts as the existing contact model requires. Personnel numbers, phone numbers and group mailboxes are never read. The report and the logs name rows by sheet, row number and Middel-ID only. Tests use the anonymised export. The import never creates Nextcloud user accounts, and a test asserts that the contact-person objects it writes do not qualify for the user sync.

### Risk 2: Hidden coupling to OpenRegister internals
**Severity:** Medium — **Mitigation:** `MappingEngine`, `PackDefinitionValidator` and PhpSpreadsheet are resolved through the container or a `class_exists` check. If any of them is missing, the import endpoint answers 503 with a clear message instead of failing halfway. Promoting `MappingEngine` to an OpenRegister contract is noted as an open question.

### Risk 3: Long imports over HTTP
**Severity:** Medium — **Mitigation:** an export with about 1,100 rows needs several saves per row. The service reports progress per row, honours cancel between rows, and skips the save when nothing changed. A background-job variant is a follow-up if real exports time out.

### Risk 4: TOPdesk values that do not match stackiq vocabularies
**Severity:** Low — **Mitigation:** "Status", "ICT TIME Classificatie" and "ICT BBN Classificatie" go through `lookup` maps. An unknown value drops only that field, and the row gets a warning that names the column and the value. Admins can extend the maps in the JSON.

## Rollback Strategy

The change is additive. Revert the PR to remove the routes, the settings section, the service and the mapping files. The register fragment only adds optional properties; after a revert they stay in the deployed schema without harm, and imported objects stay as ordinary stackiq objects. To remove imported data, filter modules on a non-empty `externalKey` and delete them together with their usages.

## Open Questions

- Does the municipality confirm that the "Invoer" sheets are the unedited export, what the difference between AIA and APP is, and whether archived applications should be included? Until then the change reads both "Invoer" sheets and skips the archive sheet.
- Which TOPdesk "Status" values occur, and which usage status should each one get? The lookup map in design.md is provisional.
- Should OpenRegister expose `MappingEngine` as a public contract (as it does for `ObjectServiceInterface`)?
