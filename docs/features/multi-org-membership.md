<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

# Multi-organisation membership

Lets one Nextcloud account act for more than one organisation: an
organisation switcher in the app header, and a self-service flow so an
organisation's own beheerders can grant or revoke an existing colleague's
access — without an administrator. Built for shared service centres,
samenwerkingsverbanden, and the dual-membership transition period during a
gemeentelijke herindeling. See
[VNG Softwarecatalogus issue #57](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/57),
[#60](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/60), and
[#65](https://github.com/VNG-Realisatie/Softwarecatalogus/issues/65).

Specification: [`openspec/specs/multi-org-membership/spec.md`](../../openspec/specs/multi-org-membership/spec.md).

Everything in this feature is built on OpenRegister's own, already-shipped
`OrganisationService`/`OrganisationController` — SoftwareCatalog does not
store a separate membership record anywhere.

## Switching your active organisation

A member of two or more organisations sees a switcher in the app header
(next to the other header actions), showing the currently-active
organisation's name. Opening it lists every organisation the user belongs
to; picking a different one:

1. Calls OpenRegister's own `POST /apps/openregister/api/organisations/{uuid}/set-active`
   directly — SoftwareCatalog does not proxy this call.
2. OpenRegister verifies, server-side, that the caller is actually a member
   of that organisation (`Organisation::hasUser()`) before changing
   anything. A switch to an organisation the user does not belong to is
   refused and the active organisation is left unchanged — this can never
   be bypassed by anything the client sends.
3. On success, the page reloads. Every list, dashboard, and detail view
   therefore re-fetches from scratch under the new active organisation's
   session — there is no risk of one view still showing the previous
   organisation's data after a switch.

A user who belongs to zero or one organisation does not see a switch-target
list (mirrors the shared `CnTenantBadge` component's auto-hide behaviour),
though the header entry still shows their one organisation's name.

## Granting or revoking a colleague's access

A **beheerder** — a member of the organisation who also holds the
`beheerder` Nextcloud group role — sees a "Manage members" entry in the same
header switcher, opening a dialog for their currently-active organisation:

- **Current members** — the organisation's member list, read directly from
  OpenRegister's own `GET /apps/openregister/api/organisations/{uuid}`
  (already access-controlled there).
- **Grant access** — pick an *existing* Nextcloud user (via `NcSelectUsers`)
  and confirm. This is deliberately existing-user-only: it is not an invite
  flow, and never creates a Nextcloud account.
- **Revoke access** — remove a member from the list.

Both actions are authorised server-side by a new, SoftwareCatalog-specific
check (`OrganisationMembersController::authorizeBeheerder()`) that OpenRegister's
own membership endpoints don't perform (OpenRegister's `join`/`leave` only
recognise a Nextcloud admin or the organisation's single `owner` field as
allowed to manage another user's membership — SoftwareCatalog's `beheerder`
role is a separate, broader concept). The check requires **both**:

1. The caller is authenticated and in the global `beheerder` Nextcloud group.
2. The caller's own organisation memberships — resolved from OpenRegister's
   `OrganisationService::getUserOrganisations()`, never from anything the
   client sends — include the organisation being managed.

Only once both hold does the controller call OpenRegister's own
`joinOrganisation()` / `leaveOrganisation()` to perform the actual mutation.
A beheerder of one organisation cannot grant or revoke access to a
*different* organisation they don't belong to, even though they hold the
role.

```
POST /apps/softwarecatalog/api/organisations/{uuid}/members
{ "userId": "j.devries" }

DELETE /apps/softwarecatalog/api/organisations/{uuid}/members/{userId}
```

## What this does not do

- **No new-user invites.** Granting access always requires an existing
  Nextcloud account; inviting someone by e-mail is a separate, unbuilt
  capability (user provisioning).
- **No change to what a role can see.** Switching the active organisation
  changes *which* organisation a session is scoped to; the RBAC rules that
  govern what each role may read within that organisation are unchanged
  (`vendor-visibility-rbac`).
- **No cross-organisation data merge.** That is a different capability
  ([organisation merge](organisation-merge.md), softwarecatalog#370).

## Screenshots

Not captured in this pass — this change did not deploy to a live instance
(the shared dev Nextcloud container was deliberately left untouched per the
change's constraints). A follow-up pass should capture the header switcher
and the "Manage members" dialog via Playwright MCP against a running
instance with a multi-organisation test user, consistent with ADR-010.
