# Design: cmdb-import-archive-reconciliation

## Context

The CMDB import (archived change `2026-10-05-cmdb-export-import`) matches rows on `module.externalKey` = `topdesk:<municipality uuid>:<APPID>` and leaves records that are missing from a newer export untouched (REQ-CMDB-012). The municipality's export carries, next to the two CMDB sheets, a sheet "Gearchiveerde Applicaties" (columns Applicatiecomponent, APPID, Applicatie Code, Applicatie Naam, Roepnaam, Applicatie Status, Bron, Datum Interface, Datum wijziging), derived from the raw "Invoer gearchiveerde appl" sheet. The municipality wants an application that moved there to be archived, and one that is on no sheet any more to be soft-deleted (2026-10-01).

What OpenRegister offers (origin/beta `ff4dad5c`, identical to development for `lib/`):

- **Soft delete.** `ObjectServiceInterface::deleteObject(uuid, register, schema, ...)` writes `@self.deleted` (`deletedBy`, `deletedAt`, `purgeDate` 30 days on). Lists exclude deleted objects unless `_includeDeleted=true`, but the list search cannot return one even then (D9). `MagicMapper::findDeletedAcrossAllMagicTables()` lists the trash; `MagicMapper::restoreObject(uuid)` clears the marker. `DeletedController` uses both.
- **Archive.** `ObjectEntity::archive()` / `unarchive()` write and clear `@self.archived` (`by`, `at`, `reason`). `Service\Object\ArchiveHandler::archive()` / `unarchive()` is the service path behind `POST` / `DELETE /api/objects/{register}/{schema}/{id}/archive`: it resolves the object, refuses a schema without `x-openregister-archive: {enabled: true}` (`ArchiveNotOfferedException`), checks `update` on the object for the session user, persists the marker and writes an audit entry. Lists exclude archived objects unless `_archived=true` (only the archive) or `_archived=any`. A read by identifier is unaffected. `SaveObject` refuses a data write to an archived object (`ObjectStateWriteException`).
- **Search lenses** combine in SQL: `_ids`, `_includeDeleted` and `_archived` apply together in `MagicSearchHandler`. Its row conversion, however, drops a soft-deleted row and hydrates no archive marker (D9).

OpenCatalogi (beta `df25ff75`) passes list parameters through to OpenRegister and forces `_includeDeleted=false`; it has no archive filter of its own, so `_archived=true` reaches OpenRegister unchanged. Portaliq (beta `8287e390`) lists usages with neither lens. Both hide archived and deleted records as they are (decided 2026-10-09).

## Goals / Non-Goals

**Goals**

- A re-import archives what moved to the archive sheet and soft-deletes what left the export entirely, for the imported municipality only.
- A returning application is revived, never duplicated.
- The admin sees and chooses the behaviour, and the report accounts for every archived, unarchived, deleted and restored application.
- Archived applications and usages can be listed in stackiq on request and stay out of OpenCatalogi and Portaliq without a change there.

**Non-Goals**

- Hard deletion, the trash's retention, contact persons, suppliers, modules another organisation imported (a module carrying this municipality's import key is this municipality's record, also when another organisation uses it; D5), changes to OpenCatalogi or Portaliq, an archive widget on the detail pages.

## Architecture Overview

```
CmdbImportController::import()          missingRecords: keep | archive (default archive); remove → 422
  ▼
CmdbExportImportService::import()
  ├─ resolve ArchiveHandler + MagicMapper   (archive mode only; missing → 503 ARCHIVE_UNAVAILABLE)
  ├─ lock the register
  ├─ CmdbWorkbookReader::read()             CMDB sheets as before + the archive sheet's APPID column
  ├─ rows, each in its own boundary          match module and usage: working, then _archived=true, then the trash (D9)
  │     archived match → unarchive, deleted match → restore (and unarchive), then the usual update
  └─ reconcile()  (archive mode, not cancelled)   phase `reconciling`, same lock
        page usages (consumer = municipality): working, then _archived=true; deleted ones from the trash
        load their modules in batches (_ids: working, then _archived=true; the rest from the trash)
        per application: key on CMDB sheets → nothing
                         key on archive sheet → archive module + usage (restore first when deleted)
                         key nowhere          → soft-delete module + usage, or archive when the sheet is absent
        each in its own boundary → report rows archived | deleted | failed
  ▼
OpenRegister: module, usage (archived / deleted markers); contactPerson, organization untouched
  ├─▶ OpenCatalogi: archived modules only with _archived=true
  └─▶ Portaliq: archived and deleted usages disappear from "Software we use"
```

