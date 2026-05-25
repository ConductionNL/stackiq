# Retrofit — cronjob-context

Describes observed behavior of 3 methods as 2 REQ(s) under the `cronjob-context` capability. Code already exists — this change retroactively specifies it (strict coverage policy: every method gets a spec).

## Affected code units

- lib/BackgroundJob/CronjobContextTrait.php::setCronjobContext
- lib/BackgroundJob/CronjobContextTrait.php::clearCronjobContext
- lib/BackgroundJob/OrganizationContactSyncJob.php::run

## Approach

- Describe observed inputs/outputs/side-effects of each method.
- Group methods implementing the same observable behavior under one REQ.

Source: openspec/coverage-report (csc.py --mode report) generated 2026-05-26. Umbrella: ConductionNL/softwarecatalog#294.
