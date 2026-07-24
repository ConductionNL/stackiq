# multi-org-membership Specification

**Status**: in-progress
**Scope**: softwarecatalog
**OpenSpec changes**:
- multi-org-membership

## Purpose
Lets one Nextcloud user act for multiple organisations in SoftwareCatalog —
an organisation switcher that changes the session's active organisation, and
a self-service flow so an organisation's own beheerders can grant or revoke
an existing Nextcloud user's membership without an administrator. Both build
on OpenRegister's existing `OrganisationService`/`OrganisationController`
(ADR-011, ADR-022) rather than reimplementing membership.

## ADDED Requirements

### Requirement: Switching the active organisation MUST be verified against server-side membership, never a client-supplied claim (REQ-001)

The system MUST verify, on every switch of the active organisation, that the
authenticated user is a member of the target organisation by resolving that
membership from OpenRegister's `Organisation` entity on the server, and MUST
NOT accept or trust any client-supplied assertion of membership. A switch
request naming an organisation the user does not belong to MUST be refused
and MUST NOT change the session's active organisation.

#### Scenario: Member switches into an organisation they belong to
- GIVEN an authenticated user who is a member of organisation A and organisation B
- WHEN the user selects organisation B in the organisation switcher
- THEN the request to change the active organisation MUST succeed
- AND the session's active organisation MUST become organisation B

#### Scenario: Switch to a non-member organisation is refused
- GIVEN an authenticated user who is a member of organisation A only
- WHEN the user (or a forged request) attempts to switch the active organisation to organisation C, of which the user is not a member
- THEN the request MUST be refused
- AND the session's active organisation MUST remain organisation A
- AND no data belonging to organisation C MUST be returned as a result of the attempt

#### Scenario: A forged organisation id in the request body is not trusted over server-side membership
- GIVEN an authenticated user who is a member of organisation A only
- WHEN a switch request is sent naming organisation C together with any client-supplied field claiming the user belongs to organisation C
- THEN the server MUST re-derive membership from OpenRegister's `Organisation` entity rather than the client-supplied claim
- AND the switch MUST be refused exactly as in the non-member scenario

### Requirement: After a switch, the user MUST see only the newly-active organisation's data (REQ-002)

The system MUST refresh every currently-rendered list, dashboard, and detail
view after a confirmed organisation switch so that no data belonging to the
previously-active organisation remains visible, and MUST NOT rely on a
partial or best-effort cache invalidation that could leave one surface
showing stale, cross-organisation data.

#### Scenario: List view shows only the new organisation's data after a switch
- GIVEN a user viewing an index page scoped to organisation A, which is currently active
- WHEN the user switches the active organisation to organisation B
- THEN the index page MUST refetch its data
- AND the rendered list MUST contain only objects scoped to organisation B
- AND no object scoped to organisation A MUST remain rendered

#### Scenario: Detail view navigates back to the index on a switch
- GIVEN a user viewing an object's detail page under organisation A
- WHEN the user switches the active organisation to organisation B
- THEN the view MUST navigate back to the parent index
- AND the index MUST refetch and show only organisation B's data

### Requirement: The organisation switcher MUST list only the authenticated user's own organisations (REQ-003)

The system MUST populate the organisation switcher exclusively from the
authenticated user's own organisation memberships, as resolved server-side,
and MUST indicate which one is currently active.

#### Scenario: Switcher lists the user's organisations with the active one marked
- GIVEN an authenticated user who is a member of organisation A and organisation B, with A currently active
- WHEN the user opens the organisation switcher
- THEN both organisation A and organisation B MUST be listed
- AND organisation A MUST be indicated as the active organisation
- AND no organisation the user is not a member of MUST appear in the list

### Requirement: Granting or revoking organisation access MUST be restricted to a beheerder of that organisation (REQ-004)

The system MUST allow a caller to grant or revoke another Nextcloud user's
membership of an organisation only when the caller is both a member of that
organisation and holds the beheerder role, both verified server-side from
the authenticated session, never from a client-supplied claim. A caller who
does not satisfy both conditions MUST be denied before any membership
mutation is attempted.

#### Scenario: Beheerder grants an existing colleague access to their organisation
- GIVEN an authenticated user who is a member of organisation A and holds the beheerder role
- AND a second, existing Nextcloud user who is not yet a member of organisation A
- WHEN the beheerder grants that second user access to organisation A
- THEN the second user MUST become a member of organisation A
- AND the second user MUST subsequently be able to switch their active organisation to organisation A

