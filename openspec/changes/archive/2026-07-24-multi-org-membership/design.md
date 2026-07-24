# Design: multi-org-membership

## Architecture Overview
SoftwareCatalog is a thin OpenRegister client (ADR-001/ADR-022): it stores no
domain tables of its own and already stores organisation *identity* in
OpenRegister's `Organisation` entity via `OCA\OpenRegister\Service\
OrganisationService` (injected throughout `ContactpersonenController`,
`OrganizationHandler`, `SoftwareCatalogueService`, etc. — see
`../openregister/lib/Service/OrganisationService.php`). That service already
implements every membership primitive this change needs:

- `getUserOrganisations()` — the caller's organisations (session-cached).
- `getActiveOrganisation()` — the caller's currently-active organisation
  (session + persistent `IConfig` user value).
- `setActiveOrganisation($uuid)` — switches the active organisation,
  **throwing unless `Organisation::hasUser($callerId) === true`** — i.e.
  membership is already verified server-side, from the authoritative
  `Organisation.users` array, never from a client-supplied claim.
- `joinOrganisation($uuid, $targetUserId = null)` /
  `leaveOrganisation($uuid, $targetUserId = null)` — add/remove a user from
  `Organisation.users`.

`OrganisationController` (`../openregister/lib/Controller/
OrganisationController.php`) already exposes all of the above over HTTP
(`appinfo/routes.php` lines 937-960): `GET /api/organisations` (list + stats),
`GET /api/organisations/active`, `GET /api/organisations/{uuid}`,
`POST /api/organisations/{uuid}/set-active`, `POST /api/organisations/{uuid}/join`,
`POST /api/organisations/{uuid}/leave`. Every one of those actions is
`@NoAdminRequired` + session-scoped or membership-gated; `join`/`leave`
additionally already refuse a caller who tries to enrol/remove *another* user
unless that caller is a Nextcloud admin or the organisation's `owner` field
(`canManageOrganisationMembers()`).

**Consequence for this design**: the organisation switcher is a pure
frontend consumer of OpenRegister's existing HTTP surface — no new
SoftwareCatalog PHP is needed for switching. SoftwareCatalog's own
`OrganisationMembersController` is needed for exactly one reason: its
`beheerder`-role authorisation model (any org member who is also in the NC
group `beheerder` may manage that org's membership) is *not* the same
authorisation model OpenRegister's `join`/`leave` enforce (admin or single
`owner` only). SoftwareCatalog's controller adds that domain-specific
authorisation check and then delegates the mutation itself to
`OrganisationService::joinOrganisation()`/`leaveOrganisation()` — it does not
reimplement or duplicate the membership storage.

```
Frontend (Vue)                     SoftwareCatalog (PHP)              OpenRegister (PHP)
─────────────────                  ────────────────────               ──────────────────
OrganisationSwitcher.vue  ───GET──▶ ContactpersonenController          (existing, unchanged)
  (list + active)                    ::getMe()  [existing endpoint]
                            ───POST─────────────────────────────────▶ OrganisationController
  (switch)                                                              ::setActive()
                                                                          → OrganisationService
                                                                             ::setActiveOrganisation()
                                                                             (membership verified
                                                                              server-side)

GrantOrganisationAccessModal.vue
  (members list)            ───GET──────────────────────────────────▶ OrganisationController::show()
                                                                          (hasAccessToOrganisation() gate)
  (grant / revoke)          ───POST─▶ OrganisationMembersController    (NEW — beheerder-role gate)
                                        ::grant() / ::revoke()
                                        → OrganisationService
                                           ::joinOrganisation()/::leaveOrganisation()
```

## API Design

### `POST /apps/openregister/api/organisations/{uuid}/set-active` (existing, consumed unchanged)
Frontend calls this directly. No SoftwareCatalog route added.

### `GET /apps/softwarecatalog/api/me` (existing, consumed unchanged)
Already returns `organisations.active` and `organisations.all` (uuid, naam,
id, slug) — the switcher's list source.

### `GET /apps/openregister/api/organisations/{uuid}` (existing, consumed unchanged)
Frontend calls this directly for the member list (`organisation.users`,
an array of NC user ids) when the grant/revoke modal opens. Already gated by
`hasAccessToOrganisation()`.

### `POST /apps/softwarecatalog/api/organisations/{uuid}/members` (NEW)
**Request:**
```json
{ "userId": "j.devries" }
```
**Response (200):**
```json
{ "message": "Successfully granted organisation access", "userId": "j.devries" }
```
**Response (403 — caller not a beheerder of this organisation):**
```json
{ "error": "Only a beheerder of this organisation may grant access" }
```
**Response (404 — target user does not exist):**
```json
{ "error": "User not found" }
```

