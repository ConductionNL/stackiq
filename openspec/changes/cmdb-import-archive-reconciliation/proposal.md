---
kind: code
depends_on: []
---

# Proposal: cmdb-import-archive-reconciliation

## Summary

A repeated CMDB import now reconciles the applications a municipality uses with the newer export. An application whose APPID is no longer on the two CMDB sheets but is listed on the export's sheet "Gearchiveerde Applicaties" is archived: its `module` and the municipality's `usage` get OpenRegister's archive state, so they drop out of every list and search until an admin asks for the archive. An application whose APPID is on no sheet at all is soft-deleted through OpenRegister's trash, from which it can be restored. An application that returns to the export is unarchived, or restored from the trash, and then updated as usual, so no duplicate is ever created. The import option `missingRecords` accepts `keep` and `archive`, and `archive` is the default; the settings section shows the choice. Stackiq's Applications and Applications in use pages get an "Archived" quick filter. The report gets the outcomes `archived`, `unarchived`, `deleted` and `restored`.

## Motivation

The first version of the import (openspec/changes/archive/2026-10-05-cmdb-export-import) left records that disappeared from a newer export untouched. The municipality delivers a new export every month and asked on 2026-10-01 (Jira WOO-587, parent WOO-586, epic WOO-281): an application that left the CMDB sheets but is on the archive sheet must be marked as archived and be findable in OpenCatalogi only with an explicit filter; an application that is on no sheet any more must be soft-deleted with the existing functionality. Without this, the catalogue keeps showing applications the municipality retired, and Portaliq keeps listing them under "Software we use".

OpenRegister already has both states. Its archive state (`@self.archived`, `POST /api/objects/{register}/{schema}/{id}/archive`) keeps an object whole but out of every list unless `_archived=true` or `_archived=any` is asked; its soft delete (`@self.deleted`) puts an object in the trash for 30 days, from which it can be restored. OpenCatalogi passes the list parameters through to OpenRegister, and Portaliq lists usages without either lens, so both apps hide archived and deleted records with no change of their own (decided 2026-10-09).

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `cmdb-export-import`: `missingRecords` becomes `keep | archive` with `archive` as the default (REQ-CMDB-012 modified); the reconciliation of missing records against the archive sheet (REQ-CMDB-015), the return of an archived or deleted application (REQ-CMDB-016) and the archive lens in stackiq (REQ-CMDB-017) are added; the settings section shows the choice (REQ-CMDB-014 modified). The `module` and `usage` schemas opt into OpenRegister's archive state through the register fragment (see design.md, Mixed-spec rationale).

## Affected Projects

- [ ] Project: `stackiq`: import service, workbook reader and profile, controller option, register fragment, the "CMDB import" settings section, the manifest quick filters, tests, fixtures, administrator docs and translations.

## Scope

### In Scope

- The sheet "Gearchiveerde Applicaties" (columns Applicatiecomponent, APPID, Applicatie Code, Applicatie Naam, Roepnaam, Applicatie Status, Bron, Datum Interface, Datum wijziging) is read for its APPID column only, when it is present.
- After every row of a completed import: the municipality's usages, with their modules, are compared with the APPIDs on the CMDB sheets and on the archive sheet. Missing and on the archive sheet: module and usage archived. Missing everywhere: module and usage soft-deleted. Without the archive sheet: archived only, with a warning in the report.
- A returning APPID: an archived module or usage is unarchived, a soft-deleted one is restored from the trash, and the row is then updated as before.
- `missingRecords`: `keep` leaves everything as it is (the behaviour so far), `archive` reconciles and is the default; `remove` stays reserved and is refused.
- The settings section offers the choice with help text; the report shows the new outcomes in its summary and rows.
- `x-openregister-archive` on the `module` and `usage` schemas through the register fragment, with a version bump.
- An "Archived" quick filter on the Applications and Applications in use pages.
- PHPUnit, Jest, a Playwright e2e for the re-import with the archive sheet, a fixture variant, docs and Dutch and English strings.

### Out of Scope

- Hard deletion of objects; the trash's own retention (30 days) stays OpenRegister's.
- Changes to the municipality's TOPdesk export, and the raw "Invoer gearchiveerde appl" sheet (the derived "Gearchiveerde Applicaties" sheet is the source).
- Contact persons: the owner of an archived or deleted application stays, because it is shared with the municipality's other applications.
- Modules only other organisations use, and organisations (suppliers) that no application names any more.
- An archive widget on the detail pages: `@conduction/nextcloud-vue` 2.65 does not render `@self.archived`; a follow-up once the library does.
- Changes to OpenCatalogi or Portaliq.

