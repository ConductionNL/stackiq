# Design: catalog-ratings

## Architecture Overview
`beoordeeling` becomes a moderated, authored object type on the existing
`voorzieningen` OpenRegister register — no new register, no new database
table (ADR-001). Two new thin backend seams are added on top of the generic
`ObjectService` path softwarecatalog otherwise uses directly from the
frontend, mirroring the two existing precedents for security-sensitive
writes/reads (`IntakeService`/`IntakeController` for anonymous intake,
`ModerationService`/`ModerationController` for the approval queue):

- `ReviewService`/`ReviewController` — authenticated submit (author stamped
  from session, status forced to `pending`) + public approved-only read +
  aggregate.
- `ModerationService`/`ModerationController` — generalised (not duplicated)
  to also moderate `beoordeeling`, alongside its existing `organisatie` path.

```
Vue (ModuleDetail bodyWidget: ReviewsPanel.vue)
  │  GET  /api/reviews?type=module&id=<uuid>        (public, approved-only + aggregate)
  │  POST /api/reviews                              (auth session, author/status stamped)
  ▼
ReviewController → ReviewService → OpenRegister ObjectService (register.d/catalog-ratings.json RBAC)

Vue (SoftwareCatalogSettings.vue: ModerationQueue type="beoordeeling")
  │  GET  /api/moderation/pending?type=beoordeeling
  │  POST /api/moderation/{uuid}/approve?type=beoordeeling
  │  POST /api/moderation/{uuid}/reject?type=beoordeeling
  ▼
ModerationController → ModerationService (generalised) → OpenRegister ObjectService
```

## API Design

### `GET /api/reviews`
Public, read-only. Query params `type` (`module`|`dienst`), `id` (subject
uuid). Returns approved reviews for that subject plus the aggregate.

**Response:**
```json
{
  "average": 8.25,
  "count": 4,
  "items": [
    { "id": "…", "naam": "Solid intake flow", "waardering": 9, "auteur": "Jan Jansen", "beschrijvingLang": "…" }
  ]
}
```

### `POST /api/reviews`
Authenticated (`#[NoAdminRequired]`). Body: `naam`, `waardering` (1-10),
`beschrijvingKort`/`beschrijvingLang` (testimonial), `subjectType`
(`module`|`dienst`), `subjectId` (uuid). `auteur`, `status`, `id`, `uuid`,
`_owner`, `_organisation`, `_source` are stripped from the payload server-side
before validation (mirrors `IntakeService::FORBIDDEN_KEYS`) and `auteur` is
set from `IUserSession::getUser()->getDisplayName()`, `status` forced to
`pending`.

**Response (202):**
```json
{ "ok": true, "uuid": "…", "status": "pending", "message": "Review received and queued for moderation" }
```

### `GET /api/moderation/pending?type=beoordeeling`
### `POST /api/moderation/{uuid}/approve?type=beoordeeling`
### `POST /api/moderation/{uuid}/reject?type=beoordeeling`
Admin-gated (`#[AuthorizedAdminSetting(SoftwareCatalogAdmin::class)]`),
identical contract to the existing `organisatie` moderation endpoints; `type`
defaults to `organisatie` for backward compatibility with the existing
`ModerationQueue.vue` instance and its tests.

## Database Changes
None — no Nextcloud migration class. All state lives in OpenRegister objects
governed by the `beoordeeling` JSON schema, extended via
`lib/Settings/register.d/catalog-ratings.json` (ADR-037), never by editing
`lib/Settings/softwarecatalogus_register.json`.

## Nextcloud Integration
- Controllers: `ReviewController` (new), `ModerationController` (extended)
- Services: `ReviewService` (new), `ModerationService` (extended)
- Mappers/Entities: none — all persistence via OpenRegister's `ObjectService`
- Events/Hooks: none new — the schema's existing
  `x-openregister-notifications.review-submitted` rule (already present,
  unused today) starts firing once objects are actually created; reused
  as-is per the proposal's Out-of-Scope

## Security Considerations
This IS the security-critical part of the change; see also
`context-brief.md`.

1. **Fail-closed public read.** `beoordeeling.authorization.read` changes
   from an unconditional `["public"]` to
   `[{"group":"public","match":{"status":"approved"}}, <internal catalog groups>]`.
   Per or#2025 (veto-after-grant is dead code), the fix must ensure the
   dangerous bare `"public"` entry is fully REMOVED, not additionally
   guarded — appending a narrower rule after an unconditional one is a no-op
   because OpenRegister's rule evaluation is a first-match/any-match OR, not
   a most-specific-wins evaluation.

2. **The register.d merge trap.** `SettingsService::deepMergeConfig()`
   concatenates list-valued overlay keys onto the base (documented,
   intentional, and correct for e.g. extending a `required` array). Applied
   naively to `authorization.read`, concatenating my new list onto the
   existing `["public"]` base produces `["public", {...}]` — `"public"` is
   still present, so the schema would still be unconditionally
   world-readable and the whole point of this change would silently not
   ship. **Decision:** teach `deepMergeConfig` that any key literally named
   `authorization` is replaced wholesale (list values included) rather than
   concatenated, for that key's entire subtree. This is scoped to the
   `authorization` key only — every other merge behavior (including the one
   existing fragment, `contracts-to-decidesk.json`, which never touches
   `authorization`) is unaffected. Alternative considered: express the
   fragment's `read` array as `["public+conditional-only"]` and rely on some
   later filter — rejected, no such conditional-suppression mechanism exists
   in the RBAC evaluator (confirmed against
   `openregister/openspec/specs/auth-system/spec.md`); replacing is
   the only construct that actually removes the base entry.

