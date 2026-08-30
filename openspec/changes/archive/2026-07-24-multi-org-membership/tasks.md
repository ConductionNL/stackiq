# Tasks: multi-org-membership

## Implementation Tasks

### Task 1: OrganisationMembersController — beheerder-gated grant/revoke
- **spec_ref**: `openspec/changes/multi-org-membership/specs/multi-org-membership/spec.md#requirement-granting-or-revoking-organisation-access-must-be-restricted-to-a-beheerder-of-that-organisation-req-004`
- **files**: `lib/Controller/OrganisationMembersController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a caller who is a member of organisation A and holds the `beheerder` NC group WHEN they POST an existing NC user id to grant access to organisation A THEN `OrganisationService::joinOrganisation()` is called and the response is 200
  - GIVEN a caller who is a member of organisation A but not in the `beheerder` group WHEN they attempt to grant or revoke access to organisation A THEN the response is 403 and `joinOrganisation`/`leaveOrganisation` is never called
  - GIVEN a caller who is a `beheerder` of organisation B only WHEN they attempt to grant/revoke access to organisation A THEN the response is 403
  - GIVEN a target user id that does not resolve via `IUserManager::get()` WHEN a beheerder attempts to grant access THEN the response is 404 and no membership mutation occurs
  - GIVEN a beheerder of organisation A WHEN they revoke an existing member's access THEN `OrganisationService::leaveOrganisation()` is called and the member is removed
- [x] Implement
- [x] Test

### Task 2: PHPUnit tests for OrganisationMembersController (incl. mandatory negatives)
- **spec_ref**: `openspec/changes/multi-org-membership/specs/multi-org-membership/spec.md#requirement-membership-mutations-must-be-delegated-to-openregisters-organisationservice-not-reimplemented-req-006`
- **files**: `tests/Unit/Controller/OrganisationMembersControllerTest.php`
- **acceptance_criteria**:
  - Every acceptance criterion of Task 1 has a corresponding PHPUnit test, including the non-beheerder-denied and cross-organisation-beheerder-denied negative cases
  - Tests assert `joinOrganisation`/`leaveOrganisation` are never invoked on a denied path (mock expectation, not just response code)
- [x] Implement
- [x] Test

### Task 3: App.vue — boot-time active-organisation fetch + write-header wiring
- **spec_ref**: `openspec/changes/multi-org-membership/specs/multi-org-membership/spec.md#requirement-after-a-switch-the-user-must-see-only-the-newly-active-organisations-data-req-002`
- **files**: `src/App.vue`, `src/composables/orClient.js`
- **acceptance_criteria**:
  - GIVEN the app boots WHEN `/api/me` resolves THEN `CnAppRoot` mounts with `initial-organisation-uuid`/`initial-organisation` set from the active organisation, AND `orClient.js`'s module-level active-organisation-uuid getter is set from the same response so subsequent writes stamp `X-OpenRegister-Organisation`
  - GIVEN `/api/me` fails or returns no active organisation WHEN the app boots THEN it falls back to unset (single-tenant legacy behaviour), matching the "no header when no tenant active" contract
- [x] Implement
- [x] Test

### Task 4: OrganisationSwitcher.vue — self-contained header switcher, reload on success
- **spec_ref**: `openspec/changes/multi-org-membership/specs/multi-org-membership/spec.md#requirement-switching-the-active-organisation-must-be-verified-against-server-side-membership-never-a-client-supplied-claim-req-001`
- **files**: `src/components/organisations/OrganisationSwitcher.vue`, `src/App.vue`
- **acceptance_criteria**:
  - GIVEN a user with two or more organisations WHEN they open the switcher THEN both organisations are listed (from `/api/me`) with the active one marked, per REQ-003
  - GIVEN a user with zero or one organisation WHEN the switcher's dropdown is opened THEN the switch-target list is empty (no other organisations to switch to), mirroring `CnTenantBadge`'s auto-hide contract for the switching affordance specifically — the dropdown itself still renders to host the "Manage members" entry
  - GIVEN the user picks a different organisation WHEN the switch completes THEN `POST /apps/openregister/api/organisations/{uuid}/set-active` is called directly (no SoftwareCatalog proxy controller) and the page is reloaded on success only, guaranteeing every view re-derives its data from the new server-side session (REQ-002)
  - GIVEN OpenRegister refuses the switch (non-member) WHEN the response is an error THEN the UI shows the error inline and the page is NOT reloaded and the active organisation is unchanged, per REQ-001's negative scenario
  - The default `#tenant-badge` slot is suppressed (`<template #tenant-badge />`) in favour of this combined badge+switcher rendered via `#header-actions`, avoiding a duplicate/inconsistent display
- [x] Implement
- [x] Test

