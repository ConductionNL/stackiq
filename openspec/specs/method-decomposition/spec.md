---
status: draft
priority: high
estimated_effort: large
---

# Method Decomposition -- SoftwareCatalog

## Goal

Eliminate 145 PHPMD complexity suppressions by decomposing complex methods into smaller, focused units. Each suppression represents a method or class that exceeds PHPMD's strict thresholds (CC>10, NPath>200, MethodLength>100, ClassLength>1000). The total codebase has 326 `@SuppressWarnings(PHPMD.*)` annotations; this spec targets the 145 that relate to structural complexity.

## Current State

- **CyclomaticComplexity suppressions:** 31 (methods with >10 branches)
- **NPathComplexity suppressions:** 26 (methods with >200 execution paths)
- **ExcessiveMethodLength suppressions:** 35 (methods >100 lines)
- **ExcessiveClassComplexity suppressions:** 19 (classes with too much logic)
- **ExcessiveClassLength suppressions:** 14 (classes >1000 lines)
- **CouplingBetweenObjects suppressions:** 12 (too many dependencies)
- **TooManyMethods suppressions:** 8

## Requirements

### REQ-DECOMP-001: SettingsController Decomposition

The `lib/Controller/SettingsController.php` (23 PHPMD suppressions) MUST be decomposed by extracting sync, module registration, and configuration logic into dedicated handler classes. The controller retains only thin action methods that delegate to handlers, reducing class-level complexity (ExcessiveClassLength, TooManyMethods, ExcessiveClassComplexity, CouplingBetweenObjects) and method-level complexity on `syncSoftwareCatalogue`, `registerModules`, `syncOrganizations`, and `configureArchiMate`.

**Scenarios:**

1. **GIVEN** SettingsController has a `syncSoftwareCatalogue()` method with CC>10, NPath>200, and >100 lines **WHEN** the method is decomposed **THEN** a `SyncHandler` class is created with `validateSyncConfig()`, `prepareSyncData()`, `executeSyncBatch()`, and `buildSyncResponse()` methods, and the controller delegates to `SyncHandler::handle()`.

2. **GIVEN** SettingsController has `registerModules()` with ExcessiveMethodLength **WHEN** the method is decomposed **THEN** a `ModuleRegistrationHandler` class is created with `validateModuleInput()`, `resolveModuleDependencies()`, and `persistModules()` methods.

3. **GIVEN** SettingsController injects 10+ dependencies (CouplingBetweenObjects) **WHEN** handlers are extracted **THEN** each handler receives only its required dependencies (e.g., SyncHandler gets ObjectService and SoftwareCatalogueService; ModuleRegistrationHandler gets ObjectService and ModuleRegistrationService), reducing the controller's constructor parameter count.

4. **GIVEN** the decomposition is complete **WHEN** `composer check:strict` runs **THEN** PHPMD reports zero violations for SettingsController and all suppression annotations are removed from the file.

5. **GIVEN** the decomposition is complete **WHEN** the existing unit tests run **THEN** all tests pass without modification (pure refactoring, public API unchanged).

### REQ-DECOMP-002: SoftwareCatalogEventListener Decomposition

The `lib/EventListener/SoftwareCatalogEventListener.php` (11 suppressions) MUST be decomposed by extracting event-specific handling logic into separate handler methods and helper classes. The monolithic `handleModuleCreated`, `handleModuleUpdated`, and `handleOrganizationEvent` methods each have CC>10, NPath>200, and >100 lines.

**Scenarios:**

1. **GIVEN** `handleModuleCreated()` has nested conditional logic for module type detection, schema lookup, and property mapping **WHEN** the method is decomposed **THEN** guard clauses extract early-return validation into `validateModuleEvent()`, type-specific logic is extracted into `processModuleByType()`, and schema mapping is extracted into `mapModuleToSchema()`.

2. **GIVEN** `handleModuleUpdated()` shares 60% of its logic with `handleModuleCreated()` **WHEN** both methods are decomposed **THEN** shared logic is extracted into a `ModuleEventProcessor` helper class used by both handlers, eliminating code duplication.

3. **GIVEN** `handleOrganizationEvent()` has >200 execution paths **WHEN** the method is decomposed **THEN** independent conditional blocks are extracted into `handleOrganizationCreate()`, `handleOrganizationUpdate()`, and `handleOrganizationDelete()` private methods, each with NPath <50.

### REQ-DECOMP-003: ContactpersonenController Decomposition

