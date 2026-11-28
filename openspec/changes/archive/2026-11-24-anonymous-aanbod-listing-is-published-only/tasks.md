# Tasks: anonymous-aanbod-listing-is-published-only

## 1. Code

- [x] 1.1 In `lib/Controller/AanbodController.php` `getAanbod()`, replace the `rejectUnauthenticated` branch for an anonymous session with a read of published aanbod entries only (`publicatiedatum` at or before now, not depublished), serialized through the open-data projection. The service must not resolve an organisation for that read. Keep the explicit branch before `AanbodService::getAanbod()`.
- [x] 1.2 PHPUnit in `tests/Unit/Controller/AanbodControllerTest.php`: an anonymous call returns the published entry and not the unpublished one, and no organisation-scoped field. An authenticated caller with no active organisation still gets the empty envelope.
- [x] 1.3 Newman: the anonymous `GET /api/aanbod` case in the open-data collection asserts published-only.
  - Written (17 Nov) in `tests/integration/stackiq.postman_collection.json` ("get aanbod NO AUTH"): every entry published now, no `@self`, no internal key. Ticked 24 Nov as written, not verified: Newman is disabled in this app's CI (`enable-newman: false` in code-quality.yml, left to the app owner), so the case runs once Newman is re-enabled there.

## 2. Close out

- [x] 2.1 Archive this change so `vendor-visibility-rbac` follows.
