---
status: done
---

# contactpersoon-sync Specification

## Purpose
Synchronises contact-person records into managed Nextcloud user accounts, creating, updating, and removing the linked user as contacts change and assigning group membership and the organisation beheerder role. Also lists and looks up contacts' account details (individually and in bulk) and toggles their linked accounts' enabled state.

@e2e exclude PHP contactpersoon (contact-person) synchronisation backend (Nextcloud-user linking, mapping, upsert) — no UI surface; covered by PHPUnit service tests.

## Requirements
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


### Requirement: The system SHALL only accept a contact person from someone who may add members to its organisation (REQ-003)
The contact sync turns a contact person into an account in the contact's organisation, resets the role group of an existing account named by its e-mail address, and writes names and e-mail address to the account named by `username`. A create, or an update that changes any of `organisation`, `organization`, `email`, `e-mailadres` or `username`, SHALL be refused unless the caller is an administrator, or a member of the contact's OpenRegister organisation in `gebruik-beheerder` or `aanbod-beheerder`. Every one of these keys SHALL be checked on its own, and a contact whose two organisation keys or two e-mail keys hold different values SHALL be refused. A contact person SHALL NOT name an existing account other than the caller's own, in its e-mail keys or in `username`, unless that account already belongs to the contact's organisation, and `username` only when it is the caller's own account. A member MAY change the e-mail address of their own contact person in the same organisation. A contact for an organisation that has no OpenRegister organisation yet (a registration under review) is accepted when it names no existing account. Only the writes stackiq's own contact sync job and contact migration mark as system writes are not checked; other command line writes are.

@e2e exclude Enforced in an OpenRegister pre-save listener; tests/Unit/EventListener/ContactPersonOrganisationListenerTest.php drives it with the real guard.

#### Scenario: REQ-003 another organisation
- GIVEN a signed-in user who is not a member of organisation X
- WHEN they create a contact person for organisation X
- THEN the create MUST be refused and no account is made

#### Scenario: REQ-003 an existing account
- GIVEN an existing account that belongs to organisation A
- WHEN a member manager of organisation X creates a contact person for X with that account's e-mail address
- THEN the create MUST be refused and the account's groups are left alone

#### Scenario: REQ-003 own organisation
- GIVEN a member of organisation X in `gebruik-beheerder`
- WHEN they create a contact person for X with a new e-mail address
- THEN the create MUST be accepted
