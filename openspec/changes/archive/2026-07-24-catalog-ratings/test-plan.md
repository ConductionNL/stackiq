# Test Plan: catalog-ratings

## Test Cases

### TC-1: Unapproved review is not publicly readable
- **spec_ref**: `openspec/specs/catalog-ratings/spec.md#requirement-public-read-access-to-a-review-must-be-restricted-to-approved-reviews`
- **type**: security
- **persona**: n/a (unauthenticated)
- **preconditions**: a `beoordeeling` object exists with `status: "pending"`
- **steps**: unauthenticated request reads/lists `beoordeeling` objects
- **expected result**: the pending review is absent from the response
- **test command**: PHPUnit `ReviewServiceTest::testPendingReviewExcludedFromAggregateAndPublicRead` (aggregate/list path); schema RBAC itself asserted via `DeepMergeAuthorizationTest`

### TC-2: Rejected review is not publicly readable
- **spec_ref**: `openspec/specs/catalog-ratings/spec.md#requirement-public-read-access-to-a-review-must-be-restricted-to-approved-reviews`
- **type**: security
- **preconditions**: a `beoordeeling` object exists with `status: "rejected"`
- **steps**: unauthenticated request reads/lists `beoordeeling` objects
- **expected result**: the rejected review is absent from the response
- **test command**: PHPUnit `ReviewServiceTest`

### TC-3: Register fragment merge replaces (not concatenates) the authorization list
- **spec_ref**: `openspec/specs/catalog-ratings/spec.md#requirement-the-register-fragment-merge-must-replace-authorization-rule-lists-not-concatenate-them`
- **type**: security
- **preconditions**: base config `authorization.read = ["public"]`
- **steps**: merge the catalog-ratings fragment's `authorization.read` overlay
- **expected result**: merged `read` has no bare `"public"` entry
- **test command**: PHPUnit `DeepMergeAuthorizationTest::testAuthorizationListReplacesNotConcatenates`

### TC-4: Non-authorization list keys still concatenate (regression)
- **spec_ref**: `openspec/specs/catalog-ratings/spec.md#requirement-the-register-fragment-merge-must-replace-authorization-rule-lists-not-concatenate-them`
- **type**: regression
- **preconditions**: base config `required = ["naam"]`
- **steps**: merge an overlay `required = ["waardering"]`
- **expected result**: merged `required = ["naam", "waardering"]`
- **test command**: PHPUnit `DeepMergeAuthorizationTest::testNonAuthorizationListsStillConcatenate`

### TC-5: Client-supplied author is ignored
- **spec_ref**: `openspec/specs/catalog-ratings/spec.md#requirement-the-submitting-users-identity-must-be-bound-server-side-and-must-not-be-accepted-from-client-input`
- **type**: security
- **persona**: Jan (authenticated municipal user)
- **preconditions**: user "jan.jansen" authenticated
- **steps**: `ReviewService::submit()` with payload containing `auteur: "Someone Else"`
- **expected result**: persisted `auteur` is "jan.jansen"'s display name
- **test command**: PHPUnit `ReviewServiceTest::testClientSuppliedAuthorIsIgnored`

### TC-6: Unauthenticated submission is refused
- **spec_ref**: `openspec/specs/catalog-ratings/spec.md#requirement-creating-updating-or-deleting-a-review-must-be-governed-by-explicit-authorization-rules`
- **type**: security
- **preconditions**: no active session
- **steps**: `POST /api/reviews`
- **expected result**: 401/403, no object created
- **test command**: PHPUnit `ReviewServiceTest::testUnauthenticatedSubmissionRefused`

### TC-7: New submission lands pending
- **spec_ref**: `openspec/specs/catalog-ratings/spec.md#requirement-a-newly-submitted-review-must-require-moderation-approval-before-becoming-public`
- **type**: functional
- **persona**: Jan
- **preconditions**: authenticated user, valid payload
- **steps**: `ReviewService::submit()`
- **expected result**: stored `status: "pending"`
- **test command**: PHPUnit `ReviewServiceTest::testSubmissionLandsPending`

### TC-8: Admin approval flips a review to approved and public
- **spec_ref**: `openspec/specs/catalog-ratings/spec.md#requirement-a-newly-submitted-review-must-require-moderation-approval-before-becoming-public`
- **type**: functional
- **persona**: Noor (municipal CISO / functional admin)
- **preconditions**: a pending review exists
- **steps**: admin calls `POST /api/moderation/{uuid}/approve?type=beoordeeling`
- **expected result**: `status: "approved"`, now returned to public readers
- **test command**: PHPUnit `IntakeModerationTest::testBeoordeelingApprovalActivates`

