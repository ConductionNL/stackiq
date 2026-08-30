---
status: done
---

# method-decomposition Specification

## Purpose
Defines the structural decomposition of the app's oversized controllers, services, and event listeners into thin action methods, focused handler classes, and guard-clause-gated helpers that pass PHPMD complexity thresholds without suppressions. It also fixes the resulting SettingsController endpoint contracts for settings CRUD, configuration bootstrap, sync orchestration, diagnostics, and progress streaming, preserving behaviour as pure refactoring.

@e2e exclude PHP internal refactor (controller/service method decomposition into helpers) — pure backend structure with no observable UI behaviour; covered by PHPUnit unit tests.
## Requirements
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

### Requirement: REQ-DECOMP-001 SettingsController Decomposition

`lib/Controller/SettingsController.php` (23 PHPMD suppressions) MUST be
decomposed by extracting sync, module registration, and configuration logic
into dedicated handler classes. The controller MUST retain only thin action
methods (≤10 lines per ADR-003) that delegate to handlers. Class-level
suppressions (ExcessiveClassLength, TooManyMethods, ExcessiveClassComplexity,
CouplingBetweenObjects) and method-level suppressions on `syncStackiq`,
`registerModules`, `syncOrganizations`, and `configureArchiMate` MUST all be
removed.

#### Scenario: syncStackiq decomposed into SyncHandler

- GIVEN `SettingsController::syncStackiq()` has CC>10, NPath>200, and >100 lines
- WHEN the method is decomposed
- THEN a `lib/Controller/Settings/SyncHandler.php` class MUST be created
- AND it MUST expose `validateSyncConfig()`, `prepareSyncData()`, `executeSyncBatch()`, and `buildSyncResponse()` private methods
- AND the controller MUST delegate to `SyncHandler::handle()` in ≤10 lines

#### Scenario: registerModules decomposed into ModuleRegistrationHandler

- GIVEN `SettingsController::registerModules()` has ExcessiveMethodLength
- WHEN the method is decomposed
- THEN a `lib/Controller/Settings/ModuleRegistrationHandler.php` class MUST be created
- AND it MUST expose `validateModuleInput()`, `resolveModuleDependencies()`, and `persistModules()` private methods

#### Scenario: Constructor coupling reduced

- GIVEN `SettingsController` injects 10+ dependencies (CouplingBetweenObjects)
- WHEN handlers are extracted
- THEN `SyncHandler` MUST receive only `ObjectService` and `StackiqService`
- AND `ModuleRegistrationHandler` MUST receive only `ObjectService` and `ModuleRegistrationService`
- AND the controller's constructor parameter count MUST drop below 13

#### Scenario: composer check:strict passes with no suppressions

- GIVEN the decomposition is complete
- WHEN `composer check:strict` runs
- THEN PHPMD MUST report zero violations for `SettingsController.php`
- AND all `@SuppressWarnings(PHPMD.*)` annotations MUST be removed from the file

#### Scenario: Existing tests pass unchanged

- GIVEN the decomposition is complete
- WHEN the existing PHPUnit tests run
- THEN all tests MUST pass without modification
- AND no behavioral change MUST occur (pure refactoring)

### Requirement: REQ-DECOMP-002 StackiqEventListener Decomposition

`lib/EventListener/StackiqEventListener.php` (11 suppressions) MUST be
decomposed by extracting event-specific handling logic into guard-clause-gated
helper methods and a shared `ModuleEventProcessor` class. The monolithic
`handleModuleCreated`, `handleModuleUpdated`, and `handleOrganizationEvent`
methods each have CC>10, NPath>200, and >100 lines.

#### Scenario: handleModuleCreated decomposed with guard clauses

- GIVEN `handleModuleCreated()` has nested conditional logic for module type detection, schema lookup, and property mapping
- WHEN the method is decomposed
- THEN guard clauses MUST extract early-return validation into a `validateModuleEvent()` private method
- AND type-specific logic MUST be extracted into `processModuleByType()`
- AND schema mapping MUST be extracted into `mapModuleToSchema()`
- AND each extracted method MUST have CC<5

