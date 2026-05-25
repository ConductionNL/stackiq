---
status: draft
retrofit: true
---

# Contactpersonen Api Specification

## Purpose

Captures observed behavior of the contactpersonen (contact persons) management API — listing an organisation's contacts, converting a contact into a Nextcloud user, and the user-account lifecycle (password, groups, enable/disable, info lookups).

## ADDED Requirements

### REQ-001: The system SHALL list an organisation's contact persons with their linked user details

`getContactpersonen(organisationId)`, `getContactPersonsWithUserDetailsForOrganization(organizationUuid)`, `getUserInfo(contactpersoonId)`, `getBulkUserInfo()`/`testBulkUserInfo()`, and `getMe()` MUST return contact-person records — optionally enriched with the linked Nextcloud user's account details — as JSON responses.

#### Scenario: REQ-001 case 1
- WHEN `getContactpersonen(orgId)` is called
- THEN it MUST return the organisation's contact persons

#### Scenario: REQ-001 case 2
- WHEN `getMe()` is called
- THEN it MUST return the current user's contact-person profile

### REQ-002: The system SHALL convert a contact person into a Nextcloud user account

`convertToUser(contactpersoonId)` MUST create a Nextcloud user for the contact person (if one does not already exist) and link it back to the contact record, returning the result as a JSON response.

#### Scenario: REQ-002 case 1
- WHEN `convertToUser(id)` is called for a contact without a user
- THEN a Nextcloud user MUST be created and linked to the contact

### REQ-003: The system SHALL manage the lifecycle of a contact person's linked user account

`changePassword(username,newPassword)`, `updateUserGroups(username,groups)`, `disableUser(contactpersoonId)`, `enableUser(contactpersoonId)`, and `getAvailableGroups()` MUST apply the requested account mutation (password reset, group membership change, enable/disable) or return the available groups, each as a JSON response.

#### Scenario: REQ-003 case 1
- WHEN `disableUser(id)` is called
- THEN the linked user account MUST be disabled

#### Scenario: REQ-003 case 2
- WHEN `updateUserGroups(username, groups)` is called
- THEN the user's group membership MUST be set to the supplied groups