The `lib/Controller/ContactpersonenController.php` (11 suppressions) MUST be decomposed by extracting create/update validation, bulk import, and export logic. The `create()` and `update()` methods each exceed CC and method length thresholds.

**Scenarios:**

1. **GIVEN** `create()` has >100 lines of validation, data preparation, and persistence logic **WHEN** decomposed **THEN** validation is extracted into `validateContactInput(array $data): array`, data enrichment into `enrichContactData(array $data): array`, and persistence into `persistContact(array $data): JSONResponse`.

2. **GIVEN** `bulkImport()` has ExcessiveMethodLength **WHEN** decomposed **THEN** CSV/JSON parsing is extracted into `parseImportFile()`, row validation into `validateImportRow()`, batch processing into `processImportBatch()`, and error collection into `buildImportReport()`.

3. **GIVEN** `exportContacts()` has ExcessiveMethodLength **WHEN** decomposed **THEN** query building is extracted into `buildExportQuery()`, format conversion into `formatExportData()`, and response building into `buildExportResponse()`.

4. **GIVEN** the controller has 3 class-level suppressions (ExcessiveClassLength, ExcessiveClassComplexity, CouplingBetweenObjects) **WHEN** handler extraction reduces class size **THEN** the controller class drops below 1000 lines and coupling drops below 13 dependencies.

### REQ-DECOMP-004: SoftwareCatalogueService Decomposition

The `lib/Service/SoftwareCatalogueService.php` (20 suppressions) MUST be decomposed by splitting into focused service classes. This core service handles VNG Software Catalogus API synchronization with multiple concerns: API communication, data mapping, conflict resolution, and progress tracking.

**Scenarios:**

1. **GIVEN** SoftwareCatalogueService has 7+ class-level suppressions indicating an oversized god-class **WHEN** decomposed **THEN** API communication methods move to `SoftwareCatalogue/ApiClient.php`, data mapping to `SoftwareCatalogue/DataMapper.php`, and conflict resolution to `SoftwareCatalogue/ConflictResolver.php`.

2. **GIVEN** the codebase already has a `SoftwareCatalogue/` subdirectory with handler classes (ContactPersonHandler, OrganizationHandler, HierarchyHandler, GroupHandler) **WHEN** additional extraction follows this pattern **THEN** consistency is maintained and the existing handler injection pattern (constructor DI, delegation from parent service) is reused.

3. **GIVEN** SoftwareCatalogueService methods contain progress tracking code interleaved with business logic **WHEN** decomposed **THEN** progress tracking calls are isolated to a ProgressTracker wrapper (which already exists at `lib/Service/ProgressTracker.php`), keeping business methods focused.

### REQ-DECOMP-005: SettingsService Decomposition

The `lib/Service/SettingsService.php` (23 suppressions) MUST be decomposed. This service manages application configuration persistence with methods that validate, transform, and store multiple configuration sections. The high coupling comes from accessing many configuration keys across different domains.

**Scenarios:**

1. **GIVEN** SettingsService handles sync settings, module settings, organization settings, and ArchiMate settings in one class **WHEN** decomposed **THEN** each configuration domain gets a dedicated handler: `SyncSettingsHandler`, `ModuleSettingsHandler`, `OrganizationSettingsHandler`, with SettingsService acting as a facade.

2. **GIVEN** methods use deep conditional chains to validate configuration values **WHEN** decomposed **THEN** validation logic is extracted into `validate{Domain}Config()` methods using early returns and guard clauses, reducing CC below 10 per method.

3. **GIVEN** the class has TooManyMethods suppression **WHEN** handler extraction moves groups of related methods to handlers **THEN** SettingsService retains only delegation methods (under 15 public methods).

### REQ-DECOMP-006: ArchiMate Services Decomposition

The `lib/Service/ArchiMateService.php` (18 suppressions), `lib/Service/ArchiMateImportService.php` (16 suppressions), and `lib/Service/ArchiMateExportService.php` (16 suppressions) MUST be decomposed. These services handle XML parsing, element-level processing, and relationship mapping with deeply nested loops and conditionals.

**Scenarios:**

1. **GIVEN** ArchiMateImportService has methods that parse XML elements with nested switch statements for element types **WHEN** decomposed **THEN** each element type handler is extracted into a dedicated method (`importElement()`, `importRelationship()`, `importView()`, `importDiagram()`), each under 50 lines.

