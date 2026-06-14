# Design — method-decomposition

> This change is a **pure PHP refactoring**. No new OpenRegister schemas are
> introduced or modified. The seed-data requirement (ADR-001) does NOT apply —
> there are no schema introductions or modifications in this change.

## Reuse Analysis

Per ADR-001, any capability must check for overlap with existing OpenRegister
services before building new code. This change adds no net-new capability;
it reorganises existing code using patterns already present in the codebase.

| Capability | Reuse from | Notes |
|------------|-----------|-------|
| Handler pattern | `lib/Service/SoftwareCatalogue/ContactPersonHandler`, `OrganizationHandler`, `HierarchyHandler`, `GroupHandler` | Existing pattern. New handlers follow the same constructor-DI + delegation contract. |
| Progress tracking | `lib/Service/ProgressTracker.php` | Already exists. `SoftwareCatalogueService` must delegate all progress calls to it instead of inlining them. |
| Constructor DI | Nextcloud DI container | All new handler classes are wired via constructor injection. No `\OC::$server` or static locators per ADR-003. |
| Config access | `IAppConfig` | Settings domain handlers continue to use `IAppConfig`. No new config mechanism introduced. |
| PHPMD tooling | `./vendor/bin/phpmd` + `phpmd.xml` | The existing rule set is the target. No new tooling needed. |

### What we deliberately do NOT build

- **Custom PHPMD runner** — `composer check:strict` already runs PHPMD via
  `phpmd.xml`. No tooling additions required.
- **New ObjectService wrappers** — this change does not touch data access. All
  existing `ObjectService::findObject()` / `saveObject()` call sites remain in
  place, moved wholesale into their new handler classes.
- **New public API endpoints** — pure internal refactoring. No routes change.
- **New OpenRegister schemas** — no `lib/Settings/` register template changes.

### Deduplication check

Searched `lib/Service/SoftwareCatalogue/` for existing handlers to avoid
re-creating them:

| Existing file | Suppressions | Action |
|--------------|-------------|--------|
| `lib/Service/SoftwareCatalogue/ContactPersonHandler.php` | 7 | Decompose in place (private method extraction) |
| `lib/Service/SoftwareCatalogue/OrganizationHandler.php` | 4 | Decompose in place |
| `lib/Service/SoftwareCatalogue/HierarchyHandler.php` | 3 | Decompose in place |
| `lib/Service/SoftwareCatalogue/GroupHandler.php` | 1 | Decompose in place |
| `lib/Service/ProgressTracker.php` | 0 | Reuse — isolate progress calls from business logic |

No new capability duplicates existing OpenRegister platform services. New
handler classes (SyncHandler, ModuleRegistrationHandler, etc.) are
app-internal domain logic with no equivalent in OpenRegister.

## Architecture

### The Handler Pattern

The existing `lib/Service/SoftwareCatalogue/` subdirectory establishes the
pattern. New handlers follow the same contract:

```php
// New handler class (example: SyncHandler)
class SyncHandler
{
    // @spec openspec/changes/method-decomposition/tasks.md#task-1
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly SoftwareCatalogueService $catalogueService,
    ) {}

    public function handle(array $config): array
    {
        $validated = $this->validateSyncConfig($config);
        $data      = $this->prepareSyncData($validated);
        $result    = $this->executeSyncBatch($data);
        return $this->buildSyncResponse($result);
    }

    private function validateSyncConfig(array $config): array { /* CC<4 */ }
    private function prepareSyncData(array $config): array    { /* CC<4 */ }
    private function executeSyncBatch(array $data): array     { /* CC<5 */ }
    private function buildSyncResponse(array $result): array  { /* CC<3 */ }
}

// Original controller becomes thin
class SettingsController
{
    public function __construct(
        private readonly SyncHandler $syncHandler,
        // fewer deps — SyncHandler owns ObjectService + CatalogueService
    ) {}

    public function syncSoftwareCatalogue(): JSONResponse
    {
        // <10 lines per ADR-003
        return new JSONResponse($this->syncHandler->handle($this->request->getParams()));
    }
}
```

### Decomposition Strategies

#### Strategy A: Private method extraction (CyclomaticComplexity / NPathComplexity / ExcessiveMethodLength)