#### Scenario: Shared logic extracted into ModuleEventProcessor

- GIVEN `handleModuleUpdated()` shares 60% of its logic with `handleModuleCreated()`
- WHEN both methods are decomposed
- THEN shared logic MUST be extracted into a `ModuleEventProcessor` helper class
- AND both handlers MUST delegate to `ModuleEventProcessor`
- AND code duplication between the two handlers MUST be eliminated

#### Scenario: handleOrganizationEvent split into three methods

- GIVEN `handleOrganizationEvent()` has >200 execution paths
- WHEN the method is decomposed
- THEN independent conditional blocks MUST be extracted into `handleOrganizationCreate()`, `handleOrganizationUpdate()`, and `handleOrganizationDelete()` private methods
- AND each extracted method MUST have NPath<50

### Requirement: REQ-DECOMP-003 ContactpersonenController Decomposition

`lib/Controller/ContactpersonenController.php` (11 suppressions) MUST be
decomposed by extracting create/update validation, bulk import, and export
logic. The `create()` and `update()` methods each exceed CC and method length
thresholds.

#### Scenario: create() decomposed into validate/enrich/persist phases

- GIVEN `create()` has >100 lines of validation, data preparation, and persistence logic
- WHEN decomposed
- THEN validation MUST be extracted into `validateContactInput(array $data): array`
- AND data enrichment MUST be extracted into `enrichContactData(array $data): array`
- AND persistence MUST be extracted into `persistContact(array $data): JSONResponse`
- AND each extracted method MUST have CC<10

#### Scenario: bulkImport() decomposed into parse/validate/process/report

- GIVEN `bulkImport()` has ExcessiveMethodLength with batch processing, per-item validation, and error aggregation
- WHEN decomposed
- THEN CSV/JSON parsing MUST be extracted into `parseImportFile()`
- AND row validation into `validateImportRow()`
- AND batch processing into `processImportBatch()`
- AND error collection into `buildImportReport()`

#### Scenario: exportContacts() decomposed

- GIVEN `exportContacts()` has ExcessiveMethodLength
- WHEN decomposed
- THEN query building MUST be extracted into `buildExportQuery()`
- AND format conversion into `formatExportData()`
- AND response building into `buildExportResponse()`

#### Scenario: Class-level suppressions removed

- GIVEN the controller has 3 class-level suppressions (ExcessiveClassLength, ExcessiveClassComplexity, CouplingBetweenObjects)
- WHEN handler extraction reduces class size
- THEN the controller class MUST drop below 1000 lines
- AND coupling MUST drop below 13 dependencies

### Requirement: REQ-DECOMP-004 StackiqService Decomposition

`lib/Service/StackiqService.php` (20 suppressions) MUST be
decomposed by splitting into focused service classes. This core service handles
VNG Software Catalogus API synchronization with multiple concerns: API
communication, data mapping, conflict resolution, and progress tracking.

#### Scenario: Concerns extracted into sub-services

- GIVEN `StackiqService` has 7+ class-level suppressions indicating an oversized god-class
- WHEN decomposed
- THEN API communication methods MUST move to `lib/Service/Stackiq/ApiClient.php`
- AND data mapping MUST move to `lib/Service/Stackiq/DataMapper.php`
- AND conflict resolution MUST move to `lib/Service/Stackiq/ConflictResolver.php`

#### Scenario: Existing handler pattern reused

- GIVEN the codebase already has a `Stackiq/` subdirectory with handler classes
- WHEN additional extraction follows this pattern
- THEN consistency MUST be maintained
- AND the existing handler injection pattern (constructor DI, delegation from parent service) MUST be reused without modification

#### Scenario: Progress tracking isolated to ProgressTracker