## Approach

`CmdbWorkbookReader` loads the archive sheet next to the two CMDB sheets when the workbook has it, under the same size and row bounds, and hands the import service the APPIDs it holds. `CmdbExportImportService` widens the match of a module (by import key) and of a usage (by consumer and module) to archived and soft-deleted objects, and revives what it matches before the usual update. After the last row, when the import was not cancelled and `missingRecords` is `archive`, a reconciliation step pages through the municipality's usages with no lens and with OpenRegister's `_archived=true` lens, reads the trash once through `MagicMapper::findDeletedAcrossAllMagicTables()` (a search with `_includeDeleted=true` returns no deleted rows on the current OpenRegister beta; design.md D9), loads their modules in batches, and archives, unarchives, soft-deletes or restores module and usage through OpenRegister's `ArchiveHandler`, `ObjectServiceInterface::deleteObject()` and `MagicMapper::restoreObject()`, each object in its own error boundary. The step runs under the import's register lock, reports its progress in a phase of its own, and adds its outcomes to the report. Details are in design.md.

## New Dependencies

None. `ArchiveHandler` and `MagicMapper` come from OpenRegister, which stackiq already requires; both are resolved through the container behind a guard, like the mapping engine.

## Impact

- **Backend**: `lib/Service/CmdbExportImportService.php` (match lenses, revival, reconciliation), `lib/Service/Cmdb/CmdbWorkbookReader.php` and `CmdbImportProfile.php` (archive sheet), `CmdbImportReport.php` (outcomes), `lib/Controller/CmdbImportController.php` (option default and accepted values), `lib/Service/ProgressTracker.php` (phase `reconciling`), `lib/Exception/CmdbImportException.php` (`ARCHIVE_UNAVAILABLE`).
- **Configuration**: `lib/Settings/cmdb-import/topdesk-profile.json` (archive sheet, accepted modes).
- **Schema**: `lib/Settings/register.d/topdesk-cmdb-import.json` declares `x-openregister-archive` on `module` (0.3.9) and `usage` (1.5.7).
- **Frontend**: `src/views/settings/sections/CmdbImport.vue`, `src/utils/cmdbImport.js`, `src/manifest.json`, `src/manifest.d/usages.json`, `src/icons.js`.
- **Data**: a re-import with the default option archives or soft-deletes modules and usages the export no longer lists. Nothing is hard-deleted; a soft-deleted object can be restored from OpenRegister's trash for 30 days.

## Cross-Project Dependencies

- **openregister** (consumed, not changed): `ObjectServiceInterface::deleteObject()` (contract), `Service\Object\ArchiveHandler` and `Db\MagicMapper::restoreObject()` and `findDeletedAcrossAllMagicTables()` (not public contracts, guarded), the `_archived` list lens, and the schema annotation `x-openregister-archive`.
- **opencatalogi** and **portaliq** (consumers, not changed): both list through OpenRegister's default lens, so archived and deleted applications disappear from them without a change; an OpenCatalogi caller finds archived applications with `_archived=true` on the existing public routes.

## Risks

### Risk 1: A re-import with the new default archives what an admin did not expect
**Severity:** Medium — **Mitigation:** only the municipality's own usages and the modules they point at are in scope, never a supplier's or another municipality's module; nothing is hard-deleted; the choice is visible in the section with help text, and the report lists every archived and deleted application. A cancelled or failed import never reconciles.

### Risk 2: A workbook without the archive sheet soft-deletes everything that moved there
**Severity:** Medium — **Mitigation:** without the sheet, missing applications are archived, never soft-deleted, and the report warns that the sheet was not found.

### Risk 3: The archive services are OpenRegister internals
**Severity:** Low — **Mitigation:** resolved through the container behind a guard; when missing, an import with `missingRecords=archive` is refused with 503 `ARCHIVE_UNAVAILABLE` before the file is read, and `keep` still works.

### Risk 4: The schemas on an installation predate the annotation
**Severity:** Low — **Mitigation:** OpenRegister refuses to archive an object of a schema without `x-openregister-archive`; the import reports that object as failed and the docs point to Force Update, which deploys the fragment.

## Rollback Strategy

Revert the PR. Objects archived or soft-deleted by an import keep their state; an admin unarchives them through OpenRegister's object actions or restores them from the trash. The schema annotation may stay deployed without harm.

## Open Questions

- Should a module that no usage points at any more (every municipality archived it) be archived as well? Out of scope here; a supplier's module stays.