For methods that are long or branchy but do not warrant a new class:

```php
// Before: 120-line method, CC=14, NPath=340
public function handleOrganizationEvent(Event $event): void
{
    if ($event->getType() === 'create') {
        if ($event->getOrganization() !== null) {
            // 40 lines of create logic
            if ($someCondition) { /* nested */ }
        }
    } elseif ($event->getType() === 'update') {
        // 40 lines of update logic
    } else {
        // 30 lines of delete logic
    }
}

// After: orchestrator + private helpers, CC=3, NPath<50 per method
public function handleOrganizationEvent(Event $event): void
{
    match($event->getType()) {
        'create' => $this->handleOrganizationCreate($event),
        'update' => $this->handleOrganizationUpdate($event),
        default  => $this->handleOrganizationDelete($event),
    };
}

private function handleOrganizationCreate(Event $event): void  { /* CC<5 */ }
private function handleOrganizationUpdate(Event $event): void  { /* CC<5 */ }
private function handleOrganizationDelete(Event $event): void  { /* CC<5 */ }
```

#### Strategy B: Handler class extraction (ExcessiveClassLength / ExcessiveClassComplexity / TooManyMethods)

For oversized classes, extract cohesive method groups into dedicated handlers:

```php
// Before: SettingsController with 23 suppressions, 10+ constructor deps

// After: handlers own their deps
class SettingsController {
    public function __construct(
        private readonly SyncHandler $syncHandler,
        private readonly ModuleRegistrationHandler $moduleHandler,
        // 3-4 remaining deps instead of 10+
    ) {}
}

class SyncHandler {
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly SoftwareCatalogueService $catalogueService,
    ) {}
}

class ModuleRegistrationHandler {
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly ModuleRegistrationService $moduleService,
    ) {}
}
```

#### Strategy C: Guard clauses (CyclomaticComplexity)

Replace nested conditionals with early returns to eliminate execution paths:

```php
// Before: deeply nested, CC=12
public function handleModuleCreated(Event $event): void
{
    if ($event->getModule() !== null) {
        if ($this->isValidType($event->getModule())) {
            if ($this->schemaExists($event->getModule())) {
                // ... main logic 80 lines
            }
        }
    }
}

// After: guard clauses + delegation, CC=4
public function handleModuleCreated(Event $event): void
{
    if (!$this->validateModuleEvent($event)) {
        return;
    }
    $this->processModuleByType($event->getModule());
}

private function validateModuleEvent(Event $event): bool
{
    return $event->getModule() !== null
        && $this->isValidType($event->getModule())
        && $this->schemaExists($event->getModule());
}

private function processModuleByType(Module $module): void { /* CC<5 */ }
```

#### Strategy D: Dependency grouping (CouplingBetweenObjects)

For classes with >13 constructor parameters, group shared infrastructure:

```php
// ArchiMateContext carries shared infrastructure deps
class ArchiMateContext
{
    public function __construct(
        public readonly ObjectService $objectService,
        public readonly SettingsService $settingsService,
        public readonly LoggerInterface $logger,
    ) {}
}

// Services inject the context instead of 3 individual deps
class ArchiMateImportService
{
    public function __construct(
        private readonly ArchiMateContext $context,
        // domain-specific deps only
    ) {}
}
```

## Handler File Layout

New handler files follow the existing `SoftwareCatalogue/` naming convention.
All new files MUST include an SPDX header and `@spec` PHPDoc tags per ADR-003
and ADR-015.

