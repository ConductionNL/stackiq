# Proposal: catalog-ratings

## Summary
Turns the dormant `beoordeeling` (review) schema into a working, moderated
ratings-and-testimonials feature and closes the authorization hole it ships
with today: `beoordeeling` currently has `authorization: {"read": ["public"]}`
with no create/update/delete rules and no author or owning-organisation
binding, so any review would be world-readable with undefined write rules and
no attributable author. This change adds explicit create/update/delete
authorization, a server-stamped author + owning organisation, a
pending/approved/rejected moderation status reusing the existing
`ModerationQueue.vue` approval pattern, a submit-a-review flow (rating +
testimonial) from the module detail page, an aggregate rating (average +
count) on module detail, and fixes the manifest's dead `auteur` Reviews-index
column. Closes softwarecatalog#375.

## Motivation
VNG Softwarecatalogus issue #49 — peer municipalities want to see peer
experience/ratings when selecting software; this is a core catalog
job-to-be-done and a point of differentiation versus the centralised GEMMA
registry, which does not offer peer review. The schema for this already
exists in the published data model but has never been wired up, and shipping
it as-is would expose an unauthenticated write-anything, read-everything
surface with no accountability — worse than not having the feature at all.
This change makes the feature real while closing that hole first.

## Affected Projects
- [x] Project: `softwarecatalog` — schema fragment (author/org binding +
  authorization + status), submit/list/aggregate endpoints, moderation reuse,
  module-detail ratings panel, Reviews-index column fix, i18n, tests.

## Scope

### In Scope
- Schema: add `auteur` (server-stamped author display name) and `status`
  (`pending`/`approved`/`rejected`, default `pending`) properties to
  `beoordeeling` via a new `lib/Settings/register.d/catalog-ratings.json`
  fragment (never editing the monolith). Owning organisation uses
  OpenRegister's existing `_organisation` system field (the same convention
  already used by `contactpersoon`/`gebruik`/`koppeling` in this register) —
  no new schema property needed for it.
- Explicit `authorization.create/update/delete` on `beoordeeling` (today
  entirely absent), and a `read` rule that is public ONLY for
  `status: approved` reviews, replacing the current unconditional
  `["public"]` grant with a genuinely fail-closed rule.
