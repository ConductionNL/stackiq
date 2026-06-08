---
retrofit_extensions:
  - REQ-DECOMP-013
  - REQ-DECOMP-014
  - REQ-DECOMP-015
  - REQ-DECOMP-016
  - REQ-DECOMP-017
---

## ADDED Requirements

### Requirement: SettingsController Settings CRUD endpoints (REQ-DECOMP-013)

The SettingsController MUST expose JSON endpoints to read and write the four primary settings blocks — full settings tree, configuration + selected register, general config (`catalogLocation`), and sync config — plus a unified `create` endpoint that routes by request-body shape.

**Endpoints**: `index()`, `create()`, `getGeneralConfig()`, `updateGeneralConfig()`, `getSyncConfig()`, `updateSyncConfig()`.

**Common contract**: Each endpoint MUST catch every `\Exception`, log it via `LoggerInterface::error`, and return `{error: <message>}` with HTTP 500. Successful reads MUST return either the raw settings tree (`index`) or `{success: true, config: {...}}` (the granular getters). Successful writes MUST return `{success: true, data: ..., message: ...}` (for `create`) or `{success: true, message: ..., config: {...}}` (for the granular setters).

**`create()` routing**: It MUST inspect `request.params` and dispatch on the presence of `configuration` / `selectedRegister` → `SettingsService::updateSettings`; `userGroups.generic` / `.organizationAdmin` / `.superUser` → validate each via `validateGroups`, return HTTP 400 with the validation envelope on any `invalid` entries, otherwise persist via the matching setter; `emailSettings` → `updateEmailSettings`. Multiple sections in one body MUST be processed in sequence and reported under a combined `data` map.

#### Scenario: index attaches openRegisters availability + isAdmin
- GIVEN the OpenRegister app is installed and the current user is in the `admin` group
- WHEN `GET /settings` is invoked
- THEN the response body MUST include `openRegisters: true` and `isAdmin: true` alongside the settings tree

#### Scenario: create with invalid generic group returns 400
- GIVEN `request.params = { userGroups: { generic: ['no-such-group'] } }`
- AND `validateGroups` flags `'no-such-group'` as invalid
- WHEN `POST /settings` is invoked
- THEN the response status MUST be `400`
- AND the body MUST equal `{error: "Invalid generic group names provided", validation: {...}}`

#### Scenario: getGeneralConfig surfaces catalogLocation
- WHEN `GET /settings/general` is invoked
- THEN the response body MUST equal `{success: true, config: {catalogLocation: <value>}}`

### Requirement: Configuration bootstrap + status endpoints (REQ-DECOMP-014)

The SettingsController MUST expose four endpoints that surface app readiness and version information for the admin UI: `load()` (initial UI bootstrap payload), `initialize()` (idempotent first-run setup), `status()` (current configuration health), `getVersionInfo()` (app version + cache-busting timestamp).

`getVersionInfo()` MUST attach a `timestamp` field (Unix seconds at response time) to every response — including error responses — for cache-busting on the frontend.

`status()` MUST aggregate the configuration health summary from `SettingsService` and return it as a JSON envelope without wrapping; consumers depend on the raw shape.

#### Scenario: getVersionInfo attaches timestamp on success
- WHEN `GET /settings/version` is invoked successfully
- THEN the response body MUST include `timestamp: <int>` matching `time()` at response time

#### Scenario: getVersionInfo attaches timestamp on error
- GIVEN `SettingsService::getVersionInfo()` throws
- WHEN the endpoint is invoked
- THEN the response status MUST be `500`
- AND the body MUST include `{error: <message>, timestamp: <int>}`

### Requirement: Sync orchestration endpoints (REQ-DECOMP-015)

The SettingsController MUST expose two endpoints for organisation synchronisation: `getSyncStatus(minutesBack=10)` (read-only sync status with error handling) and `performSync(minutesBack=0)` (trigger sync).

`performSync` MUST branch on `minutesBack`: `0` → full optimized sync via `OrganizationSyncService::performOptimizedManualSync(maxRounds: 15, batchSize: 75)` returning `{success: true, results, message, isOptimized: true}`; non-zero → incremental sync via `performManualSync($minutesBack)` returning the service result.

`getSyncStatus` MUST delegate without try/catch — the underlying service method already wraps errors into the response shape.

#### Scenario: Full sync invokes optimized path
- WHEN `POST /settings/sync` is called with `minutesBack=0`
- THEN `performOptimizedManualSync` MUST be invoked with `maxRounds: 15` and `batchSize: 75`
- AND the response body MUST contain `isOptimized: true`

