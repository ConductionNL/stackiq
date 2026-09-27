# Design: operations-sync-status-and-progress

Read at development 49e65cb4, with `@conduction/nextcloud-vue` 2.57.1.

## Where it fits

| Part | File and line | What changes |
|---|---|---|
| Service | `lib/Service/ProgressTracker.php` (constructor `:82`, `saveProgress()`, `getProgress()` `:332`, `PHASES` `:40`) | state in `ICacheFactory::createDistributed('stackiq_progress')` instead of `ISession`; a `current_<type>` key per operation type; sync phases `processing_contacts` and `processing_users` |
| Wiring | `lib/AppInfo/Application.php:593` to `:600` | the factory passes `ICacheFactory` instead of `ISession` |
| Service | `lib/Service/OrganizationSyncService.php:2976` `performScheduledSync()`, `:2881` `performOptimizedManualSync()`, `:1674` `recordSyncTime()` | start an `organisation_sync` operation, report per batch, complete it, and write app config `last_sync_result` next to `last_sync_time` |
| Job | `lib/BackgroundJob/OrganizationContactSyncJob.php:75` and `:99` | interval as a class constant `INTERVAL_SECONDS`; `run()` returns early when the switch is off |
| Settings | `lib/Service/SettingsService.php:6827` `getCronjobConfig()`, `:6878` `getAvailableCronjobs()` | new `isCronjobEnabled(string $jobId)`; the metadata interval reads `OrganizationContactSyncJob::INTERVAL_SECONDS` |
| Controller and route | new `lib/Controller/SyncStatusController.php`, `GET /api/sync/status` in `appinfo/routes.php` | schedule, last run and current run, for admins and functional administrators |
| Policy | new `lib/Service/SyncAccessPolicy.php` | one place that answers "may this user see the sync": Nextcloud admin or member of `functioneel-beheerder` |
| Controller | `lib/Controller/SettingsController.php:1289` `getProgress()` | read rule: owner, admin, or policy for `organisation_sync` |
| Page | new `src/manifest.d/operations-sync-status-and-progress.json`: page `SyncStatus` at `/organisaties/synchronisation`, `type: custom`, component `SyncStatusView`; menu entry `SyncStatusMenu` with `permission: sync.view` | |
| Menu layout | `src/menu-layout.json` `relocations` | `"SyncStatusMenu": "Organisaties"` (ADR-097) |
| View | new `src/views/sync/SyncStatusView.vue`, registered in `src/customComponents.js` | `CnProgressBar` and `CnWidgetWrapper` from the library; polls while a run is going |
| Shell | `lib/Controller/DashboardController.php:54` `page()`, `src/App.vue:181` `permissions()` | initial state `permissions` (`user`, plus `admin`, plus `sync.view`), read by the shell instead of `window.OC.currentUser.permissions` |

## Decisions

### D1. Progress moves from the session to the distributed cache

`ProgressTracker` writes `progress_<id>` into `ISession`. A PHP session belongs to one browser: the five-minute job runs from cron with no user session, and a page in a second tab of another user can never read it. The distributed cache (the same mechanism `FacetService` uses, `lib/Service/FacetService.php:131`) is shared by every request and by cron. Entries live for one hour after their last write. The public methods and the response shape stay as they are, so `SbomImportService` and `MergeOrganisatieService` keep working unchanged.

A `current_<type>` entry points at the running operation of a type, so the page can find the running sync without knowing its id; `completeOperation()` clears it.

Rejected: a database table of runs. A run in progress is transient state that nobody needs after an hour, and the last result is one small record (D3).

Rejected: the SSE stream (`streamProgress()`, `:1360`) for the page. Polling `GET /api/sync/status` every three seconds while a run is going works behind every proxy and needs no long-lived PHP worker; the stream stays for its current callers.

### D2. The job honours the switch

`CronjobConfiguration.vue:56` lets an admin switch the sync off and stores it in `cronjob_config`, but `OrganizationContactSyncJob::run()` (`:99`) never reads it. A page that says Off while the job keeps running would be worse than no page. `run()` asks `SettingsService::isCronjobEnabled('organization_contact_sync')` (default true when the key is absent, the same default `getCronjobConfig()` uses at `:6847`) and logs and returns when it is false. `isCronjobEnabled()` reads only the `enabled` key, so it does not depend on the deprecated user and organisation context in the same config.

The interval shown is the one the job sets. Today it is written twice, `setInterval(seconds: 300)` in the job and `'interval' => 300` in `getAvailableCronjobs()` (`:6883`); both read one class constant so they cannot disagree.

### D3. The last run is a small record in app config

Next to `last_sync_time`, `recordSyncTime()` also writes `last_sync_result`: started at, finished at, trigger (scheduled or manual), the counts the sync already returns (organisations processed, entities created and updated, contact persons processed, users created) and the number of errors. It is operational state of the app, like `last_sync_time`, not catalogue data, so it stays in app config and not in OpenRegister.

### D4. Who sees the sync

`SyncAccessPolicy` allows a Nextcloud admin or a member of `functioneel-beheerder`, the group `GroupHandler` keeps in step with the contact person role Functioneel-beheerder (`lib/Service/Stackiq/GroupHandler.php:170`). `SyncStatusController` uses it with `#[NoAdminRequired]` and returns 403 otherwise. `getProgress()` keeps its owner check and adds: an admin may read any operation, and the policy may read `organisation_sync` operations. An operation id alone never grants access.

When `organisations-role-mapping-and-access-review` makes the role's group configurable, the policy reads the mapped group; it is the only place that names the group.

### D5. The menu shows the entry only to those users

`CnAppNav` checks an entry's `permission` against the shell's `permissions` list, and lets everything through when the list is empty (`src/components/CnAppNav/CnAppNav.vue:997` in the library). Stackiq's shell reads `window.OC.currentUser.permissions` (`src/App.vue:182`), which Nextcloud does not set, so today the list is always empty. `DashboardController::page()` provides initial state `permissions`: `user` for every signed-in user, `admin` for Nextcloud admins, and `sync.view` for users the policy allows. The list is never empty, so an entry with a permission is hidden from users without it.

## Declarative versus imperative

Progress and the schedule are runtime state of a background job, not object lifecycle, aggregation, notification or relation behaviour. Nothing here can be an `x-openregister-*` rule (ADR-031), so it stays in the service and the job.

## Seed data

No schema changes and no seed objects.

## Risks

- **Cache without a distributed backend.** On an instance with only a local cache, cron and web requests may not share entries. The page then shows the last run and no live bar, and says that live progress needs a shared cache such as Redis.
- **The switch starts to work.** A migration step logs every instance where `cronjob_config` has the sync switched off, so an administrator can see why syncs stop after the upgrade.
- **Permission enforcement.** D5 makes the Integrations entry's `permission: admin` (`src/manifest.d/connection-registry.json:15`) take effect for the first time, which is what that entry declares.
