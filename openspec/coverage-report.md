# Coverage Report — softwarecatalog

Generated: 2026-05-24 (UTC)
Branch: development
Scanner: opsx-coverage-scan v1

## Summary

| Bucket | Count | Next action |
|---|---|---|
| annotated | 1 | — (already tagged) |
| plumbing | 28 | — (never tagged) |
| 1 — REQ matched | 60 | `/opsx-annotate softwarecatalog` |
| 2a — existing capability, no REQ | ~700 methods (aggregate, 1 cluster) | `/opsx-reverse-spec softwarecatalog --extend method-decomposition` |
| 2b — no capability owner | ~70 items / 11 clusters | `/opsx-reverse-spec softwarecatalog --cluster <name>` (see warnings) |
| 3a — REQ broken (code removed) | 0 | — |
| 3b — REQ never implemented | 13 | Mark deferred or progress in-flight changes |
| 4 — ADR conformance | 55+ findings across 4 rules | Follow-up issue |

**Inventory**: 43 PHP files (~804 methods), 60 Vue/JS files, 1 spec (`method-decomposition`, status: draft) + 3 in-flight delta changes.

---

## Critical context (read before annotating)

The single spec under `openspec/specs/` (`method-decomposition`) is a **refactoring/quality spec, not a feature spec**. Its 12 REQs name files to decompose and describe TARGET handler classes (`SyncHandler`, `ModuleRegistrationHandler`, `ViewQueryBuilder`, `ContactValidator`, `StatusTransitionValidator`, `ProfileFieldMapper`, `EventRegistrar`, `ServiceRegistrar`, `SyncSettingsHandler`, `ModuleSettingsHandler`, `OrganizationSettingsHandler`, `ArchiMateContext`, `SoftwareCatalogue/ApiClient`, `SoftwareCatalogue/DataMapper`, `SoftwareCatalogue/ConflictResolver`).

**None of those handler classes exist.** Removed-lines cache returned 0 hits — they have never been implemented. The existing complex methods in the targeted files are the **subjects** of refactoring, not implementations of the REQs.

Bucket 1 entries below mark these as `pre-refactor baseline`. The unimplemented handler classes are tracked in Bucket 3b.

Additionally, REQ-DECOMP-003 and REQ-DECOMP-009 reference method names (`create()`, `update()`, `bulkImport()`, `exportContacts()`, `bulkCreate()`, `updateStatus()`) that **do not exist** in the current controllers — the spec was either written against a different controller shape or anticipates greenfield work. Flagged NEEDS-REVIEW.

Three in-flight changes provide additional surface:
- `softwarecatalog-manifest-v1` — implemented (src/manifest.json + main.js wired)
- `softwarecatalog-store-migration` — implemented (object.js uses createObjectStore + plugin)
- `softwarecatalog-adopt-or-abstractions` — mostly pending (RegisterResolverService adoption still uses IAppConfig)

---

## Bucket 1 — Ready to annotate (via ghost change `retrofit-2026-05-24-annotate-softwarecatalog`)

