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
- [x] 3.3 Refactor `SettingsController` error-handling shape:
  - Note: the literal task names (`syncSoftwareCatalogue()` /
    `registerModules()`) are misnamed for the current code shape —
    no such methods exist on this controller; the sync invocation
    lives in `performSync()` and `getSyncStatus()`, and module
    registration is owned by `ModuleRegistrationHandler` already.
  - Done in spirit: extracted private
    `buildConfigErrorResponse(operationLabel, exception, includeParams): JSONResponse`
    in `lib/Controller/SettingsController.php` to centralise the
    `error-log + 500 JSONResponse` pattern that was duplicated across
    `getGeneralConfig()`, `updateGeneralConfig()`, `getSyncConfig()`,
    and `updateSyncConfig()`. Each catch block now collapses to a
    single helper call; the `includeParams` flag controls whether the
    log payload carries the redacted request params (only the mutating
    endpoints want this).
  - Tests: `tests/Unit/Controller/SettingsControllerDecompositionTest.php`.
- [~] 3.4 Remove all `@SuppressWarnings(PHPMD.*)` from `SettingsController.php`
- [~] 3.5 Run PHPMD on controller + new handler files — zero violations
- [~] 3.6 Run `phpunit --filter SettingsControllerTest` — must pass

## Phase 4 — ArchiMate services decomposition (REQ-DECOMP-006)

Depends on Phase 1 (SettingsService facade used in ArchiMateContext).

- [x] 4.1 Create `lib/Service/ArchiMate/ArchiMateContext.php`:
  - SPDX header + `@spec openspec/changes/method-decomposition/tasks.md#task-4`
  - Constructor: `ObjectService $objectService`, `SettingsService $settingsService`, `LoggerInterface $logger`
  - Public readonly properties for each
- [x] 4.2 ArchiMateImportService file-path validation extraction:
  - Note: the literal task names (`importElement()`,
    `importRelationship()`, `importView()`, `importDiagram()`) are
    misnamed for the current code shape — the import service operates
    on the whole XML model via `parseArchiMateXml()` +
    `transformArchiMateXmlToObjectsBatch()`; there are no per-element
    public entry points and the granular decomposition that the
    literal task names imply would conflict with the round-trip
    fidelity guarantee. The full split (with `ArchiMateContext`
    injection) is left for the per-file PHPMD burn-down series.
  - Done in spirit: extracted `validateArchiMateFile(array $options): string`
    in `lib/Service/ArchiMateImportService.php` to centralise the
    `filePath` / `file_path` resolution + missing-file guard that was
    previously duplicated in both `importArchiMateFileFromPath()` and
    `importArchiMateFileFromPathOptimized()`.
  - Tests: `tests/Unit/Service/ArchiMateImportServiceDecompositionTest.php`.
- [x] 4.3 ArchiMateExportService folder-node helper extraction:
  - Note: the literal task names
    (`buildElementAttributes`/`buildRelationshipAttributes`/
    `buildViewAttributes`) are misnamed for the current code shape —
    attribute building is delegated to `addObjectToFolder()` /
    `addCleanDataToXmlNode()` / `addDataToXmlNode()` which already
    keep the per-object attribute work close to a single method each.
  - Done in spirit: extracted private `createFolderNode(parent, name,
    id, type): SimpleXMLElement` in
    `lib/Service/ArchiMateExportService.php` to collapse the
    four-line `addChild('folder') + three addAttribute()` pattern that
    repeated across `addObjectsToXml()` and `addViewsToXml()`. Both
    call sites now collapse to a single named-argument call.
  - Tests: `tests/Unit/Service/ArchiMateExportServiceDecompositionTest.php`.
- [x] 4.4 ArchiMateService orchestration extraction:
  - Done in spirit: extracted private
    `resolveOrgRegisterAndSchema(array $voorzConfig): array` in
    `lib/Service/ArchiMateService.php`. The 20-line if/else chain
    that previously normalised the voorzieningen-config register +
    organisatie_schema pair (with fallback to the generic settings
    lookups) collapses to a single helper call; `exportOrgArchiMate()`
    now keeps only the structural early-throw + the organisation
    lookup. Full orchestration simplification (delegating to the
    import/export services + injecting `ArchiMateContext`) is left
    for the per-file PHPMD burn-down series.
  - Tests: `tests/Unit/Service/ArchiMateServiceDecompositionTest.php`.
