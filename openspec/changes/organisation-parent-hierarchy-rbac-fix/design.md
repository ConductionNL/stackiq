# Design — organisation-parent-hierarchy-rbac-fix

## Context

`OrganisatieService::createOrganisationEntityInternal()`
(`lib/Service/OrganisatieService.php:256-312`) used to set the newly created
organisation's `parent` to the caller's active organisation uuid
(`getActiveOrganisationUuid()`, `:323-342`). A prior hotfix disabled this
because it made the newly created (child) organisation inaccessible to its
own creator — an RBAC scoping bug where hierarchical `parent` linkage
interacted badly with the object-level/organisation-level access filter.
Since then, every organisation created by this app is flat: no parent, no
hierarchy, ever.

## Problem

Two failure modes are plausible for why setting `parent` broke access; the
investigation task (1.1) must determine which one applies before choosing a
fix, because they call for different remedies:

1. **RBAC scope resolution walks the hierarchy the wrong direction** — e.g.
   read-access is computed only for organisations the user is a *direct*
   member of, and a child organisation's ACL check requires membership on
   the child specifically, which the creator never received (only
   membership on the parent). Fix: OR-side, or app-side membership grant on
   create.
2. **RBAC scope resolution short-circuits when `parent` is non-null** in a
   way unrelated to membership (e.g. a cache key collision, or a query that
   filters `organisation = X` but the child's effective organisation for
   object-scoping purposes is computed as `parent` instead of the child's own
   uuid). Fix: likely needs an OR patch; app-side workaround is the
   create-then-link sequence (task 2.2).

## Two candidate fixes

### A. Direct fix (if upstream OR already resolved the scoping bug)
Uncomment the existing code path. Lowest risk, smallest diff, but requires
confirming (task 1.1/1.2) that OR's current RBAC actually handles this
correctly — do NOT re-enable on faith; the original bug was severe enough
(users locked out of their own newly created data) to warrant hotfixing in
the first place.

### B. Create-then-link (safe regardless of the OR-side root cause)
1. Create the organisation with no parent (today's working path).
2. Verify the creating user has access to the new organisation (a cheap
   `OrganisationService` read-back as the creating user's context).
3. Set `parent` on the already-accessible organisation via a second
   `OrganisationService` call.
4. Re-verify access did not regress after step 3; if it did, roll back the
   parent assignment and surface an error rather than silently leaving a
   half-linked or now-inaccessible organisation.

Option B is the fallback if task 1.1 shows the bug still reproduces upstream
— it trades one extra round-trip (acceptable; organisation creation is not a
hot path) for guaranteed non-regression of the exact failure the hotfix was
protecting against.

## Decision

Investigated against the currently-vendored OpenRegister
(`apps-extra/openregister`). Findings:

**Root cause (task 1.1):** Access to an organisation *record* is
membership-based — `OrganisationService::hasAccessToOrganisation()` returns
`admin OR $organisation->hasUser($uid)`, and `getUserOrganisations()` is
`findByUserId($uid)`. Access to an organisation's *resources/objects* is
governed by the multitenancy filter `getUserActiveOrganisations()`, which
returns the active organisation UUID **plus its parent chain**
(`findParentChain()`). The OR `Organisation` entity documents the invariant
explicitly: *"Children can view parent resources but parents cannot view
child resources."* So when the app created a child `C` with `parent = P` and
the creating user's active organisation was `P`, the creator (active = P)
fell **outside** C's own scope (C is below P, not a parent of P) and could
not read C's resources — exactly the "users could not access newly created
organisations" the hotfix cited. The failure mode is a **hierarchy-direction
scoping** effect (mechanism 1 above), not a cache/short-circuit bug.

**Is it fixed upstream? (task 1.2):** No — this is not a *bug*, it is the
intended OR invariant (parents don't see downward). It "still reproduces" by
design. Separately, OR's public `OrganisationService` API exposes **no
`parent` argument on `createOrganisation()` and no `setParent`/
`updateOrganisation` method at all**, so Option A (uncomment + pass parent to
`createOrganisation`) is not achievable as tasks.md 2.1 literally describes —
that argument does not exist.

**Chosen fix (Option B, create-then-link):** Create the organisation first
(the working, accessible path), then apply `parent` in a second step via the
`OrganisationMapper` — the *same* mapper this service already reaches for in
`addUsersToOrganization()` (an established in-app seam, not a new OR-internal
reach). The link step is guarded (never self-parent; only when an active
parent resolved) and fail-soft: a link failure logs and returns the
organisation *flat* rather than losing it, preserving the pre-hotfix
"organisation is always created" guarantee.

**On "creator retains access" (proposal / task 3.2):** The app's
organisation-create path runs `addCurrentUser: false` by design — these
organisations represent *external* parties (gemeenten/leveranciers) created
during catalog sync (`SoftwareCatalogueService` / `OrganizationSyncService`
background paths), not orgs owned by the syncing user. Those sync paths run
in admin/system context, and `hasAccessToOrganisation()` grants admins access
to *every* organisation regardless of hierarchy direction — so an admin/
system creator retains full read/write access to the new child after linking.
We do **not** force-add the creator as a member, because that would contradict
the app's deliberate `addCurrentUser: false` external-org model; the
admin-bypass already covers the real create path.
