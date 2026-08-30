# Proposal: multi-org-membership

## Summary
Lets one Nextcloud user act for multiple organisations in SoftwareCatalog: an
organisation switcher in the app header that lists the user's organisations
and switches which one is active, and a self-service flow so an organisation
member with the right role can grant or revoke an existing Nextcloud user's
access to their organisation without going through an administrator. Both
build directly on OpenRegister's already-shipped `OrganisationService`
(`getUserOrganisations`, `getActiveOrganisation`, `setActiveOrganisation`,
`joinOrganisation`, `leaveOrganisation`) and its `OrganisationController` HTTP
surface — no membership logic is reimplemented in SoftwareCatalog. Closes
softwarecatalog#371.

## Motivation
VNG Softwarecatalogus issues #57 and #60 report that one account cannot act
for more than one organisation, which breaks the daily reality of shared
service centres (SSC's) and samenwerkingsverbanden, and creates friction
during herindeling transition periods when a user legitimately belongs to
both the old and new municipality for a time. VNG issue #65 reports that
granting a colleague access today requires an administrator, when the
organisation's own beheerders should be able to self-serve it. SoftwareCatalog
currently ships neither an org switcher UI nor an invite/access-grant flow,
even though the platform-level primitives it needs (OpenRegister's
`OrganisationService` + `OrganisationController`) have existed since
`retrofit-2026-05-24-b-svc-compute-profile-org` and the tenant-context wiring
contract was already specified (but not yet implemented in SoftwareCatalog)
by `softwarecatalog-adopt-or-abstractions`.

## Affected Projects
- [x] Project: `softwarecatalog` — organisation switcher UI, self-service
      colleague access controller + modal, tenant-context wiring in `App.vue`,
      i18n, unit tests
- [ ] Project: `openregister` — consumed read-only via its existing
      `OrganisationService` (PHP, in-process) and `OrganisationController`
      HTTP surface (`/api/organisations/*`); no changes made here

## Scope

### In Scope
- An organisation switcher in the app header/nav area listing the
  authenticated user's organisations (from OpenRegister's
  `getUserOrganisationStats()` / the existing `getMe()` aggregate) and letting
  them pick a different one as active, via OpenRegister's own
  `POST /api/organisations/{uuid}/set-active` (server-side membership
  verification already lives there — `setActiveOrganisation()` throws unless
  `Organisation::hasUser($userId)`).