## Decisions

### D1. "Archived" is OpenRegister's archive state on the module and on the usage

Decided by the product owner on 2026-10-09. No new field, no new `usage.status` value, no `depublicationDate`.

- **Alternative: `usage.status` `Archived`.** Rejected: OpenCatalogi drops `status === 'archived'` only on `/api/search`, so `/api/{catalogSlug}` and the federation route would need an OpenCatalogi change and a new beta.
- **Alternative: `module.depublicationDate`.** Rejected: the module read rule does not look at it, and the import never writes it (D6 of the first change).

Both schemas opt in through the register fragment: `module` 0.3.8 → 0.3.9 and `usage` 1.5.6 → 1.5.7 get `configuration.x-openregister-archive: {"enabled": true}`. Fragments are merged by key union and the loader keeps the highest `version` per schema, so the bump deploys with the existing register import. The `SCHEMA_OUTDATED` check is not widened: an installation whose schemas predate the annotation gets `ArchiveNotOfferedException` per object, which the reconciliation reports as a failed row (D7), and the docs point to Force Update.

### D2. The option: `keep | archive`, default `archive`

`CmdbImportProfile::missingRecordsModes()` reads the accepted values from the profile (`["keep", "archive"]`) without validating the packs, like `maxFileBytes()`, because the controller checks the option before the import runs. The default lives in `CmdbExportImportService::DEFAULT_MISSING_RECORDS`. `remove` and `mark` stay reserved: 422 `MISSING_RECORDS_UNSUPPORTED` with `details.accepted` naming both accepted values. The section sends the admin's choice as `missingRecords` and defaults to `archive`.

With `updateExisting=false`, a matched row is skipped as `exists` before anything is revived, as before: the admin asked to leave existing records alone.

### D3. Reading the archive sheet

The profile names the sheet (`archiveSheet.name`: "Gearchiveerde Applicaties"). The reader lists the workbook's sheets, and when the archive sheet is present it is loaded with the CMDB sheets under the same bounds: it counts as a read sheet for `CmdbPartReferences` (an oversized archive sheet is refused, not blanked) and for the referenced-string budget, its row span is bounded like a source sheet, and `TOO_MANY_ROWS` names it. Only its APPID column is resolved and read; the allowlist does not grow. The reader returns `archive: {present, keyColumnMissing, appIds}` next to `rows`.

- Sheet absent, or present without an APPID column: `present` false (with `keyColumnMissing`), and the service adds a report warning. The reconciliation then archives every missing application and soft-deletes nothing: a workbook saved without the sheet must not empty the trash-bound set by accident.
- The archive sheet's other columns are not read; `Applicatie Status` there is always `Verwijderd` in the municipality's export and adds nothing.

### D4. Matching widens to archived and deleted objects, and revives what it matches

`matchModule()` looks the module up by `externalKey` in every state, as D9 reads it; `moduleBelongsTo()` looks up this municipality's usage the same way (a module whose usage is archived still belongs to the municipality), and keeps the working lens for "does any other organisation use it". `upsertUsage()` finds the usage in every state. Whatever is matched is revived before it is written: a soft-deleted object is restored (`MagicMapper::restoreObject()`), and unarchived as well when the restored object says it was archived; an archived one is unarchived (`ArchiveHandler::unarchive()`), because `SaveObject` refuses a write to an archived object. The update starts from the object `restoreObject()` answers, not from the trash listing's copy. The row outcome is `restored` when anything was restored, else `unarchived` when anything was unarchived, else the usual `created | updated | unchanged`. When more than one object matches, `findInAnyState()` takes a working one before an archived one, and an archived one before one in the trash: before this change a module deleted by hand was invisible to the match, so the next import created a second one with the same `externalKey`, and reviving the deleted one would make two live modules. Organisations and contact persons keep the working lens: a deleted supplier is never matched.

### D5. The reconciliation step