- GIVEN `StackiqService` methods contain progress tracking code interleaved with business logic
- WHEN decomposed
- THEN progress tracking calls MUST be isolated to the existing `lib/Service/ProgressTracker.php` wrapper
- AND business methods MUST contain no inline progress tracking code

### Requirement: REQ-DECOMP-005 SettingsService Decomposition

`lib/Service/SettingsService.php` (23 suppressions) MUST be decomposed. This
service manages application configuration persistence with methods that
validate, transform, and store multiple configuration sections. High coupling
comes from accessing many configuration keys across different domains.

#### Scenario: Domain-scoped settings handlers extracted

- GIVEN `SettingsService` handles sync settings, module settings, organisation settings, and ArchiMate settings in one class
- WHEN decomposed
- THEN `lib/Service/Settings/SyncSettingsHandler.php` MUST be created for sync domain
- AND `lib/Service/Settings/ModuleSettingsHandler.php` MUST be created for module domain
- AND `lib/Service/Settings/OrganizationSettingsHandler.php` MUST be created for organisation domain
- AND `SettingsService` MUST act as a facade delegating to these handlers

#### Scenario: Validation methods use guard clauses

- GIVEN methods use deep conditional chains to validate configuration values
- WHEN decomposed
- THEN validation logic MUST be extracted into `validate{Domain}Config()` methods
- AND these methods MUST use early returns and guard clauses
- AND each method MUST have CC<10

#### Scenario: TooManyMethods resolved

- GIVEN the class has a `TooManyMethods` suppression
- WHEN handler extraction moves groups of related methods to handlers
- THEN `SettingsService` MUST retain only delegation methods
- AND the public method count MUST be under 15

### Requirement: REQ-DECOMP-006 ArchiMate Services Decomposition

The ArchiMate services MUST be decomposed to remove their structural-complexity suppressions.

`lib/Service/ArchiMateService.php` (18 suppressions),
`lib/Service/ArchiMateImportService.php` (16 suppressions), and
`lib/Service/ArchiMateExportService.php` (16 suppressions) MUST be decomposed.
These services handle XML parsing, element-level processing, and relationship
mapping with deeply nested loops and conditionals.

#### Scenario: ArchiMateImportService element handlers extracted

- GIVEN `ArchiMateImportService` has methods that parse XML elements with nested switch statements for element types
- WHEN decomposed
- THEN each element type MUST be handled by a dedicated private method: `importElement()`, `importRelationship()`, `importView()`, `importDiagram()`
- AND each extracted method MUST be under 50 lines

#### Scenario: ArchiMateExportService attribute builders extracted

- GIVEN `ArchiMateExportService` builds XML with deep conditional nesting for attribute handling
- WHEN decomposed
- THEN attribute builders MUST be extracted into `buildElementAttributes()`, `buildRelationshipAttributes()`, and `buildViewAttributes()` helper methods

#### Scenario: ArchiMateService orchestration simplified

- GIVEN `ArchiMateService` coordinates import and export with 7 class-level suppressions
- WHEN decomposed
- THEN orchestration logic MUST be simplified by delegating to the import/export services
- AND file validation logic MUST be extracted into `validateArchiMateFile()`

#### Scenario: Shared dependencies grouped into ArchiMateContext

- GIVEN all three services have `CouplingBetweenObjects` suppressions
- WHEN shared dependencies are grouped
- THEN an `ArchiMate/ArchiMateContext.php` value object MUST carry shared state (ObjectService, SettingsService, logger)
- AND constructor parameter counts for all three services MUST drop below 13

### Requirement: REQ-DECOMP-007 OrganizationSyncService Decomposition

`lib/Service/OrganizationSyncService.php` (7 suppressions) MUST be decomposed
by extracting sync logic into pipeline stages. The service pulls organisation
data from external sources with complex mapping, validation, and conflict
resolution.

#### Scenario: Sync method decomposed into pipeline stages

- GIVEN the sync method has >200 execution paths combining fetch, validate, transform, and persist phases
- WHEN decomposed
- THEN each phase MUST become a private method: `fetchOrganizations()`, `validateOrganization()`, `transformOrganization()`, `persistOrganization()`
- AND each extracted method MUST have NPath<50