### TC-9: Non-admin cannot reach moderation endpoints
- **spec_ref**: `openspec/specs/catalog-ratings/spec.md#requirement-review-moderation-must-reuse-the-existing-moderation-queue-mechanism-not-a-second-one`
- **type**: security
- **preconditions**: authenticated non-admin user
- **steps**: `GET /api/moderation/pending?type=beoordeeling`
- **expected result**: rejected before controller body runs (`AuthorizedAdminSetting`)
- **test command**: covered by the existing `#[AuthorizedAdminSetting]` attribute (framework-enforced, same as `organisatie` today) + code inspection

### TC-10: Existing organisatie moderation path is unaffected
- **spec_ref**: `openspec/specs/catalog-ratings/spec.md#requirement-review-moderation-must-reuse-the-existing-moderation-queue-mechanism-not-a-second-one`
- **type**: regression
- **preconditions**: existing `IntakeModerationTest.php` suite
- **steps**: run the full existing test file unmodified in assertions
- **expected result**: all existing assertions still pass with zero changes to their expected values
- **test command**: `phpunit -c phpunit-unit.xml --filter IntakeModerationTest`

### TC-11: Non-author cannot edit another user's review
- **spec_ref**: `openspec/specs/catalog-ratings/spec.md#requirement-only-the-reviews-author-or-an-organisation-scoped-admin-may-update-it-unrelated-users-must-be-refused`
- **type**: security
- **persona**: Piet (regular catalog user, not owner/admin)
- **preconditions**: a review owned by "jan.jansen"; "piet.peters" authenticated, not owner, not in an update-authorized group
- **steps**: "piet.peters" issues an update against the review
- **expected result**: refused (403) — asserted via the schema authorization list composition (no broad update grant) + owner-privilege documentation test
- **test command**: PHPUnit `DeepMergeAuthorizationTest::testUpdateAuthorizationExcludesBroadCatalogUserGroup`

### TC-12: Aggregate reflects only approved reviews, handles zero
- **spec_ref**: `openspec/specs/catalog-ratings/spec.md#requirement-module-and-dienst-detail-pages-must-display-an-aggregate-rating-computed-only-from-approved-reviews`
- **type**: functional
- **preconditions**: a module with one approved (waardering 8) and one pending (waardering 2) review; a second module with zero reviews
- **steps**: `ReviewService::getAggregate('module', $id)` for both
- **expected result**: first returns `{average: 8, count: 1}`; second returns `{average: null, count: 0}`
- **test command**: PHPUnit `ReviewServiceTest::testAggregate*`

### TC-13: Submit-a-review flow from the module detail page
- **spec_ref**: `openspec/specs/catalog-ratings/spec.md#requirement-module-and-dienst-detail-pages-must-display-an-aggregate-rating-computed-only-from-approved-reviews`
- **type**: functional
- **persona**: Mark (MKB software vendor / municipal catalog user)
- **preconditions**: authenticated user on a module detail page
- **steps**: click "Write a review", fill rating + testimonial, submit
- **expected result**: success toast, aggregate unchanged until approved (pending)
- **test command**: `tests/vitest/reviewsPanel.spec.js` (component logic); manual Playwright screenshot for docs

### TC-14: Reviews index columns match the schema
- **spec_ref**: `openspec/specs/catalog-ratings/spec.md#requirement-the-reviews-index-must-display-columns-that-exist-on-the-beoordeeling-schema`
- **type**: regression
- **preconditions**: `src/manifest.json` after the change
- **steps**: cross-reference `Reviews` page `config.columns` against `beoordeeling` schema properties
- **expected result**: all four columns exist on the schema
- **test command**: `npm run check:manifest` (structural) + manual cross-reference (no automated schema-cross-reference tool exists in this repo)

## Coverage Summary
- Public read fail-closed (approved-only): TC-1, TC-2 — covered
- Fragment merge replace-not-concatenate: TC-3, TC-4 — covered
- Explicit create/update/delete authorization: TC-6, TC-11 — covered
- Server-side author binding: TC-5 — covered
- Pending-by-default + moderation approval/rejection: TC-7, TC-8 — covered
- Moderation reuse (not a second mechanism) + admin gating + regression: TC-9, TC-10 — covered
- Aggregate rating (approved-only, zero-case): TC-12 — covered
- Submit flow UI: TC-13 — covered
- Reviews index dead columns fixed: TC-14 — covered

## Out of Scope
- Full E2E Playwright coverage of the moderation approve/reject buttons is
  not added new — the existing `organisatie` moderation E2E coverage (if
  any) is unaffected; `beoordeeling` moderation reuses the identical
  component/endpoint shape and is covered at the PHPUnit/vitest level per
  ADR-009's minimum bar. A manual Playwright screenshot is captured for
  `docs/features/catalog-ratings.md` (ADR-010) but is not a maintained
  regression suite entry.
- Newman/Postman collection for `/api/reviews*` is not added; the existing
  test convention in this repo for security-sensitive write paths
  (`IntakeService`/`ModerationService`) is PHPUnit-only, followed here for
  consistency.
