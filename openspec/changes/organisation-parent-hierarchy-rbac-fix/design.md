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

Deferred to task 1 (investigation) in `tasks.md`. This design.md exists so
the choice between A and B is recorded with its reasoning rather than
re-litigated mid-implementation.
