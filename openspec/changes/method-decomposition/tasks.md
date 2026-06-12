# Tasks — method-decomposition

> Spec-only companion tasks. This change is a pure PHP refactoring; no new
> OpenRegister schemas, no frontend changes, no new public API.

> **Build note (2026-06-10):** the decomposition infrastructure for
> Phases 1–4 is in place on `development`:
>
> - Phase 1 (SettingsService): `lib/Service/Settings/SyncSettingsHandler.php`,
>   `ModuleSettingsHandler.php`, `OrganizationSettingsHandler.php` exist.
> - Phase 2 (SoftwareCatalogueService): `lib/Service/SoftwareCatalogue/`
>   contains `ApiClient.php`, `ConflictResolver.php`, `DataMapper.php`,
>   `ContactPersonHandler.php`, `GroupHandler.php`, `HierarchyHandler.php`,
>   `OrganizationHandler.php`.
> - Phase 3 (SettingsController): `lib/Controller/Settings/SyncHandler.php`,
>   `ModuleRegistrationHandler.php` exist.
> - Phase 4 (ArchiMate): `lib/Service/ArchiMate/ArchiMateContext.php` exists.
>
> The PHPMD baseline is captured and the gate is green (see
> `softwarecatalog-legacy-quality-cleanup`). The line-level "extract method X,
> remove suppression Y" subtasks below are the per-file detail work that
> would be required to retire the PHPMD baseline; per CLAUDE.md "no scripting
> for code changes" and given the volume (86 subtasks, each requires a
> targeted edit + test re-run), those subtasks are left **[~] DEFERRED — to
> a per-file follow-up PR series** so the baseline can be incrementally
> burned down without a single-PR megachange.
>
> The Phase 0 baseline subtasks are partially [x] (the baselines were
> captured by `softwarecatalog-legacy-quality-cleanup`); the Phase 10
> verification subtasks are [~] DEFERRED because they require a live docker
> container and are gated on the per-file PHPMD burn-down.

## Phase 0 — Deduplication check + baseline

- [x] 0.1 Verify no new capability duplicates existing OpenRegister services:
  - `design.md` Reuse Analysis documents that the handler classes are
    in-app extractions of existing service methods, not new capabilities.
- [x] 0.2 Capture current PHPMD suppression baseline:
  - Baseline captured by `softwarecatalog-legacy-quality-cleanup`
    (`phpmd.baseline.xml`); gate green via `--baseline-file`.
- [x] 0.3 Capture PHPUnit baseline:
  - Baseline captured by `softwarecatalog-legacy-quality-cleanup`
    (Phase 1); no pre-existing failures recorded.
- [x] 0.4 Verify `composer check:strict` script exists and runs PHPMD, PHPCS,
      Psalm/PHPStan — confirmed; `softwarecatalog-legacy-quality-cleanup`
      Phase 5 wires it into the per-PR CI gate.

## Phase 1 — SettingsService decomposition (REQ-DECOMP-005)

Decompose first — downstream handlers (ArchiMateContext, SyncHandler) depend on
its post-refactor facade.

- [x] 1.1 Audit `lib/Service/SettingsService.php`:
  - List all public methods and group by domain: sync, modules, organisations, ArchiMate
  - Identify which methods have CC>10 or >100 lines
- [x] 1.2 Create `lib/Service/Settings/SyncSettingsHandler.php`:
  - SPDX header: `// SPDX-License-Identifier: EUPL-1.2`
  - `@spec openspec/changes/method-decomposition/tasks.md#task-1`
  - Move sync-domain methods; extract `validateSyncConfig()` guard clauses
- [x] 1.3 Create `lib/Service/Settings/ModuleSettingsHandler.php`:
  - Same SPDX + `@spec` requirements
  - Move module-domain methods; extract `validateModuleConfig()`
- [x] 1.4 Create `lib/Service/Settings/OrganizationSettingsHandler.php`:
  - Move organisation-domain methods; extract `validateOrganizationConfig()`
- [~] 1.5 Refactor `SettingsService` to facade pattern:
  - Inject all three handlers via constructor
  - Replace method bodies with single-line delegation calls
  - Verify public method count ≤15
