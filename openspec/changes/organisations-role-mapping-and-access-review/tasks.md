# Tasks: organisations-role-mapping-and-access-review

## Implementation tasks

### Task 1: Add the role map and its admin settings
- **spec_ref**: openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-001-a-nextcloud-admin-shall-map-each-catalogue-role-onto-groups-of-their-choice
- **files**: `lib/Service/RoleGroupMap.php`, `lib/Service/SettingsService.php`, `lib/Controller/SettingsController.php`, `src/views/settings/sections/UserGroupsConfiguration.vue`, `src/store/modules/settings.js`, `tests/Unit/Service/RoleGroupMapTest.php`, `tests/Unit/Controller/SettingsControllerUserGroupsTest.php`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN no saved mapping WHEN the map is read THEN each of the six roles has its lower-case role group and no source groups, and the four organisation types have their default roles on the English enum
  - GIVEN an admin WHEN they save Gebruik-beheerder onto Inkoop and an interval of 180 days THEN the config returns both
  - GIVEN a non-admin WHEN they read or write the config THEN they get 403
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Service/RoleGroupMapTest.php and tests/Unit/Controller/SettingsControllerUserGroupsTest.php)

### Task 2: Keep role groups in step and fix the two broken paths
- **spec_ref**: openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
- **files**: `lib/Service/RoleGrantSync.php`, `lib/Service/Stackiq/GroupHandler.php`, `lib/Service/Stackiq/ContactPersonHandler.php`, `tests/Unit/Service/RoleGrantSyncTest.php`
- **acceptance_criteria**:
  - GIVEN roles from the contact person, a mapped group and the organisation type WHEN rolesFor() runs THEN it returns their union, compared without regard to case
  - GIVEN a person who no longer derives a role WHEN the sync runs THEN they leave that role group and keep every non-role group
  - GIVEN a Supplier contact without roles WHEN their account is created THEN they are in aanbod-beheerder
  - GIVEN the old updateGemeenteGroups() WHEN the code is searched THEN it is gone
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Service/RoleGrantSyncTest.php)

### Task 3: Run the sync at sign-in, on changes and nightly, after a safe upgrade
- **spec_ref**: openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-002-a-person-shall-be-in-exactly-the-role-groups-of-the-roles-they-derive
- **files**: `lib/EventListener/RoleGrantLoginListener.php`, `lib/BackgroundJob/RoleGrantSyncJob.php`, `lib/Repair/SeedRolesFromGroups.php`, `lib/AppInfo/Application.php`, `appinfo/info.xml`, `tests/Unit/Repair/SeedRolesFromGroupsTest.php`, `tests/Unit/BackgroundJob/RoleGrantSyncJobTest.php`
- **acceptance_criteria**:
  - GIVEN a real UserLoggedInEvent WHEN the listener runs THEN the user is synced
  - GIVEN a current role group member without the role WHEN the repair step runs THEN the role is written onto their contact person and logged
  - GIVEN many users WHEN the nightly job runs THEN it works in bounded batches
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Repair/SeedRolesFromGroupsTest.php and tests/Unit/BackgroundJob/RoleGrantSyncJobTest.php)

### Task 4: Add the review fields and the review endpoints
- **spec_ref**: openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-004-a-reviewer-shall-confirm-or-withdraw-a-persons-access-and-the-decision-shall-be-recorded
- **files**: `lib/Settings/register.d/organisations-role-mapping-and-access-review.json`, `lib/Controller/AccessReviewController.php`, `appinfo/routes.php`, `tests/Unit/Controller/AccessReviewControllerTest.php`, `tests/Unit/Settings/AccessReviewDeclarationTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN contactPerson is read THEN it has accessReviewedAt and accessReviewedBy and a higher version
  - GIVEN a functional administrator WHEN they list their own organisation THEN each row carries roles with sources, last sign-in, enabled, reviewed at and by, and due
  - GIVEN another organisation WHEN they list it THEN they get 403, and a Nextcloud admin gets 200
  - GIVEN their own contact person WHEN they confirm it THEN the endpoint refuses
- [ ] Implement
- [ ] Test (PHPUnit tests/Unit/Controller/AccessReviewControllerTest.php and tests/Unit/Settings/AccessReviewDeclarationTest.php)

### Task 5: Add the Access review page under Organisations
- **spec_ref**: openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
- **files**: `src/manifest.d/organisations-role-mapping-and-access-review.json`, `src/menu-layout.json`, `src/views/access/AccessReviewView.vue`, `src/customComponents.js`, `lib/Controller/DashboardController.php`, `tests/vitest/accessReviewView.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a functional administrator WHEN they open Organisations THEN Access review is a child entry, and a regular user does not see it
  - GIVEN a due row WHEN Keep access is chosen THEN the row shows today and the reviewer, and is no longer due
  - GIVEN a role from a mapped group WHEN the row renders THEN it names the group and offers no Remove role for it
  - GIVEN a Nextcloud admin WHEN the page renders THEN it offers an organisation picker and Disable account
- [ ] Implement
- [ ] Test (vitest tests/vitest/accessReviewView.spec.js and Playwright tests/e2e/spec-coverage/access-review.spec.ts)

### Task 6: Seed review dates and document both features
- **spec_ref**: openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
- **files**: `lib/Settings/stackiq_mock_register.json`, `docs/features/role-mapping-and-access-review.md`
- **acceptance_criteria**:
  - GIVEN a fresh demo import WHEN the Access review page opens THEN it shows one current and two due rows
  - GIVEN the docs WHEN a reader opens the feature page THEN it explains role groups, mapped groups and the review with screenshots, and points SAML and OpenID Connect users to OpenRegister's derived grants
- [ ] Implement
- [ ] Test (Playwright tests/e2e/spec-coverage/role-mapping.spec.ts and tests/e2e/spec-coverage/access-review.spec.ts against the demo data)

## Verification

- `openspec validate organisations-role-mapping-and-access-review --type change --strict`
- PHPUnit: tests/Unit/Service/RoleGroupMapTest.php, tests/Unit/Controller/SettingsControllerUserGroupsTest.php, tests/Unit/Service/RoleGrantSyncTest.php, tests/Unit/Repair/SeedRolesFromGroupsTest.php, tests/Unit/BackgroundJob/RoleGrantSyncJobTest.php, tests/Unit/Controller/AccessReviewControllerTest.php, tests/Unit/Settings/AccessReviewDeclarationTest.php
- vitest: tests/vitest/accessReviewView.spec.js
- Playwright: tests/e2e/spec-coverage/role-mapping.spec.ts and tests/e2e/spec-coverage/access-review.spec.ts
- Docs in docs/features/role-mapping-and-access-review.md with screenshots (ADR-010)
- English and Dutch strings for the roles, the mapping table, the page, its states and actions (ADR-005)
