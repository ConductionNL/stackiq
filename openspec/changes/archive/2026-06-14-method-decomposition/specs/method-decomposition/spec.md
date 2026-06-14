---
status: draft
priority: high
estimated_effort: large
---

# Method Decomposition — SoftwareCatalog

## Purpose

Specify the requirements for eliminating 145 PHPMD complexity suppressions in
`lib/` by decomposing complex methods and classes into smaller, focused units.
Each suppression bypasses a strict PHPMD threshold (CC>10, NPath>200,
MethodLength>100, ClassLength>1000). This is a pure refactoring change — no
behavioral changes, no schema modifications, no new public API.

**Current suppression inventory:**

| Category | Count | Threshold |
|----------|-------|-----------|
| ExcessiveMethodLength | 35 | >100 lines |
| CyclomaticComplexity | 31 | >10 branches |
| NPathComplexity | 26 | >200 paths |
| ExcessiveClassComplexity | 19 | — |
| ExcessiveClassLength | 14 | >1000 lines |
| CouplingBetweenObjects | 12 | >13 deps |
| TooManyMethods | 8 | — |

## ADDED Requirements

### Requirement: REQ-DECOMP-001 SettingsController Decomposition

`lib/Controller/SettingsController.php` (23 PHPMD suppressions) MUST be
decomposed by extracting sync, module registration, and configuration logic
into dedicated handler classes. The controller MUST retain only thin action
methods (≤10 lines per ADR-003) that delegate to handlers. Class-level
suppressions (ExcessiveClassLength, TooManyMethods, ExcessiveClassComplexity,
CouplingBetweenObjects) and method-level suppressions on `syncSoftwareCatalogue`,
`registerModules`, `syncOrganizations`, and `configureArchiMate` MUST all be
removed.

#### Scenario: syncSoftwareCatalogue decomposed into SyncHandler

- GIVEN `SettingsController::syncSoftwareCatalogue()` has CC>10, NPath>200, and >100 lines
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
- THEN `SyncHandler` MUST receive only `ObjectService` and `SoftwareCatalogueService`
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

### Requirement: REQ-DECOMP-002 SoftwareCatalogEventListener Decomposition

`lib/EventListener/SoftwareCatalogEventListener.php` (11 suppressions) MUST be
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

### Requirement: REQ-DECOMP-004 SoftwareCatalogueService Decomposition

`lib/Service/SoftwareCatalogueService.php` (20 suppressions) MUST be
decomposed by splitting into focused service classes. This core service handles
VNG Software Catalogus API synchronization with multiple concerns: API
communication, data mapping, conflict resolution, and progress tracking.

#### Scenario: Concerns extracted into sub-services

- GIVEN `SoftwareCatalogueService` has 7+ class-level suppressions indicating an oversized god-class
- WHEN decomposed
- THEN API communication methods MUST move to `lib/Service/SoftwareCatalogue/ApiClient.php`
- AND data mapping MUST move to `lib/Service/SoftwareCatalogue/DataMapper.php`
- AND conflict resolution MUST move to `lib/Service/SoftwareCatalogue/ConflictResolver.php`

#### Scenario: Existing handler pattern reused

- GIVEN the codebase already has a `SoftwareCatalogue/` subdirectory with handler classes
- WHEN additional extraction follows this pattern
- THEN consistency MUST be maintained
- AND the existing handler injection pattern (constructor DI, delegation from parent service) MUST be reused without modification

#### Scenario: Progress tracking isolated to ProgressTracker

- GIVEN `SoftwareCatalogueService` methods contain progress tracking code interleaved with business logic
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

## Non-functional Requirements

### Requirement: REQ-DECOMP-NFR-001 No behavioral changes

- GIVEN any decomposed method or class
- WHEN tests run before and after the decomposition
- THEN test outcomes MUST be identical
- AND no observable behavior (API response shape, database writes, event dispatching) MUST change

### Requirement: REQ-DECOMP-NFR-002 @spec traceability

- GIVEN any new class or public method introduced by this change
- WHEN its PHPDoc block is read
- THEN it MUST include at least one `@spec openspec/changes/method-decomposition/tasks.md#task-N` tag per ADR-003

### Requirement: REQ-DECOMP-NFR-003 SPDX headers on new files

- GIVEN any new PHP file created by this change
- WHEN its contents are read
- THEN the second line (after `<?php`) MUST be `// SPDX-License-Identifier: EUPL-1.2` per ADR-015

### Requirement: REQ-DECOMP-NFR-004 Container invocation for tests

- GIVEN a developer wants to verify a decomposed class
- WHEN they run unit tests
- THEN they MUST invoke PHPUnit via:
  `docker exec -w /var/www/html/custom_apps/softwarecatalog nextcloud php vendor/bin/phpunit -c phpunit-unit.xml`
- AND class-scoped filtering MUST be available via `--filter ClassName` per ADR-008
