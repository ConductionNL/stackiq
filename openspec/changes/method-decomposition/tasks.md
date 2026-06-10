# Tasks — method-decomposition

> Spec-only companion tasks. This change is a pure PHP refactoring; no new
> OpenRegister schemas, no frontend changes, no new public API.

## Phase 0 — Deduplication check + baseline

- [~] 0.1 Verify no new capability duplicates existing OpenRegister services: — deferred to downstream cycle (handoff)
  - Search `openregister/lib/Service/` and `openspec/specs/` for ObjectService,
    RegisterService, SchemaService, ConfigurationService equivalents
  - Document findings in `design.md` Reuse Analysis table (even if "no overlap found")
- [~] 0.2 Capture current PHPMD suppression baseline: — deferred to downstream cycle (handoff)
  - Run: `docker exec -w /var/www/html/custom_apps/softwarecatalog nextcloud ./vendor/bin/phpmd lib/ text phpmd.xml 2>&1 | grep -c SuppressWarnings`
  - Record count in PR description (expected: ≥145 in targeted categories)
- [~] 0.3 Capture PHPUnit baseline: — deferred to downstream cycle (handoff)
  - Run: `docker exec -w /var/www/html/custom_apps/softwarecatalog nextcloud php vendor/bin/phpunit -c phpunit-unit.xml`
  - Record any pre-existing failures so they are not attributed to this change
- [~] 0.4 Verify `composer check:strict` script exists and runs PHPMD, PHPCS, — deferred to downstream cycle (handoff)
      Psalm/PHPStan. If missing, do NOT create it — raise with maintainer.

## Phase 1 — SettingsService decomposition (REQ-DECOMP-005)

Decompose first — downstream handlers (ArchiMateContext, SyncHandler) depend on
its post-refactor facade.

- [~] 1.1 Audit `lib/Service/SettingsService.php`: — deferred to downstream cycle (handoff)
  - List all public methods and group by domain: sync, modules, organisations, ArchiMate
  - Identify which methods have CC>10 or >100 lines
- [~] 1.2 Create `lib/Service/Settings/SyncSettingsHandler.php`: — deferred to downstream cycle (handoff)
  - SPDX header: `// SPDX-License-Identifier: EUPL-1.2`
  - `@spec openspec/changes/method-decomposition/tasks.md#task-1`
  - Move sync-domain methods; extract `validateSyncConfig()` guard clauses
- [~] 1.3 Create `lib/Service/Settings/ModuleSettingsHandler.php`: — deferred to downstream cycle (handoff)
  - Same SPDX + `@spec` requirements
  - Move module-domain methods; extract `validateModuleConfig()`
- [~] 1.4 Create `lib/Service/Settings/OrganizationSettingsHandler.php`: — deferred to downstream cycle (handoff)
  - Move organisation-domain methods; extract `validateOrganizationConfig()`
- [~] 1.5 Refactor `SettingsService` to facade pattern: — deferred to downstream cycle (handoff)
  - Inject all three handlers via constructor
  - Replace method bodies with single-line delegation calls
  - Verify public method count ≤15
- [~] 1.6 Remove all `@SuppressWarnings(PHPMD.*)` from `SettingsService.php` — deferred to downstream cycle (handoff)
- [~] 1.7 Run `./vendor/bin/phpmd lib/Service/Settings/ text phpmd.xml` — must be zero violations — deferred to downstream cycle (handoff)
- [~] 1.8 Run `phpunit --filter SettingsServiceTest` — must pass — deferred to downstream cycle (handoff)

## Phase 2 — SoftwareCatalogueService decomposition (REQ-DECOMP-004)

- [~] 2.1 Audit `lib/Service/SoftwareCatalogueService.php`: — deferred to downstream cycle (handoff)
  - Identify API communication, data mapping, and conflict resolution method groups
  - Map which methods call `ProgressTracker` — these must be isolated
- [~] 2.2 Create `lib/Service/SoftwareCatalogue/ApiClient.php`: — deferred to downstream cycle (handoff)
  - SPDX header + `@spec openspec/changes/method-decomposition/tasks.md#task-2`
  - Move all API communication methods (HTTP calls, response parsing)