#### Scenario: Sync exception maps to 500 with success: false
- GIVEN the underlying sync service throws
- WHEN the endpoint is invoked
- THEN the response status MUST be `500`
- AND the body MUST equal `{success: false, message: "Synchronization failed: <msg>", error: <msg>}`

### Requirement: Cache, heartbeat, and diagnostic endpoints (REQ-DECOMP-016)

The SettingsController MUST expose lightweight endpoints for ops + diagnostics: `clearCache()` (force schema/register cache reload), `heartbeat()` (keep-alive for long-running browser-side operations), `stats()` (catalog statistics), `debug()` (diagnostic dump).

`heartbeat()` MUST accept an optional `timestamp` query parameter (defaulting to `time() * 1000`), echo it back alongside the server's current timestamp, and respond with `{success: true, message: "Heartbeat received", timestamp, server_time}`. Both timestamps MUST be in milliseconds.

`heartbeat()` is the only endpoint in this group annotated `@NoAdminRequired`; the rest require admin (default for `@NoCSRFRequired` without `@NoAdminRequired` is admin-required per ADR-005).

#### Scenario: Heartbeat echoes timestamp in ms
- GIVEN the client sends `timestamp=1716576000000`
- WHEN `POST /settings/heartbeat` is invoked
- THEN the response body MUST contain `timestamp: 1716576000000` and `server_time: <current-ms>`

#### Scenario: Heartbeat default timestamp uses server time
- GIVEN no `timestamp` parameter is provided
- WHEN the endpoint is invoked
- THEN the response `timestamp` MUST equal the server's current `time() * 1000`

### Requirement: Progress snapshot + SSE streaming endpoints (REQ-DECOMP-017)

The SettingsController MUST expose two progress-related endpoints that consume the `ProgressTracker` service (see `progress-tracking#REQ-005`): `getProgress(operationId)` (one-shot JSON snapshot) and `streamProgress(operationId)` (Server-Sent Events stream).

`getProgress` MUST delegate to `ProgressTracker::getProgress($operationId)` and return `{success: true, progress: <snapshot>}` with HTTP 200 when a snapshot exists, or `{success: false, error: "Operation not found"}` with HTTP 404 when it does not.

`streamProgress` MUST return an `OCP\AppFramework\Http\Response` subclass that streams `text/event-stream` events for the operation until the operation reaches phase `completed` or the client disconnects. The response MUST set `Content-Type: text/event-stream`, `Cache-Control: no-cache`, and `Connection: keep-alive`.

#### Scenario: getProgress returns 404 for unknown id
- GIVEN no operation with id `import_xyz` exists in the session
- WHEN `GET /settings/progress/import_xyz` is invoked
- THEN the response status MUST be `404`
- AND the body MUST contain `{success: false, error: "Operation not found"}`

#### Scenario: streamProgress sets SSE headers
- WHEN `GET /settings/stream-progress/import_abc` is invoked
- THEN the response Content-Type MUST be `text/event-stream`
- AND `Cache-Control` MUST be `no-cache`

## Notes

These REQs describe the *intended contract* of the endpoints. Three concrete bugs were spotted while reverse-spec'ing — flagged here for follow-up rather than silently captured as REQ behaviour:

- **`performSync()` empty-if inverts incremental-sync status code.** Lines 732-735: `if ($result['success'] === true) { }` empty block followed by an unconditional `return new JSONResponse($result, 500);`. Successful incremental syncs (any non-zero `minutesBack` value) return HTTP 500 with the success envelope. The intended branch was probably `return new JSONResponse($result, 200);` inside the if-block. Mirrors the `progress-tracking#calculateOverallPercentage` bug pattern from PR #288.
- **`resetAutoConfig()` has the same empty-if pattern.** Lines 865-868: `if ($result['success'] === true) { }` empty followed by `return new JSONResponse($result, 400);` (indented inside the function but outside the if). Successful resets return HTTP 400. Same fix shape as performSync.
- **`getGeneralConfig()` / `updateGeneralConfig()` lack `@NoAdminRequired`.** They are decorated only with `@NoCSRFRequired`. Per ADR-005 + the Nextcloud framework defaults, this routes them through admin-required middleware. The intent is unclear — REQ-DECOMP-013's contract describes successful responses irrespective of the auth posture; revisiting the annotations is a separate concern.

The 14 remaining SettingsController public methods (autoConfigure, resetAutoConfig, manualImport, forceUpdate, consolidatedAutoConfigure, importArchiMate, exportArchiMate, exportOrgArchiMate, downloadArchiMate, sendTestEmail, testEmailConnection, getEmailSettings, updateEmailSettings, render) are intentionally deferred — they form behavioural groups (ArchiMate import/export, email transport, auto-config orchestration) that warrant their own REQ groupings in a subsequent retrofit pass.
