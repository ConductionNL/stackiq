---
status: done
---

# stackique-orchestration Specification

## Purpose
Coordinates the cross-service reactions to contact-person, organisation, and gebruiker lifecycle events: provisioning and linking Nextcloud user accounts, maintaining group membership and beheerder roles, mirroring organisations into OpenRegister, and sending welcome emails. It also blocks, restores, and reverts account access for gebruikers and exposes helpers for generic user groups, the user-manager hierarchy, and organisation structure.

@e2e exclude PHP orchestration service backend (cross-service coordination of sync/import/export flows) — no UI surface; covered by PHPUnit service tests.

## Requirements
### Requirement: The system SHALL react to contact-person create/update/delete events (REQ-001)

`processContactpersoon`, `handleNewContact`, `handleContactUpdate`, `handleContactpersoonUpdate`, `handleContactDeletion`, `createUserForContactIfNotExists`, `updateUserGroups`, `ensureOrganizationBeheerder`, `getUserManager`, `syncContactPersonUsernamesWithOrganization`, `ensureContactPersonInOrganization`, `shouldAddContactpersoonToOrganization`, and `addContactpersoonToOrganization` MUST create/maintain the contact's Nextcloud user, group membership, beheerder role, and organisation linkage in response to contact lifecycle events.

#### Scenario: REQ-001 case 1
- WHEN `handleNewContact(obj)` fires
- THEN the contact's user account MUST be created if absent and linked to its organisation

#### Scenario: REQ-001 case 2
- WHEN `shouldAddContactpersoonToOrganization(obj)` returns true and `addContactpersoonToOrganization(obj)` is called
- THEN the contact MUST be added to the organisation entity

### Requirement: The system SHALL react to organisation create/update/delete events and sync to OpenRegister (REQ-002)

`processOrganization`, `handleNewOrganization`, `handleOrganizationUpdate`, `handleOrganizationDeletion`, `syncOrganizationWithOpenRegister`, `createOrganisationInOpenRegister`, and `sendOrganizationWelcomeEmail` MUST provision the organisation's group, mirror the organisation into OpenRegister, and send the welcome email on creation.

#### Scenario: REQ-002 case 1
- WHEN `handleNewOrganization(obj)` fires
- THEN the organisation MUST be created in OpenRegister and a welcome email sent

#### Scenario: REQ-002 case 2
- WHEN `handleOrganizationDeletion(obj)` fires
- THEN the organisation's downstream artefacts MUST be cleaned up

### Requirement: The system SHALL react to gebruiker lifecycle events and manage account access (REQ-003)

`handleNewGebruiker`, `handleGebruikerUpdate`, `sendGebruikerWelcomeEmail`, `blockUserForGebruiker`, `temporarilyBlockUserForGebruiker`, `restoreUserAccessForGebruiker`, `syncUserWithRevertedContact`, and `updateUserFromRevertedGebruiker` MUST manage the gebruiker's account access (block/restore/revert) and welcome notification in response to gebruiker events.

#### Scenario: REQ-003 case 1
- WHEN `handleNewGebruiker(obj)` fires
- THEN the gebruiker's account access MUST be provisioned and a welcome email sent

#### Scenario: REQ-003 case 2
- WHEN `blockUserForGebruiker(obj)` is called
- THEN the linked account MUST be blocked

### Requirement: The system SHALL expose user-group, hierarchy and structure helpers (REQ-004)

`getGenericUserGroups`/`setGenericUserGroups`/`ensureGenericUserGroupsExist`, `getUserHierarchy(username)`, and `getOrganizationStructure(orgUuid)` MUST read/maintain the generic user groups and report the user-manager hierarchy and organisation structure.

#### Scenario: REQ-004 case 1
- WHEN `ensureGenericUserGroupsExist()` is called
- THEN the generic user groups MUST be created if missing

#### Scenario: REQ-004 case 2
- WHEN `getOrganizationStructure(orgUuid)` is called
- THEN the organisation's structure MUST be returned