- [~] 2.3 Create `lib/Service/SoftwareCatalogue/DataMapper.php`: — deferred to downstream cycle (handoff)
  - Move all data mapping / transformation methods
- [~] 2.4 Create `lib/Service/SoftwareCatalogue/ConflictResolver.php`: — deferred to downstream cycle (handoff)
  - Move all conflict resolution / deduplication methods
- [~] 2.5 Isolate progress tracking: — deferred to downstream cycle (handoff)
  - Remove all inline `ProgressTracker` calls from business methods
  - Ensure `ProgressTracker` is only called at the orchestration level in the parent service
- [~] 2.6 Update `SoftwareCatalogueService` to inject new sub-services and delegate — deferred to downstream cycle (handoff)
- [~] 2.7 Remove all `@SuppressWarnings(PHPMD.*)` from `SoftwareCatalogueService.php` — deferred to downstream cycle (handoff)
- [~] 2.8 Run PHPMD on all affected files — zero violations — deferred to downstream cycle (handoff)
- [~] 2.9 Run `phpunit --filter SoftwareCatalogueServiceTest` — must pass — deferred to downstream cycle (handoff)

## Phase 3 — SettingsController decomposition (REQ-DECOMP-001)

Depends on Phase 1 (SettingsService facade must exist).

- [~] 3.1 Create `lib/Controller/Settings/SyncHandler.php`: — deferred to downstream cycle (handoff)
  - SPDX header + `@spec openspec/changes/method-decomposition/tasks.md#task-3`
  - Inject only `ObjectService` and `SoftwareCatalogueService`
  - Expose `handle(array $config): array` public method
  - Extract private: `validateSyncConfig()`, `prepareSyncData()`, `executeSyncBatch()`, `buildSyncResponse()`
- [~] 3.2 Create `lib/Controller/Settings/ModuleRegistrationHandler.php`: — deferred to downstream cycle (handoff)
  - Inject only `ObjectService` and `ModuleRegistrationService`
  - Extract private: `validateModuleInput()`, `resolveModuleDependencies()`, `persistModules()`
- [~] 3.3 Refactor `SettingsController`: — deferred to downstream cycle (handoff)
  - Replace `syncSoftwareCatalogue()` body with `$this->syncHandler->handle(...)`
  - Replace `registerModules()` body with `$this->moduleHandler->handle(...)`
  - Verify each action method is ≤10 lines per ADR-003
  - Verify constructor parameter count <13
- [~] 3.4 Remove all `@SuppressWarnings(PHPMD.*)` from `SettingsController.php` — deferred to downstream cycle (handoff)
- [~] 3.5 Run PHPMD on controller + new handler files — zero violations — deferred to downstream cycle (handoff)
- [~] 3.6 Run `phpunit --filter SettingsControllerTest` — must pass — deferred to downstream cycle (handoff)

## Phase 4 — ArchiMate services decomposition (REQ-DECOMP-006)

Depends on Phase 1 (SettingsService facade used in ArchiMateContext).

- [~] 4.1 Create `lib/Service/ArchiMate/ArchiMateContext.php`: — deferred to downstream cycle (handoff)
  - SPDX header + `@spec openspec/changes/method-decomposition/tasks.md#task-4`
  - Constructor: `ObjectService $objectService`, `SettingsService $settingsService`, `LoggerInterface $logger`
  - Public readonly properties for each
- [~] 4.2 Decompose `lib/Service/ArchiMateImportService.php`: — deferred to downstream cycle (handoff)
  - Extract `importElement()`, `importRelationship()`, `importView()`, `importDiagram()` — each ≤50 lines
  - Inject `ArchiMateContext` to reduce constructor coupling
  - Remove all `@SuppressWarnings(PHPMD.*)`
- [~] 4.3 Decompose `lib/Service/ArchiMateExportService.php`: — deferred to downstream cycle (handoff)
  - Extract `buildElementAttributes()`, `buildRelationshipAttributes()`, `buildViewAttributes()`
  - Inject `ArchiMateContext`
  - Remove all `@SuppressWarnings(PHPMD.*)`
