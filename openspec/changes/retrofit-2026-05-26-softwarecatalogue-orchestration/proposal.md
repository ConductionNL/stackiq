# Retrofit — softwarecatalogue-orchestration

Describes observed behavior of 33 methods as 4 REQ(s) under the `softwarecatalogue-orchestration` capability. Code already exists — this change retroactively specifies it (strict coverage policy: every method gets a spec).

## Affected code units

- lib/Service/SoftwareCatalogueService.php::processContactpersoon
- lib/Service/SoftwareCatalogueService.php::handleNewContact
- lib/Service/SoftwareCatalogueService.php::handleContactUpdate
- lib/Service/SoftwareCatalogueService.php::handleContactpersoonUpdate
- lib/Service/SoftwareCatalogueService.php::handleContactDeletion
- lib/Service/SoftwareCatalogueService.php::createUserForContactIfNotExists
- lib/Service/SoftwareCatalogueService.php::updateUserGroups
- lib/Service/SoftwareCatalogueService.php::ensureOrganizationBeheerder
- lib/Service/SoftwareCatalogueService.php::getUserManager
- lib/Service/SoftwareCatalogueService.php::syncContactPersonUsernamesWithOrganization
- lib/Service/SoftwareCatalogueService.php::ensureContactPersonInOrganization
- lib/Service/SoftwareCatalogueService.php::shouldAddContactpersoonToOrganization
- lib/Service/SoftwareCatalogueService.php::addContactpersoonToOrganization
- lib/Service/SoftwareCatalogueService.php::processOrganization
- lib/Service/SoftwareCatalogueService.php::handleNewOrganization
- lib/Service/SoftwareCatalogueService.php::handleOrganizationUpdate
- lib/Service/SoftwareCatalogueService.php::handleOrganizationDeletion
- lib/Service/SoftwareCatalogueService.php::syncOrganizationWithOpenRegister
- lib/Service/SoftwareCatalogueService.php::createOrganisationInOpenRegister
- lib/Service/SoftwareCatalogueService.php::sendOrganizationWelcomeEmail
- lib/Service/SoftwareCatalogueService.php::handleNewGebruiker
- lib/Service/SoftwareCatalogueService.php::handleGebruikerUpdate
- lib/Service/SoftwareCatalogueService.php::sendGebruikerWelcomeEmail
- lib/Service/SoftwareCatalogueService.php::blockUserForGebruiker
- lib/Service/SoftwareCatalogueService.php::temporarilyBlockUserForGebruiker
- lib/Service/SoftwareCatalogueService.php::restoreUserAccessForGebruiker
- lib/Service/SoftwareCatalogueService.php::syncUserWithRevertedContact
- lib/Service/SoftwareCatalogueService.php::updateUserFromRevertedGebruiker
- lib/Service/SoftwareCatalogueService.php::getGenericUserGroups
- lib/Service/SoftwareCatalogueService.php::setGenericUserGroups
- lib/Service/SoftwareCatalogueService.php::ensureGenericUserGroupsExist
- lib/Service/SoftwareCatalogueService.php::getUserHierarchy
- lib/Service/SoftwareCatalogueService.php::getOrganizationStructure

## Approach

- Describe observed inputs/outputs/side-effects of each method.
- Group methods implementing the same observable behavior under one REQ.

Source: openspec/coverage-report (csc.py --mode report) generated 2026-05-26. Umbrella: ConductionNL/softwarecatalog#294.