### capability: method-decomposition

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Controller/SettingsController.php | syncSoftwareCatalogue | REQ-DECOMP-001 | 0.95 | name cited in REQ scenario 1 |
| lib/Controller/SettingsController.php | registerModules | REQ-DECOMP-001 | 0.95 | name cited in REQ scenario 2 |
| lib/Controller/SettingsController.php | syncOrganizations | REQ-DECOMP-001 | 0.90 | name cited in REQ prose |
| lib/Controller/SettingsController.php | configureArchiMate | REQ-DECOMP-001 | 0.90 | name cited; ⚠️ method not found in source — spec drift |
| lib/EventListener/SoftwareCatalogEventListener.php | handle | REQ-DECOMP-002 | 0.85 | file explicitly named |
| lib/EventListener/SoftwareCatalogEventListener.php | handleObjectCreated | REQ-DECOMP-002 | 0.85 | nearest match to REQ's `handleModuleCreated` |
| lib/EventListener/SoftwareCatalogEventListener.php | handleObjectUpdated | REQ-DECOMP-002 | 0.85 | nearest match to REQ's `handleModuleUpdated` |
| lib/EventListener/SoftwareCatalogEventListener.php | handleObjectDeleted | REQ-DECOMP-002 | 0.80 | event-handling pattern |
| lib/Controller/ContactpersonenController.php | convertToUser | REQ-DECOMP-003 | 0.75 | ⚠️ NEEDS-REVIEW — REQ names create/update/bulkImport which don't exist |
| lib/Controller/ContactpersonenController.php | getContactpersonen | REQ-DECOMP-003 | 0.70 | ⚠️ NEEDS-REVIEW |
| lib/Service/SoftwareCatalogueService.php | processContactpersoon | REQ-DECOMP-004 | 0.90 | file named; matches API/mapping concern |
| lib/Service/SoftwareCatalogueService.php | processOrganization | REQ-DECOMP-004 | 0.90 | file named |
| lib/Service/SettingsService.php | getSettings | REQ-DECOMP-005 | 0.90 | file named; settings facade |
| lib/Service/SettingsService.php | updateSettings | REQ-DECOMP-005 | 0.90 | file named |
| lib/Service/SettingsService.php | autoConfigure | REQ-DECOMP-005 | 0.85 | file named |
| lib/Service/ArchiMateImportService.php | importArchiMateFileFromPath | REQ-DECOMP-006 | 0.95 | scenario 1 references import entry |
| lib/Service/ArchiMateImportService.php | parseArchiMateXml | REQ-DECOMP-006 | 0.90 | scenario about nested-switch XML parsing |
| lib/Service/ArchiMateExportService.php | createCleanArchiMateXml | REQ-DECOMP-006 | 0.90 | scenario 2 about export XML |
| lib/Service/ArchiMateExportService.php | addElementsToXml | REQ-DECOMP-006 | 0.85 | scenario about attribute builders |
| lib/Service/ArchiMateService.php | importArchiMateFileFromPath | REQ-DECOMP-006 | 0.90 | orchestrator delegation |
| lib/Service/ArchiMateService.php | exportToArchiMate | REQ-DECOMP-006 | 0.90 | orchestrator delegation |
| lib/Service/OrganizationSyncService.php | performFullSync | REQ-DECOMP-007 | 0.90 | scenario 1 about pipeline stages |
| lib/Service/OrganizationSyncService.php | performOrganizationsSync | REQ-DECOMP-007 | 0.85 | file named |
| lib/Service/OrganizationSyncService.php | processOrganisatieObject | REQ-DECOMP-007 | 0.85 | transform/persist phase |
| lib/Service/ContactpersoonService.php | processContactpersoon | REQ-DECOMP-008 | 0.90 | main business method |
| lib/Service/ContactpersoonService.php | handleContactpersoonUpdate | REQ-DECOMP-008 | 0.85 | file named |
| lib/Controller/AangebodenGebruikController.php | setGebruikSelfToActiveOrg | REQ-DECOMP-009 | 0.70 | ⚠️ NEEDS-REVIEW — semantic drift |
| lib/Controller/AangebodenGebruikController.php | deleteGebruikAsAfnemer | REQ-DECOMP-009 | 0.70 | ⚠️ NEEDS-REVIEW |
| lib/Service/AangebodenGebruikService.php | setGebruikSelfToActiveOrg | REQ-DECOMP-009 | 0.75 | ⚠️ NEEDS-REVIEW |
| lib/Service/ViewService.php | getAllViews | REQ-DECOMP-010 | 0.85 | query/filter logic |
| lib/Service/ViewService.php | enrichView | REQ-DECOMP-010 | 0.85 | complex enrichment per scenario 1 |
| lib/Service/SymfonyEmailService.php | sendOrganizationRegistrationEmail | REQ-DECOMP-010 | 0.90 | scenario 2 about email composition |
| lib/Service/SymfonyEmailService.php | sendTemplatedEmail | REQ-DECOMP-010 | 0.90 | scenario 2 about template selection |
| lib/Service/ModuleComplianceService.php | handleModuleComplianceUpdate | REQ-DECOMP-011 | 0.85 | scenario about compliance rule eval |
| lib/Service/ModuleComplianceService.php | bulkSyncModuleStandards | REQ-DECOMP-011 | 0.80 | priority-2 list |
| lib/EventListener/UserProfileUpdatedEventListener.php | handle | REQ-DECOMP-011 | 0.85 | scenario 2 — ProfileFieldMapper |
| lib/EventListener/UserProfileUpdatedEventListener.php | syncToContactpersoon | REQ-DECOMP-011 | 0.85 | priority-2 list |
| lib/Service/SoftwareCatalogue/HierarchyHandler.php | getUserHierarchy | REQ-DECOMP-011 | 0.85 | scenario 3 — tree traversal |
| lib/Service/SoftwareCatalogue/HierarchyHandler.php | getOrganizationStructure | REQ-DECOMP-011 | 0.85 | priority-2 list |
| lib/Service/SoftwareCatalogue/OrganizationHandler.php | processOrganization | REQ-DECOMP-011 | 0.85 | priority-2 list |
| lib/Service/SoftwareCatalogue/OrganizationHandler.php | processContactpersonen | REQ-DECOMP-011 | 0.85 | priority-2 list |
| lib/Service/AanbodService.php | getAanbod | REQ-DECOMP-011 | 0.80 | priority-2 list |
| lib/Service/AanbodService.php | acceptAanbod | REQ-DECOMP-011 | 0.80 | priority-2 list |
| lib/Service/AanbodService.php | denyAanbod | REQ-DECOMP-011 | 0.80 | priority-2 list |
| lib/Service/ModuleRegistrationService.php | handleModuleRegistration | REQ-DECOMP-011 | 0.85 | priority-2 list |
| lib/Service/GebruikSyncService.php | processSpecificGebruik | REQ-DECOMP-011 | 0.85 | priority-2 list |
| lib/EventListener/OpenRegisterEventsDebugListener.php | handle | REQ-DECOMP-011 | 0.80 | priority-2 list |
| lib/Controller/GebruikController.php | getGebruiken | REQ-DECOMP-012 | 0.80 | priority-3 list |
| lib/Controller/GebruikController.php | getGebruikenForDeelnemer | REQ-DECOMP-012 | 0.80 | priority-3 list |
| lib/AppInfo/Application.php | boot | REQ-DECOMP-012 | 0.90 | scenario 1 explicitly references this |
| lib/Service/SoftwareCatalogue/GroupHandler.php | updateUserGroups | REQ-DECOMP-012 | 0.80 | priority-3 list |
| lib/Service/ModuleVersionService.php | ensureDefaultVersion | REQ-DECOMP-012 | 0.90 | scenario 2 explicitly references this |
| lib/Controller/ViewController.php | getAllViews | REQ-DECOMP-012 | 0.80 | priority-3 list |
| lib/Controller/ViewController.php | getView | REQ-DECOMP-012 | 0.80 | priority-3 list |