#### Scenario: A non-beheerder member cannot grant access
- GIVEN an authenticated user who is a member of organisation A but does not hold the beheerder role
- AND a second, existing Nextcloud user who is not yet a member of organisation A
- WHEN the non-beheerder member attempts to grant that second user access to organisation A
- THEN the request MUST be denied
- AND the second user's membership MUST NOT change

#### Scenario: A beheerder of a different organisation cannot grant access to an organisation they do not belong to
- GIVEN an authenticated user who holds the beheerder role but is a member of organisation B only, not organisation A
- AND a second, existing Nextcloud user who is not yet a member of organisation A
- WHEN the user attempts to grant that second user access to organisation A
- THEN the request MUST be denied
- AND the second user's membership of organisation A MUST NOT change

#### Scenario: A beheerder can revoke another member's access
- GIVEN an authenticated user who is a member of organisation A and holds the beheerder role
- AND a second user who is currently a member of organisation A
- WHEN the beheerder revokes that second user's access to organisation A
- THEN the second user MUST no longer be a member of organisation A

#### Scenario: A non-beheerder member cannot revoke another member's access
- GIVEN an authenticated user who is a member of organisation A but does not hold the beheerder role
- AND a second user who is currently a member of organisation A
- WHEN the non-beheerder member attempts to revoke that second user's access
- THEN the request MUST be denied
- AND the second user MUST remain a member of organisation A

### Requirement: Granting access MUST only target an existing Nextcloud user (REQ-005)

The system MUST require the target of a grant to already exist as a
Nextcloud user, resolved server-side, and MUST refuse the grant when no such
user exists rather than creating a new account or membership placeholder.

#### Scenario: Grant to a non-existent user id is refused
- GIVEN an authenticated beheerder of organisation A
- WHEN the beheerder attempts to grant access to a user id that does not resolve to any existing Nextcloud user
- THEN the request MUST be refused
- AND no membership change MUST occur

### Requirement: Membership mutations MUST be delegated to OpenRegister's OrganisationService, not reimplemented (REQ-006)

The system MUST perform every membership mutation (switch, grant, revoke) by
calling OpenRegister's `OrganisationService` (`setActiveOrganisation`,
`joinOrganisation`, `leaveOrganisation`) and MUST NOT maintain a separate,
parallel membership store for the same relationship.

#### Scenario: A granted user's membership is visible through OpenRegister's own membership query
- GIVEN a beheerder of organisation A grants a second user access to organisation A
- WHEN OpenRegister's own `getUserOrganisations` is evaluated for that second user
- THEN organisation A MUST be included in the result
- AND this MUST be true without SoftwareCatalog maintaining any separate record of the membership

## Non-Functional Requirements

- **Performance:** Switching the active organisation MUST complete within
  the same request/response budget as any other SoftwareCatalog write
  action (no additional round trips beyond the switch call and the
  subsequent refresh).
- **Accessibility:** The organisation switcher and the grant/revoke modal
  MUST be operable by keyboard and MUST expose accessible labels
  (`NcSelect`/`NcSelectUsers` `inputLabel`), per WCAG 2.1 AA.
- **Internationalization:** Dutch and English MUST be supported (ADR-005) —
  all new user-facing strings ship with `nl` and `en_US` translations.

## Acceptance Criteria

- [ ] A user who belongs to two or more organisations can switch the active
      one from the app header, and every list/detail view they subsequently
      see reflects the new organisation only.
- [ ] Attempting to switch to an organisation the user does not belong to is
      refused server-side, with a test proving the active organisation does
      not change.
- [ ] A beheerder of an organisation can grant an existing Nextcloud user
      access to that organisation, and revoke it again.
- [ ] A non-beheerder member's attempt to grant or revoke access is refused,
      with a test proving no membership change occurred.
- [ ] No new membership storage is introduced in SoftwareCatalog — every
      mutation delegates to OpenRegister's `OrganisationService`.

## Notes

- Built entirely on already-shipped platform primitives: OpenRegister's
  `OrganisationService`/`OrganisationController` (verified present at
  `../openregister/lib/Service/OrganisationService.php` and
  `../openregister/lib/Controller/OrganisationController.php`) and nc-vue's
  `useTenantContext()`/`tenantContextMixin`/`CnTenantBadge`/`CnAppRoot`
  tenant props (verified present in the pinned `@conduction/nextcloud-vue`
  range).
- Interacts directly with the read-side RBAC scoping hardened in
  `vendor-visibility-rbac` (schema `_organisation` matching against the
  session's active organisation) — this capability governs *which*
  organisation a session is scoped to and *who* may belong to one; it does
  not alter what a role may read once scoped.
- Out of scope: inviting a brand-new user by e-mail (user provisioning,
  tracked separately); cross-organisation data merging
  (softwarecatalog#370); changes to the RBAC model itself.