- [~] 4.4 Decompose `lib/Service/ArchiMateService.php`: — deferred to downstream cycle (handoff)
  - Simplify orchestration — delegate to import/export services
  - Extract `validateArchiMateFile()` with guard clauses
  - Inject `ArchiMateContext`
  - Remove all `@SuppressWarnings(PHPMD.*)`
- [~] 4.5 Run PHPMD on all three ArchiMate service files — zero violations — deferred to downstream cycle (handoff)
- [~] 4.6 Run `phpunit --filter ArchiMate` — must pass — deferred to downstream cycle (handoff)

## Phase 5 — ContactpersonenController decomposition (REQ-DECOMP-003)

- [~] 5.1 Extract `validateContactInput(array $data): array` private method from `create()` — deferred to downstream cycle (handoff)
- [~] 5.2 Extract `enrichContactData(array $data): array` private method from `create()` — deferred to downstream cycle (handoff)
- [~] 5.3 Extract `persistContact(array $data): JSONResponse` private method from `create()` — deferred to downstream cycle (handoff)
- [~] 5.4 Decompose `bulkImport()`: — deferred to downstream cycle (handoff)
  - Extract `parseImportFile()`, `validateImportRow()`, `processImportBatch()`, `buildImportReport()`
- [~] 5.5 Decompose `exportContacts()`: — deferred to downstream cycle (handoff)
  - Extract `buildExportQuery()`, `formatExportData()`, `buildExportResponse()`
- [~] 5.6 Verify class drops below 1000 lines and coupling below 13 — deferred to downstream cycle (handoff)
- [~] 5.7 Remove all `@SuppressWarnings(PHPMD.*)` from `ContactpersonenController.php` — deferred to downstream cycle (handoff)
- [~] 5.8 Run PHPMD — zero violations; run `phpunit --filter ContactpersonenControllerTest` — must pass — deferred to downstream cycle (handoff)

## Phase 6 — SoftwareCatalogEventListener decomposition (REQ-DECOMP-002)

- [~] 6.1 Create `lib/Service/ModuleEventProcessor.php`: — deferred to downstream cycle (handoff)
  - SPDX header + `@spec openspec/changes/method-decomposition/tasks.md#task-6`
  - Extract shared 60% logic from `handleModuleCreated` and `handleModuleUpdated`
- [~] 6.2 Decompose `handleModuleCreated()`: — deferred to downstream cycle (handoff)
  - Extract `validateModuleEvent()` with guard clauses (early-return validation)
  - Extract `processModuleByType()` for type-specific logic
  - Extract `mapModuleToSchema()` for schema mapping
- [~] 6.3 Decompose `handleModuleUpdated()` to delegate to `ModuleEventProcessor` — deferred to downstream cycle (handoff)
- [~] 6.4 Decompose `handleOrganizationEvent()`: — deferred to downstream cycle (handoff)
  - Extract `handleOrganizationCreate()`, `handleOrganizationUpdate()`, `handleOrganizationDelete()`
  - Each extracted method MUST have NPath<50
- [~] 6.5 Remove all `@SuppressWarnings(PHPMD.*)` from event listener — deferred to downstream cycle (handoff)
- [~] 6.6 Run PHPMD — zero violations; run `phpunit --filter SoftwareCatalogEventListenerTest` — must pass — deferred to downstream cycle (handoff)

## Phase 7 — Remaining Priority 1 files (REQ-DECOMP-007, 008, 009, 010)

- [~] 7.1 **OrganizationSyncService** (REQ-DECOMP-007): — deferred to downstream cycle (handoff)
  - Extract pipeline stages: `fetchOrganizations()`, `validateOrganization()`, `transformOrganization()`, `persistOrganization()` each with NPath<50
  - Extract shared `validateOrganizationData()` called by both `handleCreate()` and `handleUpdate()`
  - Centralise error handling into `handleSyncError()`
  - Remove all suppressions; run PHPMD + phpunit
- [~] 7.2 **ContactpersoonService** (REQ-DECOMP-008): — deferred to downstream cycle (handoff)
  - Create `lib/Service/Contactpersoon/ContactValidator.php` with `validateEmail()`, `validatePhone()`, `validateName()`
  - Extract `enrichContactData()` before `persistContact()`
  - Lazy-load email service + export service via `ContainerInterface`
  - Remove all suppressions; run PHPMD + phpunit