### capability: softwarecatalog-manifest-v1 (in-flight delta)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| src/manifest.json | `<file>` | REQ-SCMV1-1 | 0.95 | canonical path exists |
| src/main.js | `<bootstrap>` | REQ-SCMV1-1 | 0.90 | useAppManifest + bundledManifest wired |

### capability: softwarecatalog-store-migration (in-flight delta)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| src/store/modules/object.js | `<file>` | createObjectStore-for-OR-CRUD | 0.90 | imports createObjectStore |
| src/store/plugins/softwarecatalogPlugin.js | `<file>` | plugin-shape-for-app-extensions | 0.90 | imports buildHeaders + buildQueryString |

### capability: softwarecatalog-adopt-or-abstractions (in-flight delta — partially implemented)

| File | Method | REQ | Confidence | Signal |
|---|---|---|---|---|
| lib/Service/ModuleComplianceService.php | `<file>` | RegisterResolverService-consume | 0.40 | ⚠️ REQ targets this file but currently uses IAppConfig — adoption pending |
| lib/Service/GebruikSyncService.php | `<file>` | RegisterResolverService-consume | 0.40 | ⚠️ pending |
| lib/Service/OrganizationSyncService.php | `<file>` | RegisterResolverService-consume | 0.40 | ⚠️ pending |
| lib/Service/ViewService.php | `<file>` | RegisterResolverService-consume | 0.40 | ⚠️ pending |
| lib/EventListener/UserProfileUpdatedEventListener.php | `<file>` | RegisterResolverService-consume | 0.40 | ⚠️ pending |

