---
kind: code
depends_on: []
---

# See the synchronisation schedule, its last run and a running sync

## Summary

Stackiq synchronises organisations, contact persons and users every five minutes. Only a Nextcloud admin can see that it happens, in admin settings, and even they see the last run time and a spinner, never how far a running sync is. The functional administrator of the catalogue, who answers "why is our new colleague not in the catalogue yet", sees nothing. This change records the progress of a synchronisation where any request can read it, keeps the result of the last run, makes the schedule's on and off switch real, and adds a Synchronisation page under Organisations for the Nextcloud admin and the functional administrator.

## Why

This change covers two matrix rows.

- `stackiq:ins-progress`, "Follow the progress of a long synchronisation or import." Stackiq rates itself partial: a progress API exists but no page reads it. SAP LeanIX rates yes: "A real-time progress widget in the inventory side-panel keeps you informed of import status" (https://updates.leanix.net/announcements/product-update-march-2026), and integration runs are followed in Administration, Integrations, Sync Log (https://help.sap.com/docs/leanix/ea/collibra-data-catalog-integration). GLPI rates yes from its source at 11.0.9: massive actions show a progress bar (`src/MassiveAction.php:1294` `displayProgressBar`) and long web operations report through `src/Glpi/Controller/ProgressController.php:50` `/progress/check/{key}`.
- `stackiq:ops-scheduled-sync`, "Run the organisation and contact synchronisation on a schedule and see when it last ran." Stackiq rates itself partial: the sync runs on a schedule and shows its last run time, but only to an admin. SAP LeanIX rates yes: "enable automated nightly runs for an inbound Integration API processor" (https://help.sap.com/docs/leanix/ea/configuring-automated-nightly-runs-for-inbound-processors). BlueDolphin rates yes: "This data can then be synchronized with BlueDolphin based on a scheduled task, periodically (for example, every hour)" (https://help.bluedolphin.io/en/articles/11967472-welcome-to-bluedolphin).

Both rows are partial and built. For `ins-progress` this change builds a page that shows the progress of a running synchronisation; for `ops-scheduled-sync` it builds the schedule and last run for the organisation's functional administrator, not only the Nextcloud admin.

The row `stackiq:arch-import-progress` (follow and cancel a running ArchiMate import) is marked building elsewhere and is not part of this change.

## What stackiq has today

Read at development 49e65cb4.

- `lib/Controller/SettingsController.php:1289` `getProgress()` and `:1360` `streamProgress()` (`appinfo/routes.php:119` and `:120`) serve `lib/Service/ProgressTracker.php`. No file under `src/` calls `/api/progress`.
- `ProgressTracker` keeps its state in the PHP session (`ISession`, constructor at `:82`, `saveProgress()` writes `progress_<id>` into the session, `getProgress()` at `:332` reads it back). A session belongs to one browser, so a background job has none to write to and another user can never read it. Its phases are ArchiMate import phases (`:40` `PHASES`). Only `SbomImportService` (`:144`) and `MergeOrganisatieService` (`:242`) start operations.
- `lib/BackgroundJob/OrganizationContactSyncJob.php:75` sets a 300 second interval and `run()` (`:99`) calls `OrganizationSyncService::performScheduledSync()` (`lib/Service/OrganizationSyncService.php:2976`) every time. It never reads the enable switch that `src/views/settings/sections/CronjobConfiguration.vue:56` shows and stores in app config `cronjob_config`; only the deprecated context helpers read that key (`lib/Service/SettingsService.php:6827`, `:6984`). The switch changes nothing.
- The last run is one timestamp, app config `last_sync_time`, written by `recordSyncTime()` (`lib/Service/OrganizationSyncService.php:1674`) and read into the status at `:1609`. Counts and errors of that run are logged, not kept.
- `src/views/settings/sections/OrganizationSynchronization.vue:211` shows Last sync inside admin settings. The ArchiMate import shows a spinner and then the final count (`src/views/settings/sections/ArchiMateImportExport.vue:103`).
- The functional administrator role exists as the Nextcloud group `functioneel-beheerder`, created by `lib/Service/Stackiq/GroupHandler.php:170` from the contact person role Functioneel-beheerder.

## What this change builds

- `ProgressTracker` stores progress in Nextcloud's distributed cache instead of the session (in the app config when no cache is shared by every server and the CLI), so a background job can write it and a page in another request can read it, with the sync phases added.
- The scheduled and the manual organisation sync report progress per batch and keep a last run record: start, end, trigger, counts and number of errors.
- The job honours the enable switch.
- A Synchronisation page at `/organisaties/synchronisation`, a child of the Organisations menu entry, for Nextcloud admins and functional administrators: the schedule (interval, on or off), the last run, and a progress bar while a run is going.
- A permission list for the app shell, so the menu shows that entry only to those users.

## Out of scope

- Following and cancelling the ArchiMate import: row `stackiq:arch-import-progress`.
- Changing the schedule from the page. The interval and switch stay in admin settings; the page reads them.
- A history of past runs. The page shows the last run; a run log can follow if administrators ask for it.
- Starting a sync from the page. The manual sync stays an admin settings action.
- Synchronisation with outside systems. Those runs belong to integriq (ADR-091).

## Risks

- Moving progress out of the session changes who can read it. The read rule becomes explicit: the owner of an operation, a Nextcloud admin, and for the sync operation also the functional administrator. An operation id alone never grants access.
- Honouring the switch means a switch that was off by mistake now stops the sync. The migration step logs every instance where it is off, and the page shows Off in plain sight.
- The permission list turns on the menu `permission` check for the first time; the Integrations entry (`src/manifest.d/connection-registry.json:15`, `permission: admin`) then really hides for non-admins, which is what it declares.