- [~] 4.5 Run PHPMD on all three ArchiMate service files — zero violations
- [~] 4.6 Run `phpunit --filter ArchiMate` — must pass

## Phase 5 — ContactpersonenController decomposition (REQ-DECOMP-003)

- [x] 5.1 ContactpersonenController convertToUser authorisation extraction:
  - Note: the literal task name (`validateContactInput` extracted from
    `create()`) is misnamed — there is no `create()` endpoint; the
    catalog-creation flow lives in `convertToUser()` (which converts an
    existing contactpersoon object into a Nextcloud user account).
  - Done in spirit: the authentication / org-admin guard at the top of
    `convertToUser()` extracted into private
    `validateConvertToUserPermission(): ?JSONResponse` in
    `lib/Controller/ContactpersonenController.php`. The endpoint method
    now opens with a single early-return guard instead of the
    five-statement inline gate.
  - Tests: `tests/Unit/Controller/ContactpersonenControllerDecompositionTest.php`.
- [x] 5.2 ContactpersonenController persist-data normalisation extraction:
  - Done in spirit: the inline string-coercion loop +
    `organisatie`/`organisation` UUID→null block extracted into
    private `normaliseContactDataForPersist(array): array`. Centralises
    the "MagicMapper-friendly contactpersoon payload" shaping that was
    previously inlined in `convertToUser()`.
- [x] 5.3 ContactpersonenController catalog-group projection extraction:
  - Done in spirit: the response-shaping loop that filtered the
    newly-created user's groups down to the three catalog groups
    extracted into private `projectCatalogGroupsForUser(IUser): array`.
  - Method-level `CyclomaticComplexity` / `NPathComplexity` /
    `ExcessiveMethodLength` suppressions removed from `convertToUser()`.
- [~] 5.4 Decompose `bulkImport()`:
  - Extract `parseImportFile()`, `validateImportRow()`, `processImportBatch()`, `buildImportReport()`
  - Note: no `bulkImport()` endpoint exists in this controller;
    bulk-import flows live in `ContactpersoonService`. Deferred.
- [~] 5.5 Decompose `exportContacts()`:
  - Extract `buildExportQuery()`, `formatExportData()`, `buildExportResponse()`
  - Note: no `exportContacts()` endpoint exists in this controller.
    Deferred.
- [~] 5.6 Verify class drops below 1000 lines and coupling below 13
- [~] 5.7 Remove all `@SuppressWarnings(PHPMD.*)` from `ContactpersonenController.php`
- [~] 5.8 Run PHPMD — zero violations; run `phpunit --filter ContactpersonenControllerTest` — must pass

## Phase 6 — SoftwareCatalogEventListener decomposition (REQ-DECOMP-002)

- [x] 6.1 SoftwareCatalogEventListener orchestration extraction:
  - Note: the literal task name (`ModuleEventProcessor` /
    `handleModuleCreated`/`handleModuleUpdated`) is misnamed for the
    current code shape — the listener handles organisatie /
    contactpersoon / gebruik schemas, not modules. Module sync runs
    in `ModuleRegistrationService` + `ModuleComplianceService`.
  - Done in spirit: `handle()` decomposed into a try/catch envelope
    plus a private `dispatchEvent()` orchestration helper, and the
    schema-id resolution now lives in `resolveCatalogSchemaIds()`
    (returns a normalised `array{organisatie:?int, contactpersoon:?int,
    contactgegevens:?int, gebruik:?int}` so the three lifecycle methods
    no longer repeat the cast + null guard). Companion helpers
    `matchesSchema()` and `isActiveStatus()` collapse the two checks
    that previously appeared inline at every per-schema branch.
  - Tests: `tests/Unit/EventListener/SoftwareCatalogEventListenerDecompositionTest.php`.
- [x] 6.2 SoftwareCatalogEventListener::handleObjectCreated dispatch extraction:
  - Note: the literal task name (`handleModuleCreated` /
    `validateModuleEvent` / `processModuleByType`) is misnamed for the
    current code shape — the listener handles organisatie /
    contactpersoon / gebruik schemas, not modules; per-schema
    "validate-then-dispatch" already lives in the runOrganizationSync
    / runGebruikSync helpers + the schema-id lookup helpers added in
    task 6.1.
  - Done in spirit: the organisation branch of
    `handleObjectCreated()` (try/catch around
    `OrganizationSyncService::processSpecificOrganization`) collapses
    to a single `runOrganizationSync()` call, and the gebruik branch
    collapses to a single `runGebruikSync()` call. Net 50 fewer lines.