---

## Bucket 2a — Existing capability, no REQ (reverse-spec --extend)

### cluster: method-decomposition (~700 methods aggregate)

The `method-decomposition` capability legitimately owns every PHP method in REQ-targeted files. Since the spec only names specific entry-point methods (above), the remaining bulk of each class is uncovered by an explicit REQ. Most of these are private helpers, getters, and supporting methods.

**⚠️ Large-cluster sanity check passed**: sampled method names match the capability vocabulary (sync, settings, archimate, contactpersoon, etc.).

**Aggregate breakdown** (per file):

- **lib/Controller/SettingsController.php** — 26 misc public/protected methods covering settings CRUD, status, debug, email, auto-config, progress streaming, cache clearing.
- **lib/Service/SettingsService.php** — ~95 misc methods: register/schema lookups, auto-config orchestration, group management, voorzieningen register helpers, OpenRegister discovery. **Far exceeds REQ-DECOMP-005 scope** (REQ lists only sync/module/organization/ArchiMate domains).
- **lib/Service/SoftwareCatalogueService.php** — ~45 misc methods: handleNew*/handle*Update/handle*Deletion across organisation/contact/gebruiker domains plus user lifecycle (block/restore/sync). **User-lifecycle methods are outside REQ-DECOMP-004 scope.**
- **lib/Service/ArchiMateImportService.php** — ~70 misc methods: deep XML parsing helpers, identifier extraction, caching, parallel batch persistence.
- **lib/Service/ArchiMateExportService.php** — ~50 misc methods: namespaced XML serializers, view/relationship/element walkers, attribute filters.
- **lib/Service/ArchiMateService.php** — ~50 misc methods: orchestration glue, schema-id maps, section structure config, batch save helpers.
- **lib/Service/SoftwareCatalogue/ContactPersonHandler.php** — ~35 misc methods: username generation, group assignment, role mapping, user lifecycle.
- **lib/Controller/ContactpersonenController.php** — 10 misc methods (user-management endpoints). **REQ-DECOMP-003 lists methods that don't exist** — large semantic gap.
- **lib/Controller/AangebodenGebruikController.php** — 8 misc methods (gebruik queries). **REQ-DECOMP-009 lists methods that don't exist.**
- **lib/Service/AangebodenGebruikService.php** — 16 misc methods (gebruik query helpers paired with controller).
- **lib/Service/OrganizationSyncService.php** — ~25 misc methods (performContactSync, performUserSync, processContactPerson, email sending, admin lookup).
- **lib/Service/ContactpersoonService.php** — 15 misc methods (normalize, sync name fields, enable/disable variants).
- **lib/Service/ViewService.php** — 30 misc methods (enrichment toggles, data fetchers, fan-out).
- **lib/Service/SymfonyEmailService.php** — 35 misc methods (per-transport factories: Mailgun/Postmark/SES/SendGrid/Mailjet/SMTP; template rendering).
- **lib/Service/SoftwareCatalogue/OrganizationHandler.php** — 12 misc methods.
- **lib/Service/SoftwareCatalogue/HierarchyHandler.php** — 5 misc methods.
- **lib/Service/SoftwareCatalogue/GroupHandler.php** — 10 misc methods.
- **lib/Service/ModuleComplianceService.php** — 6 misc methods.
- **lib/Service/AanbodService.php** — 5 misc methods.
- **lib/Service/GebruikSyncService.php** — 5 misc methods.
- **lib/EventListener/UserProfileUpdatedEventListener.php** — 1 private helper (`findContactpersoon`, Pass B inherits from `handle()`).
- **lib/EventListener/OpenRegisterEventsDebugListener.php** — 3 helpers.