Runs inside `runImport()` after the row loop, only when the loop finished (not cancelled) and `missingRecords` is `archive`, under the register lock the import already holds. It checks for a cancel once before it loads the scope (a cancel that came in during the last row) and again before each application; a cancel stops it there, keeps what was already archived or deleted, and marks the report cancelled. An exception outside an object's boundary fails the run like any other (`IMPORT_FAILED`, operation `failed`), so a half-done reconciliation is visible.

Scope: the usages whose `consumer` is the municipality, in every state as D9 reads it: paged with `PAGE_SIZE` without a lens and with `_archived=true`, plus the deleted ones from the trash. Their modules are loaded in chunks of `PAGE_SIZE` with `_ids`, without a lens and with `_archived=true`, and the rest from the trash. Every usage and module keeps the state D9 gave it; the table below decides on that state. Only a module whose `externalKey` starts with `topdesk:<municipality uuid>:` is an imported application; the key after the last `:` is the APPID's match key. A usage without such a module (a hand-made usage, or a module another organisation imported) is left alone.

Per application, with `processed` = every APPID match key on the CMDB sheets (whatever the row's outcome: a row that failed or was skipped is still in the export) and `archived` = the match keys on the archive sheet:

| key is | module/usage state | action | outcome |
|---|---|---|---|
| on a CMDB sheet | any | nothing (the row handled it) | — |
| on the archive sheet | working | archive usage, then module | `archived` |
| on the archive sheet | archived | nothing | — |
| on the archive sheet | deleted | restore, then archive | `archived` |
| nowhere, sheet present | working or archived | soft-delete usage, then module | `deleted` |
| nowhere, sheet present | deleted | nothing | — |
| nowhere, sheet absent | working | archive usage, then module | `archived` |
| nowhere, sheet absent | archived or deleted | nothing | — |

The usage is written before the module, so a failure between the two leaves the municipality's usage in the new state and the module visible, never the reverse. A module another municipality still uses is archived or deleted all the same when this municipality's usage says so: the module carries this municipality's import key, so it is this municipality's record (REQ-CMDB-006 allows a shared module only when the other organisation's usage was added by hand). The archive reason is `cmdb-import: on sheet "Gearchiveerde Applicaties"` or `cmdb-import: not in the export`, plus the operation id, so the audit trail says why.

Each application runs in its own boundary: a failure is a `failed` row naming the step (`archive`, `restore`, `delete`) and the exception class, as row failures do, and the other applications continue. Contact persons are never touched.

Progress: the operation gets the phase `reconciling` (added to `ProgressTracker::PHASES` with weight 0, so the ArchiMate import's weighted percentage is unchanged), `total_items` grows by the number of usages in scope, and `processed_items` advances per application; the section's own percentage comes from those counts.

### D6. The report

`CmdbImportReport` gains the outcomes `archived`, `unarchived`, `deleted` and `restored`; `summary()` counts them like the others. Reconciliation rows carry the archive sheet's name (for `archived` from the sheet) or an empty sheet, row 0, the APPID (`externalNumber`), the module name and both uuids: no person data. `toStoredArray()` ranks them with the rest. The section shows the four counts as summary tiles when they are non-zero, and the outcome filter offers them.

### D7. Failure modes

- `ArchiveHandler` or `MagicMapper` missing, or without a method the import calls (`archive`, `unarchive`, `restoreObject`, `findDeletedAcrossAllMagicTables`): 503 `ARCHIVE_UNAVAILABLE` before the lock is taken, only in archive mode. `keep` needs neither: without them the trash cannot be read, so a soft-deleted module or usage is not matched (the log says so) and a row whose match must be unarchived fails at step `module` or `usage`.
- A schema without `x-openregister-archive`: `ArchiveNotOfferedException` from OpenRegister per object → a failed row with the exception class; the docs say to run Force Update.
- A failure while reviving a matched row: the row fails at step `module` or `usage` like any other row failure.

### D8. Stackiq UI

The Applications (`Modules`, a `FacetedCatalogIndexView` that forwards `quickFilters` to `CnIndexPage`) and Applications in use (`Gebruik`) pages get the quick filter `{"label": "Archived", "filter": {"_archived": "true"}}`. `useSelfFetchList` spreads the active tab's filter into the fetch parameters as they are, so `_archived=true` reaches OpenRegister and the list shows the archive alone; the facet narrowing's `_ids` composes with it (AND). The icon `ArchiveOutline` is registered in `src/icons.js`. `@conduction/nextcloud-vue` 2.65 renders no `@self.archived` on a detail page or in the sidebar metadata, so the detail pages show no archive state yet; that is a library follow-up, not a stackiq widget.

### D9. How the import reads the state of a stored object

Found on a Nextcloud 35 rig with OpenRegister 2.1.37-beta (origin/beta `ff4dad5c`): the second import of the original export left APPID 1234 archived (`unchanged`) and created a second module for APPID 2. `ObjectService::searchObjects()` (via `QueryHandler::searchObjects()` → `MagicMapper::searchObjects()` → `MagicSearchHandler::searchObjects()`) applies the lenses in SQL (`buildFilteredQuery()` → `applyBasicFilters()`, `MagicSearchHandler.php` 2135–2153), but converts each row with `MagicSearchHandler::convertRowToObjectEntity()` (3780), which:

- reads no `_archived` column, so every result keeps `ObjectEntity::$archived = []` (`ObjectEntity.php` 334) and looks working;
- turns the `_deleted` marker into `new DateTime(...)` (3896–3898), but `DeleteObject` writes it as a JSON object (`DeleteObject.php` 361), so the conversion throws, the row becomes null (4035) and `executeSearchQuery()` skips it (3714): `_includeDeleted=true` returns no soft-deleted object.

So the import never reads the state from a search result:

- **Working**: found by a search without a lens. **Archived**: found by a search with `_archived=true` (the archive alone, not deleted). An object is looked up working first, then archived.
- **Deleted**: from `MagicMapper::findDeletedAcrossAllMagicTables(_rbac: false, _multitenancy: false)`, the listing behind the Deleted page, which reads the rows itself (`MagicMapper.php` 7001). It scans every magic table of the instance and pages only after merging them, so the import reads it once per run, lazily, and keeps this register's modules and usages. Its objects carry data but no markers (`MagicMapper::rowToObjectEntity()`, 9131), and that data has every string that parses as JSON decoded ("2" becomes 2), so it is never written back.
- **Deleted and archived**: the trash does not say. `MagicMapper::restoreObject()` clears `_deleted` (10078) and answers the object as the mapper reads it by uuid, through `MagicStatisticsHandler::convertRowToObjectEntity()`, which does decode `_archived` (`MagicStatisticsHandler.php` 631–652). A returning row unarchives it when that object is archived, and updates from it. The reconciliation does not need to know: an object in the trash is either left there or restored and archived, and `ArchiveHandler::archive()` leaves an archived object as it is (`ArchiveHandler.php` 111).

`ArchiveHandler::unarchive()` clears the marker to SQL NULL, so the object is back in the working lists, and does nothing for an object that is not archived (161). A lookup costs one search for a working match and two for an archived one or none, plus the one trash read per run. When OpenRegister hydrates both markers on search results, this can go back to one search with both lenses; the lens-based reading stays correct either way.

## API Design

`POST /api/cmdb-import`: `missingRecords` accepts `keep` and `archive` (default `archive`). The report's `summary` gains `archived`, `unarchived`, `deleted` and `restored`; `rows[].outcome` may be any of the four; reconciliation rows have `row: 0`. New error code 503 `ARCHIVE_UNAVAILABLE`. `MISSING_RECORDS_UNSUPPORTED` keeps its status and gains both accepted values in `details.accepted`. `openapi.json` is updated accordingly.

## Database Changes

None. The archive and delete markers are OpenRegister's metadata columns.

## Mixed-spec rationale (ADR-032)

The change is `kind: code`. Its only schema delta is the annotation `x-openregister-archive: {enabled: true}` on `module` and `usage` in the existing fragment `topdesk-cmdb-import.json`, with a version bump of both. The annotation is glue for this code: OpenRegister refuses to archive an object of a schema without it, and nothing else in stackiq reads it. It follows the fragment convention (ADR-037) and deploys with the existing register import.

## Declarative-vs-imperative decision (ADR-031)

- **Declarative:** which schemas may be archived (`x-openregister-archive` on `module` and `usage`), which sheet is the archive sheet and which option values are accepted (`topdesk-profile.json`), and which list shows the archive (the manifest quick filter `_archived=true`).
- **Imperative, because it is the external-integration exception the first change already claimed:** comparing an uploaded workbook with the register and deciding per application between nothing, archive, unarchive, soft-delete and restore. No `x-openregister-*` block can express "archive when the source lists it as archived and delete when the source no longer lists it": that rule depends on a file the register never sees. The state transitions themselves are OpenRegister's (`ArchiveHandler`, `deleteObject()`, `restoreObject()`); stackiq only decides when to call them.
- **Rule stated once, enforced in code:** the table in D5.

## Nextcloud Integration

- Controller: `CmdbImportController::import()` (unchanged auth: Nextcloud admins only, CSRF).
- Services: `CmdbExportImportService` (lenses, revival, reconciliation), `Cmdb\CmdbWorkbookReader` and `Cmdb\CmdbImportProfile` (archive sheet), `Cmdb\CmdbImportReport` (outcomes), `ProgressTracker` (phase).
- OpenRegister: `ObjectServiceInterface::searchObjects()` with `_archived` and `_ids`, `::deleteObject()` (contract); `Service\Object\ArchiveHandler::archive()` / `unarchive()` and `Db\MagicMapper::restoreObject()` / `findDeletedAcrossAllMagicTables()` (container, guarded, like the mapping engine). The handler checks `update` for the session user with RBAC on; the import is admin-only, so the check passes, and the audit entry names the admin.

## Security Considerations

- Still admin-only, CSRF-protected, with the file bounds of the first change; the archive sheet is read under the same bounds, APPID column only.
- Scope is the imported municipality's usages; a supplier's or another municipality's module is never archived or deleted through this municipality's import.
- Nothing is hard-deleted; every transition is an OpenRegister audit entry with a reason.
- The report and the logs carry APPIDs, module names and uuids, never owner data; contact persons are untouched.

## NL Design System

The section adds one radio group with a help text, built from `NcCheckboxRadioSwitch` like the existing switches, labelled and translated. The quick filter is the library's own chip.

## File Structure

```
lib/Service/CmdbExportImportService.php          lenses, revival, reconcile(), state helpers
lib/Service/Cmdb/CmdbWorkbookReader.php          the archive sheet
lib/Service/Cmdb/CmdbImportProfile.php           archiveSheetName(), readSheetNames(), missingRecordsModes()
lib/Service/Cmdb/CmdbImportReport.php            archived | unarchived | deleted | restored
lib/Controller/CmdbImportController.php          default archive, accepted values, ARCHIVE_UNAVAILABLE
lib/Exception/CmdbImportException.php            ARCHIVE_UNAVAILABLE
lib/Service/ProgressTracker.php                  phase reconciling
lib/Settings/cmdb-import/topdesk-profile.json    archiveSheet, missingRecords
lib/Settings/register.d/topdesk-cmdb-import.json x-openregister-archive, module 0.3.9, usage 1.5.7
src/utils/cmdbImport.js, src/views/settings/sections/CmdbImport.vue
src/manifest.json, src/manifest.d/usages.json, src/icons.js
tests/Unit/..., tests/fixtures/cmdb/topdesk-archived-applications.xlsx, tests/e2e/spec-coverage/cmdb-import.spec.ts
docs/features/cmdb-import.md, l10n/en.json, l10n/nl.json
```

## Seed Data

No seed objects change. The fragment's three seed modules stay as they are; the `module` and `usage` schemas gain only the archive annotation.

## Risks / Trade-offs

- [The default archives on the first re-import after the upgrade] → The section shows the choice with help text; the report lists every archived and deleted application; nothing is hard-deleted.
- [A workbook saved without the archive sheet] → archive only, never delete, plus a warning (D3).
- [A failure between the usage and the module] → the usage is written first, so the municipality's view is right and the module is at worst still visible (D5).
- [Reviving before writing adds calls per returning row] → only for rows whose match is archived or deleted; a row whose module is working costs the same searches as before, a new row one more search (the archive lens).
- [The trash listing scans every magic table of the instance] → read once per import, not per row (D9).
- [OpenRegister internals change shape] → guarded resolution, 503 before the file is read (D7).

## Migration Plan

No data migration. The fragment deploys with the existing register import (Force Update on an installation that already has the register). Rollback is a revert of the PR; archived or deleted objects keep their state and can be unarchived or restored by hand.

## Open Questions

- Should the library render `@self.archived` on detail pages, so an admin sees why an application is not in the list? (library follow-up)
- Should a module that no usage points at any more be archived as well? Out of scope here.