2. **GIVEN** ArchiMateExportService builds XML with deep conditional nesting for attribute handling **WHEN** decomposed **THEN** attribute builders are extracted into `buildElementAttributes()`, `buildRelationshipAttributes()`, and `buildViewAttributes()` helper methods.

3. **GIVEN** ArchiMateService coordinates import and export with 7 class-level suppressions **WHEN** decomposed **THEN** orchestration logic is simplified by delegating to the import/export services (which themselves are now cleaner), and validation logic is extracted into `validateArchiMateFile()`.

4. **GIVEN** all three services have CouplingBetweenObjects suppressions **WHEN** shared dependencies are grouped **THEN** a `ArchiMateContext` value object carries shared state (ObjectService, SettingsService, logger) reducing constructor parameter counts.

### REQ-DECOMP-007: OrganizationSyncService Decomposition

The `lib/Service/OrganizationSyncService.php` (7 class-level suppressions) MUST be decomposed by extracting sync logic into pipeline stages. The service pulls organization data from external sources with complex mapping, validation, and conflict resolution.

**Scenarios:**

1. **GIVEN** the sync method has >200 execution paths combining fetch, validate, transform, and persist phases **WHEN** decomposed **THEN** each phase becomes a private method: `fetchOrganizations()`, `validateOrganization()`, `transformOrganization()`, `persistOrganization()`, each with NPath <50.

2. **GIVEN** the service handles both create and update flows with shared validation **WHEN** decomposed **THEN** shared validation is extracted into `validateOrganizationData()` called by both `handleCreate()` and `handleUpdate()`.

3. **GIVEN** error handling is scattered throughout the sync method **WHEN** decomposed **THEN** error handling is centralized into `handleSyncError()` with consistent error logging and progress tracking.

### REQ-DECOMP-008: ContactpersoonService Decomposition

The `lib/Service/ContactpersoonService.php` (6 class-level suppressions) MUST be decomposed by extracting validation, enrichment, and persistence phases from the main business methods.

**Scenarios:**

1. **GIVEN** the service has methods exceeding CC>10 due to field-level validation checks **WHEN** decomposed **THEN** field validation is extracted into a `ContactValidator` helper with methods like `validateEmail()`, `validatePhone()`, `validateName()`, reducing CC below 10.

2. **GIVEN** data enrichment logic (looking up organization, resolving duplicates) is interleaved with persistence **WHEN** decomposed **THEN** enrichment is extracted into `enrichContactData()` called before `persistContact()`.

3. **GIVEN** the class has CouplingBetweenObjects suppression **WHEN** dependencies are analyzed **THEN** rarely-used dependencies (email service, export service) are lazy-loaded via ContainerInterface reducing immediate coupling.

### REQ-DECOMP-009: AangebodenGebruikController and Service Decomposition

The `lib/Controller/AangebodenGebruikController.php` (6 suppressions) and `lib/Service/AangebodenGebruikService.php` (5 suppressions) MUST be decomposed. The controller's `create()`, `bulkCreate()`, and `updateStatus()` methods exceed complexity thresholds.

**Scenarios:**

1. **GIVEN** `bulkCreate()` has ExcessiveMethodLength with batch processing, validation per item, and error aggregation **WHEN** decomposed **THEN** the method delegates to `validateBulkInput()`, `processBulkItem()`, and `aggregateBulkResults()` private methods.

2. **GIVEN** `updateStatus()` has CC>10 due to status transition validation **WHEN** decomposed **THEN** status transition rules are extracted into a `StatusTransitionValidator` with a transition map, reducing the method to a simple `validate -> transition -> persist` flow.

3. **GIVEN** the service has 5 class-level suppressions **WHEN** decomposed following the handler pattern **THEN** a `GebruikStatusHandler` and `GebruikBulkHandler` are created, each handling their specific concern.

### REQ-DECOMP-010: ViewService and SymfonyEmailService Decomposition

The `lib/Service/ViewService.php` (5 suppressions) and `lib/Service/SymfonyEmailService.php` (5 suppressions) MUST be decomposed. ViewService manages configurable data views with complex query building. SymfonyEmailService handles email composition with template rendering and attachment handling.

**Scenarios:**

1. **GIVEN** ViewService has methods that build complex queries with multiple optional filters **WHEN** decomposed **THEN** query building is extracted into a `ViewQueryBuilder` helper with chainable filter methods: `applyDateFilter()`, `applyStatusFilter()`, `applySearchFilter()`, `applySorting()`.

