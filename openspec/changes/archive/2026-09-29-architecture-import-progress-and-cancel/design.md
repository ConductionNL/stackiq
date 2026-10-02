# Design: architecture-import-progress-and-cancel

Read at development 855dca20. Line numbers below are from that sha.

## Where it fits

| Layer | Touched | Read at |
|---|---|---|
| Progress store | `lib/Service/ProgressTracker.php`: `startOperation` (:124) gains an optional `?string $operationId`; new `requestCancel(string $operationId)` and `isCancelRequested(string $operationId): bool` on the same distributed store (`stackiq_progress`, :109), key `cancel_<id>`, TTL `STORE_TTL` | distributed cache, owner check in `SettingsController::mayReadProgress` |
| Import service | `lib/Service/ArchiMateImportService.php`: `importArchiMateFileFromPathOptimized` (:347) starts the operation when `$options['operationId']` is set, sets the phases, checks the cancel flag between steps; `saveObjectsToDatabase` (:1291) reports processed objects and checks the flag before each schema group | |
| Facade | `lib/Service/ArchiMateService.php`: new `cancelArchiMateImport(?string $operationId = null): array` | `SettingsService::cancelArchiMateImport` (:5211) already calls it |
| Controller | `SettingsController::parseArchiMateFileUpload` passes a validated `operationId` param (pattern `^archimate_import_[A-Za-z0-9]{8,64}$`) into the options; `cancelArchiMateImport` (:2845) reads `operationId` from the request and passes it through `SettingsService` | admin check stays as is |
| View | `src/views/settings/sections/ArchiMateImportExport.vue`: generates the id, sends it with the upload (:806), polls `GET /api/progress/{id}` every two seconds while `importing`, shows phase and percentage with `NcProgressBar`, and a Cancel import button that posts to `/api/archimate/import/cancel` | |

## Decisions

### D1. The page names the operation

The upload is one blocking request, so the page cannot learn a server-made id before the request ends. The page makes the id (`archimate_import_` plus a random string) and sends it with the form. The controller accepts only ids matching the pattern, so a caller cannot write into another operation's key space with a crafted value. The operation's owner is the admin who uploads, as `startOperation` already records.

### D2. Cancel is cooperative

PHP cannot stop another request. The cancel endpoint writes `cancel_<id>` into the distributed store; the import reads it before each schema group save and between parse, transform and save. A cancelled import completes its operation with phase `completed`, status `cancelled`, and returns `success: false, cancelled: true` with the counts of the groups it saved. A cancel with no operation id keeps the old behaviour of clearing the stored import status, so existing callers do not break.

### D3. A missing cache degrades to no progress, not a failure

When the distributed cache is the null cache, `getProgress` answers 404 and the page keeps its spinner. The import itself does not depend on the tracker.
