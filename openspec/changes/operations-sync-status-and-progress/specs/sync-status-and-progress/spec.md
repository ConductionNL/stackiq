# sync-status-and-progress specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- operations-sync-status-and-progress

## Purpose

A functional administrator or a Nextcloud admin sees whether the organisation and contact synchronisation is on, how often it runs, what its last run did, and how far a running sync is, on a page under Organisations.

## ADDED Requirements

### Requirement: REQ-SSP-001 Progress of a long operation SHALL be readable from any request, and only by users allowed to read it

`ProgressTracker` SHALL keep progress in Nextcloud's distributed cache, so a background job can write it and another request can read it. `GET /api/progress/{operationId}` SHALL answer the operation's owner and Nextcloud admins, and for an `organisation_sync` operation also members of the functional administrator group. Anyone else SHALL get 404, the same answer as for an unknown id.

#### Scenario: A running sync started by cron is readable
@e2e exclude Needs a cron run in the middle of a request; tests/Unit/Service/ProgressTrackerTest.php asserts a second tracker instance on the same cache reads the first one's progress, and tests/Unit/Controller/SettingsControllerProgressTest.php asserts the read rule.

- **GIVEN** the scheduled sync running from cron with operation type `organisation_sync`
- **WHEN** a functional administrator's page calls `GET /api/progress/{operationId}` for it
- **THEN** stackiq SHALL answer 200 with the phase, the processed and total items and the percentage

#### Scenario: Another user cannot read an operation by guessing its id
@e2e exclude An authorisation rule; tests/Unit/Controller/SettingsControllerProgressTest.php asserts 404 for a user who is not the owner, not an admin and not allowed by the sync policy.

- **GIVEN** an SBOM import started by an application owner
- **WHEN** another user without admin rights calls `GET /api/progress/{operationId}` with its id
- **THEN** stackiq SHALL answer 404

### Requirement: REQ-SSP-002 The organisation sync SHALL report its progress and keep the result of its last run

The scheduled and the manual organisation sync SHALL start an `organisation_sync` operation, report progress per batch through the organisations, contact persons and users phases, and complete it. When a run ends it SHALL store the start, the end, the trigger (scheduled or manual), the counts and the number of errors as the last run.

#### Scenario: A finished run leaves its result
@e2e exclude The sync touches every organisation; tests/Unit/Service/OrganizationSyncServiceTest.php asserts performScheduledSync() starts and completes an operation and writes last_sync_result with trigger scheduled and its counts.

- **GIVEN** a scheduled run that processes 12 organisations and 30 contact persons with 1 error
- **WHEN** the run ends
- **THEN** the last run record SHALL hold trigger scheduled, 12 organisations, 30 contact persons and 1 error

### Requirement: REQ-SSP-003 The scheduled sync MUST NOT run while its switch is off

`OrganizationContactSyncJob` SHALL read the enable switch stored by the admin cronjob settings before each run and SHALL skip the run, with a log line, when it is off. The interval the job uses and the interval stackiq shows SHALL come from one constant.

#### Scenario: A Nextcloud admin switches the sync off
@e2e exclude A background job; tests/Unit/BackgroundJob/OrganizationContactSyncJobTest.php asserts run() does not call performScheduledSync() when isCronjobEnabled() returns false, and does when the key is absent.

- **GIVEN** a Nextcloud admin who switched Organization Contact Sync off in admin settings
- **WHEN** cron reaches the job
- **THEN** the job SHALL NOT synchronise
- **AND** the Synchronisation page SHALL show the schedule as Off

### Requirement: REQ-SSP-004 The Synchronisation page SHALL show the schedule, the last run and a running sync to Nextcloud admins and functional administrators

A page `SyncStatus` at `/organisaties/synchronisation`, reached from a menu entry under Organisations, SHALL show the interval and whether the sync is on, the last run with its time, trigger, counts and errors, and while a run is going a progress bar with its phase. It SHALL read `GET /api/sync/status`, which SHALL answer Nextcloud admins and members of the functional administrator group and SHALL refuse others with 403. The menu entry SHALL be shown only to those users.

#### Scenario: A functional administrator checks when the sync last ran
@e2e tests/e2e/spec-coverage/sync-status.spec.ts

- **GIVEN** a functional administrator who is not a Nextcloud admin, and a sync that last ran ten minutes ago
- **WHEN** they open Organisations and choose Synchronisation
- **THEN** the page SHALL show every 5 minutes, On, and the last run with its time, trigger and counts

#### Scenario: A running sync shows its progress
@e2e tests/e2e/spec-coverage/sync-status.spec.ts

- **GIVEN** a Nextcloud admin who starts a manual sync in admin settings
- **WHEN** they open `/organisaties/synchronisation` in another tab
- **THEN** the page SHALL show a progress bar with the current phase
- **AND** when the run ends the bar SHALL give way to the new last run

#### Scenario: A regular user neither sees nor reaches the page
@e2e tests/e2e/spec-coverage/sync-status.spec.ts

- **GIVEN** a municipal information manager who is not an admin and not a functional administrator
- **WHEN** they open stackiq
- **THEN** the Organisations menu SHALL have no Synchronisation entry
- **AND** `GET /api/sync/status` SHALL answer 403