2. **GIVEN** SymfonyEmailService composes emails with conditional template selection, attachment handling, and recipient resolution **WHEN** decomposed **THEN** email building is split into `resolveRecipients()`, `renderTemplate()`, `attachFiles()`, and `sendEmail()` methods.

3. **GIVEN** both services have CyclomaticComplexity and NPathComplexity suppressions **WHEN** decomposed with early returns and guard clauses **THEN** each method has CC<10 and NPath<200 without needing suppressions.

### REQ-DECOMP-011: Priority 2 File Decomposition

The 8 files with 3-4 suppressions each (OrganizationHandler, ModuleComplianceService, AanbodService, UserProfileUpdatedEventListener, HierarchyHandler, ModuleRegistrationService, GebruikSyncService, OpenRegisterEventsDebugListener) MUST be decomposed to reduce their complexity below PHPMD thresholds.

**Scenarios:**

1. **GIVEN** ModuleComplianceService has 4 suppressions (class complexity, CC, NPath, method length) **WHEN** decomposed **THEN** compliance check logic is split into individual rule evaluators: `checkLicenseCompliance()`, `checkSecurityCompliance()`, `checkDocumentationCompliance()`, each returning a compliance result object.

2. **GIVEN** UserProfileUpdatedEventListener has 4 suppressions across 2 methods **WHEN** decomposed **THEN** profile field mapping is extracted into a `ProfileFieldMapper` helper, and the event handling methods delegate to it.

3. **GIVEN** HierarchyHandler has 3 suppressions for tree traversal complexity **WHEN** decomposed **THEN** tree operations are extracted into `buildHierarchyTree()`, `resolveParent()`, and `updateChildReferences()` methods.

4. **GIVEN** all 8 files are decomposed **WHEN** the combined suppression count is checked **THEN** the total is reduced from approximately 28 to 0, contributing to the overall 145-suppression elimination goal.

### REQ-DECOMP-012: Priority 3 File Cleanup

The 6 files with 1-2 suppressions each (ModuleComplianceSubscriber, GebruikController, Application, GroupHandler, ModuleVersionService, ViewController) MUST be decomposed or refactored to remove remaining suppressions.

**Scenarios:**

1. **GIVEN** Application.php has CouplingBetweenObjects + ExcessiveMethodLength **WHEN** decomposed **THEN** event listener registration is extracted into a `EventRegistrar` helper, and service registration into `ServiceRegistrar`, reducing the boot method size.

2. **GIVEN** ModuleVersionService has a single ExcessiveMethodLength **WHEN** decomposed **THEN** the long method is split into `fetchVersionData()`, `compareVersions()`, and `updateVersionRecord()` phases.

3. **GIVEN** all 6 files are cleaned up **WHEN** `composer check:strict` runs **THEN** zero PHPMD violations remain across the entire codebase for the targeted suppression categories.

## Decomposition Strategy

### For CyclomaticComplexity (>10 branches)
Extract conditional branches into private helper methods:
- Guard clauses: Extract early-return validation into `validate{Thing}()` methods
- Switch-like logic: Extract case handlers into `handle{Case}()` methods
- Nested conditions: Flatten by extracting inner blocks into descriptive methods

### For NPathComplexity (>200 paths)
Reduce execution paths by:
- Breaking method into pipeline stages (each stage = private method)
- Extracting independent conditional blocks into separate methods
- Using early returns to eliminate nested paths

### For ExcessiveMethodLength (>100 lines)
Split long methods into logical phases:
- Validation phase -> `validate{Input}()`
- Preparation phase -> `prepare{Data}()`
- Processing phase -> `process{Thing}()`
- Response phase -> `build{Response}()`

### For ExcessiveClassComplexity / ExcessiveClassLength
Extract method groups into Handler classes (existing pattern in codebase):
- Create `{ClassName}/{HandlerName}Handler.php`
- Move related methods to the handler
- Inject handler via constructor
- Delegate from original methods (keep public API stable)

### For CouplingBetweenObjects (>13 dependencies)
Reduce constructor parameters by:
- Grouping related dependencies into a single service
- Using lazy loading for rarely-used dependencies (ContainerInterface->get())
- Moving methods that use specific deps to handler classes

## Files Requiring Decomposition

### Priority 1 -- Highest complexity (files with 5+ suppressions)

