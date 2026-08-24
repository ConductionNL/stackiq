# Tasks: catalog-ratings

## Implementation Tasks

### Task 1: Add the catalog-ratings register fragment (author/org binding + fail-closed authorization + status)
- **spec_ref**: `openspec/specs/catalog-ratings/spec.md#requirement-public-read-access-to-a-review-must-be-restricted-to-approved-reviews`
- **files**: `lib/Settings/register.d/catalog-ratings.json`
- **acceptance_criteria**:
  - GIVEN the fragment is merged WHEN `beoordeeling` is loaded THEN it has `auteur` and `status` properties, and `authorization.create/update/delete` are all present and non-empty
  - GIVEN the merged config WHEN `authorization.read` is inspected THEN it contains no bare `"public"` entry, only a `status: approved`-conditioned one
- [x] Implement
- [x] Test

### Task 2: Fix deepMergeConfig to replace (not concatenate) authorization lists
- **spec_ref**: `openspec/specs/catalog-ratings/spec.md#requirement-the-register-fragment-merge-must-replace-authorization-rule-lists-not-concatenate-them`
- **files**: `lib/Service/SettingsService.php`, `tests/Unit/Service/DeepMergeAuthorizationTest.php`
- **acceptance_criteria**:
  - GIVEN a base `authorization.read` of `["public"]` and an overlay of `[{"group":"public","match":{"status":"approved"}}]` WHEN merged THEN the result is exactly the overlay
  - GIVEN a base `required` of `["naam"]` and an overlay of `["waardering"]` WHEN merged THEN the result is `["naam","waardering"]` (concatenated, unchanged behavior)
- [x] Implement
- [x] Test

### Task 3: ReviewService + ReviewController (submit, approved-only read, aggregate)
- **spec_ref**: `openspec/specs/catalog-ratings/spec.md#requirement-the-submitting-users-identity-must-be-bound-server-side-and-must-not-be-accepted-from-client-input`
- **files**: `lib/Service/ReviewService.php`, `lib/Controller/ReviewController.php`, `appinfo/routes.php`, `tests/Unit/Service/ReviewServiceTest.php`
- **acceptance_criteria**:
  - GIVEN an authenticated user submits a review with a forged `auteur` WHEN it is persisted THEN the stored `auteur` is the session user's display name, not the forged value
  - GIVEN an unauthenticated request to `POST /api/reviews` WHEN processed THEN it is rejected and no object is created
  - GIVEN a module with one approved and one pending review WHEN the aggregate is requested THEN only the approved review counts
  - GIVEN a module with zero approved reviews WHEN the aggregate is requested THEN average is null and count is 0
- [x] Implement
- [x] Test

### Task 4: Generalise ModerationService/ModerationController to a second moderated type (beoordeeling)
- **spec_ref**: `openspec/specs/catalog-ratings/spec.md#requirement-review-moderation-must-reuse-the-existing-moderation-queue-mechanism-not-a-second-one`
- **files**: `lib/Service/ModerationService.php`, `lib/Controller/ModerationController.php`, `tests/Unit/Service/IntakeModerationTest.php`
- **acceptance_criteria**:
  - GIVEN `type=beoordeeling` WHEN `listPending()`/`approve()`/`reject()` are called THEN they operate on the `beoordeeling` register/schema using the `status` field and `approved`/`rejected` values
  - GIVEN no `type` parameter (existing callers) WHEN the same methods are called THEN behavior is byte-for-byte identical to the pre-existing `organisatie`/`registratiestatus` path (existing test assertions keep passing unmodified)
- [x] Implement
- [x] Test

### Task 5: Parameterise ModerationQueue.vue and add the review moderation section to Settings
- **spec_ref**: `openspec/specs/catalog-ratings/spec.md#requirement-review-moderation-must-reuse-the-existing-moderation-queue-mechanism-not-a-second-one`
- **files**: `src/views/settings/sections/ModerationQueue.vue`, `src/views/settings/SoftwareCatalogSettings.vue`, `tests/vitest/moderationItem.spec.js`
- **acceptance_criteria**:
  - GIVEN the settings page WHEN it renders THEN a second `ModerationQueue` instance (`type="beoordeeling"`) appears alongside the existing organisation-registration one, with its own title/description
- [x] Implement
- [x] Test

### Task 6: SubmitReviewModal.vue + ReviewsPanel.vue (submit flow + aggregate display) wired onto ModuleDetail
- **spec_ref**: `openspec/specs/catalog-ratings/spec.md#requirement-module-and-dienst-detail-pages-must-display-an-aggregate-rating-computed-only-from-approved-reviews`
- **files**: `src/modals/SubmitReviewModal.vue`, `src/components/reviews/ReviewsPanel.vue`, `src/customComponents.js`, `src/manifest.json`, `tests/vitest/reviewsPanel.spec.js`
- **acceptance_criteria**:
  - GIVEN a module detail page WHEN it loads THEN it shows the aggregate rating (average + count) and a "Write a review" action opening `SubmitReviewModal.vue`
  - GIVEN the rating input WHEN rendered THEN it is an `NcSelect` with `inputLabel` set (no bare `<label>`)
- [x] Implement
- [x] Test

### Task 7: Fix the dead Reviews index columns
- **spec_ref**: `openspec/specs/catalog-ratings/spec.md#requirement-the-reviews-index-must-display-columns-that-exist-on-the-beoordeeling-schema`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the `Reviews` index page config WHEN its `columns` are checked against the `beoordeeling` schema properties THEN every column exists (`naam`, `auteur`, `waardering`, `status`) and none of `titel`/`score`/`datum` remain
- [x] Implement
- [x] Test

### Task 8: i18n + documentation
- **spec_ref**: `openspec/specs/catalog-ratings/spec.md#non-functional-requirements`
- **files**: `l10n/nl.js`, `l10n/nl.json`, `l10n/en_US.js`, `l10n/en_US.json`, `docs/features/catalog-ratings.md`
- **acceptance_criteria**:
  - GIVEN the new UI strings WHEN `l10n/nl.js`/`l10n/en_US.js` are checked THEN every new user-facing string has a Dutch and English entry
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`), including the mandated negative security tests (unapproved review not publicly readable; client-supplied author ignored; non-author cannot edit another's review)
- New/changed Vue logic covered by vitest (`tests/vitest/`)
- `php -l` on every changed PHP file; `npm run check:manifest` (manifest touched)
- All tests pass: `docker run --rm -v "$PWD":/app -w /app nextcloud:34.0.0-apache php vendor/bin/phpunit -c phpunit-unit.xml` and `npx vitest run`
- Feature documentation added in `docs/features/catalog-ratings.md` with a screenshot (ADR-010)
- Dutch (`nl`) and English (`en_US`) translation strings added for all new user-facing strings (ADR-005)
- `openspec validate catalog-ratings --type change --strict` passes