### `DELETE /apps/softwarecatalog/api/organisations/{uuid}/members/{userId}` (NEW)
**Response (200):**
```json
{ "message": "Successfully revoked organisation access", "userId": "j.devries" }
```
**Response (403):** same shape as grant.

## Database Changes
None. `lib/Settings/register.d/multi-org-membership.json` is added as an
**empty-but-present** fragment file only if a follow-up needs a config
surface; this change stores nothing new — all state already lives in
OpenRegister's `Organisation` entity and Nextcloud's `IGroupManager`/
`IUserManager`. (Decision: omit the fragment entirely rather than ship a
no-op file — see Decisions below.)

## Nextcloud Integration
- Controllers: NEW `OCA\SoftwareCatalog\Controller\OrganisationMembersController`
  (extends `OCP\AppFramework\Controller`, `#[NoAdminRequired]` +
  `#[NoCSRFRequired]` on `grant`/`revoke`, following
  `ContactpersonenController`'s constructor-injection pattern:
  `IUserSession`, `IUserManager`, `IGroupManager`, `ContainerInterface`
  (to reach OpenRegister's `OrganisationService`/`OrganisationMapper`),
  `LoggerInterface`).
- Services: consumes `OCA\OpenRegister\Service\OrganisationService` and
  `OCA\OpenRegister\Db\OrganisationMapper` via the container, exactly as
  `ContactpersonenController::getMe()` and `OrganizationHandler` already do
  — no new SoftwareCatalog service class for membership itself.
- Mappers/Entities: none new.
- Events/Hooks: none new.

## Security Considerations
This is a security-relevant change (`hydra-gate-security-change-has-tests`
applies) because it changes *which* organisation's data a session is scoped
to, and *who* may become a member of an organisation.

1. **Switch is never client-trusted.** The switcher POSTs only the target
   `uuid` (a route parameter); OpenRegister's `setActiveOrganisation()`
   re-derives membership from `Organisation::hasUser($this->userSession->
   getUser()->getUID())` — the session user, not anything the client sends —
   and throws otherwise. SoftwareCatalog adds no bypass path.
2. **Grant/revoke authorisation is derived, not accepted.** The new
   controller never trusts a client claim of "I am a beheerder of org X". It
   derives the caller's own organisation membership from
   `OrganisationService::getUserOrganisations()` (session-scoped, matching
   what `setActiveOrganisation` itself trusts) intersected with NC group
   `beheerder` (`IGroupManager::isInGroup($callerId, 'beheerder')`, the same
   group `ContactPersonHandler::assignBeheerderRole()` already populates).
   Both checks run server-side before the mutation; failing either returns
   403 without calling `joinOrganisation`/`leaveOrganisation` (deny before
   any default grant — OR trap or#2025).
3. **Read-scoping is unaffected and does the rest of the work.** Once the
   active organisation changes, `vendor-visibility-rbac`'s schema-level
   `_organisation` matching (already hardened, unchanged by this proposal)
   is what actually prevents cross-organisation reads — this change's job is
   only to make sure the *session's* active organisation is trustworthy and
   that the UI reflects it, which is why every list/detail view is forced to
   re-fetch after a confirmed switch (see Decisions).
4. **Existing-user-only.** Granting access never creates a Nextcloud
   account; the target must already resolve via `IUserManager::get()`,
   closing the "enrol an unverified identity" vector entirely (email invites
   are explicitly out of scope).

## NL Design System
- `OrganisationSwitcher.vue` renders in `CnAppRoot`'s `#tenant-badge` slot
  using only Nextcloud CSS variables (matches `CnTenantBadge`'s existing
  styling contract — no `--nldesign-*` references, per nc-vue rules).
- `GrantOrganisationAccessModal.vue` is a `NcDialog` (has a heading + action
  buttons — see nc-vue's NcDialog-vs-NcModal rule) living in its own file
  under `src/modals/`, using `NcSelectUsers` (`inputLabel` set) for the user
  picker and `NcListItem` + a destructive `NcActionButton` for revoke rows.

## File Structure
```
lib/
  Controller/
    OrganisationMembersController.php        (NEW)
src/
  App.vue                                     (modified — tenant-context wiring)
  components/
    organisations/
      OrganisationSwitcher.vue                (NEW)
  modals/
    GrantOrganisationAccessModal.vue          (NEW)
    Modals.vue                                (modified — register new modal)
  store/
    modules/
      organisatie.js                          (modified — grant/revoke actions)
tests/
  unit/
    Controller/
      OrganisationMembersControllerTest.php   (NEW)
  (src/**/*.spec.js for the Vue pieces, colocated per existing convention)
l10n/
  nl.js / nl.json                             (modified)
  en_US.js / en_US.json                       (modified)
docs/
  features/
    multi-org-membership.md                   (NEW, with screenshots)
```

## Seed Data
Not applicable — this change introduces no new OpenRegister schema/register.
Organisations and users used in testing are the existing dev-environment
seed data (multiple `organisatie` objects with distinct `users` arrays are
already present from prior multi-tenancy work); manual test setup adds a
second Nextcloud user to one organisation's `beheerder` group to exercise
the grant/revoke flow.

**`OrganisationSwitcher.vue` does not depend on nc-vue's `provide`/`inject`
tenant-context propagating correctly through a slot override.** `CnAppRoot`
mounts its own `provideTenantContext()` internally, and its default
`#tenant-badge` slot content (`CnTenantBadge`) is authored inside
`CnAppRoot.vue` itself, so it is unambiguously a true descendant for
`inject` purposes. Overriding that slot from `App.vue` (`<template
#tenant-badge>`) is different: slot content authored by the consuming app
and passed into a child component's slot has, in the general Vue
provide/inject model, an inject-resolution chain whose exact behaviour
depends on internals this change does not need to gamble on, and no other
app in the fleet has exercised this exact "override `#tenant-badge` from
the consumer" path yet to confirm it empirically. Rather than risk two
tenant-context readers (a live badge and the switcher) silently resolving
to two different reactive objects after a switch, `OrganisationSwitcher.vue`
is entirely self-contained: it fetches its own org list from `/api/me`, and
on a successful switch reloads the page rather than mutating any shared
reactive context in place. The default `#tenant-badge` slot is suppressed
(`<template #tenant-badge></template>`) and the switcher renders instead via
`#header-actions` (documented as free-form consumer content, no inject
contract implied) so there is exactly one on-screen indicator, always
self-consistent. The full-reload-on-switch strategy (already adopted for
REQ-002's security guarantee, see below) makes this the simpler design, not
just the safer one — nothing needs to propagate reactively within a single
page load, because a switch always ends that page load.

## Trade-offs

**Full view refresh vs. targeted cache invalidation on switch.**
`CnIndexPage`'s `activeOrganisation` watcher (already shipped in nc-vue)
clears the bound object store's cache but does not itself re-issue a fetch,
and SoftwareCatalog's manifest-driven `CnPageRenderer` does not currently
forward an `activeOrganisation` prop to every page type (dashboard, custom
views, faceted views) — wiring that per-page would touch a much larger
surface than this change's scope. Instead, after a confirmed switch this
change clears the shared object store's cache
(`useObjectStore().setActiveTenantOrganisation()`) **and** forces a full
reload of the current route. This is less elegant than fine-grained
reactivity but is the only option in this change's scope that is
*verifiably* correct for the security requirement ("the user sees only the
newly-active organisation's data") across all thirty manifest pages,
including the bespoke Dashboard and settings custom views that do not go
through `CnIndexPage`/`CnDetailPage` at all. Follow-up: promote per-page-type
`activeOrganisation` forwarding to `CnPageRenderer` in nc-vue so a future
change can drop the reload in favour of targeted refetch.

**SoftwareCatalog's own per-organisation NC group vs. OpenRegister's
`Organisation.users`.** `OrganizationHandler::userBelongsToOrganization()`
checks a group name derived from `sanitizeGroupName($organisationUuid)` (the
raw UUID), but the organisation's actual group is created and populated
using `sanitizeGroupName($organisationName)` (the org's *name*) — the two
never match, so that helper's primary branch (and its UUID-substring
fallback) are dead in the normal flow. This is a pre-existing, unrelated
latent bug (multiple call sites: `HierarchyHandler`, `ContractApprovalService`,
`getOrganizationBeheerders()`'s membership filter). This change deliberately
does not read or fix that helper — every check this change performs uses
OpenRegister's `Organisation.users`/`hasUser()` directly, which is correct
and already authoritative elsewhere (`setActiveOrganisation`, schema RBAC).
Fixing `userBelongsToOrganization` is out of scope here (unrelated blast
radius in a security-sensitive PR) and is called out as a follow-up.

**No new register fragment file.** ADR-037 requires any *register* change to
land in a new `lib/Settings/register.d/multi-org-membership.json` fragment,
never the monolith. This change adds no new schema/register objects, so no
fragment file is created — an empty placeholder fragment would be dead
weight and would not be "a register change" in any real sense.

## Open Questions
None — see proposal.md's Open Questions for the two assumptions already
resolved with the requester (direct OR consumption; `beheerder`-role
authorisation).
