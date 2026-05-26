---
status: draft
retrofit: true
---

# Softwarecatalogue Orchestration Specification

## Purpose

Captures observed behavior of SoftwareCatalogueService — the high-level orchestrator that reacts to SoftwareCatalog object create/update/delete events for contacts, organisations and gebruikers, delegating to the focused handlers while managing user accounts, groups, hierarchy, welcome emails, and OpenRegister organisation sync.

## ADDED Requirements

### REQ-001: The system SHALL react to contact-person create/update/delete events

`processContactpersoon`, `handleNewContact`, `handleContactUpdate`, `handleContactpersoonUpdate`, `handleContactDeletion`, `createUserForContactIfNotExists`, `updateUserGroups`, `ensureOrganizationBeheerder`, `getUserManager`, `syncContactPersonUsernamesWithOrganization`, `ensureContactPersonInOrganization`, `shouldAddContactpersoonToOrganization`, and `addContactpersoonToOrganization` MUST create/maintain the contact's Nextcloud user, group membership, beheerder role, and organisation linkage in response to contact lifecycle events.

#### Scenario: REQ-001 case 1
- WHEN `handleNewContact(obj)` fires
- THEN the contact's user account MUST be created if absent and linked to its organisation

#### Scenario: REQ-001 case 2
- WHEN `shouldAddContactpersoonToOrganization(obj)` returns true and `addContactpersoonToOrganization(obj)` is called
- THEN the contact MUST be added to the organisation entity

### REQ-002: The system SHALL react to organisation create/update/delete events and sync to OpenRegister

`processOrganization`, `handleNewOrganization`, `handleOrganizationUpdate`, `handleOrganizationDeletion`, `syncOrganizationWithOpenRegister`, `createOrganisationInOpenRegister`, and `sendOrganizationWelcomeEmail` MUST provision the organisation's group, mirror the organisation into OpenRegister, and send the welcome email on creation.

#### Scenario: REQ-002 case 1
- WHEN `handleNewOrganization(obj)` fires
- THEN the organisation MUST be created in OpenRegister and a welcome email sent

#### Scenario: REQ-002 case 2
- WHEN `handleOrganizationDeletion(obj)` fires
- THEN the organisation's downstream artefacts MUST be cleaned up

### REQ-003: The system SHALL react to gebruiker lifecycle events and manage account access

`handleNewGebruiker`, `handleGebruikerUpdate`, `sendGebruikerWelcomeEmail`, `blockUserForGebruiker`, `temporarilyBlockUserForGebruiker`, `restoreUserAccessForGebruiker`, `syncUserWithRevertedContact`, and `updateUserFromRevertedGebruiker` MUST manage the gebruiker's account access (block/restore/revert) and welcome notification in response to gebruiker events.

#### Scenario: REQ-003 case 1
- WHEN `handleNewGebruiker(obj)` fires
- THEN the gebruiker's account access MUST be provisioned and a welcome email sent

#### Scenario: REQ-003 case 2
- WHEN `blockUserForGebruiker(obj)` is called
- THEN the linked account MUST be blocked

### REQ-004: The system SHALL expose user-group, hierarchy and structure helpers

`getGenericUserGroups`/`setGenericUserGroups`/`ensureGenericUserGroupsExist`, `getUserHierarchy(username)`, and `getOrganizationStructure(orgUuid)` MUST read/maintain the generic user groups and report the user-manager hierarchy and organisation structure.

#### Scenario: REQ-004 case 1
- WHEN `ensureGenericUserGroupsExist()` is called
- THEN the generic user groups MUST be created if missing

#### Scenario: REQ-004 case 2
- WHEN `getOrganizationStructure(orgUuid)` is called
- THEN the organisation's structure MUST be returned