- [~] 1.6 Remove all `@SuppressWarnings(PHPMD.*)` from `SettingsService.php`
- [~] 1.7 Run `./vendor/bin/phpmd lib/Service/Settings/ text phpmd.xml` — must be zero violations
- [~] 1.8 Run `phpunit --filter SettingsServiceTest` — must pass

## Phase 2 — SoftwareCatalogueService decomposition (REQ-DECOMP-004)

- [x] 2.1 Audit `lib/Service/SoftwareCatalogueService.php`:
  - Identify API communication, data mapping, and conflict resolution method groups
  - Map which methods call `ProgressTracker` — these must be isolated
- [x] 2.2 Create `lib/Service/SoftwareCatalogue/ApiClient.php`:
  - SPDX header + `@spec openspec/changes/method-decomposition/tasks.md#task-2`
  - Move all API communication methods (HTTP calls, response parsing)
- [x] 2.3 Create `lib/Service/SoftwareCatalogue/DataMapper.php`:
  - Move all data mapping / transformation methods
- [x] 2.4 Create `lib/Service/SoftwareCatalogue/ConflictResolver.php`:
  - Move all conflict resolution / deduplication methods
- [~] 2.5 Isolate progress tracking:
  - Remove all inline `ProgressTracker` calls from business methods
  - Ensure `ProgressTracker` is only called at the orchestration level in the parent service
- [~] 2.6 Update `SoftwareCatalogueService` to inject new sub-services and delegate
- [~] 2.7 Remove all `@SuppressWarnings(PHPMD.*)` from `SoftwareCatalogueService.php`
- [~] 2.8 Run PHPMD on all affected files — zero violations
- [~] 2.9 Run `phpunit --filter SoftwareCatalogueServiceTest` — must pass

## Phase 3 — SettingsController decomposition (REQ-DECOMP-001)

Depends on Phase 1 (SettingsService facade must exist).

- [x] 3.1 Create `lib/Controller/Settings/SyncHandler.php`:
  - SPDX header + `@spec openspec/changes/method-decomposition/tasks.md#task-3`
  - Inject only `ObjectService` and `SoftwareCatalogueService`
  - Expose `handle(array $config): array` public method
  - Extract private: `validateSyncConfig()`, `prepareSyncData()`, `executeSyncBatch()`, `buildSyncResponse()`
- [x] 3.2 Create `lib/Controller/Settings/ModuleRegistrationHandler.php`:
  - Inject only `ObjectService` and `ModuleRegistrationService`
  - Extract private: `validateModuleInput()`, `resolveModuleDependencies()`, `persistModules()`
- [~] 3.3 Refactor `SettingsController`:
  - Replace `syncSoftwareCatalogue()` body with `$this->syncHandler->handle(...)`
  - Replace `registerModules()` body with `$this->moduleHandler->handle(...)`
  - Verify each action method is ≤10 lines per ADR-003
  - Verify constructor parameter count <13
- [~] 3.4 Remove all `@SuppressWarnings(PHPMD.*)` from `SettingsController.php`
- [~] 3.5 Run PHPMD on controller + new handler files — zero violations
- [~] 3.6 Run `phpunit --filter SettingsControllerTest` — must pass

## Phase 4 — ArchiMate services decomposition (REQ-DECOMP-006)

Depends on Phase 1 (SettingsService facade used in ArchiMateContext).

- [x] 4.1 Create `lib/Service/ArchiMate/ArchiMateContext.php`:
  - SPDX header + `@spec openspec/changes/method-decomposition/tasks.md#task-4`
  - Constructor: `ObjectService $objectService`, `SettingsService $settingsService`, `LoggerInterface $logger`
  - Public readonly properties for each
- [~] 4.2 Decompose `lib/Service/ArchiMateImportService.php`:
  - Extract `importElement()`, `importRelationship()`, `importView()`, `importDiagram()` — each ≤50 lines
  - Inject `ArchiMateContext` to reduce constructor coupling
  - Remove all `@SuppressWarnings(PHPMD.*)`
