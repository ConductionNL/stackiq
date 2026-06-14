> REVERTED 2026-06-01: archived prematurely; implementation not present on development — re-opened for real apply. REQ-001 (CronjobContextTrait::setCronjobContext / clearCronjobContext) names code that does NOT exist anywhere in lib/ or src/; that requirement has been removed from the synced main spec and task-1 re-opened. REQ-002 (OrganizationContactSyncJob::run) IS real and remains documented/checked.

# Retrofit — cronjob-context

Describes observed behavior of 1 real method (REQ-002) under the `cronjob-context` capability. The original retrofit also claimed a `CronjobContextTrait` (REQ-001) that does not exist — see the REVERTED note above.

## Affected code units

- lib/BackgroundJob/OrganizationContactSyncJob.php::run (REQ-002 — real)
- ~~lib/BackgroundJob/CronjobContextTrait.php::setCronjobContext~~ (REQ-001 — NON-EXISTENT, reverted)
- ~~lib/BackgroundJob/CronjobContextTrait.php::clearCronjobContext~~ (REQ-001 — NON-EXISTENT, reverted)

## Approach

- Describe observed inputs/outputs/side-effects of each method.
- Group methods implementing the same observable behavior under one REQ.

Source: openspec/coverage-report (csc.py --mode report) generated 2026-05-26. Umbrella: ConductionNL/softwarecatalog#294.