- Wiring nc-vue's `useTenantContext()` / `tenantContextMixin` into
  SoftwareCatalog's `App.vue` (mounted by `CnAppRoot`) so that a switch
  updates the shared `activeOrganisationUuid`, the `X-OpenRegister-Organisation`
  write-header (`orClient.js`'s already-shipped `buildWriteHeaders`), the
  `CnTenantBadge` indicator, and clears the shared object store's cache
  (`useObjectStore().setActiveTenantOrganisation()`).
- A hard refresh of the current view after a confirmed switch so every list
  and detail page re-fetches under the new active organisation's session —
  the read-side RBAC scoping hardened in `schema-rbac-hardening`/
  `vendor-visibility-rbac` is keyed off the *server-side* session active
  organisation, not any client-supplied value, so a full re-fetch is the only
  way to guarantee no stale cross-tenant data stays rendered.
- Self-service colleague access: a new `OrganisationMembersController` that
  lets a caller who is both (a) a member of the target organisation
  (verified against OpenRegister's `Organisation` entity, the authoritative
  source `setActiveOrganisation`/`getUserOrganisations` already use) and
  (b) a `beheerder` (the existing NC group `ContactPersonHandler::
  assignBeheerderRole` already assigns) grant or revoke an *existing* NC
  user's membership of that organisation, by delegating to OpenRegister's
  `joinOrganisation()` / `leaveOrganisation()`.
- A `NcSelectUsers`-based modal for picking the existing NC user to grant, and
  a members list (sourced from OpenRegister's own `GET /api/organisations/{uuid}`,
  which already gates on `hasAccessToOrganisation()`) with a revoke action.
- i18n (EN keys, `nl` + `en_US` translations), unit tests including the
  mandatory negative/security cases, and a feature doc.

### Out of Scope
- Inviting a brand-new user by e-mail who does not yet have a Nextcloud
  account — that is user provisioning, tracked separately.
- Cross-organisation data merging (softwarecatalog#370 /
  `organisation-merge`).
- Changing the RBAC model itself (`schema-rbac-hardening` /
  `vendor-visibility-rbac` land unchanged) — this change only adds a way to
  change *which* organisation's data a session is scoped to, and *who* may be
  a member of an organisation; it does not touch what a role may read once
  scoped.
- Any change to OpenRegister's `OrganisationService`/`OrganisationController`
  — both are already correct and sufficient for this change's needs.

## Approach
Consume, don't rebuild. OpenRegister's `OrganisationService::
setActiveOrganisation()` already verifies membership server-side
(`Organisation::hasUser($userId)`, throwing otherwise) before writing the
active-organisation session/user-config value, and its `OrganisationController
::setActive()` HTTP action wraps that with no bypass — SoftwareCatalog's
frontend calls that endpoint directly instead of adding a pass-through PHP
controller (ADR-011/ADR-022; a literal wrapper would trip
`hydra-gate-redundant-controller`). SoftwareCatalog's own backend work is
therefore limited to the one place OpenRegister's authorization model does
not fit SoftwareCatalog's role system: OpenRegister's `join`/`leave` HTTP
actions only let a Nextcloud admin or the organisation's single `owner`
field manage another user's membership, but SoftwareCatalog's domain model
authorises any `beheerder` of the organisation to do so. `OrganisationMembersController`
adds that `beheerder`-role check (itself built from already-shipped
primitives: NC group `beheerder` + OpenRegister's `Organisation::hasUser()`)
and then delegates the actual membership mutation to `OrganisationService::
joinOrganisation()` / `leaveOrganisation()` — no new membership storage,
no new group-membership scheme.

On the frontend, `App.vue` passes the user's current active organisation
(read from the existing `/api/me` aggregate endpoint) into `CnAppRoot`'s
`initial-organisation-uuid` / `initial-organisation` props (which mount nc-vue's
tenant-context provider), and a new switcher component overrides `CnAppRoot`'s
`#tenant-badge` slot to make that badge interactive. Mirrors the pattern
already shipped in `larpingapp`'s `App.vue` for the reactive
cache-clear-and-refetch pipeline, and satisfies the tenant-switch scenarios
already specified (but, per the context brief, not yet implemented) in
`softwarecatalog-adopt-or-abstractions`.

## New Dependencies
None — `NcSelectUsers` (existing NC user search/picker) ships in the already
pinned `@nextcloud/vue ^8.39.0`; `useTenantContext()` / `tenantContextMixin`
ship in the already-pinned `@conduction/nextcloud-vue` range.

## Impact
- `src/App.vue` — mounts the tenant-context provider with an initial
  organisation, adds the tenant-switch watcher (cache clear + refresh).
- New `src/components/organisations/OrganisationSwitcher.vue` (header slot),
  `src/modals/GrantOrganisationAccessModal.vue`, member-list surface.
- New `lib/Controller/OrganisationMembersController.php`,
  `appinfo/routes.php` entries, `lib/Settings/register.d/multi-org-membership.json`
  (no new register objects are strictly required by this change but the
  fragment file is reserved per ADR-037 if any config surface is needed).
- i18n `l10n/nl.js`, `l10n/nl.json`, `l10n/en_US.js`, `l10n/en_US.json`.

## Cross-Project Dependencies
Depends on OpenRegister's already-shipped `OrganisationService` +
`OrganisationController` (read-only dependency, no OpenRegister PR required)
and on `@conduction/nextcloud-vue`'s already-shipped `useTenantContext()` /
`tenantContextMixin` / `CnTenantBadge` / `CnAppRoot` tenant props (no nc-vue
PR required — verified present in the currently pinned range).

## Risks

### Risk 1: A client-supplied organisation id could be trusted for a switch or a grant
**Severity:** High — **Mitigation:** Every mutation in this change is
guarded server-side against the authoritative OpenRegister `Organisation`
entity, never a client-supplied trust boundary: `setActiveOrganisation()`
throws unless `Organisation::hasUser($callerId)`; the new
`OrganisationMembersController` re-derives the caller's own membership from
`OrganisationService::getUserOrganisations()` (not from any request
parameter) before authorising a grant/revoke of someone else. Both paths are
covered by mandatory negative tests (switch to non-member org refused; grant
by a non-beheerder refused).

### Risk 2: Stale cross-tenant data remains visible in the UI after a switch
**Severity:** Medium — **Mitigation:** `CnIndexPage`'s `activeOrganisation`
watcher clears the shared object-store cache but does not itself re-fetch;
this change forces a full view refresh after a confirmed switch (mirroring
the `softwarecatalog-adopt-or-abstractions` "Tenant switch on detail
navigates back" scenario for detail views, and a full re-fetch for index/
dashboard views) so no partially-updated view can render another
organisation's data.

### Risk 3: SoftwareCatalog's own per-organisation NC group (`OrganizationHandler::
userBelongsToOrganization`) is a separate, legacy membership signal from
OpenRegister's `Organisation.users`
**Severity:** Low — **Mitigation:** This change does not read or write that
legacy group-based signal at all; both the switcher and the self-service
grant/revoke flow are built exclusively on OpenRegister's `Organisation`
entity, which is also the source `setActiveOrganisation()`/schema RBAC
already treat as authoritative. The pre-existing mismatch between that
legacy helper's UUID-based group-name lookup and the name-based group it is
actually populated with is out of scope for this change and is called out
as a follow-up in the design doc rather than silently fixed here.

## Rollback Strategy
Revert the SoftwareCatalog commits on this branch. No OpenRegister changes,
no destructive migrations, and no register-schema changes are made, so a
revert fully restores prior behaviour (no switcher UI, no self-service
grant/revoke, single-organisation-per-session as today).

## Open Questions
None outstanding — clarified with the requester before implementation: the
switcher consumes OpenRegister's endpoints directly rather than proxying
them, and "the right role" for granting access is SoftwareCatalog's existing
`beheerder` group scoped to the target organisation's OpenRegister
membership.