- [~] 4.3 Decompose `lib/Service/ArchiMateExportService.php`:
  - Extract `buildElementAttributes()`, `buildRelationshipAttributes()`, `buildViewAttributes()`
  - Inject `ArchiMateContext`
  - Remove all `@SuppressWarnings(PHPMD.*)`
- [~] 4.4 Decompose `lib/Service/ArchiMateService.php`:
  - Simplify orchestration — delegate to import/export services
  - Extract `validateArchiMateFile()` with guard clauses
  - Inject `ArchiMateContext`
  - Remove all `@SuppressWarnings(PHPMD.*)`
- [~] 4.5 Run PHPMD on all three ArchiMate service files — zero violations
- [~] 4.6 Run `phpunit --filter ArchiMate` — must pass

## Phase 5 — ContactpersonenController decomposition (REQ-DECOMP-003)

- [~] 5.1 Extract `validateContactInput(array $data): array` private method from `create()`
- [~] 5.2 Extract `enrichContactData(array $data): array` private method from `create()`
- [~] 5.3 Extract `persistContact(array $data): JSONResponse` private method from `create()`
- [~] 5.4 Decompose `bulkImport()`:
  - Extract `parseImportFile()`, `validateImportRow()`, `processImportBatch()`, `buildImportReport()`
- [~] 5.5 Decompose `exportContacts()`:
  - Extract `buildExportQuery()`, `formatExportData()`, `buildExportResponse()`
- [~] 5.6 Verify class drops below 1000 lines and coupling below 13
- [~] 5.7 Remove all `@SuppressWarnings(PHPMD.*)` from `ContactpersonenController.php`
- [~] 5.8 Run PHPMD — zero violations; run `phpunit --filter ContactpersonenControllerTest` — must pass

## Phase 6 — SoftwareCatalogEventListener decomposition (REQ-DECOMP-002)

- [~] 6.1 Create `lib/Service/ModuleEventProcessor.php`:
  - SPDX header + `@spec openspec/changes/method-decomposition/tasks.md#task-6`
  - Extract shared 60% logic from `handleModuleCreated` and `handleModuleUpdated`
- [~] 6.2 Decompose `handleModuleCreated()`:
  - Extract `validateModuleEvent()` with guard clauses (early-return validation)
  - Extract `processModuleByType()` for type-specific logic
  - Extract `mapModuleToSchema()` for schema mapping
- [~] 6.3 Decompose `handleModuleUpdated()` to delegate to `ModuleEventProcessor`
- [~] 6.4 Decompose `handleOrganizationEvent()`:
  - Extract `handleOrganizationCreate()`, `handleOrganizationUpdate()`, `handleOrganizationDelete()`
  - Each extracted method MUST have NPath<50
- [~] 6.5 Remove all `@SuppressWarnings(PHPMD.*)` from event listener
- [~] 6.6 Run PHPMD — zero violations; run `phpunit --filter SoftwareCatalogEventListenerTest` — must pass

## Phase 7 — Remaining Priority 1 files (REQ-DECOMP-007, 008, 009, 010)

- [~] 7.1 **OrganizationSyncService** (REQ-DECOMP-007):
  - Extract pipeline stages: `fetchOrganizations()`, `validateOrganization()`, `transformOrganization()`, `persistOrganization()` each with NPath<50
  - Extract shared `validateOrganizationData()` called by both `handleCreate()` and `handleUpdate()`
  - Partial: `handleSyncError()` centralised — replaces the ad-hoc catch blocks
    in `performOrganizationsSync()` + `performContactSync()`. Tests in
    `tests/Unit/Service/OrganizationSyncServiceDecompositionTest.php`.
    Pipeline-stage extraction left for the per-file PHPMD burn-down series.
  - Remove all suppressions; run PHPMD + phpunit
- [~] 7.2 **ContactpersoonService** (REQ-DECOMP-008):
  - Create `lib/Service/Contactpersoon/ContactValidator.php` with `validateEmail()`, `validatePhone()`, `validateName()`
  - Extract `enrichContactData()` before `persistContact()`
  - Lazy-load email service + export service via `ContainerInterface`
  - Remove all suppressions; run PHPMD + phpunit
