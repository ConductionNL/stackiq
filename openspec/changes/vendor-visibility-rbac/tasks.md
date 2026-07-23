# Tasks: vendor-visibility-rbac

## Implementation Tasks

### Task 1: Explicit auth guard on the offered-usage afnemer endpoint
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-004`
- **files**: `lib/Controller/AangebodenGebruikController.php`
- **acceptance_criteria**:
  - GIVEN no authenticated user session WHEN `GET /api/aangeboden-gebruik/afnemer` is called THEN the controller explicitly rejects the call (empty envelope or 401) before `AangebodenGebruikService::getGebruiksWhereAfnemer()` is invoked
  - GIVEN an authenticated user with no active organisation WHEN the same endpoint is called THEN the documented empty envelope is returned
- [ ] Implement
- [ ] Test

### Task 2: Scope `gebruik-beheerder` reads to the caller's own organisation
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-003`
- **files**: `lib/Controller/GebruikController.php`
- **acceptance_criteria**:
  - GIVEN a `gebruik-beheerder` user whose active organisation is A, and municipality B owns unrelated gebruik records WHEN the user calls `GET /api/gebruik` THEN no record owned by B is returned
  - GIVEN the same user WHEN A owns 12 gebruik records THEN all 12 are still returned unchanged
  - GIVEN an `ambtenaar` (with or without `gebruik-beheerder`) WHEN the same endpoint is called THEN the existing unrestricted read is preserved
- [ ] Implement
- [ ] Test

### Task 3: Lock in vendor (`aanbod-beheerder`) scoping with negative regression tests
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-002`
- **files**: `tests/Unit/Controller/GebruikControllerTest.php`, `tests/Unit/Service/AangebodenGebruikServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a vendor V offering module M WHEN V requests `GET /api/gebruik` THEN only V's own module's gebruik records are returned
  - GIVEN vendor V and unrelated municipality G WHEN V requests koppelingen/gebruik for a UUID identifying G via `GET /api/koppelingen-gebruik/{uuid}` THEN the empty envelope is returned and no data belonging to G is present
  - GIVEN a vendor offering zero applications WHEN it requests `GET /api/gebruik` THEN the empty envelope is returned without an unscoped OpenRegister search
- [ ] Implement
- [ ] Test

### Task 4: Lock in afnemer/deelnemer relationship reads with regression tests
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-005`
- **files**: `tests/Unit/Controller/AangebodenGebruikControllerTest.php`, `tests/Unit/Service/AangebodenGebruikServiceTest.php`
- **acceptance_criteria**:
  - GIVEN an organisation A that is afnemer on 3 offered gebruik records WHEN A calls `GET /api/aangeboden-gebruik/afnemer` THEN all 3 are returned unchanged from current behaviour
  - GIVEN organisation A appears as deelnemer in 2 gebruiksobjecten owned by other organisations WHEN A calls `GET /api/aangeboden-gebruik/deelnemers` THEN both are returned unchanged from current behaviour
- [ ] Implement
- [ ] Test

### Task 5: Verify (and if needed fix) the contract schema RBAC read rule
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-006`
- **files**: `lib/Settings/softwarecatalogus_register.json`, `tests/Integration/ContractRbacTest.php`
- **acceptance_criteria**:
  - GIVEN a contract owned by municipality A WHEN a vendor V that is not a counterparty attempts to read it via the OpenRegister object API THEN the read is denied
  - GIVEN the same contract WHEN A, its counterparty, `admin`, or `ambtenaar` reads it THEN the read succeeds
  - IF the deployed schema RBAC rule does not already deny the first case THEN the rule in `softwarecatalogus_register.json` is corrected as part of this task
- [ ] Implement
- [ ] Test

### Task 6: Leak-path audit of gebruik/koppeling/contract routes
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-007`
- **files**: `docs/security/vendor-visibility-rbac.md`, `appinfo/routes.php` (audit only — no route shape changes expected)
- **acceptance_criteria**:
  - GIVEN `appinfo/routes.php` WHEN every route whose controller method reads a gebruik, koppeling, or contract object is enumerated THEN each appears in an audit table with its authorization posture and the test(s) that cover it
  - GIVEN the audit table WHEN cross-checked against Tasks 1-5 THEN every route's posture matches an implemented guard or an explicit, justified exception (e.g. OR schema RBAC)
- [ ] Implement
- [ ] Test

### Task 7: Deny-before-grant ordering guard on every RBAC-bypassing read path
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-001`
- **files**: `lib/Controller/AangebodenGebruikController.php`, `lib/Controller/GebruikController.php`, `lib/Service/AangebodenGebruikService.php`
- **acceptance_criteria**:
  - GIVEN a caller who fails the visibility-matrix resolution for a target object WHEN the request is processed THEN the deny branch returns before any `_rbac: false` query is built
  - GIVEN role/relationship resolution throws (e.g. `OrganisationService` unavailable) WHEN a gebruik/koppeling/contract read is requested THEN the response is the empty envelope or a 5xx, never another organisation's data
- [ ] Implement
- [ ] Test

### Task 8: i18n strings for new/changed authorization responses
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#non-functional-requirements`
- **files**: `l10n/nl.json`, `l10n/en.json` (or the app's existing translation source files)
- **acceptance_criteria**:
  - GIVEN the explicit auth-guard rejection and any new denied-access user-facing text introduced by Tasks 1-2 WHEN the UI renders them THEN both Dutch (`nl_NL`) and English (`en_US`) strings are present
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`), run via `docker exec -w /var/www/html/custom_apps/softwarecatalog nextcloud php vendor/bin/phpunit -c phpunit-unit.xml` — minimum 75% coverage on the changed files (ADR-009)
- Every negative (denied-access) scenario in the spec has a corresponding PHPUnit test — required by the hydra `security-change-has-tests` gate for this security change
- New/changed API endpoint behaviour covered by Newman/Postman collection updates (`getGebruiksWhereAfnemer`, `GET /api/gebruik`) reflecting the tightened responses
- No UI changes are introduced by this capability (server-side enforcement only), so no Playwright browser test task or feature screenshot is required (ADR-010 N/A — justification: enforcement-only backend change, no new/changed UI surface); the routes/security posture is documented instead in `docs/security/vendor-visibility-rbac.md` per Task 6
- All tests pass (`composer test:all` via container, per project convention; not the theatre `composer test:all` alias — invoke phpunit directly as shown above)
- `openspec validate --change vendor-visibility-rbac` passes