3. **Author identity never from client input.** `ReviewController::submit()`
   strips `auteur` (and `status`/`id`/`uuid`/`_owner`/`_organisation`/
   `_source`) from the request body before it ever reaches `ObjectService`,
   then sets `auteur` itself from the authenticated `IUserSession`. This
   mirrors `IntakeService::FORBIDDEN_KEYS` exactly (same class of problem —
   different trust boundary: anonymous vs. authenticated-but-untrusted
   client payload).

4. **Ownership-scoped edit.** `beoordeeling` gets no bespoke "is this the
   author" check in application code: OpenRegister's own role hierarchy
   (`admin > object owner > named groups > authenticated > public`, per
   `auth-system` spec REQ "role hierarchy") already grants the creating
   user (`_owner`, auto-stamped by `ObjectService::saveObject()` from the
   session at create time — no application code needed) full CRUD on their
   own review regardless of the schema's named-group `update`/`delete`
   lists. The schema's own `update`/`delete` lists are therefore
   deliberately narrow (admin + org-scoped org-admin groups only, no broad
   "all catalog users" entry) — that narrowness is what makes "non-author
   cannot edit another's review" true; owner override is what makes
   "author can edit their own" true, without needing to duplicate that
   check in `ReviewService`.

5. **Deletion restricted.** Per the brief, `delete` is intentionally not
   granted to the broad staff-role list every other schema in this register
   uses — only `software-catalog-admins`, plus the owner override above.

6. **CSRF/rate-limiting.** `POST /api/reviews` is a normal authenticated,
   CSRF-protected (Nextcloud default) endpoint — no `#[PublicPage]`, no
   `#[NoCSRFRequired]`, unlike `IntakeController` (which is deliberately
   anonymous + rate-limited). `GET /api/reviews` and the moderation
   list/decide endpoints follow the exact existing precedent
   (`FacetController`/`ModerationController`).

7. **Residual risk (accepted, documented in the proposal).** A user already
   in an authorized `create` group could still call OpenRegister's generic
   object API directly instead of `ReviewController`, and set `auteur`/
   `status` themselves on that path. This is an existing, accepted trust
   boundary shared by every other schema in this app (frontend talks to OR
   directly); the public read gate is enforced independently of which path
   wrote the object, so this does not reproduce the brief's "no
   authorization at all" hole.

## NL Design System
`ReviewsPanel.vue`/`SubmitReviewModal.vue` use `NcButton`, `NcTextField`,
`NcTextArea`, `NcNoteCard`/`NcEmptyContent` from `@nextcloud/vue` and NC CSS
variables only (no hardcoded colors, ADR-003). A star/numeric rating input
component: a simple 1-10 `NcSelect` (`inputLabel` set, ADR-012/hydra-gate-
nc-input-labels) rather than inventing a bespoke star-rating widget the
design system doesn't provide.

## File Structure
```
lib/
  Settings/register.d/catalog-ratings.json      (new fragment)
  Controller/ReviewController.php               (new)
  Service/ReviewService.php                     (new)
  Controller/ModerationController.php           (generalised: +type param)
  Service/ModerationService.php                 (generalised: +type param)
  Service/SettingsService.php                   (deepMergeConfig authorization fix)
src/
  components/reviews/ReviewsPanel.vue           (new body widget)
  modals/SubmitReviewModal.vue                  (new, own file per ADR-012)
  views/settings/sections/ModerationQueue.vue   (parameterised: type/labels props)
  views/settings/SoftwareCatalogSettings.vue    (second ModerationQueue instance)
  utils/adminApi.js                             (reused as-is for the new endpoints)
  customComponents.js                           (register ReviewsPanel)
  manifest.json                                 (ModuleDetail bodyWidgets; Reviews index columns)
appinfo/routes.php                              (new /api/reviews* routes)
tests/Unit/Service/ReviewServiceTest.php        (new, incl. negative security tests)
tests/Unit/Service/DeepMergeAuthorizationTest.php (new)
tests/Unit/Service/IntakeModerationTest.php     (extended: type=beoordeeling coverage)
tests/vitest/reviewsPanel.spec.js               (new)
l10n/nl.js, l10n/nl.json, l10n/en_US.js, l10n/en_US.json (new keys)
docs/features/catalog-ratings.md                (new, with screenshot)
```

## Seed Data
No seed data is added by this change. `beoordeeling` remains empty on a
fresh install (as it is today); the moderation queue and ratings panel both
render correctly on zero rows (`NcEmptyContent`, `count: 0` / `average: null`
handled explicitly). Reviews are created only by real user submission
through `ReviewController`.

## Trade-offs
- **Custom PHP aggregate vs. declarative manifest `stat` widget.** The
  existing `stat` widget type (used by `rv-score`/`ct-value`) is
  attractive for consistency, but its `filter` semantics for an
  array-of-related-object property (`beoordeeling.modules`) are unverified
  in this codebase (no existing usage filters an array-of-relations field;
  `TimeseriesRequestValidator` only confirms the aggregated *field* must be
  a declared schema property, not that array-containment filtering works).
  Given the "orphaned capability" failure mode already observed elsewhere in
  this fleet (spec-says-done ≠ feature runs), the aggregate is computed in
  `ReviewService` against `ObjectService::searchObjects()` results in PHP
  instead — slightly more code, but deterministic and fully unit-testable
  without depending on unverified filter behavior.
- **Generalising `ModerationService`/`ModerationQueue.vue` vs. a parallel
  review-specific moderation stack.** The brief is explicit: reuse the
  pattern, don't invent a second mechanism. Generalising risks the
  well-tested `organisatie` path; mitigated by keeping every new parameter
  defaulted to the exact current `organisatie`/`registratiestatus`/`active`
  behavior, so `IntakeModerationTest.php`'s existing assertions (which never
  pass a `type`) continue to exercise the unchanged default path.