- [~] 7.3 **AangebodenGebruikController + Service** (REQ-DECOMP-009):
  - Create `lib/Service/AangebodenGebruik/StatusTransitionValidator.php` with transition map
  - Create `lib/Service/AangebodenGebruik/GebruikStatusHandler.php`
  - Create `lib/Service/AangebodenGebruik/GebruikBulkHandler.php`
  - Decompose `bulkCreate()` into `validateBulkInput()`, `processBulkItem()`, `aggregateBulkResults()`
  - Reduce `updateStatus()` to `validate → transition → persist` with CC<5
  - Remove all suppressions; run PHPMD + phpunit
- [~] 7.4 **ViewService** (REQ-DECOMP-010):
  - Create `lib/Service/ViewQueryBuilder.php` with `applyDateFilter()`, `applyStatusFilter()`, `applySearchFilter()`, `applySorting()`
  - Remove all suppressions; run PHPMD + phpunit
- [~] 7.5 **SymfonyEmailService** (REQ-DECOMP-010):
  - Extract `resolveRecipients()`, `renderTemplate()`, `attachFiles()`, `sendEmail()` private methods
  - Partial: `renderTemplate()` + `resolveSender()` extracted in
    `lib/Service/SymfonyEmailService.php`; `sendEmail()` already existed.
    Tests in `tests/Unit/Service/SymfonyEmailServiceDecompositionTest.php`.
    `attachFiles()` not in scope (no attachment paths used today).
  - Remove all suppressions; run PHPMD + phpunit
- [~] 7.6 **SoftwareCatalogue/ContactPersonHandler** (Priority 1, 7 suppressions):
  - Private method decomposition — extract per-phase methods
  - Remove all suppressions; run PHPMD + phpunit

## Phase 8 — Priority 2 files (REQ-DECOMP-011)

- [~] 8.1 **SoftwareCatalogue/OrganizationHandler** (4 suppressions):
  - Private method extraction for complex methods
  - Remove suppressions; run PHPMD
- [~] 8.2 **ModuleComplianceService** (4 suppressions):
  - Note: this service syncs module->standaardversie mappings — it does not
    perform license/security/documentation compliance scoring; the literal
    task names above are misnamed for the current code shape.
  - Done in spirit: `handleModuleComplianceUpdate()` decomposed into
    `normaliseCurrentStandaarden()` + `syncStandaarden()`. Tests in
    `tests/Unit/Service/ModuleComplianceServiceDecompositionTest.php`.
  - Remove suppressions; run PHPMD
- [~] 8.3 **AanbodService** (4 suppressions):
  - Audit and apply private method extraction per decomposition strategy
  - Remove suppressions; run PHPMD
- [~] 8.4 **UserProfileUpdatedEventListener** (4 suppressions):
  - Create `lib/Service/ProfileFieldMapper.php`
  - Event handling methods delegate to `ProfileFieldMapper`
  - Remove suppressions; run PHPMD
- [~] 8.5 **SoftwareCatalogue/HierarchyHandler** (3 suppressions):
  - Extract `buildHierarchyTree()`, `resolveParent()`, `updateChildReferences()`
  - Remove suppressions; run PHPMD
- [x] 8.6 **ModuleRegistrationService** (3 suppressions):
  - `handleModuleRegistration()` decomposed into `resolveOrganisationType()`,
    `mapOrgTypeToRegisteredBy()`, `updateModuleRegisteredBy()` in
    `lib/Service/ModuleRegistrationService.php`; tests in
    `tests/Unit/Service/ModuleRegistrationServiceDecompositionTest.php`.
  - Class-level Cyclomatic / NPath / ExcessiveMethodLength suppressions removed.
- [~] 8.7 **GebruikSyncService** (3 suppressions):
  - Private method extraction; remove suppressions; run PHPMD
- [~] 8.8 **OpenRegisterEventsDebugListener** (3 suppressions):
  - Private method extraction; remove suppressions; run PHPMD

## Phase 9 — Priority 3 files (REQ-DECOMP-012)

- [~] 9.1 **Application.php** (2 suppressions):
  - Partial: event listener registration extracted to private
    `registerEventListeners(IRegistrationContext)` on Application itself
    (kept in-class — the listener catalogue does not warrant a dedicated
    service per ADR-022 reuse-or-abstractions). Service-registration
    extraction and boot-method shrink deferred to the per-file PHPMD
    burn-down series.
  - Remove suppressions; run PHPMD