When extending the capability via `/opsx-reverse-spec --extend method-decomposition`, explode the per-file `_misc_*` aggregates in the JSON sidecar back into per-method REQs.

---

## Bucket 2b — No capability owner (reverse-spec --cluster)

⚠️ **Namespace-word warnings**: the labels `background-jobs`, `frontend-shell-and-views`, `frontend-modals-and-dialogs`, `frontend-components`, `frontend-stores`, `frontend-utils`, `repair-bootstrap`, `examples-docs` are filesystem/component-type words, not behavioral names. **Pre-split required before running `/opsx-reverse-spec --cluster`**.

### cluster: background-jobs (6 methods) ⚠️ namespace word
- `lib/BackgroundJob/CronjobContextTrait.php` — 5 trait methods (setCronjobContext, clearCronjobContext, hasContext, getCronjobUser, getCronjobOrganisationUuid). Behavioral split candidate: **`cronjob-execution-context`**.
- `lib/BackgroundJob/OrganizationContactSyncJob.php::run()` — scheduled job pulling organisation contacts. Behavioral split candidate: **`organization-contact-sync-cron`**.

### cluster: aanbod-listings (4 methods)
- `lib/Controller/AanbodController.php` — getAanbod / acceptAanbod / denyAanbod / parseQueryOptions. CRUD surface for software-offering aanbod. Pure behavior label.

### cluster: concept-organizations-widget (1 method)
- `lib/Dashboard/ConceptOrganisatiesWidget.php::load()` — dashboard widget asset loader (other getters bucketed as plumbing). Behavior label OK.

### cluster: repair-bootstrap (1 method) ⚠️ namespace word
- `lib/Repair/InitializeSettings.php::run()` — post-install: imports register/schemas from configuration files. Behavioral split: **`first-install-bootstrap`**.

### cluster: examples-docs (2 methods) ⚠️ namespace word
- `lib/Examples/ContactpersoonServiceExample.php` — documentation/example code, not a runtime path. Consider excluding via `.opsx-ignore`.

### cluster: gebruik-services (3 methods)
- `lib/Service/GebruikService.php` — getGebruiksConfiguration / getGebruiken / getApplicationIds. Read-only query layer for usage records.

### cluster: organisatie-service (8 methods)
- `lib/Service/OrganisatieService.php` — create/update organisation in OpenRegister, status transition, data mapping, admin user lookup. Behavior label OK.

### cluster: progress-tracking (13 methods)
- `lib/Service/ProgressTracker.php` — operation lifecycle, phases, % calculation, ETA, persistence. Behavior label OK.

### cluster: frontend-shell-and-views (~13 files) ⚠️ namespace word
- `src/App.vue`, `src/router.js`, `src/customComponents.js`, `src/settings.js`, `src/conceptOrganisatiesWidget.js`, plus views under `src/views/`. Suggested behavioral split: **`dashboard-page`**, **`organisaties-page`**, **`settings-page`**, **`statistics-overview`**, **`email-configuration`**, **`cronjob-configuration`**, **`user-groups-configuration`**, **`version-information`**, **`open-register-integration-status`**, **`archimate-import-export-ui`**, **`organization-synchronization-ui`**.

### cluster: frontend-modals-and-dialogs (~16 files) ⚠️ namespace word
- 12 generic object-action modals (Delete/Download/Lock/Mass*/Merge/Migration/Object/Upload/View) — these are OpenRegister object-action surfaces, candidates for **`object-actions-modals`**.
- `OrganisationModal.vue`, `ChangeOrganisatieStatusDialog.vue` — organisation-specific. Candidate: **`organisation-detail-actions`**.
- Sidebars (`SideBars.vue`, `DashboardSideBar.vue`, `DirectorySideBar.vue`, `SearchSideBar.vue`) — candidate: **`detail-sidebars`**.
- `Configuration.vue` — navigation config UI.