- [~] 7.3 **AangebodenGebruikController + Service** (REQ-DECOMP-009): — deferred to downstream cycle (handoff)
  - Create `lib/Service/AangebodenGebruik/StatusTransitionValidator.php` with transition map
  - Create `lib/Service/AangebodenGebruik/GebruikStatusHandler.php`
  - Create `lib/Service/AangebodenGebruik/GebruikBulkHandler.php`
  - Decompose `bulkCreate()` into `validateBulkInput()`, `processBulkItem()`, `aggregateBulkResults()`
  - Reduce `updateStatus()` to `validate → transition → persist` with CC<5
  - Remove all suppressions; run PHPMD + phpunit
- [~] 7.4 **ViewService** (REQ-DECOMP-010): — deferred to downstream cycle (handoff)
  - Create `lib/Service/ViewQueryBuilder.php` with `applyDateFilter()`, `applyStatusFilter()`, `applySearchFilter()`, `applySorting()`
  - Remove all suppressions; run PHPMD + phpunit
- [~] 7.5 **SymfonyEmailService** (REQ-DECOMP-010): — deferred to downstream cycle (handoff)
  - Extract `resolveRecipients()`, `renderTemplate()`, `attachFiles()`, `sendEmail()` private methods
  - Remove all suppressions; run PHPMD + phpunit
- [~] 7.6 **SoftwareCatalogue/ContactPersonHandler** (Priority 1, 7 suppressions): — deferred to downstream cycle (handoff)
  - Private method decomposition — extract per-phase methods
  - Remove all suppressions; run PHPMD + phpunit

## Phase 8 — Priority 2 files (REQ-DECOMP-011)

- [~] 8.1 **SoftwareCatalogue/OrganizationHandler** (4 suppressions): — deferred to downstream cycle (handoff)
  - Private method extraction for complex methods
  - Remove suppressions; run PHPMD
- [~] 8.2 **ModuleComplianceService** (4 suppressions): — deferred to downstream cycle (handoff)
  - Extract `checkLicenseCompliance()`, `checkSecurityCompliance()`, `checkDocumentationCompliance()`
  - Each returns a compliance result object
  - Remove suppressions; run PHPMD
- [~] 8.3 **AanbodService** (4 suppressions): — deferred to downstream cycle (handoff)
  - Audit and apply private method extraction per decomposition strategy
  - Remove suppressions; run PHPMD
- [~] 8.4 **UserProfileUpdatedEventListener** (4 suppressions): — deferred to downstream cycle (handoff)
  - Create `lib/Service/ProfileFieldMapper.php`
  - Event handling methods delegate to `ProfileFieldMapper`
  - Remove suppressions; run PHPMD
- [~] 8.5 **SoftwareCatalogue/HierarchyHandler** (3 suppressions): — deferred to downstream cycle (handoff)
  - Extract `buildHierarchyTree()`, `resolveParent()`, `updateChildReferences()`
  - Remove suppressions; run PHPMD
- [~] 8.6 **ModuleRegistrationService** (3 suppressions): — deferred to downstream cycle (handoff)
  - Private method extraction; remove suppressions; run PHPMD
- [~] 8.7 **GebruikSyncService** (3 suppressions): — deferred to downstream cycle (handoff)
  - Private method extraction; remove suppressions; run PHPMD
- [~] 8.8 **OpenRegisterEventsDebugListener** (3 suppressions): — deferred to downstream cycle (handoff)
  - Private method extraction; remove suppressions; run PHPMD

## Phase 9 — Priority 3 files (REQ-DECOMP-012)

- [~] 9.1 **Application.php** (2 suppressions): — deferred to downstream cycle (handoff)
  - Create `lib/Service/EventRegistrar.php` — extract event listener registration
  - Create `lib/Service/ServiceRegistrar.php` — extract service registration
  - Boot method MUST drop below 100 lines
  - Remove suppressions; run PHPMD
- [~] 9.2 **ModuleComplianceSubscriber** (2 suppressions): — deferred to downstream cycle (handoff)
  - Private method extraction; remove suppressions; run PHPMD