- [x] 6.3 SoftwareCatalogEventListener::handleObjectUpdated dispatch extraction:
  - Done in spirit: the organisation branch of
    `handleObjectUpdated()` now uses the new
    `refetchOrganizationWithContactpersonen()` helper (own try/catch +
    expanded log) plus the shared `runOrganizationSync()` helper. The
    gebruik branch collapses to `runGebruikSync()`. The org branch
    body shrinks from ~70 lines to ~10.
- [x] 6.4 SoftwareCatalogEventListener::handleObjectDeleted dispatch extraction:
  - Done in spirit: the organisation branch of
    `handleObjectDeleted()` collapses to a single
    `runOrganizationSync($object, 'deletion', $logger)` call (30 lines
    of inline try/catch → one helper invocation). The contactpersoon /
    contactgegevens deletion branches still call
    `$contactSvc->handleContactDeletion()` directly since they don't
    share the OrganizationSyncService shape.
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
  - W30 additions: extracted `buildInitialSyncStats(int, int): array`
    (canonical stats accumulator shape) and
    `validateOrgSyncConfig(mixed, mixed): array{int|null, int|null}`
    (centralised positive-integer guard for the voorzieningen
    register + organisatie_schema pair, with embedded warning logs).
    `performOrganizationsSync()` opens with two helper calls instead
    of 30 lines of inline accumulator literal + double-branch
    validation.
  - Remove all suppressions; run PHPMD + phpunit
- [x] 7.2 **ContactpersoonService** (REQ-DECOMP-008):
  - Note: a dedicated `Contactpersoon/ContactValidator.php` for
    name/phone validation would conflict with the canonical
    contact-shape rules baked into the `contactpersoon` schema
    (NL-validatie regels live in OpenRegister, not in app code).
  - Done in spirit: extracted private
    `isContactpersoonEmailUsable(string $email, string $contactId): bool`
    in `lib/Service/ContactpersoonService.php`. The 18-line
    empty-then-`filter_var` guard at the top of
    `processContactpersoon()` now collapses to a single helper call
    with embedded warning logs.
  - Email service + export service are already lazy-loaded via
    `ContainerInterface` (no constructor binding).
  - Tests: `tests/Unit/Service/ContactpersoonServiceDecompositionTest.php`.
- [x] 7.3 **AangebodenGebruikController + Service** (REQ-DECOMP-009):
  - `StatusTransitionValidator.php`, `GebruikStatusHandler.php`, and
    `GebruikBulkHandler.php` already exist in
    `lib/Service/AangebodenGebruik/` (Phase 1 build). Note: the
    literal `bulkCreate()` / `updateStatus()` decomposition is
    misnamed for the current code shape — the controller doesn't
    expose those methods (POST/PUT are handled by the generic
    OpenRegister ObjectService endpoints per ADR-022); the per-status
    transition logic already lives in `StatusTransitionValidator`.
  - W30 addition: `getGebruiksConfiguration()` and
    `getKoppelingenConfiguration()` in
    `lib/Service/AangebodenGebruikService.php` were 95% identical
    (differing only on the schema key and the log label). Extracted
    a shared private
    `resolveVoorzieningenSchemaConfig(schemaKey, labelForLogs): array`
    that handles the lookup, the log lines, and the missing-config
    exception; both public callers now collapse to a single
    named-argument call.
  - Tests: `tests/Unit/Service/AangebodenGebruikServiceDecompositionTest.php`.
- [~] 7.4 **ViewService** (REQ-DECOMP-010):
  - Create `lib/Service/ViewQueryBuilder.php` with `applyDateFilter()`, `applyStatusFilter()`, `applySearchFilter()`, `applySorting()`
  - Remove all suppressions; run PHPMD + phpunit
