# Tasks — organisation-parent-hierarchy-rbac-fix

## 1. Root-cause the RBAC bug

- [x] 1.1 Reproduce against the currently-vendored OpenRegister version: create
  organisation B with organisation A active/parent, as the current user; log
  in (or query as) that same user and check whether B is readable/writable.
  Record the actual failure mode (403? empty list? wrong scope filter?) — the
  original hotfix commit message does not specify.
  Note in this app's context there is no local OpenRegister checkout —
  coordinate with the OR-owning devs / OR's own RBAC test suite if the bug
  needs to be traced inside OR's `OrganisationService`/RBAC middleware rather
  than in this app.
- [x] 1.2 Determine whether the bug is already fixed upstream (OR has
  changed significantly since this hotfix — check OR's changelog/RBAC ADRs
  for organisation-hierarchy-scoped access fixes).
- [x] 1.3 Record the root cause (or "still reproduces, see OR issue #NNN") in
  `design.md`.

## 2. Fix

  > Recorded in design.md, "Decision": hierarchy-direction scoping is the intended OpenRegister invariant, and `createOrganisation()` has no parent argument.
- [ ] 2.1 If upstream-fixed: uncomment `$parentOrganisationUuid =
  $this->getActiveOrganisationUuid(organisationService: $organisationService)`
  (`lib/Service/OrganisatieService.php:281`) and pass it into
  `$organisationService->createOrganisation(...)`'s parent argument; restore
  the `'parentOrganisation' => $parentOrganisationUuid` log context (`:288`).
  > Not applicable: not fixed upstream, and `createOrganisation()` exposes no parent argument (design.md). Task 2.2 is the path taken.
- [x] 2.2 If still broken: implement the create-then-link sequence — create
  the organisation without a parent, then call the OR API/service to set
  `parent` in a second step, verifying the creating user's access holds
  before and after. Document why this ordering avoids the bug in `design.md`.
  > Done in `OrganisatieService::linkResolvedParent()` / `linkParentOrganisation()`.
- [x] 2.3 Remove the `HOTFIX`/`TODO` comments once resolved
  (`lib/Service/OrganisatieService.php:259-262`, `:277-281`).

## 3. Tests

  > No HOTFIX/TODO comments remain in `lib/Service/OrganisatieService.php`.
- [x] 3.1 `OrganisatieServiceTest`: creating an organisation with an active
  parent organisation results in `getParent()` returning the parent's uuid.
  > `OrganisatieServiceParentLinkCreateTest::testAChildIsLinkedToTheActiveOrganisation`, `OrganisatieServiceParentHierarchyTest`.
- [ ] 3.2 `OrganisatieServiceTest`/integration: the creating user retains
  read/write access to the newly created child organisation (the exact
  regression the hotfix was guarding against) — assert access is NOT lost.
  > Left open: design.md decides not to force-add the creator (`addCurrentUser: false` external-organisation model); access on the real create path comes from the admin/system context, which a unit test cannot show. Needs an integration test against OpenRegister.
- [x] 3.3 Regression: creating an organisation with no active parent still
  results in a parentless (root) organisation, unchanged from today.

## 4. Docs

  > `OrganisatieServiceParentHierarchyTest::testNullParentLeavesOrganisationAsRoot`.
- [x] 4.1 Update `docs/` (e.g. `BUG_FIX_ORGANISATION_USER_ASSIGNMENT.md` or
  equivalent) to record the hierarchy re-enablement and link this change.
  > `docs/ORGANIZATION_SYNC_USECASES.md`, use case 1, "Parent organisation".
