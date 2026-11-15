# role-mapping-and-access-review specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- organisations-role-mapping-and-access-review

## Purpose

A Nextcloud admin tells stackiq which of the organisation's own groups grant each catalogue role, and stackiq keeps the role groups its access rules use in step. A functional administrator reviews, per organisation, who holds which role, where it comes from and when they last signed in, and confirms or withdraws it.

## ADDED Requirements

### Requirement: REQ-RMA-001 A Nextcloud admin SHALL map each catalogue role onto groups of their choice

The admin User groups settings SHALL list the catalogue roles Aanbod-beheerder, Gebruik-beheerder, Gebruik-raadpleger, Functioneel-beheerder, Organisatie-beheerder and VNG-raadpleger, each with the Nextcloud groups whose members hold that role, and the default role for each organisation type. Only a Nextcloud admin SHALL read or change the mapping.

#### Scenario: An admin makes the Inkoop group buyers
@e2e tests/e2e/spec-coverage/role-mapping.spec.ts

- **GIVEN** a Nextcloud admin in stackiq's admin settings and a group Inkoop with one member
- **WHEN** they map Gebruik-beheerder onto Inkoop and save
- **THEN** that member SHALL be in `gebruik-beheerder` after the save
- **AND** the member SHALL see the usages of their own organisation

#### Scenario: A non-admin cannot change the mapping
@e2e exclude An admin guard; tests/Unit/Controller/SettingsControllerUserGroupsTest.php asserts 403 on read and write for a non-admin.

- **GIVEN** a functional administrator who is not a Nextcloud admin
- **WHEN** they post a role mapping to `/api/user-groups/config`
- **THEN** stackiq SHALL answer 403 and keep the mapping

### Requirement: REQ-RMA-002 A person SHALL be in exactly the role groups of the roles they derive

A person's roles SHALL be the union of their contact person's `roles`, the roles whose mapped groups they are in, and the default role of their organisation's type. Stackiq SHALL add them to each matching role group and remove them from role groups they no longer derive, comparing names without regard to case, and SHALL NOT touch any other group. It SHALL do so at sign-in, after the mapping changes, after the contact person's roles change, and nightly.

#### Scenario: A new supplier contact gets the supplier role
@e2e exclude A group side effect; tests/Unit/Service/RoleGrantSyncTest.php asserts a contact person of an organisation of type Supplier is added to aanbod-beheerder and one of type Municipality to gebruik-beheerder.

- **GIVEN** a new contact person of an organisation of type Supplier, with no roles
- **WHEN** their account is created
- **THEN** they SHALL be in `aanbod-beheerder`

#### Scenario: Leaving the mapped group ends the role
@e2e exclude A directory change between sign-ins; tests/Unit/Service/RoleGrantSyncTest.php asserts removal from gebruik-beheerder when the person leaves Inkoop and has no other source for the role.

- **GIVEN** a member of Inkoop holding Gebruik-beheerder only through Inkoop
- **WHEN** they leave Inkoop and the nightly sync runs
- **THEN** they SHALL no longer be in `gebruik-beheerder`

#### Scenario: The upgrade takes nobody's access
@e2e exclude A repair step; tests/Unit/Repair/SeedRolesFromGroupsTest.php asserts every current member of a role group gets the role on their contact person before the first sync.

- **GIVEN** a person an admin had added to `gebruik-beheerder` by hand, without the role on their contact person
- **WHEN** stackiq is upgraded and the sync runs
- **THEN** they SHALL still be in `gebruik-beheerder`

### Requirement: REQ-RMA-003 A functional administrator SHALL review the access of their organisation's people

A page `AccessReview` at `/organisaties/access-review`, under Organisations, SHALL list each contact person of an organisation who has an account, with their roles and the source of each role, their last sign-in, whether the account is enabled, when their access was last reviewed and by whom, and whether a review is due under the configured interval. It SHALL answer Nextcloud admins for any organisation and members of `functioneel-beheerder` or `organisatie-beheerder` for their own active organisation, and refuse others.

#### Scenario: An information manager finds who has not signed in for a year
@e2e tests/e2e/spec-coverage/access-review.spec.ts

- **GIVEN** a functional administrator of Gemeente Voorbeeld, and a colleague who last signed in fourteen months ago and was never reviewed
- **WHEN** they open `/organisaties/access-review`
- **THEN** the colleague SHALL be listed with their last sign-in and marked due

#### Scenario: Another organisation's list is refused
@e2e exclude An authorisation rule; tests/Unit/Controller/AccessReviewControllerTest.php asserts 403 for a functional administrator asking for another organisation and 200 for a Nextcloud admin.

- **GIVEN** a functional administrator of Gemeente Voorbeeld
- **WHEN** they call `GET /api/access-review/{organisationUuid}` for Gemeente Anders
- **THEN** stackiq SHALL answer 403

### Requirement: REQ-RMA-004 A reviewer SHALL confirm or withdraw a person's access, and the decision SHALL be recorded

Keep access SHALL set `accessReviewedAt` and `accessReviewedBy` on the contact person through OpenRegister. Remove role SHALL remove a role from the contact person's `roles`, and the role group SHALL follow. For a role that comes from a mapped group the page SHALL name that group instead of offering Remove role. A reviewer SHALL NOT confirm their own access. A Nextcloud admin SHALL also be able to disable the account.

#### Scenario: A reviewer confirms a colleague
@e2e tests/e2e/spec-coverage/access-review.spec.ts

- **GIVEN** a due colleague on the Access review page
- **WHEN** the functional administrator chooses Keep access
- **THEN** the row SHALL show today as reviewed, by that administrator, and no longer due
- **AND** the contact person's history SHALL record the change

#### Scenario: A reviewer withdraws a role
@e2e tests/e2e/spec-coverage/access-review.spec.ts

- **GIVEN** a colleague who holds Gebruik-beheerder on their contact person and no longer buys software
- **WHEN** the functional administrator chooses Remove role for Gebruik-beheerder
- **THEN** the colleague SHALL no longer hold the role or be in `gebruik-beheerder`

#### Scenario: A reviewer cannot confirm themselves
@e2e exclude A guard; tests/Unit/Controller/AccessReviewControllerTest.php asserts the confirm endpoint refuses a reviewer's own contact person.

- **GIVEN** a functional administrator on the Access review page
- **WHEN** they try to confirm their own row
- **THEN** stackiq SHALL refuse it