### cluster: frontend-components (9 files) ⚠️ namespace word
- AddContactpersoonModal, AlwaysVisibleSection, CollapsibleSection, ContactpersonenList, PaginationComponent, PublishedIcon, SelectedObjectsList, StandardTabs, OrganisatieCard. Mixed reusable components; suggested split by usage site rather than as a single cluster.

### cluster: frontend-stores (6 files) ⚠️ namespace word
- `store.js`, `pinia.js`, modules (catalog/navigation/organisatie/settings — note `object.js` is in Bucket 1 under store-migration). Behavioral split: **`pinia-store-aggregator`**, **`navigation-state`**, **`catalog-state`**, **`organisatie-state`**, **`settings-state`**.

### cluster: frontend-utils (2 files) ⚠️ namespace word
- `services/getTheme.js`, `utils/heartbeat.js`. Behavior labels: **`theme-resolution`**, **`heartbeat-polling`**.

---

## Bucket 3 — Surfaced for human triage

### 3a — possibly broken (code removed)

None. Removed-lines cache showed zero hits for any of the desired handler-class names.

### 3b — never implemented (13 REQs / REQ-scenarios)

| REQ | Note |
|---|---|
| method-decomposition#REQ-DECOMP-001 (handler classes) | `SyncHandler`, `ModuleRegistrationHandler` never created; 0 removed-line hits |
| method-decomposition#REQ-DECOMP-002 (ModuleEventProcessor) | not present |
| method-decomposition#REQ-DECOMP-003 (handler classes + decomposition) | ⚠️ REQ references methods `create()`/`update()`/`bulkImport()`/`exportContacts()` that DO NOT EXIST in the current controller — likely spec authoring error or pre-rename drift |
| method-decomposition#REQ-DECOMP-004 (SoftwareCatalogue/{ApiClient,DataMapper,ConflictResolver}) | subdir classes not found |
| method-decomposition#REQ-DECOMP-005 (Sync/Module/Organization SettingsHandlers) | not present |
| method-decomposition#REQ-DECOMP-006 (ArchiMateContext value object) | not present |
| method-decomposition#REQ-DECOMP-009 (StatusTransitionValidator + GebruikStatusHandler + GebruikBulkHandler) | ⚠️ REQ also references methods that don't exist |
| method-decomposition#REQ-DECOMP-010 (ViewQueryBuilder) | not present |
| method-decomposition#REQ-DECOMP-011 (ProfileFieldMapper + rule evaluators) | not present |
| method-decomposition#REQ-DECOMP-012 (EventRegistrar + ServiceRegistrar) | not present |
| softwarecatalog-adopt-or-abstractions#RegisterResolverService-consume | Five files still use `IAppConfig::getValueString` for register/schema lookups; pending |
| softwarecatalog-adopt-or-abstractions#manifest-architectural-extras | useAppManifest wired; `@resolve` sentinel validation pending |
| softwarecatalog-adopt-or-abstractions#useTenantContext + i18n-source-of-truth | composable not imported; i18n migration pending |

---

## Bucket 4 — ADR conformance findings

### missing-copyright-in-file-docblock (13 files)
- lib/Controller/GebruikController.php
- lib/Controller/ViewController.php
- lib/Service/ArchiMateExportService.php
- lib/Service/ArchiMateImportService.php
- lib/Service/ArchiMateService.php
- lib/Service/ProgressTracker.php
- lib/Service/SettingsService.php
- lib/Service/SoftwareCatalogue/ContactPersonHandler.php
- lib/Service/SoftwareCatalogue/GroupHandler.php
- lib/Service/SoftwareCatalogue/HierarchyHandler.php
- lib/Service/SoftwareCatalogue/OrganizationHandler.php
- lib/Service/SoftwareCatalogueService.php
- lib/Service/ViewService.php