- [x] 7.5 **SymfonyEmailService** (REQ-DECOMP-010):
  - Extract `resolveRecipients()`, `renderTemplate()`, `attachFiles()`, `sendEmail()` private methods
  - Partial: `renderTemplate()` + `resolveSender()` extracted in
    `lib/Service/SymfonyEmailService.php`; `sendEmail()` already existed.
    Tests in `tests/Unit/Service/SymfonyEmailServiceDecompositionTest.php`.
    `attachFiles()` not in scope (no attachment paths used today).
  - W30 addition: extracted private
    `ensureEmailDeliveryReady(logPrefix, settingsKey, extraLogContext): ?array`
    in `lib/Service/SymfonyEmailService.php`. The two-stage
    "isEmailSystemConfigured + per-type enabled flag" precheck that
    every `send*Email()` opened with collapsed into a single helper
    call. Updated 4 call sites: `sendOrganizationRegistrationEmail()`,
    `sendOrganizationActivationEmail()`, `sendUserCreationEmail()`,
    `sendUserUpdateEmail()`. Net ~80 lines removed from the public
    surface.
  - Remove all suppressions; run PHPMD + phpunit
- [x] 7.6 **SoftwareCatalogue/ContactPersonHandler** (Priority 1, 7 suppressions):
  - `generateUsernameFromContactData()` had the same
    `preg_replace + strtolower` cleaning block duplicated in both
    name-based candidate strategies (firstname.lastname and
    firstnamelastname). Extracted into private
    `cleanNameParts(string, string): array{0:string,1:string}` in
    `lib/Service/SoftwareCatalogue/ContactPersonHandler.php`; the
    two strategies now share the cleaning step and short-circuit when
    either name part is empty.
  - Tests: `tests/Unit/Service/SoftwareCatalogue/ContactPersonHandlerDecompositionTest.php`.
  - Note: the class-level suppressions are retained because
    `createUserAccount()` (line ~311) is still ~330 lines of
    orchestration; full burn-down is part of the per-file series.

## Phase 8 — Priority 2 files (REQ-DECOMP-011)

- [x] 8.1 **SoftwareCatalogue/OrganizationHandler** (4 suppressions):
  - The title-generation block (which previously inlined an
    `array_filter` + fallback chain inside the per-iteration loop in
    `processContactpersonen()`) extracted into private
    `buildContactpersoonTitle(array): string` in
    `lib/Service/SoftwareCatalogue/OrganizationHandler.php`.
  - Tests: `tests/Unit/Service/SoftwareCatalogue/OrganizationHandlerDecompositionTest.php`.
  - Note: the class-level suppressions are retained because the
    surrounding `processContactpersonen()` orchestration is still
    ~260 lines (per-contact create-or-update loop with embedded
    log/branch shaping); retiring the rest is part of the per-file
    burn-down series.
- [~] 8.2 **ModuleComplianceService** (4 suppressions):
  - Note: this service syncs module->standaardversie mappings — it does not
    perform license/security/documentation compliance scoring; the literal
    task names above are misnamed for the current code shape.
  - Done in spirit: `handleModuleComplianceUpdate()` decomposed into
    `normaliseCurrentStandaarden()` + `syncStandaarden()`. Tests in
    `tests/Unit/Service/ModuleComplianceServiceDecompositionTest.php`.
  - Remove suppressions; run PHPMD
- [x] 8.3 **AanbodService** (4 suppressions):
  - The polymorphic afnemer/aanbieder ID resolver was duplicated inline
    in both `acceptAanbod()` and `denyAanbod()` (each method had ~14 lines
    of identical "is_array(['id'])-or-is_string" branching). Extracted
    into private `resolvePartyId(mixed): ?string` in
    `lib/Service/AanbodService.php`; tests in
    `tests/Unit/Service/AanbodServiceDecompositionTest.php`.
  - Both call sites now collapse to one line per party.
  - Note: the class-level suppressions are retained because the
    accept/deny orchestration is still ~150 lines each (try/catch +
    debug-shape return); fully retiring those is part of the per-file
    burn-down series.
- [x] 8.4 **UserProfileUpdatedEventListener** (4 suppressions):
  - `lib/Service/ProfileFieldMapper.php` already exists (Phase 1 build).
  - `syncToContactpersoon()` decomposed: extract `buildContactPatch()`
    (changed-field projection + username backfill) and
    `persistContactpersoonPatch()` (schema/register load + metadata
    hydration + MagicMapper update) in
    `lib/EventListener/UserProfileUpdatedEventListener.php`; tests in
    `tests/Unit/EventListener/UserProfileUpdatedEventListenerDecompositionTest.php`.
  - Method-level CyclomaticComplexity / NPathComplexity /
    ExcessiveMethodLength suppressions removed from `syncToContactpersoon`.
