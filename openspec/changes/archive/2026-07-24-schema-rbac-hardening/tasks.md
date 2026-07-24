# Tasks: schema-rbac-hardening

## Implementation Tasks

### Task 1: Scope `gebruik-beheerder` schema RBAC reads on gebruik, koppeling, organisatie
- **spec_ref**: `openspec/changes/schema-rbac-hardening/specs/vendor-visibility-rbac/spec.md#req-008`
- **files**: `lib/Settings/softwarecatalogus_register.json`
- **acceptance_criteria**:
  - GIVEN the `gebruik`, `koppeling`, and `organisatie` schemas' `authorization.read` rules WHEN inspected THEN no bare unscoped `gebruik-beheerder` string remains in any of the three
  - GIVEN a `gebruik-beheerder` in organisation A WHEN reading a `koppeling` or `organisatie` object owned by organisation B via the OpenRegister object API THEN the read is denied
  - GIVEN a `gebruik-beheerder` in organisation A WHEN reading their own organisation's `gebruik`, `koppeling`, or `organisatie` objects (owner or, for gebruik, afnemer) THEN the read succeeds
- [x] Implement
- [x] Test

### Task 2: Scope the remaining bare roles on the contract schema RBAC read rule
- **spec_ref**: `openspec/changes/schema-rbac-hardening/specs/vendor-visibility-rbac/spec.md#req-006`
- **files**: `lib/Settings/softwarecatalogus_register.json`
- **acceptance_criteria**:
  - GIVEN the `contract` schema's `authorization.read` rule WHEN inspected THEN every role except `ambtenaar` and `software-catalog-admins` is match-scoped to `_organisation`
  - GIVEN a `gebruik-beheerder` (or any other newly-scoped role) in organisation B WHEN reading a contract owned by organisation A THEN the read is denied
  - GIVEN `ambtenaar` or `software-catalog-admins` WHEN reading any contract THEN the read succeeds regardless of active organisation
- [x] Implement
- [x] Test

### Task 3: Explicit auth guard on the aanbod listing endpoint
- **spec_ref**: `openspec/changes/schema-rbac-hardening/specs/vendor-visibility-rbac/spec.md#req-009`
- **files**: `lib/Controller/AanbodController.php`
- **acceptance_criteria**:
  - GIVEN no authenticated user session WHEN `GET /api/aanbod` is called THEN the controller explicitly rejects the call, empty envelope or 401, before `AanbodService::getAanbod()` is invoked
  - GIVEN an authenticated user with no active organisation WHEN the same endpoint is called THEN the documented empty envelope is returned
- [x] Implement
- [x] Test

### Task 4: Regression tests for the afnemer/deelnemer app-level paths and the documented deelnemer residual
- **spec_ref**: `openspec/changes/schema-rbac-hardening/specs/vendor-visibility-rbac/spec.md#req-008`
- **files**: `tests/Unit/Service/AangebodenGebruikServiceTest.php`
- **acceptance_criteria**:
  - GIVEN organisation A is afnemer on offered gebruik records WHEN A calls the afnemer endpoint THEN behaviour is unchanged from before this change (pre-existing coverage, unaffected)
  - GIVEN organisation A appears as deelnemer in gebruiksobjecten owned by other organisations WHEN A calls the deelnemers endpoint THEN behaviour is unchanged from before this change, confirming the schema-RBAC edits did not affect the RBAC-disabled deelnemer bypass path
- [x] Implement
- [x] Test

### Task 5: Extend the security docs with the schema-RBAC layer and the deelnemer residual
- **spec_ref**: `openspec/changes/schema-rbac-hardening/specs/vendor-visibility-rbac/spec.md#req-008`
- **files**: `docs/security/vendor-visibility-rbac.md`
- **acceptance_criteria**:
  - GIVEN the route-audit doc WHEN read after this change THEN it documents the schema-RBAC fix for gebruik/koppeling/organisatie/contract, the `AanbodController` guard, and the deelnemer-array residual with the reason it cannot be expressed at the schema-RBAC layer today
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria
- [x] Code review against spec requirements

## Tests (company-wide ADR-009)
- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`)
- [x] Newman/Postman tests for new/changed API endpoints — N/A, no new/changed public endpoint shape, `/api/aanbod` and the OpenRegister object API are unchanged wire contracts
- [x] Browser tests (Playwright MCP) for UI changes — N/A, no frontend/UI change, this is a backend RBAC-config and controller-guard fix
- [x] All tests pass (`composer test`, PHPUnit in the `nextcloud:34.0.0-apache` container) — 428 suite tests run, 1 pre-existing unrelated failure (`PortfolioReportControllerTest::testCsvFormatReturnsDownloadResponse`, environment-only Symfony class gap, untouched by this change)

## Documentation (company-wide ADR-010)
- [x] Feature documentation updated in `docs/` (`docs/security/vendor-visibility-rbac.md`)
- [x] Screenshot captured and committed to `docs/images/` — N/A, no UI surface to screenshot; this is a schema-config and backend-controller security fix

## i18n (company-wide ADR-005)
- [x] N/A — no new user-facing strings introduced by this change
