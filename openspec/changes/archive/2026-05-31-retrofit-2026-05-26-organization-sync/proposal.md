# Retrofit — organization-sync

Describes observed behavior of 13 methods as 3 REQ(s) under the `organization-sync` capability. Code already exists — this change retroactively specifies it (strict coverage policy: every method gets a spec).

## Affected code units

- lib/Service/OrganizationSyncService.php::performOrganizationsSync
- lib/Service/OrganizationSyncService.php::performContactSync
- lib/Service/OrganizationSyncService.php::performUserSync
- lib/Service/OrganizationSyncService.php::performFullSync
- lib/Service/OrganizationSyncService.php::performScheduledSync
- lib/Service/OrganizationSyncService.php::performManualSync
- lib/Service/OrganizationSyncService.php::performOptimizedManualSync
- lib/Service/OrganizationSyncService.php::processSpecificOrganization
- lib/Service/OrganizationSyncService.php::processSpecificContactPerson
- lib/Service/OrganizationSyncService.php::ensureOrganisationEntityPublic
- lib/Service/OrganizationSyncService.php::getSyncStatus
- lib/Service/OrganizationSyncService.php::getSyncStatusWithErrorHandling
- lib/Service/OrganizationSyncService.php::recordSyncTime

## Approach

- Describe observed inputs/outputs/side-effects of each method.
- Group methods implementing the same observable behavior under one REQ.

Source: openspec/coverage-report (csc.py --mode report) generated 2026-05-26. Umbrella: ConductionNL/softwarecatalog#294.
