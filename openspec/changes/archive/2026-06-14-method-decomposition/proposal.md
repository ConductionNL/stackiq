# Method Decomposition — SoftwareCatalog

## Why

SoftwareCatalog has accumulated 326 `@SuppressWarnings(PHPMD.*)` annotations
across its `lib/` directory. Of these, **145 relate to structural complexity**:
CyclomaticComplexity (31), NPathComplexity (26), ExcessiveMethodLength (35),
ExcessiveClassComplexity (19), ExcessiveClassLength (14),
CouplingBetweenObjects (12), and TooManyMethods (8).

Each annotation suppresses a real quality signal: a method or class that is too
large or too complex to safely review, test, or modify. Suppressions are
bypassed gates — `composer check:strict` enforces strict PHPMD thresholds, and
every suppression is a rule exemption that hides real complexity debt.

The immediate cost is operational: PR reviewers cannot trust static analysis
output for affected files, making regression risk invisible.

## What Changes

Decompose the 29 files that carry these 145 suppressions using three mechanical
strategies aligned to ADR-003's Controller → Service layer contract:

1. **Private method extraction** — break long methods into named pipeline
   stages (`validate → prepare → process → respond`) each ≤50 lines and CC<10.
2. **Handler class extraction** — for oversized classes, move cohesive method
   groups to dedicated `{Class}/{HandlerName}Handler.php` files, following the
   existing `lib/Service/SoftwareCatalogue/` handler pattern already in the
   codebase (ContactPersonHandler, OrganizationHandler, HierarchyHandler,
   GroupHandler).
3. **Dependency grouping** — reduce constructor coupling by injecting handler
   classes (which bundle their own dependencies) or lazy-loading via
   `ContainerInterface` for rarely-used dependencies.

All changes are **pure refactoring**: no new public API, no behavioral change,
no schema additions, no frontend changes.

### Priority 1 — Highest complexity (5+ suppressions, 15 files)

| File | Suppressions | Primary strategy |
|------|-------------|-----------------|
| `lib/Controller/SettingsController.php` | 23 | Handler extraction: SyncHandler, ModuleRegistrationHandler |
| `lib/Service/SettingsService.php` | 23 | Handler extraction: SyncSettingsHandler, ModuleSettingsHandler, OrganizationSettingsHandler |
| `lib/Service/SoftwareCatalogueService.php` | 20 | Sub-service extraction: ApiClient, DataMapper, ConflictResolver |
| `lib/Service/ArchiMateService.php` | 18 | Private method extraction + ArchiMateContext value object |
| `lib/Service/ArchiMateImportService.php` | 16 | Element-type handler methods |
| `lib/Service/ArchiMateExportService.php` | 16 | Attribute builder methods |
| `lib/EventListener/SoftwareCatalogEventListener.php` | 11 | Guard clauses + ModuleEventProcessor helper |
| `lib/Controller/ContactpersonenController.php` | 11 | Handler extraction: validate/enrich/persist phases |
| `lib/Service/SoftwareCatalogue/ContactPersonHandler.php` | 7 | Private method decomposition |
| `lib/Service/OrganizationSyncService.php` | 7 | Pipeline stage methods |
| `lib/Service/ContactpersoonService.php` | 6 | ContactValidator helper |
| `lib/Controller/AangebodenGebruikController.php` | 6 | StatusTransitionValidator |
| `lib/Service/ViewService.php` | 5 | ViewQueryBuilder helper |
| `lib/Service/SymfonyEmailService.php` | 5 | Private builder methods |
| `lib/Service/AangebodenGebruikService.php` | 5 | GebruikStatusHandler, GebruikBulkHandler |

### Priority 2 — Medium complexity (3–4 suppressions, 8 files)

`lib/Service/SoftwareCatalogue/OrganizationHandler.php` (4),
`lib/Service/ModuleComplianceService.php` (4),
`lib/Service/AanbodService.php` (4),
`lib/EventListener/UserProfileUpdatedEventListener.php` (4),
`lib/Service/SoftwareCatalogue/HierarchyHandler.php` (3),
`lib/Service/ModuleRegistrationService.php` (3),
`lib/Service/GebruikSyncService.php` (3),
`lib/EventListener/OpenRegisterEventsDebugListener.php` (3).

