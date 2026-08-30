---
status: done
---

# sc-handlers Specification

## Purpose
Provides the object-lifecycle handlers that react to OpenRegister save, update, and delete events for contact persons and organisations. They provision and maintain a contact person's Nextcloud account, create and reconcile user groups from roles, organisation, and gemeente, maintain the user-manager hierarchy, and provision an organisation's group while managing its beheerders and membership.

@e2e exclude PHP object-lifecycle handlers (OpenRegister save/update/delete event handlers) — backend event plumbing with no UI surface; covered by PHPUnit handler tests.

## Requirements
### Requirement: The system SHALL provision and maintain a contact person's Nextcloud user account (REQ-001)

ContactPersonHandler MUST derive a username (`generateUsernameFromContactData`, `sanitizeEmailForUsername`, `validateEmailForUsername`), create the account (`createUserAccount`), store profile fields (`storeContactNameFields`), set/get the manager (`setUserManager`/`getUserManager`), toggle active state (`setUserActive`/`setUserInactive`), and react to contact lifecycle events (`handleNewContact`, `handleContactUpdate`, `handleContactDeletion`, `handleContactpersoonUpdate`, `processContactpersoon`). `isFirstContactForOrganization`, `assignBeheerderRole`, `shouldAddContactpersoonToOrganization`, `addContactpersoonToOrganization`, `ensureContactpersoonInOrganization`, and `addUserToOrganizationEntity` MUST manage organisation linkage + beheerder assignment, and `updateUserGroupsFromContactData`/`updateUserGroupsFromRoles` MUST reconcile group membership.

#### Scenario: REQ-001 case 1
- WHEN `createUserAccount(obj, true)` is called for the first contact of an organisation
- THEN a Nextcloud user MUST be created and assigned the beheerder role

#### Scenario: REQ-001 case 2
- WHEN `generateUsernameFromContactData(data)` is called
- THEN it MUST return a sanitised, unique username

### Requirement: The system SHALL provision groups and map roles/organisation/gemeente to group membership (REQ-002)

GroupHandler MUST read/write the generic user groups (`getGenericUserGroups`/`setGenericUserGroups`/`ensureGenericUserGroupsExist`), create groups on demand (`createGroupIfNotExists`), list/validate groups (`getAllGroups`/`validateGroups`), and reconcile a user's group membership from their roles, organisation and gemeente (`updateUserGroups`, `updateRoleBasedGroups`, `updateOrganizationGroups`, `updateGemeenteGroups`).

#### Scenario: REQ-002 case 1
- WHEN `updateRoleBasedGroups(user, objectData)` is called
- THEN the user's role-derived group membership MUST be set

#### Scenario: REQ-002 case 2
- WHEN `createGroupIfNotExists('org-X')` is called for a missing group
- THEN the group MUST be created and returned

### Requirement: The system SHALL maintain user-manager hierarchy and report organisation structure (REQ-003)

HierarchyHandler MUST ensure an organisation has a beheerder (`ensureOrganizationBeheerder`), wire manager relationships (`setupManagerRelationships`), and report the manager chain for a user (`getUserHierarchy`) and the full structure of an organisation (`getOrganizationStructure`).

#### Scenario: REQ-003 case 1
- WHEN `setupManagerRelationships(...)` is called
- THEN each contact's Nextcloud manager MUST be set to its organisation beheerder

#### Scenario: REQ-003 case 2
- WHEN `getUserHierarchy(username)` is called
- THEN it MUST return the user's manager chain

### Requirement: The system SHALL provision an organisation's group and manage its beheerders and membership (REQ-004)

OrganizationHandler MUST process an organisation (`processOrganization`, `handleNewOrganization`), ensure/create its group with a sanitised name (`ensureOrganizationGroup`, `createGroupIfNotExists`, `sanitizeGroupName`), process its contact persons (`processContactpersonen`), list its beheerders (`getOrganizationBeheerders`), and test organisation membership (`userBelongsToOrganization`).

#### Scenario: REQ-004 case 1
- WHEN `processOrganization(obj)` is called for a new organisation
- THEN its group MUST be created with a sanitised name

#### Scenario: REQ-004 case 2
- WHEN `userBelongsToOrganization(user, orgUuid)` is called
- THEN it MUST return whether the user is a member of that organisation