### Task 5: GrantOrganisationAccessModal.vue + member list/revoke UI
- **spec_ref**: `openspec/changes/multi-org-membership/specs/multi-org-membership/spec.md#requirement-granting-access-must-only-target-an-existing-nextcloud-user-req-005`
- **files**: `src/modals/GrantOrganisationAccessModal.vue`, `src/components/organisations/OrganisationSwitcher.vue`
- **acceptance_criteria**:
  - GIVEN a beheerder viewing their organisation WHEN they open the modal THEN the current member list is fetched from `GET /apps/openregister/api/organisations/{uuid}` and rendered
  - GIVEN the beheerder picks an existing NC user via `NcSelectUsers` (`inputLabel` set) WHEN they confirm THEN a grant request is sent and the member list refreshes on success
  - GIVEN a non-beheerder member WHEN they view the app header THEN the "Manage members" entry point is not rendered (client-side hint only, driven by `/api/me`'s `isBeheerder` flag — Task 1/2 remain the authority)
  - GIVEN a revoke action WHEN confirmed THEN the target is removed from the rendered member list on success
- [x] Implement
- [x] Test

### Task 6: Frontend store actions — members fetch/grant/revoke + write-header wiring
- **spec_ref**: `openspec/changes/multi-org-membership/specs/multi-org-membership/spec.md#requirement-membership-mutations-must-be-delegated-to-openregisters-organisationservice-not-reimplemented-req-006`
- **files**: `src/store/modules/organisatie.js`, `src/composables/orClient.js`, `src/store/plugins/softwarecatalogPlugin.js`
- **acceptance_criteria**:
  - GIVEN `orClient.js`'s active-organisation-uuid getter is set WHEN `softwarecatalogPlugin`'s `patchObject`/write paths run THEN `buildWriteHeaders` is called with that organisation uuid, satisfying the already-specified `softwarecatalog-adopt-or-abstractions` header-stamping requirement
  - GIVEN no active organisation WHEN a write runs THEN no `X-OpenRegister-Organisation` header is added (unchanged legacy path)
  - `organisatie.js` gains `fetchMembers(uuid)`, `grantAccess(uuid, userId)`, `revokeAccess(uuid, userId)` actions calling the endpoints from Tasks 1/5
- [x] Implement
- [x] Test

### Task 7: Pure-logic unit tests — switcher decision logic, grant/revoke payload
- **spec_ref**: `openspec/specs/multi-org-membership/spec.md#requirement-a-non-beheerder-member-cannot-grant-access`
- **files**: `src/components/organisations/organisationSwitcher.js` + `.spec.js`, `src/modals/grantOrganisationAccess.js` + `.spec.js`, `src/composables/orClient.spec.js`
- **acceptance_criteria**:
  - Pure-logic tests (matching the project's established jest colocated-`.spec.js` convention) cover: `resolveSwitchError`'s refusal handling (never treats a non-ok response as success, REQ-001), `resolveActiveOrganisationName`/`resolveOtherOrganisations`' organisation-list derivation (REQ-003), `extractUserId`'s normalisation across the shapes `NcSelectUsers` may emit (REQ-005), and `removeMember`'s local member-list update after a revoke
- [x] Implement
- [x] Test

### Task 8: i18n — Dutch + English strings
- **spec_ref**: `openspec/changes/multi-org-membership/specs/multi-org-membership/spec.md#requirement-the-organisation-switcher-must-list-only-the-authenticated-users-own-organisations-req-003`
- **files**: `l10n/nl.js`, `l10n/nl.json`, `l10n/en_US.js`, `l10n/en_US.json`
- **acceptance_criteria**:
  - Every new user-facing string introduced by Tasks 4-5 has an English source key and a Dutch translation, and `en_US` mirrors the English source
- [x] Implement
- [x] Test

### Task 9: Feature documentation with screenshots
- **spec_ref**: `openspec/specs/multi-org-membership/spec.md#purpose`
- **files**: `docs/features/multi-org-membership.md`, `docs/images/`
- **acceptance_criteria**:
  - The doc shows the switcher in the app header and the grant/revoke modal, captured live via Playwright MCP against a running instance with a multi-organisation test user
- [x] Implement — feature doc written; matches the existing `docs/features/*.md` convention (text-only, no `docs/images/` in this project today)
- [ ] Test — screenshots NOT captured: this change deliberately did not deploy to the shared dev Nextcloud instance (see tasks.md constraints); deferred as a follow-up, flagged in the final report rather than fabricated

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`), run inside the `nextcloud:34.0.0-apache` container per project convention
- New/changed API endpoints (`OrganisationMembersController`) covered by tests exercising both an allowed and a denied case
- UI changes (switcher, grant/revoke modal) covered by Vitest unit tests; manual/Playwright verification against the live dev instance
- All tests pass: `php vendor/bin/phpunit -c phpunit-unit.xml` and `npx vitest run` (pre-existing `PortfolioReportControllerTest::testCsvFormatReturnsDownloadResponse` failure, issue #393, is excluded)
- Feature documentation updated in `docs/features/` with screenshots (ADR-010)
- Dutch (`nl`) and English (`en_US`) translation strings added for every new user-facing string (ADR-005/ADR-007)
- `openspec validate --strict` passes
