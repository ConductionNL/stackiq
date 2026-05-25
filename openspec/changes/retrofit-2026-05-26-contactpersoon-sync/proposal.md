# Retrofit — contactpersoon-sync

Describes observed behavior of 11 methods as 2 REQ(s) under the `contactpersoon-sync` capability. Code already exists — this change retroactively specifies it (strict coverage policy: every method gets a spec).

## Affected code units

- lib/Service/ContactpersoonService.php::processContactpersoon
- lib/Service/ContactpersoonService.php::handleContactpersoonUpdate
- lib/Service/ContactpersoonService.php::handleContactDeletion
- lib/Service/ContactpersoonService.php::updateUserGroups
- lib/Service/ContactpersoonService.php::ensureOrganizationBeheerder
- lib/Service/ContactpersoonService.php::getUserManager
- lib/Service/ContactpersoonService.php::getContactPersonsForOrganization
- lib/Service/ContactpersoonService.php::getContactPersonsWithUserDetailsForOrganization
- lib/Service/ContactpersoonService.php::getBulkUserInfo
- lib/Service/ContactpersoonService.php::enableUserForContactpersoon
- lib/Service/ContactpersoonService.php::disableUserForContactpersoon

## Approach

- Describe observed inputs/outputs/side-effects of each method.
- Group methods implementing the same observable behavior under one REQ.

Source: openspec/coverage-report (csc.py --mode report) generated 2026-05-26. Umbrella: ConductionNL/softwarecatalog#294.
