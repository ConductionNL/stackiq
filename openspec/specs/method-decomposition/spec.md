---
status: draft
priority: high
estimated_effort: large
---

# Method Decomposition — SoftwareCatalog

## Goal
Eliminate 145 PHPMD complexity suppressions by decomposing complex methods into smaller, focused units. Each suppression represents a method or class that exceeds PHPMD's strict thresholds (CC>10, NPath>200, MethodLength>100, ClassLength>1000).

## Current State
- **CyclomaticComplexity suppressions:** 31 (methods with >10 branches)
- **NPathComplexity suppressions:** 26 (methods with >200 execution paths)
- **ExcessiveMethodLength suppressions:** 35 (methods >100 lines)
- **ExcessiveClassComplexity suppressions:** 19 (classes with too much logic)
- **ExcessiveClassLength suppressions:** 14 (classes >1000 lines)
- **CouplingBetweenObjects suppressions:** 12 (too many dependencies)
- **TooManyMethods suppressions:** 8

## Files Requiring Decomposition

### Priority 1 — Highest complexity (files with 5+ suppressions)

**lib/Controller/SettingsController.php** (12 suppressions)
Admin settings controller managing synchronization settings, module registration, and catalogue configuration. Class-level suppressions (4) for class length, TooManyMethods, class complexity, and coupling. Method-level suppressions on `syncSoftwareCatalogue` (CC+NPath+MethodLength), `registerModules` (MethodLength), `syncOrganizations` (CC+MethodLength), and `configureArchiMate` (MethodLength).

**lib/EventListener/SoftwareCatalogEventListener.php** (11 suppressions)
Event listener handling OpenRegister object events for software catalog synchronization. Class-level suppressions (2) for class complexity and coupling. Method-level suppressions on `handleModuleCreated` (CC+NPath+MethodLength), `handleModuleUpdated` (CC+NPath+MethodLength), and `handleOrganizationEvent` (CC+NPath+MethodLength).

**lib/Controller/ContactpersonenController.php** (10 suppressions)
Contact persons CRUD controller with complex create/update logic. Class-level suppressions (3) for class length, class complexity, and coupling. Method-level suppressions on `create` (CC+NPath+MethodLength), `update` (CC+MethodLength), `bulkImport` (MethodLength), and `exportContacts` (MethodLength).

**lib/Service/SoftwareCatalogueService.php** (7 suppressions)
Core service for synchronizing with the VNG Software Catalogus API. Class-level suppressions (7) for class length, class complexity, coupling, TooManyMethods, CC, NPath, and method length.

**lib/Service/SoftwareCatalogue/ContactPersonHandler.php** (7 suppressions)
Handler for contact person synchronization with the Software Catalogus. Class-level suppressions (7) for class length, class complexity, coupling, TooManyMethods, CC, NPath, and method length.

**lib/Service/SettingsService.php** (7 suppressions)
Settings persistence service managing application configuration. Class-level suppressions (7) for class length, class complexity, coupling, TooManyMethods, CC, NPath, and method length.

**lib/Service/OrganizationSyncService.php** (7 suppressions)
Organisation synchronization service pulling data from external sources. Class-level suppressions (7) for class length, class complexity, coupling, TooManyMethods, CC, NPath, and method length.

**lib/Service/ArchiMateService.php** (7 suppressions)
ArchiMate enterprise architecture model import/export. Class-level suppressions (7) for class length, class complexity, coupling, TooManyMethods, CC, NPath, and method length.

**lib/Service/ArchiMateImportService.php** (7 suppressions)
ArchiMate XML import service parsing Open Exchange Format files. Class-level suppressions (7) for class length, class complexity, coupling, TooManyMethods, CC, NPath, and method length.

**lib/Service/ContactpersoonService.php** (6 suppressions)
Contact person business logic service. Class-level suppressions (6) for class length, class complexity, coupling, CC, NPath, and method length.

**lib/Service/ArchiMateExportService.php** (6 suppressions)
ArchiMate XML export service generating Open Exchange Format files. Class-level suppressions (6) for class length, class complexity, TooManyMethods, CC, NPath, and method length.

**lib/Controller/AangebodenGebruikController.php** (6 suppressions)
"Offered usage" (software deployments) controller. Class-level suppressions (2) for class length and class complexity. Method-level suppressions on `create` (MethodLength), `bulkCreate` (MethodLength), and `updateStatus` (CC+NPath).

**lib/Service/ViewService.php** (5 suppressions)
View/dashboard service managing configurable data views. Class-level suppressions (5) for class length, class complexity, CC, NPath, and method length.

**lib/Service/SymfonyEmailService.php** (5 suppressions)
Email sending service using Symfony Mailer. Class-level suppressions (5) for class length, class complexity, CC, NPath, and method length.

**lib/Service/AangebodenGebruikService.php** (5 suppressions)
Software usage/deployment business logic. Class-level suppressions (5) for class length, class complexity, CC, NPath, and method length.

### Priority 2 — Medium complexity (files with 3-4 suppressions)

- `lib/Service/SoftwareCatalogue/OrganizationHandler.php` (4) — Organization sync handler with class complexity, CC, NPath, method length
- `lib/Service/ModuleComplianceService.php` (4) — Compliance checking with class complexity, CC, NPath, method length
- `lib/Service/AanbodService.php` (4) — Offering service with class complexity, CC, NPath, method length
- `lib/EventListener/UserProfileUpdatedEventListener.php` (4) — User profile sync with CC+NPath+MethodLength (2 methods)
- `lib/Service/SoftwareCatalogue/HierarchyHandler.php` (3) — Hierarchy sync handler with CC, NPath, method length
- `lib/Service/ModuleRegistrationService.php` (3) — Module registration with CC, NPath, method length
- `lib/Service/GebruikSyncService.php` (3) — Usage sync with CC, NPath, method length
- `lib/EventListener/OpenRegisterEventsDebugListener.php` (3) — Debug listener with coupling, CC, method length

### Priority 3 — Single or double suppressions

- `lib/EventListener/ModuleComplianceSubscriber.php` (2) — CC + NPath
- `lib/Controller/GebruikController.php` (2) — CC + NPath
- `lib/AppInfo/Application.php` (2) — Coupling + MethodLength
- `lib/Service/SoftwareCatalogue/GroupHandler.php` (1) — ExcessiveClassComplexity
- `lib/Service/ModuleVersionService.php` (1) — ExcessiveMethodLength
- `lib/Controller/ViewController.php` (1) — ExcessiveMethodLength

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
- Using lazy loading for rarely-used dependencies
- Moving methods that use specific deps to handler classes

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
- [ ] No new PHPMD violations introduced
- [ ] All existing tests continue to pass
- [ ] No behavioral changes (pure refactoring)
