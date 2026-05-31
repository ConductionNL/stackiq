# Retrofit — sc-handlers

Describes observed behavior of 44 methods as 4 REQ(s) under the `sc-handlers` capability. Code already exists — this change retroactively specifies it (strict coverage policy: every method gets a spec).

## Affected code units

- lib/Service/SoftwareCatalogue/ContactPersonHandler.php::generateUsernameFromContactData
- lib/Service/SoftwareCatalogue/ContactPersonHandler.php::sanitizeEmailForUsername
- lib/Service/SoftwareCatalogue/ContactPersonHandler.php::validateEmailForUsername
- lib/Service/SoftwareCatalogue/ContactPersonHandler.php::createUserAccount
- lib/Service/SoftwareCatalogue/ContactPersonHandler.php::storeContactNameFields
- lib/Service/SoftwareCatalogue/ContactPersonHandler.php::setUserManager
- lib/Service/SoftwareCatalogue/ContactPersonHandler.php::getUserManager
- lib/Service/SoftwareCatalogue/ContactPersonHandler.php::setUserActive
- lib/Service/SoftwareCatalogue/ContactPersonHandler.php::setUserInactive
- lib/Service/SoftwareCatalogue/ContactPersonHandler.php::handleNewContact
- lib/Service/SoftwareCatalogue/ContactPersonHandler.php::handleContactUpdate
- lib/Service/SoftwareCatalogue/ContactPersonHandler.php::handleContactDeletion
- lib/Service/SoftwareCatalogue/ContactPersonHandler.php::handleContactpersoonUpdate
- lib/Service/SoftwareCatalogue/ContactPersonHandler.php::processContactpersoon
- lib/Service/SoftwareCatalogue/ContactPersonHandler.php::isFirstContactForOrganization
- lib/Service/SoftwareCatalogue/ContactPersonHandler.php::assignBeheerderRole
- lib/Service/SoftwareCatalogue/ContactPersonHandler.php::shouldAddContactpersoonToOrganization
- lib/Service/SoftwareCatalogue/ContactPersonHandler.php::addContactpersoonToOrganization
- lib/Service/SoftwareCatalogue/ContactPersonHandler.php::ensureContactpersoonInOrganization
- lib/Service/SoftwareCatalogue/ContactPersonHandler.php::addUserToOrganizationEntity
- lib/Service/SoftwareCatalogue/ContactPersonHandler.php::updateUserGroupsFromContactData
- lib/Service/SoftwareCatalogue/ContactPersonHandler.php::updateUserGroupsFromRoles
- lib/Service/SoftwareCatalogue/GroupHandler.php::getGenericUserGroups
- lib/Service/SoftwareCatalogue/GroupHandler.php::setGenericUserGroups
- lib/Service/SoftwareCatalogue/GroupHandler.php::ensureGenericUserGroupsExist
- lib/Service/SoftwareCatalogue/GroupHandler.php::createGroupIfNotExists
- lib/Service/SoftwareCatalogue/GroupHandler.php::updateUserGroups
- lib/Service/SoftwareCatalogue/GroupHandler.php::updateRoleBasedGroups
- lib/Service/SoftwareCatalogue/GroupHandler.php::updateOrganizationGroups
- lib/Service/SoftwareCatalogue/GroupHandler.php::updateGemeenteGroups
- lib/Service/SoftwareCatalogue/GroupHandler.php::getAllGroups
- lib/Service/SoftwareCatalogue/GroupHandler.php::validateGroups
- lib/Service/SoftwareCatalogue/HierarchyHandler.php::ensureOrganizationBeheerder
- lib/Service/SoftwareCatalogue/HierarchyHandler.php::setupManagerRelationships
- lib/Service/SoftwareCatalogue/HierarchyHandler.php::getUserHierarchy
- lib/Service/SoftwareCatalogue/HierarchyHandler.php::getOrganizationStructure
- lib/Service/SoftwareCatalogue/OrganizationHandler.php::processOrganization
- lib/Service/SoftwareCatalogue/OrganizationHandler.php::ensureOrganizationGroup
- lib/Service/SoftwareCatalogue/OrganizationHandler.php::createGroupIfNotExists
- lib/Service/SoftwareCatalogue/OrganizationHandler.php::sanitizeGroupName
- lib/Service/SoftwareCatalogue/OrganizationHandler.php::processContactpersonen
- lib/Service/SoftwareCatalogue/OrganizationHandler.php::handleNewOrganization
- lib/Service/SoftwareCatalogue/OrganizationHandler.php::getOrganizationBeheerders
- lib/Service/SoftwareCatalogue/OrganizationHandler.php::userBelongsToOrganization

## Approach

- Describe observed inputs/outputs/side-effects of each method.
- Group methods implementing the same observable behavior under one REQ.

Source: openspec/coverage-report (csc.py --mode report) generated 2026-05-26. Umbrella: ConductionNL/softwarecatalog#294.