**lib/Controller/SettingsController.php** (23 suppressions)
Admin settings controller managing synchronization settings, module registration, and catalogue configuration. Class-level suppressions for class length, TooManyMethods, class complexity, and coupling. Method-level suppressions on `syncSoftwareCatalogue`, `registerModules`, `syncOrganizations`, and `configureArchiMate`.

**lib/Service/SettingsService.php** (23 suppressions)
Settings persistence service managing application configuration across multiple domains.

**lib/Service/SoftwareCatalogueService.php** (20 suppressions)
Core service for synchronizing with the VNG Software Catalogus API.

**lib/Service/ArchiMateService.php** (18 suppressions)
ArchiMate enterprise architecture model import/export orchestrator.

**lib/Service/ArchiMateImportService.php** (16 suppressions)
ArchiMate XML import service parsing Open Exchange Format files.

**lib/Service/ArchiMateExportService.php** (16 suppressions)
ArchiMate XML export service generating Open Exchange Format files.

**lib/EventListener/SoftwareCatalogEventListener.php** (11 suppressions)
Event listener handling OpenRegister object events for software catalog synchronization.

**lib/Controller/ContactpersonenController.php** (11 suppressions)
Contact persons CRUD controller with complex create/update logic.

**lib/Service/SoftwareCatalogue/ContactPersonHandler.php** (7 suppressions)
Handler for contact person synchronization with the Software Catalogus.

**lib/Service/OrganizationSyncService.php** (7 suppressions)
Organisation synchronization service pulling data from external sources.

**lib/Service/ContactpersoonService.php** (6 suppressions)
Contact person business logic service.

**lib/Controller/AangebodenGebruikController.php** (6 suppressions)
"Offered usage" (software deployments) controller.

**lib/Service/ViewService.php** (5 suppressions)
View/dashboard service managing configurable data views.

**lib/Service/SymfonyEmailService.php** (5 suppressions)
Email sending service using Symfony Mailer.

**lib/Service/AangebodenGebruikService.php** (5 suppressions)
Software usage/deployment business logic.

### Priority 2 -- Medium complexity (files with 3-4 suppressions)

- `lib/Service/SoftwareCatalogue/OrganizationHandler.php` (4)
- `lib/Service/ModuleComplianceService.php` (4)
- `lib/Service/AanbodService.php` (4)
- `lib/EventListener/UserProfileUpdatedEventListener.php` (4)
- `lib/Service/SoftwareCatalogue/HierarchyHandler.php` (3)
- `lib/Service/ModuleRegistrationService.php` (3)
- `lib/Service/GebruikSyncService.php` (3)
- `lib/EventListener/OpenRegisterEventsDebugListener.php` (3)

### Priority 3 -- Single or double suppressions

- `lib/EventListener/ModuleComplianceSubscriber.php` (2)
- `lib/Controller/GebruikController.php` (2)
- `lib/AppInfo/Application.php` (2)
- `lib/Service/SoftwareCatalogue/GroupHandler.php` (1)
- `lib/Service/ModuleVersionService.php` (1)
- `lib/Controller/ViewController.php` (1)

## Testing Strategy

### Before decomposition
1. Run existing unit tests: `docker exec -w /var/www/html/custom_apps/softwarecatalog nextcloud php vendor/bin/phpunit -c phpunit-unit.xml`
2. Note any pre-existing failures
3. Run PHPMD to record current suppression count: `./vendor/bin/phpmd lib/ text phpmd.xml 2>&1 | wc -l`

### During decomposition (per method)
1. Verify `php -l` passes on all changed files
2. Run unit tests for the specific class: `--filter ClassName`
3. Run PHPMD on the specific file to confirm suppression can be removed

### After decomposition
1. Full unit test suite passes
2. PHPMD reports 0 violations (no new warnings)
3. Total suppression count reduced by expected amount
4. `composer check:strict` passes
5. Manual smoke test in browser (http://localhost:3000)

## Acceptance Criteria
- [ ] All CyclomaticComplexity suppressions eliminated or reduced to <=5
- [ ] All NPathComplexity suppressions eliminated or reduced to <=5
- [ ] All ExcessiveMethodLength suppressions eliminated or reduced to <=5
- [ ] ExcessiveClassComplexity reduced by extracting handler classes
- [ ] CouplingBetweenObjects reduced by dependency grouping and handler extraction
- [ ] TooManyMethods reduced by handler extraction
- [ ] No new PHPMD violations introduced
- [ ] All existing tests continue to pass
- [ ] No behavioral changes (pure refactoring)
- [ ] `composer check:strict` passes with zero violations