- A fix to `SettingsService::deepMergeConfig()` (pre-existing register-
  fragment merge helper) so that a fragment overlaying a schema's
  `authorization` block REPLACES rule lists instead of concatenating them —
  the existing list-concatenation behavior is correct for ordinary schema
  properties but is a fail-OPEN trap for authorization arrays: concatenating
  a narrower overlay onto a base list that still contains bare `"public"`
  would leave the dangerous wide-open base entry in place no matter what the
  overlay adds. This is the same class of bug as OR's veto-after-grant trap
  (or#2025), one layer up in the config-merge step.
- Moderation: reuse `ModerationController`/`ModerationService`/
  `ModerationQueue.vue` by generalising them to a second moderated type
  (`beoordeeling`, field `status`, approved value `approved`) rather than
  building a second admin queue mechanism. The existing `organisatie`
  moderation path (registratiestatus/active) keeps its exact current
  behavior as the default.
- Submit-a-review flow: a new `ReviewController`/`ReviewService` (mirroring
  `IntakeService`'s pattern) so a logged-in user's author identity is always
  taken from the Nextcloud session and never from client-supplied input, and
  new submissions are always forced to `status: pending`. A `SubmitReviewModal.vue`
  (own file, ADR-012) reachable from the module detail page, plus an
  average+count aggregate, computed server-side (not via a declarative
  manifest stat widget, to avoid depending on unverified array-containment
  filter semantics for `beoordeeling.modules`).
- Fix the manifest's `Reviews` index (`src/manifest.json`): replace the
  dead `titel`/`auteur`/`score`/`datum` columns (none of which exist on the
  schema) with the real properties `naam`/`auteur`/`waardering`/`status`.
- i18n (English keys + `l10n/nl.js`/`l10n/nl.json` +
  `l10n/en_US.js`/`l10n/en_US.json`), PHPUnit + vitest tests (including the
  mandated negative security tests), and feature docs.

### Out of Scope
- Cross-organisation reputation scoring.
- Review replies/threads.
- Notifying vendors of new reviews (the `review-submitted`
  `x-openregister-notifications` rule already declared on `beoordeeling` is
  reused as-is, not extended).
- Anonymous public review submission — review authorship requires an
  authenticated session; this is the opposite of the `IntakeService` anonymous
  path and is why submission is NOT wired through `IntakeService` itself.
- A dedicated `DienstDetail` page. `beoordeeling` already supports rating a
  `dienst` via its `diensten` relation and the aggregate/submit backend is
  subject-type-agnostic (`module` or `dienst`), but the softwarecatalog
  manifest today has no `/diensten/:id` detail route at all (`Diensten` is a
  `type: custom` faceted index with no row-level detail page) — adding one is
  a pre-existing gap unrelated to the authorization hole this change closes.
  A follow-up issue is filed to wire the ratings panel there once that page
  exists.

## Approach
Add a register.d fragment binding `beoordeeling` to a server-enforced
moderation lifecycle and fail-closed RBAC; add two small backend
controller/service pairs (review submission + review moderation, the latter
generalising the existing organisatie moderation code rather than
duplicating it); add a small custom `ReviewsPanel.vue` body-widget on
`ModuleDetail` (the existing `bodyWidgets`/`component` manifest escape hatch,
same mechanism as `ContractApprovalPanel`) that shows the aggregate + a
submit button; wire a second `ModerationQueue.vue` instance into the admin
settings page for review moderation, parameterised by `type` prop.

## New Dependencies
None.

## Impact
- `lib/Settings/register.d/catalog-ratings.json` (new fragment)
- `lib/Service/SettingsService.php` (`deepMergeConfig` authorization-replace fix)
- `lib/Controller/ReviewController.php`, `lib/Service/ReviewService.php` (new)
- `lib/Controller/ModerationController.php`, `lib/Service/ModerationService.php` (generalised to a second type)
- `src/views/settings/sections/ModerationQueue.vue` (parameterised by `type`/labels)
- `src/views/settings/SoftwareCatalogSettings.vue` (second `ModerationQueue` instance)
- `src/components/reviews/ReviewsPanel.vue`, `src/modals/SubmitReviewModal.vue` (new)
- `src/customComponents.js`, `src/manifest.json` (`ModuleDetail` bodyWidgets, `Reviews` index columns)
- `src/utils/moderationItem.js` (already generic — no change expected, verified during implementation)
- `appinfo/routes.php` (new `/api/reviews*` endpoints)
- i18n: `l10n/nl.js`, `l10n/nl.json`, `l10n/en_US.js`, `l10n/en_US.json`
- Tests: `tests/Unit/Service/ReviewServiceTest.php`,
  `tests/Unit/Service/DeepMergeAuthorizationTest.php`,
  `tests/vitest/*` for the new Vue logic.

## Cross-Project Dependencies
None. `beoordeeling` is entirely internal to the `voorzieningen` register
owned by softwarecatalog; no other Conduction app reads or writes it.

## Risks

### Risk 1: A user in an authorized `create` group can bypass `ReviewController` and POST to OpenRegister's generic object API directly, setting `auteur`/`status` themselves
**Severity:** Medium — **Mitigation:** This is an accepted, pre-existing
architecture trade-off shared by every schema in this app (softwarecatalog's
frontend talks to OpenRegister directly for all other schemas; write access
is gated purely by group membership, not by a bespoke controller). The
`ReviewController` path is the one the shipped UI uses and is what closes the
brief's named hole (world-readable + no authorization at all). The public
`read` gate (`status: approved` only) is enforced by OpenRegister's own RBAC
filter regardless of which path was used to write the object, so a
self-approved forged review is still not the "no authorization at all"
situation described in the brief — it is a residual risk equivalent to what
every other schema already accepts for its trusted internal groups. Noted as
a follow-up rather than blocking this change.

### Risk 2: `deepMergeConfig` behavior change could alter existing fragments
**Severity:** Low — **Mitigation:** The only key affected is one literally
named `authorization`; the sole existing fragment
(`register.d/contracts-to-decidesk.json`) never touches that key, so its
merged output is byte-for-byte unchanged. Covered by a new unit test
asserting both the old (non-authorization) concatenation behavior and the
new (authorization) replace behavior.

## Rollback Strategy
Revert the commits on `wip/catalog-ratings`. The register fragment is
additive and isolated (`register.d/catalog-ratings.json`); deleting it
reverts `beoordeeling` to its pre-change (dormant, unauthorized) shape on the
next settings reload. No data migration is introduced, so no destructive
rollback step is needed; any reviews already submitted remain valid
OpenRegister objects and can be manually pruned if desired.

## Open Questions
None outstanding — the aggregate-filter uncertainty (whether OpenRegister's
declarative stat-widget filter supports array-containment on
`beoordeeling.modules`) was resolved by not depending on it: the aggregate is
computed by `ReviewService` in PHP against `ObjectService::searchObjects()`
results, which is fully unit-testable regardless of that filter's actual
semantics.
