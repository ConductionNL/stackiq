---
kind: code
depends_on: []
---

# Follow a running ArchiMate import and cancel it

## Summary

A Nextcloud admin who imports an ArchiMate exchange file on the settings page sees which step the import is in and how many objects are saved so far, and can cancel it. A cancelled import stops before its next save batch and reports what it had already saved. Today the page shows a spinner until the request returns, and the cancel endpoint throws because the method it calls does not exist.

## Why

This change builds the missing half of one row of the stackiq parity matrix: `stackiq:arch-import-progress`, "Follow a long model import while it runs, and cancel it if needed." The row comes from stackiq's own code (feature archimate-import-and-export). SAP LeanIX rates partial; no competitor rates yes and no demand row names it. The build-all lane decided build on 2026-09-29: the row is in stackiq's core area (architecture), and the half that exists is broken, not absent. The matrix note: "The import runs as one blocking request with a spinner. The cancel endpoint calls a missing method and import progress is never recorded, so neither following nor cancelling works."

## What stackiq has today (development 855dca20)

- `POST /api/archimate/import/cancel` (`appinfo/routes.php:103`) runs `SettingsController::cancelArchiMateImport` (`lib/Controller/SettingsController.php:2845`, admin only), which calls `SettingsService::cancelArchiMateImport` (`lib/Service/SettingsService.php:5211`). That calls `ArchiMateService::cancelArchiMateImport()`, which does not exist in `lib/Service/ArchiMateService.php`. The resulting `Error` is not caught by `catch (\Exception)`, so the endpoint answers 500.
- `GET /api/progress/{operationId}` (`routes.php:117`) reads `ProgressTracker` (`lib/Service/ProgressTracker.php`), which already keeps snapshots in the distributed cache and checks the reader (`SettingsController::getProgress`, :1290). The ArchiMate import never starts an operation there: `ArchiMateImportService::importArchiMateFileFromPathOptimized` (:347) parses, transforms and saves without touching the tracker. `ArchiMateService::isImportInProgress()` (:1916) returns a hard-coded false.
- The save loops over schema groups (`ArchiMateImportService::saveObjectsToDatabase`, :1291, the `foreach ($schemaGroups ...)` at about :1360), which is the natural point to report progress and to honour a cancel.
- `src/views/settings/sections/ArchiMateImportExport.vue` posts the file (:806) and shows `NcLoadingIcon` with "Importing..." (:538) until the response comes back. There is no cancel button.

## What this change builds

- The import accepts an `operationId` from the page, starts a `ProgressTracker` operation under that id, and records its phases (validating, parsing, processing, finalizing) and the objects saved after each schema group.
- A cancel request for that operation id sets a flag in the distributed cache. The import checks it before each save batch and between its steps, stops, and returns `cancelled: true` with the counts saved so far.
- `ArchiMateService::cancelArchiMateImport(?string $operationId)` exists and does this, so the cancel endpoint answers 200.
- The import part of the settings page shows the phase, a progress bar and a Cancel import button while an import runs.

## Out of scope

- Moving the import into a background job. The import stays one request; the page follows it from a second one.
- Rolling back objects saved before the cancel. The result says how many were saved; a re-import updates them.
- Progress for the ArchiMate export.