```
lib/
  Controller/
    Settings/
      SyncHandler.php                      (NEW — REQ-DECOMP-001)
      ModuleRegistrationHandler.php        (NEW — REQ-DECOMP-001)
  Service/
    SoftwareCatalogue/
      ApiClient.php                        (NEW — REQ-DECOMP-004)
      DataMapper.php                       (NEW — REQ-DECOMP-004)
      ConflictResolver.php                 (NEW — REQ-DECOMP-004)
    Settings/
      SyncSettingsHandler.php              (NEW — REQ-DECOMP-005)
      ModuleSettingsHandler.php            (NEW — REQ-DECOMP-005)
      OrganizationSettingsHandler.php      (NEW — REQ-DECOMP-005)
    ArchiMate/
      ArchiMateContext.php                 (NEW — REQ-DECOMP-006)
    AangebodenGebruik/
      GebruikStatusHandler.php             (NEW — REQ-DECOMP-009)
      GebruikBulkHandler.php               (NEW — REQ-DECOMP-009)
      StatusTransitionValidator.php        (NEW — REQ-DECOMP-009)
    Contactpersoon/
      ContactValidator.php                 (NEW — REQ-DECOMP-008)
    ModuleEventProcessor.php               (NEW — REQ-DECOMP-002)
    ViewQueryBuilder.php                   (NEW — REQ-DECOMP-010)
    ProfileFieldMapper.php                 (NEW — REQ-DECOMP-011)
    EventRegistrar.php                     (NEW — REQ-DECOMP-012)
    ServiceRegistrar.php                   (NEW — REQ-DECOMP-012)
```

### Decomposition sequencing

Order matters — downstream handlers must not depend on upstream classes that
are still oversized:

1. `SettingsService` (REQ-DECOMP-005) — decompose first; ArchiMateContext
   depends on its post-refactor facade.
2. `SoftwareCatalogueService` (REQ-DECOMP-004) — before decomposing its callers.
3. `SettingsController` (REQ-DECOMP-001) — after SettingsService is clean.
4. `ArchiMate*` services (REQ-DECOMP-006) — after SettingsService.
5. All remaining Priority 1 files independently.
6. Priority 2 and 3 files last.

## Migration Risk Surface

| Risk | Mitigation |
|------|-----------|
| Handler extraction silently changes behavior | Unit tests must pass before and after each extraction. No logic changes — only code movement. |
| New handler class introduces new PHPMD violations | Run `./vendor/bin/phpmd {file} text phpmd.xml` per new file during implementation. Fix before committing. |
| Constructor DI fails in container | Nextcloud auto-wires by type when all deps are type-hinted. Verify with `php -l` + DI smoke test after each class. |
| Moving private methods changes semantics | Extract one method at a time. Run `php -l` + `phpunit --filter ClassName` per step. |
| ArchiMateContext groups wrong deps | Analyze each service individually before grouping. Only truly shared infrastructure deps go into context. |
| Coupling count stays high after extraction | Track dep count before/after per class. If still >13 after handler extraction, apply `ContainerInterface` lazy-loading for edge-case deps. |
| `@spec` PHPDoc tags missing on new files | ADR-003 requires `@spec openspec/changes/method-decomposition/tasks.md#task-N` on every new class and public method. Apply during file creation, not after. |
| SettingsService facade breaks ArchiMate services | Sequence: decompose SettingsService first. ArchiMateContext uses the facade, not the original class. |

## Open Design Questions

1. **Q1 — Settings handler subdirectory convention.** Should settings handlers
   live at `lib/Service/Settings/` (new subdirectory, mirroring the controller's
   `lib/Controller/Settings/`) or as peer files in `lib/Service/`? Recommend:
   `lib/Service/Settings/` for consistency with the `SoftwareCatalogue/`
   subdirectory pattern.

2. **Q2 — ArchiMateContext scope.** Should `ArchiMateContext` carry
   `SettingsService`, `ObjectService`, and `LoggerInterface` — or only the
   first two? Logger is used in every service. Recommend: include logger to
   maximally reduce constructor coupling for all three ArchiMate services.

3. **Q3 — ContactValidator placement.** Peer service at
   `lib/Service/ContactValidator.php` vs. subdirectory at
   `lib/Service/Contactpersoon/ContactValidator.php`? Recommend: subdirectory
   for consistency with the `SoftwareCatalogue/` pattern.

4. **Q4 — StatusTransitionValidator sharing.** Both
   `AangebodenGebruikController` and `AangebodenGebruikService` have status
   transition logic. Should `StatusTransitionValidator` be shared between
   layers? Recommend: place in `lib/Service/AangebodenGebruik/` and inject into
   both controller and service (service receives it via DI; controller delegates
   to service, which uses the validator).

5. **Q5 — ModuleEventProcessor as service vs. helper.** Should
   `ModuleEventProcessor` be a standalone class injected via DI, or a
   `final class` instantiated inline by the event listener? Recommend: DI
   injection for testability (mirrors handler pattern).
