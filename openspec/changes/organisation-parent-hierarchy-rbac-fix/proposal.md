---
kind: code
depends_on: []
---

# softwarecatalog — restore organisation parent-child hierarchy (disabled since a RBAC hotfix)

## Why

`OrganisatieService::createOrganisationEntityInternal()`
(`lib/Service/OrganisatieService.php:256-312`) carries a standing hotfix that
disables setting the new organisation's parent:

> "HOTFIX: Parent organisation setting has been disabled due to RBAC issues.
> Previously, new organisations were automatically set as children of the
> active organisation, but this caused permission problems where users could
> not access newly created organisations."
> `// TODO: Investigate and fix RBAC logic to properly handle parent-child
> organisation relationships.` (`:262`, `:280`)

The call that would set `$parentOrganisationUuid` is commented out
(`:281`), and the log line that would record it is also commented out
(`:288`). `getActiveOrganisationUuid()` (`:323-342`) — the helper that would
supply the parent uuid — is now dead code kept alive only by this disabled
call site.

This is a real, silent feature regression for a VNG-style government software
catalog, whose domain routinely models hierarchical organisation structures
(gemeente → samenwerkingsverband, moederorganisatie → deelnemende partij).
Every organisation created since the hotfix landed is flat — there is no
parent/child linkage at all — and nothing in the UI or API currently
surfaces that this is disabled-by-design rather than "the domain has no
hierarchy." An admin creating a child organisation gets a silently
unlinked, unhierarchical record.

The hotfix's own justification (newly created child organisations became
inaccessible to the creating user) is an OR-side RBAC filtering bug in how
object-level and organisation-level scoping interact for
newly-created-with-a-parent organisations — not a reason to permanently
drop the feature. It should be root-caused and fixed, or the parent
relationship should be set through a path that does not trigger the RBAC
bug (e.g. set the parent in a follow-up call after the creating user's
access to the child is confirmed, rather than at creation time).

## What Changes

- Investigate the RBAC filtering bug: reproduce "child organisation with a
  parent set is inaccessible to its creator" against the current
  OpenRegister `OrganisationService`/RBAC version (the hotfix predates
  today's OR; the bug may already be fixed upstream — verify before
  re-architecting).
- Re-enable parent-organisation assignment in
  `createOrganisationEntityInternal()`, either:
  (a) directly, if the upstream RBAC bug is confirmed fixed, or
  (b) via a two-step create-then-link sequence that avoids the failure mode
  (create the organisation without a parent, confirm the creator has access,
  then set the parent), whichever the investigation supports.
- Restore the log line that records `parentOrganisation` on creation
  (`:288`, currently commented out).
- Unit test: creating an organisation while an active (parent) organisation
  is set MUST result in a child organisation whose `getParent()` returns the
  active organisation's uuid, AND the creating user MUST retain read/write
  access to the newly created child.
- Not BREAKING: existing flat (parentless) organisations are unaffected;
  this only changes behaviour for *new* organisations created while another
  organisation is active.