#### Scenario: Shared validation extracted for create and update

- GIVEN the service handles both create and update flows with shared validation
- WHEN decomposed
- THEN shared validation MUST be extracted into `validateOrganizationData()`
- AND both `handleCreate()` and `handleUpdate()` MUST call this shared method

#### Scenario: Error handling centralised

- GIVEN error handling is scattered throughout the sync method
- WHEN decomposed
- THEN error handling MUST be centralised into `handleSyncError()`
- AND all error paths MUST use consistent error logging and progress tracking

### Requirement: REQ-DECOMP-008 ContactpersoonService Decomposition

`lib/Service/ContactpersoonService.php` (6 suppressions) MUST be decomposed by
extracting validation, enrichment, and persistence phases from the main
business methods.

#### Scenario: Field validation extracted into ContactValidator

- GIVEN the service has methods exceeding CC>10 due to field-level validation checks
- WHEN decomposed
- THEN field validation MUST be extracted into a `lib/Service/Contactpersoon/ContactValidator.php` helper
- AND `ContactValidator` MUST expose `validateEmail()`, `validatePhone()`, and `validateName()` methods
- AND each method MUST have CC<10

#### Scenario: Enrichment separated from persistence

- GIVEN data enrichment logic (organisation lookup, duplicate resolution) is interleaved with persistence
- WHEN decomposed
- THEN enrichment MUST be extracted into `enrichContactData()` called before `persistContact()`
- AND these two concerns MUST not be combined in a single method

#### Scenario: Rarely-used dependencies lazy-loaded

- GIVEN the class has a `CouplingBetweenObjects` suppression
- WHEN dependencies are analyzed
- THEN rarely-used dependencies (email service, export service) MUST be lazy-loaded via `ContainerInterface`
- AND the constructor parameter count MUST drop below 13

### Requirement: REQ-DECOMP-009 AangebodenGebruikController and Service Decomposition

The AangebodenGebruik controller and service MUST be decomposed to remove their structural-complexity suppressions.

`lib/Controller/AangebodenGebruikController.php` (6 suppressions) and
`lib/Service/AangebodenGebruikService.php` (5 suppressions) MUST be decomposed.
The controller's `create()`, `bulkCreate()`, and `updateStatus()` methods
exceed complexity thresholds.

#### Scenario: bulkCreate() decomposed into validate/process/aggregate

- GIVEN `bulkCreate()` has ExcessiveMethodLength with batch processing, per-item validation, and error aggregation
- WHEN decomposed
- THEN the method MUST delegate to `validateBulkInput()`, `processBulkItem()`, and `aggregateBulkResults()` private methods
- AND the public `bulkCreate()` method body MUST be ≤20 lines

#### Scenario: updateStatus() complexity reduced via StatusTransitionValidator

- GIVEN `updateStatus()` has CC>10 due to status transition validation
- WHEN decomposed
- THEN status transition rules MUST be extracted into a `lib/Service/AangebodenGebruik/StatusTransitionValidator.php` class with a transition map
- AND the method MUST reduce to a simple `validate → transition → persist` flow with CC<5

#### Scenario: Service decomposed into domain handlers

- GIVEN the service has 5 class-level suppressions
- WHEN decomposed following the handler pattern
- THEN `lib/Service/AangebodenGebruik/GebruikStatusHandler.php` MUST be created for status concerns
- AND `lib/Service/AangebodenGebruik/GebruikBulkHandler.php` MUST be created for bulk concerns

### Requirement: REQ-DECOMP-010 ViewService and SymfonyEmailService Decomposition

ViewService and SymfonyEmailService MUST be decomposed to remove their structural-complexity suppressions.

`lib/Service/ViewService.php` (5 suppressions) and
`lib/Service/SymfonyEmailService.php` (5 suppressions) MUST be decomposed.
ViewService manages configurable data views with complex query building.
SymfonyEmailService handles email composition with template rendering and
attachment handling.

