# Retrofit — organisatie-service

Describes observed behavior of 8 methods in `OrganisatieService` as 5 new REQs under a new `organisatie-service` capability. Code already exists — this change retroactively specifies it.

## Affected code units

- lib/Service/OrganisatieService.php::createOrganisationInOpenRegister
- lib/Service/OrganisatieService.php::updateOrganizationStatus
- lib/Service/OrganisatieService.php::mapOrganizationDataForOpenRegister
- lib/Service/OrganisatieService.php::mapStatus
- lib/Service/OrganisatieService.php::createOrganisationEntityInternal
- lib/Service/OrganisatieService.php::getActiveOrganisationUuid
- lib/Service/OrganisatieService.php::addUsersToOrganization
- lib/Service/OrganisatieService.php::getAdminGroupUsernames

## Approach

- Describe organisation lifecycle (create / status update / user assignment) and the disabled parent-organisation HOTFIX.
- Notes flag the RBAC HOTFIX that disabled automatic parent-org assignment + the redundant exception logging in `addUsersToOrganization`.

Source: openspec/coverage-report.md generated 2026-05-24. Umbrella: ConductionNL/softwarecatalog#285.