- [~] 9.3 **GebruikController** (2 suppressions): — deferred to downstream cycle (handoff)
  - Private method extraction; thin controller per ADR-003; remove suppressions
- [~] 9.4 **SoftwareCatalogue/GroupHandler** (1 suppression): — deferred to downstream cycle (handoff)
  - Extract the single oversized method; remove suppression
- [~] 9.5 **ModuleVersionService** (1 suppression): — deferred to downstream cycle (handoff)
  - Split the long method into `fetchVersionData()`, `compareVersions()`, `updateVersionRecord()`
  - Remove suppression
- [~] 9.6 **ViewController** (1 suppression): — deferred to downstream cycle (handoff)
  - Extract oversized method; remove suppression

## Phase 10 — Verification

- [~] 10.1 Run full PHPUnit suite: — deferred to downstream cycle (handoff)
  ```
  docker exec -w /var/www/html/custom_apps/softwarecatalog nextcloud \
    php vendor/bin/phpunit -c phpunit-unit.xml
  ```
  All tests MUST pass. Failures not pre-existing in Phase 0 baseline MUST be fixed.
- [~] 10.2 Run PHPMD across entire `lib/`: — deferred to downstream cycle (handoff)
  ```
  docker exec -w /var/www/html/custom_apps/softwarecatalog nextcloud \
    ./vendor/bin/phpmd lib/ text phpmd.xml 2>&1
  ```
  Zero violations MUST be reported for targeted suppression categories.
- [~] 10.3 Run `composer check:strict` — exit code MUST be 0, zero new warnings — deferred to downstream cycle (handoff)
- [~] 10.4 Count remaining `@SuppressWarnings(PHPMD.*)` in `lib/`: — deferred to downstream cycle (handoff)
  ```
  grep -rc '@SuppressWarnings(PHPMD' lib/ | awk -F: '{sum+=$2} END{print sum}'
  ```
  The count MUST be reduced by at least 145 from the Phase 0 baseline.
- [~] 10.5 Manual smoke test: navigate http://localhost:3000, verify settings, — deferred to downstream cycle (handoff)
      sync, and contact person workflows behave identically to pre-refactor.
- [~] 10.6 Verify all new PHP files have SPDX headers: — deferred to downstream cycle (handoff)
  ```
  grep -rL 'SPDX-License-Identifier' lib/ --include='*.php'
  ```
  Output MUST be empty.
- [~] 10.7 Verify all new classes and public methods have `@spec` PHPDoc tags: — deferred to downstream cycle (handoff)
  - Spot-check at least 5 new handler classes
  - Each MUST have `@spec openspec/changes/method-decomposition/tasks.md#task-N`
- [~] 10.8 Pre-commit checklist (ADR-015): — deferred to downstream cycle (handoff)
  - [~] No `$e->getMessage()` in JSONResponse — use static error strings — deferred to downstream cycle (handoff)
  - [~] All POST/PUT/DELETE controller methods have `IGroupManager::isAdmin()` check — deferred to downstream cycle (handoff)
  - [~] No `\OC::$server` static locators in new handler classes — deferred to downstream cycle (handoff)

## Acceptance Criteria

- [~] All CyclomaticComplexity suppressions eliminated (target: reduced to 0 in decomposed files) — deferred to downstream cycle (handoff)
- [~] All NPathComplexity suppressions eliminated — deferred to downstream cycle (handoff)
- [~] All ExcessiveMethodLength suppressions eliminated — deferred to downstream cycle (handoff)
- [~] ExcessiveClassComplexity removed by handler extraction — deferred to downstream cycle (handoff)
- [~] CouplingBetweenObjects removed by handler extraction and lazy-loading — deferred to downstream cycle (handoff)
- [~] TooManyMethods removed by handler extraction — deferred to downstream cycle (handoff)
- [~] No new PHPMD violations introduced in any file — deferred to downstream cycle (handoff)
- [~] All existing tests continue to pass — deferred to downstream cycle (handoff)
- [~] No behavioral changes (pure refactoring — public API unchanged) — deferred to downstream cycle (handoff)
- [~] `composer check:strict` passes with zero violations — deferred to downstream cycle (handoff)
- [~] Total suppression count in `lib/` reduced by ≥145 — deferred to downstream cycle (handoff)
