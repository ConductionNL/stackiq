# Tasks: operations-sync-status-and-progress

## Implementation tasks

### Task 1: Keep progress in the distributed cache and tighten who may read it
- **spec_ref**: openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-001-progress-of-a-long-operation-shall-be-readable-from-any-request-and-only-by-users-allowed-to-read-it
- **files**: `lib/Service/ProgressTracker.php`, `lib/AppInfo/Application.php`, `lib/Service/SyncAccessPolicy.php`, `lib/Controller/SettingsController.php`, `tests/Unit/Service/ProgressTrackerTest.php`, `tests/Unit/Controller/SettingsControllerProgressTest.php`
- **acceptance_criteria**:
  - GIVEN two tracker instances on the same cache WHEN one writes progress THEN the other reads it
  - GIVEN a running operation of a type WHEN the current entry is read THEN it names that operation, and after completeOperation() it is empty
  - GIVEN a caller who is not the owner, not an admin and not allowed by the policy WHEN they read an operation THEN they get 404
  - GIVEN SbomImportService and MergeOrganisatieService WHEN their existing tests run THEN they pass unchanged
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Service/ProgressTrackerTest.php and tests/Unit/Controller/SettingsControllerProgressTest.php)

### Task 2: Report sync progress and keep the last run
- **spec_ref**: openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-002-the-organisation-sync-shall-report-its-progress-and-keep-the-result-of-its-last-run
- **files**: `lib/Service/OrganizationSyncService.php`, `tests/Unit/Service/OrganizationSyncServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a scheduled or manual sync WHEN it runs THEN it starts an organisation_sync operation, moves through the organisations, contact persons and users phases, and completes it
  - GIVEN a run that ends WHEN recordSyncTime() runs THEN last_sync_result holds start, end, trigger, counts and error count
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Service/OrganizationSyncServiceTest.php)

### Task 3: Make the job honour its switch and share one interval
- **spec_ref**: openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-003-the-scheduled-sync-must-not-run-while-its-switch-is-off
- **files**: `lib/BackgroundJob/OrganizationContactSyncJob.php`, `lib/Service/SettingsService.php`, `lib/Migration/` (a repair step that logs a switched-off sync), `tests/Unit/BackgroundJob/OrganizationContactSyncJobTest.php`
- **acceptance_criteria**:
  - GIVEN the switch off WHEN run() is called THEN performScheduledSync() is not called and a log line says why
  - GIVEN no cronjob_config key WHEN run() is called THEN the sync runs
  - GIVEN the job and getAvailableCronjobs() WHEN their interval is read THEN both return INTERVAL_SECONDS
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/BackgroundJob/OrganizationContactSyncJobTest.php)

### Task 4: Add the sync status endpoint
- **spec_ref**: openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-004-the-synchronisation-page-shall-show-the-schedule-the-last-run-and-a-running-sync-to-nextcloud-admins-and-functional-administrators
- **files**: `lib/Controller/SyncStatusController.php`, `appinfo/routes.php`, `tests/Unit/Controller/SyncStatusControllerTest.php`
- **acceptance_criteria**:
  - GIVEN an admin or a functional administrator WHEN they call GET /api/sync/status THEN they get interval, enabled, last run and the current run or null
  - GIVEN any other signed-in user WHEN they call it THEN they get 403
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Controller/SyncStatusControllerTest.php)

### Task 5: Add the Synchronisation page and the permission list
- **spec_ref**: openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-004-the-synchronisation-page-shall-show-the-schedule-the-last-run-and-a-running-sync-to-nextcloud-admins-and-functional-administrators
- **files**: `src/manifest.d/operations-sync-status-and-progress.json`, `src/menu-layout.json`, `src/views/sync/SyncStatusView.vue`, `src/customComponents.js`, `lib/Controller/DashboardController.php`, `src/App.vue`, `tests/vitest/syncStatusView.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a functional administrator WHEN they open Organisations THEN Synchronisation is a child entry and the page shows interval, On or Off and the last run
  - GIVEN a running sync WHEN the page is open THEN it polls every three seconds and shows the phase and percentage, and stops polling when the run ends
  - GIVEN a regular user WHEN the menu renders THEN the entry is hidden, and the Integrations entry is hidden for non-admins
  - GIVEN a Dutch instance WHEN the page renders THEN every label is Dutch
- [ ] Implement
- [ ] Test (vitest tests/vitest/syncStatusView.spec.js and Playwright tests/e2e/spec-coverage/sync-status.spec.ts)

### Task 6: Document the Synchronisation page
- **spec_ref**: openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-004-the-synchronisation-page-shall-show-the-schedule-the-last-run-and-a-running-sync-to-nextcloud-admins-and-functional-administrators
- **files**: `docs/features/sync-status-and-progress.md`
- **acceptance_criteria**:
  - GIVEN the docs WHEN a reader opens the feature page THEN it shows the page with a last run and with a running sync, and says who can see it
- [ ] Implement
- [ ] Test (docs build and a manual read against the running app)

## Verification

- `openspec validate operations-sync-status-and-progress --type change --strict`
- PHPUnit: tests/Unit/Service/ProgressTrackerTest.php, tests/Unit/Controller/SettingsControllerProgressTest.php, tests/Unit/Service/OrganizationSyncServiceTest.php, tests/Unit/BackgroundJob/OrganizationContactSyncJobTest.php, tests/Unit/Controller/SyncStatusControllerTest.php, and the existing SBOM import and organisation merge tests
- vitest: tests/vitest/syncStatusView.spec.js
- Playwright: tests/e2e/spec-coverage/sync-status.spec.ts
- Docs in docs/features/sync-status-and-progress.md with screenshots (ADR-010)
- English and Dutch strings for the page, the phases, the states and the menu entry (ADR-005)