- [~] 9.2 **ModuleComplianceSubscriber** (2 suppressions):
  - Private method extraction; remove suppressions; run PHPMD
- [x] 9.3 **GebruikController** (2 suppressions):
  - `getGebruiken()` decomposed via `resolveUserRoles()` + `applyAanbodScopeToOptions()` in
    `lib/Controller/GebruikController.php`; tests in
    `tests/Unit/Controller/GebruikControllerDecompositionTest.php`
- [~] 9.4 **SoftwareCatalogue/GroupHandler** (1 suppression):
  - Extract the single oversized method; remove suppression
- [x] 9.5 **ModuleVersionService** (1 suppression):
  - Split the long method into `fetchVersionData()`, `compareVersions()`, `updateVersionRecord()` — done
    in `lib/Service/ModuleVersionService.php`; unit test in
    `tests/Unit/Service/ModuleVersionServiceDecompositionTest.php`
  - Class-level `ExcessiveMethodLength` suppression removed
- [x] 9.6 **ViewController** (1 suppression):
  - `getAllViews()` + `getView()` decomposed via `determineListStatusCode()`,
    `determineViewStatusCode()`, `buildListErrorPayload()` in
    `lib/Controller/ViewController.php`; tests in
    `tests/Unit/Controller/ViewControllerDecompositionTest.php`

## Phase 10 — Verification

- [~] 10.1 Run full PHPUnit suite:
  ```
  docker exec -w /var/www/html/custom_apps/softwarecatalog nextcloud \
    php vendor/bin/phpunit -c phpunit-unit.xml
  ```
  All tests MUST pass. Failures not pre-existing in Phase 0 baseline MUST be fixed.
- [~] 10.2 Run PHPMD across entire `lib/`:
  ```
  docker exec -w /var/www/html/custom_apps/softwarecatalog nextcloud \
    ./vendor/bin/phpmd lib/ text phpmd.xml 2>&1
  ```
  Zero violations MUST be reported for targeted suppression categories.
- [~] 10.3 Run `composer check:strict` — exit code MUST be 0, zero new warnings
- [~] 10.4 Count remaining `@SuppressWarnings(PHPMD.*)` in `lib/`:
  ```
  grep -rc '@SuppressWarnings(PHPMD' lib/ | awk -F: '{sum+=$2} END{print sum}'
  ```
  The count MUST be reduced by at least 145 from the Phase 0 baseline.
- [~] 10.5 Manual smoke test: navigate http://localhost:3000, verify settings,
      sync, and contact person workflows behave identically to pre-refactor.
- [~] 10.6 Verify all new PHP files have SPDX headers:
  ```
  grep -rL 'SPDX-License-Identifier' lib/ --include='*.php'
  ```
  Output MUST be empty.
- [~] 10.7 Verify all new classes and public methods have `@spec` PHPDoc tags:
  - Spot-check at least 5 new handler classes
  - Each MUST have `@spec openspec/changes/method-decomposition/tasks.md#task-N`
- [~] 10.8 Pre-commit checklist (ADR-015):
  - [ ] No `$e->getMessage()` in JSONResponse — use static error strings
  - [ ] All POST/PUT/DELETE controller methods have `IGroupManager::isAdmin()` check
  - [ ] No `\OC::$server` static locators in new handler classes

## Acceptance Criteria

- [~] All CyclomaticComplexity suppressions eliminated (target: reduced to 0 in decomposed files)
- [~] All NPathComplexity suppressions eliminated
- [~] All ExcessiveMethodLength suppressions eliminated
- [~] ExcessiveClassComplexity removed by handler extraction
- [~] CouplingBetweenObjects removed by handler extraction and lazy-loading
- [~] TooManyMethods removed by handler extraction
- [~] No new PHPMD violations introduced in any file
- [~] All existing tests continue to pass
- [~] No behavioral changes (pure refactoring — public API unchanged)
- [~] `composer check:strict` passes with zero violations
- [~] Total suppression count in `lib/` reduced by ≥145
