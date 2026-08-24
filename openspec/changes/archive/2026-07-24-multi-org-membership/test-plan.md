# Test Plan: multi-org-membership

## Test Cases

### TC-1: Member switches into an organisation they belong to
- **spec_ref**: `openspec/changes/multi-org-membership/specs/multi-org-membership/spec.md#requirement-switching-the-active-organisation-must-be-verified-against-server-side-membership-never-a-client-supplied-claim-req-001`
- **type**: functional
- **preconditions**: user is a member of organisation A and organisation B, A active
- **steps**: open the organisation switcher, select organisation B
- **expected result**: switch succeeds; active organisation becomes B; badge updates
- **test command**: /test-functional

### TC-2 (negative): Switch to a non-member organisation is refused
- **spec_ref**: `openspec/changes/multi-org-membership/specs/multi-org-membership/spec.md#requirement-switching-the-active-organisation-must-be-verified-against-server-side-membership-never-a-client-supplied-claim-req-001`
- **type**: security
- **preconditions**: user is a member of organisation A only; organisation C exists
- **steps**: POST `/apps/openregister/api/organisations/{C}/set-active` directly (bypassing the UI, to prove server-side enforcement)
- **expected result**: 400/403 response; active organisation remains A; no data from C returned
- **test command**: /test-security

### TC-3: After a switch, only the new organisation's data is visible
- **spec_ref**: `openspec/changes/multi-org-membership/specs/multi-org-membership/spec.md#requirement-after-a-switch-the-user-must-see-only-the-newly-active-organisations-datas-req-002`
- **type**: functional
- **preconditions**: user viewing an index page scoped to organisation A
- **steps**: switch active organisation to B via the switcher
- **expected result**: view reloads; only B's objects render; no A objects remain
- **test command**: /test-functional

### TC-4: Switcher lists only the user's own organisations
- **spec_ref**: `openspec/changes/multi-org-membership/specs/multi-org-membership/spec.md#requirement-the-organisation-switcher-must-list-only-the-authenticated-users-own-organisations-req-003`
- **type**: functional
- **preconditions**: user is a member of A and B; organisation D exists but user is not a member
- **steps**: open the switcher
- **expected result**: A and B listed, A marked active, D not present
- **test command**: /test-functional

### TC-5: Beheerder grants an existing colleague access
- **spec_ref**: `openspec/changes/multi-org-membership/specs/multi-org-membership/spec.md#requirement-granting-or-revoking-organisation-access-must-be-restricted-to-a-beheerder-of-that-organisation-req-004`
- **type**: api
- **preconditions**: caller is a member + beheerder of organisation A; target user exists and is not yet a member
- **steps**: `POST /apps/softwarecatalog/api/organisations/{A}/members` with the target userId
- **expected result**: 200; target becomes a member of A (verified via OR's `getUserOrganisations`)
- **test command**: /test-api

### TC-6 (negative): Non-beheerder member cannot grant access
- **spec_ref**: `openspec/changes/multi-org-membership/specs/multi-org-membership/spec.md#requirement-granting-or-revoking-organisation-access-must-be-restricted-to-a-beheerder-of-that-organisation-req-004`
- **type**: security
- **preconditions**: caller is a member of organisation A, not in the `beheerder` group; target user exists
- **steps**: `POST /apps/softwarecatalog/api/organisations/{A}/members` with the target userId
- **expected result**: 403; target's membership unchanged; `joinOrganisation` never invoked
- **test command**: /test-security

### TC-7 (negative): Beheerder of a different organisation cannot grant access
- **spec_ref**: `openspec/changes/multi-org-membership/specs/multi-org-membership/spec.md#requirement-granting-or-revoking-organisation-access-must-be-restricted-to-a-beheerder-of-that-organisation-req-004`
- **type**: security
- **preconditions**: caller is a beheerder of organisation B only; target user exists, not a member of A
- **steps**: `POST /apps/softwarecatalog/api/organisations/{A}/members` with the target userId
- **expected result**: 403; target's membership of A unchanged
- **test command**: /test-security

### TC-8: Beheerder revokes another member's access
- **spec_ref**: `openspec/changes/multi-org-membership/specs/multi-org-membership/spec.md#requirement-granting-or-revoking-organisation-access-must-be-restricted-to-a-beheerder-of-that-organisation-req-004`
- **type**: api
- **preconditions**: caller is a member + beheerder of A; target is currently a member of A
- **steps**: `DELETE /apps/softwarecatalog/api/organisations/{A}/members/{targetUserId}`
- **expected result**: 200; target no longer a member of A
- **test command**: /test-api

### TC-9 (negative): Grant to a non-existent Nextcloud user is refused
- **spec_ref**: `openspec/changes/multi-org-membership/specs/multi-org-membership/spec.md#requirement-granting-access-must-only-target-an-existing-nextcloud-user-req-005`
- **type**: api
- **preconditions**: caller is a beheerder of A; `no-such-user` does not resolve via `IUserManager::get()`
- **steps**: `POST /apps/softwarecatalog/api/organisations/{A}/members` with userId `no-such-user`
- **expected result**: 404; no membership mutation
- **test command**: /test-api

### TC-10: Granted membership is visible through OpenRegister's own query (no parallel store)
- **spec_ref**: `openspec/changes/multi-org-membership/specs/multi-org-membership/spec.md#requirement-membership-mutations-must-be-delegated-to-openregisters-organisationservice-not-reimplemented-req-006`
- **type**: regression
- **preconditions**: TC-5 has run
- **steps**: call OpenRegister's `getUserOrganisations()` (or `GET /apps/openregister/api/organisations`) as the granted user
- **expected result**: organisation A is present in the result
- **test command**: /test-api

## Coverage Summary
- REQ-001 (server-side switch verification): TC-1, TC-2 — covered
- REQ-002 (post-switch refresh): TC-3 — covered
- REQ-003 (switcher lists own orgs only): TC-4 — covered
- REQ-004 (beheerder-gated grant/revoke): TC-5, TC-6, TC-7, TC-8 — covered
- REQ-005 (existing-user-only grant): TC-9 — covered
- REQ-006 (delegated to OpenRegister, no parallel store): TC-10 — covered

## Out of Scope
- OpenRegister's own `OrganisationService`/`OrganisationController` behaviour
  (membership storage, `hasUser()` correctness) is exercised only indirectly
  through these test cases — it is pre-existing, unchanged, and already
  covered by OpenRegister's own test suite.
- Performance/load testing of the switch or grant/revoke endpoints — both
  are low-frequency, interactive-only actions with no expected volume
  concern.