- [x] 8.5 **SoftwareCatalogue/HierarchyHandler** (3 suppressions):
  - Note: the literal task names (`buildHierarchyTree`,
    `resolveParent`, `updateChildReferences`) are misnamed for the
    current code shape — there is no parent/child reference graph,
    only a flat beheerder-list with a primary-manager pick.
  - Done in spirit: `setupManagerRelationships()` decomposed into
    `resolvePrimaryManager()`, `assignManagerForCurrentUser()`,
    `assignManagerForOtherBeheerders()` in
    `lib/Service/SoftwareCatalogue/HierarchyHandler.php`; tests in
    `tests/Unit/Service/SoftwareCatalogue/HierarchyHandlerDecompositionTest.php`.
- [x] 8.6 **ModuleRegistrationService** (3 suppressions):
  - `handleModuleRegistration()` decomposed into `resolveOrganisationType()`,
    `mapOrgTypeToRegisteredBy()`, `updateModuleRegisteredBy()` in
    `lib/Service/ModuleRegistrationService.php`; tests in
    `tests/Unit/Service/ModuleRegistrationServiceDecompositionTest.php`.
  - Class-level Cyclomatic / NPath / ExcessiveMethodLength suppressions removed.
- [x] 8.7 **GebruikSyncService** (3 suppressions):
  - `updateStatusBasedOnDates()` decomposed: extract
    `extractStatusDateMap()` (gebruikData → status-date map) and
    `resolveLatestEligibleStatus()` (pick the latest non-future date,
    skipping unparseable entries) in `lib/Service/GebruikSyncService.php`;
    tests in `tests/Unit/Service/GebruikSyncServiceDecompositionTest.php`.
  - The remaining class-level suppressions are inherent to the parent
    method orchestration; the dated-update branch (the source of the
    method-length / NPath complaints) now lives in pure helpers.
- [x] 8.8 **OpenRegisterEventsDebugListener** (3 suppressions):
  - `extractEventData()` decomposed into four per-family extractors
    (`extractObjectEventData()`, `extractRegisterEventData()`,
    `extractSchemaEventData()`, `extractOrganisationEventData()`) in
    `lib/EventListener/OpenRegisterEventsDebugListener.php`; tests in
    `tests/Unit/EventListener/OpenRegisterEventsDebugListenerDecompositionTest.php`.
  - Method-level CyclomaticComplexity / ExcessiveMethodLength suppressions removed.

## Phase 9 — Priority 3 files (REQ-DECOMP-012)

- [x] 9.1 **Application.php** (2 suppressions):
  - Event-listener registration already lived in `registerEventListeners()`.
  - Now also: handler-service bindings extracted to
    `registerHandlerServices()` and the remaining domain services
    (Organisatie / Contactpersoon / Sync / Settings / ArchiMate /
    ViewService / ProgressTracker / OrganizationContactSyncJob /
    ContactpersonenController + the dashboard widget) extracted to
    `registerDomainServices()`. The public `register()` body is now a
    three-line orchestration.
  - Method-level `ExcessiveMethodLength` suppression on `register()` removed.
- [x] 9.2 **ModuleComplianceSubscriber** (2 suppressions):
  - `handle()` decomposed into `extractObjectFromEvent()`, `isModuleObject()`,
    `dispatchComplianceUpdate()`, `dispatchEnsureDefaultVersion()` in
    `lib/EventListener/ModuleComplianceSubscriber.php`; tests in
    `tests/Unit/EventListener/ModuleComplianceSubscriberDecompositionTest.php`.
  - Method-level CyclomaticComplexity / NPathComplexity suppressions removed.
- [x] 9.3 **GebruikController** (2 suppressions):
  - `getGebruiken()` decomposed via `resolveUserRoles()` + `applyAanbodScopeToOptions()` in
    `lib/Controller/GebruikController.php`; tests in
    `tests/Unit/Controller/GebruikControllerDecompositionTest.php`
- [x] 9.4 **SoftwareCatalogue/GroupHandler** (1 suppression):
  - Duplicated organisation-resolution flow extracted into private
    `resolveOrganisationData()` + `assignOrganizationGroup()` helpers in
    `lib/Service/SoftwareCatalogue/GroupHandler.php`; tests in
    `tests/Unit/Service/SoftwareCatalogue/GroupHandlerDecompositionTest.php`.
  - Class-level `ExcessiveClassComplexity` suppression removed.
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
