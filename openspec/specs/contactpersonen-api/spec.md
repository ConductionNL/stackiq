---
status: done
---

# contactpersonen-api Specification

## Purpose
Provides a REST API for an organisation's contact persons, listing them with their linked Nextcloud user account details and exposing the current user's own profile. Lets administrators convert a contact into a Nextcloud user and manage that account's lifecycle — password reset, group membership, and enabling or disabling.

@e2e exclude PHP contactpersonen (contact-persons) REST endpoints incl. linked-user management — HTTP contract; covered by Newman REST collections and PHPUnit controller tests. The contacts list UI is covered by the manifest contactpersonen-page test.

## Requirements
### Requirement: The system SHALL list an organisation's contact persons with their linked user details (REQ-001)

`getContactpersonen(organisationId)`, `getContactPersonsWithUserDetailsForOrganization(organizationUuid)`, `getUserInfo(contactpersoonId)`, `getBulkUserInfo()`/`testBulkUserInfo()`, and `getMe()` MUST return contact-person records — optionally enriched with the linked Nextcloud user's account details — as JSON responses.

#### Scenario: REQ-001 case 1
- WHEN `getContactpersonen(orgId)` is called
- THEN it MUST return the organisation's contact persons

#### Scenario: REQ-001 case 2
- WHEN `getMe()` is called
- THEN it MUST return the current user's contact-person profile

### Requirement: The system SHALL convert a contact person into a Nextcloud user account (REQ-002)

`convertToUser(contactpersoonId)` MUST create a Nextcloud user for the contact person (if one does not already exist) and link it back to the contact record, returning the result as a JSON response.

#### Scenario: REQ-002 case 1
- WHEN `convertToUser(id)` is called for a contact without a user
- THEN a Nextcloud user MUST be created and linked to the contact

### Requirement: The system SHALL manage the lifecycle of a contact person's linked user account (REQ-003)

`changePassword(username,newPassword)`, `updateUserGroups(username,groups)`, `disableUser(contactpersoonId)`, `enableUser(contactpersoonId)`, and `getAvailableGroups()` MUST apply the requested account mutation (password reset, group membership change, enable/disable) or return the available groups, each as a JSON response.

#### Scenario: REQ-003 case 1
- WHEN `disableUser(id)` is called
- THEN the linked user account MUST be disabled

#### Scenario: REQ-003 case 2
- WHEN `updateUserGroups(username, groups)` is called
- THEN the user's group membership MUST be set to the supplied groups