All carry `@license`; only `@copyright` is missing — likely a header-generator omission on a subset.

### missing-spec-in-file-docblock (42 of 43 PHP files)
Only `src/App.vue` carries an `@spec openspec/changes/...` annotation. Expected for legacy code awaiting `/opsx-annotate`.

### console-log-in-frontend (14 instances across 4 files)
- src/modals/object/ViewObject.vue — 2 instances (lines 1097, 3237) — property-detection debug
- src/modals/object/MergeObject.vue — 7 instances — merge-data debug
- src/modals/object/ObjectModal.vue — 1 instance — generic debug
- src/store/modules/navigation.js — 4 instances — state-change logs

Recommend stripping or guarding behind a debug flag.

### forbidden-patterns-php
None. Word-boundary grep on `var_dump|die|error_log|print_r` returned no matches.

### direct-sql
None. No `$this->db->query(` or `prepare(` calls (ADR-001 compliant — goes through OpenRegister).

---

## Notes for the human reviewer

1. **The spec shape is unusual** — `method-decomposition` is a quality/refactoring spec where REQs name target handler classes that don't yet exist. Treat the Bucket 1 entries as "this is the pre-refactor baseline" rather than "this implements the REQ." Before running `/opsx-annotate`, consider whether annotating the existing complex methods with `@spec` pointing at the unimplemented decomposition tasks is the right call — annotations would describe the method's *intended future decomposition*, not its current behavior.

2. **Spec drift in two controllers** — REQ-DECOMP-003 (Contactpersonen) and REQ-DECOMP-009 (AangebodenGebruik) reference method names that don't exist in the current code. Either:
   - The spec was authored against a stale or hypothetical version of these controllers, or
   - There's been a rename and the spec wasn't updated.
   Resolve with PO before annotating; the actual methods are user-management endpoints and gebruik-query endpoints which are functionally unrelated to the REQ's scenarios.

3. **In-flight changes provide more coverage than `openspec/specs/`** — the 3 delta changes cover the frontend (manifest, store) and an OR-abstraction migration. Worth promoting these to full specs once their changes archive.

4. **Bucket 2a is dominated by `SettingsService` (95 misc methods) and `SoftwareCatalogueService` (45 misc methods)** — both classes carry concerns outside their REQ's stated scope (user lifecycle in SoftwareCatalogueService; voorzieningen-register helpers and group management in SettingsService). A `/opsx-reverse-spec --extend method-decomposition` run should likely split into multiple new capabilities: `application-settings-persistence`, `voorzieningen-register-discovery`, `user-group-management`, `vng-catalogue-sync`, `user-lifecycle-from-catalogue-events`.

5. **Bucket 2b namespace-word warnings affect 8 of 11 clusters** — most frontend code has no behavioral capability owner yet because there's no Vue-side spec. Pre-split suggestions are provided inline.

6. **No.opsx-ignore present** — `lib/Examples/` is documentation/example code (not a runtime path); consider adding an ignore entry for it.

7. **Removed-lines cache built in 2.7s on 558k lines** — well within budget. Reverse pass classification is reliable.

## Coverage Scan Complete — softwarecatalog

Buckets: annotated=1 | plumbing=28 | 1=60 | 2a=700/1 clusters | 2b=70/11 clusters | 3a=0 | 3b=13 | 4=55+

Report: softwarecatalog/openspec/coverage-report.md
JSON:   softwarecatalog/openspec/coverage-report.json

Next:
1. Read the .md manually — resolve spec drift in REQ-DECOMP-003/009 and the "describe-target-state" framing of method-decomposition before annotating
2. `/opsx-annotate softwarecatalog` — creates one ghost change + applies Bucket 1 annotations in one PR
3. `/opsx-reverse-spec softwarecatalog --extend method-decomposition` — but consider splitting into multiple capabilities (see Note 4)
4. Pre-split Bucket 2b namespace-word clusters before any `--cluster` run