### Priority 3 — Low complexity (1–2 suppressions, 6 files)

`lib/EventListener/ModuleComplianceSubscriber.php` (2),
`lib/Controller/GebruikController.php` (2),
`lib/AppInfo/Application.php` (2),
`lib/Service/SoftwareCatalogue/GroupHandler.php` (1),
`lib/Service/ModuleVersionService.php` (1),
`lib/Controller/ViewController.php` (1).

## Problem

The suppressions are concentrated in controllers and services that grew
organically without a size budget. The root causes are:

- **Sync methods** that mix API fetch, data mapping, validation, conflict
  resolution, and progress tracking in a single method body.
- **Controller methods** that validate, enrich, and persist instead of
  delegating to services (violating ADR-003's "thin controller" contract).
- **ArchiMate services** with switch-heavy XML parsers covering all element and
  relationship types inline.
- **Settings service** that manages four independent configuration domains
  (sync, modules, organisations, ArchiMate) in a single class.

Each PHPMD category identifies a distinct problem:

| Category | Threshold | Meaning |
|----------|-----------|---------|
| `CyclomaticComplexity` | >10 branches | Method is too branchy to unit-test exhaustively |
| `NPathComplexity` | >200 paths | Method has too many execution paths to reason about |
| `ExcessiveMethodLength` | >100 lines | Method is too long to review as a unit |
| `CouplingBetweenObjects` | >13 deps | Class knows too many other classes |
| `ExcessiveClassLength` | >1000 lines | Class scope is too broad |
| `ExcessiveClassComplexity` | — | Class-level aggregate complexity too high |
| `TooManyMethods` | — | Class responsibilities too dispersed |

## Proposed Solution

Apply the **Handler pattern** that already exists in
`lib/Service/SoftwareCatalogue/`: create dedicated handler classes that receive
only their required dependencies, keep the original class's public API
unchanged, and delegate from the original's action methods.

```
OriginalClass::publicAction()
  → injects HandlerA::handle()   // owns its 2-3 deps
  → injects HandlerB::handle()   // owns its 2-3 deps
```

Private-method decomposition (validate/prepare/process/respond) handles
method-level complexity without needing a new class. Guard clauses eliminate
nested conditionals with early returns.

The `ProgressTracker` service (`lib/Service/ProgressTracker.php`) MUST be used
to isolate progress tracking from business logic in SoftwareCatalogueService.

## Out of Scope

- Behavioral changes: public API, request/response shapes, and database effects
  are UNCHANGED. This is a pure refactoring.
- New features: no new functionality is added.
- Schema changes: no OpenRegister schemas are modified or added.
- Frontend changes: only PHP `lib/` files are modified.
- VNG `Softwarecatalogus/` client repo: read-only per project policy.
- Test infrastructure reorganisation: existing tests must pass unchanged.

## See Also

- `specs/method-decomposition/spec.md` — full GIVEN/WHEN/THEN requirement scenarios
- `design.md` — handler file layout, code shapes before/after, risk surface
- `tasks.md` — phase-based implementation checklist
- ADR-003 (`.claude/openspec/architecture/adr-003-backend.md`) — Controller → Service → Mapper contract and thin-controller rule
- ADR-008 (`.claude/openspec/architecture/adr-008-testing.md`) — PHPUnit and container invocation requirements
- ADR-015 (`.claude/openspec/architecture/adr-015-common-patterns.md`) — SPDX headers, error response patterns, pre-commit checklist
- `lib/Service/SoftwareCatalogue/` — existing handler pattern in the codebase
- `lib/Service/ProgressTracker.php` — existing progress tracking service to reuse
- `openspec/changes/softwarecatalog-legacy-quality-cleanup/` — the broader quality-gates burn-down that this change feeds into
