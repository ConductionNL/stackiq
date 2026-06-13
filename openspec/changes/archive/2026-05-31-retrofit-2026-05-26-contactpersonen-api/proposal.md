# Retrofit — contactpersonen-api

Describes observed behavior of 12 methods as 3 REQ(s) under the `contactpersonen-api` capability. Code already exists — this change retroactively specifies it (strict coverage policy: every method gets a spec).

## Affected code units

- lib/Controller/ContactpersonenController.php::getContactpersonen
- lib/Controller/ContactpersonenController.php::getContactPersonsWithUserDetailsForOrganization
- lib/Controller/ContactpersonenController.php::getUserInfo
- lib/Controller/ContactpersonenController.php::getBulkUserInfo
- lib/Controller/ContactpersonenController.php::testBulkUserInfo
- lib/Controller/ContactpersonenController.php::getMe
- lib/Controller/ContactpersonenController.php::convertToUser
- lib/Controller/ContactpersonenController.php::changePassword
- lib/Controller/ContactpersonenController.php::updateUserGroups
- lib/Controller/ContactpersonenController.php::disableUser
- lib/Controller/ContactpersonenController.php::enableUser
- lib/Controller/ContactpersonenController.php::getAvailableGroups

## Approach

- Describe observed inputs/outputs/side-effects of each method.
- Group methods implementing the same observable behavior under one REQ.

Source: openspec/coverage-report (csc.py --mode report) generated 2026-05-26. Umbrella: ConductionNL/softwarecatalog#294.
