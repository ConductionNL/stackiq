---
status: draft
retrofit: true
---

# Contactpersoon Sync Specification

## Purpose

Captures observed behavior of ContactpersoonService — synchronising SoftwareCatalog contact-person records to Nextcloud user accounts: processing creates/updates, assigning beheerder roles and groups, resolving the user's manager, and enabling/disabling/deleting the linked account.

## ADDED Requirements

### Requirement: The system SHALL process a contact person into a managed Nextcloud user (REQ-001)

`processContactpersoon(contactpersoonObject,isUpdate)` MUST create or update the linked Nextcloud user from the contact record; `handleContactpersoonUpdate(new,old)` MUST reconcile changes; `handleContactDeletion(contactObject)` MUST handle removal. `updateUserGroups(contact,username)` and `ensureOrganizationBeheerder(contact,username)` MUST set group membership / beheerder role. `getUserManager(username)` MUST resolve the user's configured manager.

#### Scenario: REQ-001 case 1
- WHEN `processContactpersoon(obj, false)` is called for a new contact
- THEN a linked user MUST be created and configured

#### Scenario: REQ-001 case 2
- WHEN `ensureOrganizationBeheerder(obj, username)` is called
- THEN the user MUST be granted the organisation beheerder role

### Requirement: The system SHALL list and look up contact persons' user details and toggle their accounts (REQ-002)

`getContactPersonsForOrganization(orgUuid)` and `getContactPersonsWithUserDetailsForOrganization(orgUuid)` MUST list an organisation's contacts (the latter enriched with account details); `getBulkUserInfo(ids)` MUST batch-resolve account details; `enableUserForContactpersoon(id)` / `disableUserForContactpersoon(id)` MUST toggle the linked account's enabled state.

#### Scenario: REQ-002 case 1
- WHEN `getContactPersonsWithUserDetailsForOrganization(orgUuid)` is called
- THEN each contact MUST be returned with its linked account details

#### Scenario: REQ-002 case 2
- WHEN `disableUserForContactpersoon(id)` is called
- THEN the linked account MUST be disabled