#### Scenario: ViewService query building extracted into ViewQueryBuilder

- GIVEN `ViewService` has methods that build complex queries with multiple optional filters
- WHEN decomposed
- THEN query building MUST be extracted into a `lib/Service/ViewQueryBuilder.php` helper
- AND it MUST expose chainable filter methods: `applyDateFilter()`, `applyStatusFilter()`, `applySearchFilter()`, `applySorting()`

#### Scenario: SymfonyEmailService split into composition phases

- GIVEN `SymfonyEmailService` composes emails with conditional template selection, attachment handling, and recipient resolution
- WHEN decomposed
- THEN email building MUST be split into `resolveRecipients()`, `renderTemplate()`, `attachFiles()`, and `sendEmail()` private methods

#### Scenario: All methods pass CC and NPath thresholds after decomposition

- GIVEN both services have `CyclomaticComplexity` and `NPathComplexity` suppressions
- WHEN decomposed with early returns and guard clauses
- THEN each method MUST have CC<10 and NPath<200
- AND no `@SuppressWarnings(PHPMD.*)` annotations MUST remain in either file

### Requirement: REQ-DECOMP-011 Priority 2 File Decomposition

The 8 files with 3–4 suppressions each MUST be decomposed to reduce complexity
below PHPMD thresholds.

#### Scenario: ModuleComplianceService rules split into evaluators

- GIVEN `ModuleComplianceService` has 4 suppressions (class complexity, CC, NPath, method length)
- WHEN decomposed
- THEN compliance check logic MUST be split into individual rule evaluators: `checkLicenseCompliance()`, `checkSecurityCompliance()`, `checkDocumentationCompliance()`
- AND each evaluator MUST return a compliance result object

#### Scenario: UserProfileUpdatedEventListener delegates to ProfileFieldMapper

- GIVEN `UserProfileUpdatedEventListener` has 4 suppressions across 2 methods
- WHEN decomposed
- THEN profile field mapping MUST be extracted into a `lib/Service/ProfileFieldMapper.php` helper
- AND the event handling methods MUST delegate to it

#### Scenario: HierarchyHandler tree operations extracted

- GIVEN `HierarchyHandler` has 3 suppressions for tree traversal complexity
- WHEN decomposed
- THEN tree operations MUST be extracted into `buildHierarchyTree()`, `resolveParent()`, and `updateChildReferences()` private methods

#### Scenario: All 8 Priority 2 files clean

- GIVEN all 8 Priority 2 files are decomposed
- WHEN the combined suppression count is checked
- THEN the total MUST be reduced from approximately 28 to 0
- AND no new PHPMD violations MUST be introduced

### Requirement: REQ-DECOMP-012 Priority 3 File Cleanup

The 6 files with 1–2 suppressions each MUST be decomposed or refactored to
remove remaining suppressions.

#### Scenario: Application.php boot method reduced

- GIVEN `Application.php` has `CouplingBetweenObjects` + `ExcessiveMethodLength`
- WHEN decomposed
- THEN event listener registration MUST be extracted into a `lib/Service/EventRegistrar.php` helper
- AND service registration MUST be extracted into `lib/Service/ServiceRegistrar.php`
- AND the boot method size MUST drop below 100 lines

#### Scenario: ModuleVersionService long method split

- GIVEN `ModuleVersionService` has a single `ExcessiveMethodLength` suppression
- WHEN decomposed
- THEN the long method MUST be split into `fetchVersionData()`, `compareVersions()`, and `updateVersionRecord()` phases

#### Scenario: Final check:strict passes with zero violations

- GIVEN all 6 Priority 3 files are cleaned up
- WHEN `composer check:strict` runs
- THEN zero PHPMD violations MUST remain across the entire codebase for the targeted suppression categories
- AND total `@SuppressWarnings(PHPMD.*)` annotations in `lib/` MUST be reduced by at least 145

