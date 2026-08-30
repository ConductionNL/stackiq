# Test Plan: vendor-visibility-rbac

## Test Cases

### TC-1: Deny check short-circuits before the bypass query is built
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-001`
- **type**: security
- **persona**: n/a (backend enforcement)
- **preconditions**: A caller whose role/relationship resolution fails the visibility matrix for a given gebruik object
- **steps**: Call the endpoint; assert (via mock/spy on `ObjectService`) that `searchObjectsPaginated`/`searchObjects`/`find` with `_rbac: false` is never invoked
- **expected result**: The deny branch returns before any bypass query is issued; no cross-org data present
- **test command**: PHPUnit unit test (mock ObjectService call count = 0 on the deny path)

### TC-2: Resolution exception fails closed
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-001`
- **type**: security
- **preconditions**: `OrganisationService` throws when resolving the active organisation
- **steps**: Request a gebruik/koppeling/contract read
- **expected result**: Empty envelope or 5xx; never another organisation's object data
- **test command**: PHPUnit unit test (mock throws, assert response shape + no leaked data)

### TC-3: Vendor sees only their own product's usage
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-002`
- **type**: api
- **persona**: Mark Visser (MKB Software Vendor)
- **preconditions**: Vendor V is `aanbieder` on module M; vendor V has active organisation set
- **steps**: `GET /api/gebruik` as V
- **expected result**: Response contains only gebruik records whose `module` belongs to V
- **test command**: /test-api (Newman collection) + PHPUnit

### TC-4: Vendor denied cross-organisation applicatielandschap read
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-002`
- **type**: security
- **persona**: Mark Visser (MKB Software Vendor)
- **preconditions**: Vendor V does not own or offer to municipality G
- **steps**: `GET /api/koppelingen-gebruik/{G's uuid}` as V
- **expected result**: Empty envelope; zero objects belonging to G present in the response body
- **test command**: /test-security + PHPUnit negative test

### TC-5: Vendor with zero offered applications gets empty, not unscoped, result
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-002`
- **type**: security
- **preconditions**: Vendor V's `getApplicationIds` returns `[]`
- **steps**: `GET /api/gebruik` as V
- **expected result**: Empty envelope; underlying OpenRegister search not executed without a module filter
- **test command**: PHPUnit unit test (assert `getGebruiken` never called with an unfiltered `module`)

### TC-6: Municipality user denied another municipality's landscape
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-003`
- **type**: security
- **persona**: Noor Yilmaz (Municipal CISO / Functional Admin)
- **preconditions**: `gebruik-beheerder` user, active org = municipality A; municipality B owns unrelated gebruik records
- **steps**: `GET /api/gebruik` as the A user
- **expected result**: No gebruik record owned by B present in the response
- **test command**: /test-security + PHPUnit negative test (this is the primary regression test for discovery.md finding 2)

### TC-7: Municipality user still sees own organisation's full gebruik set
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-003`
- **type**: regression
- **persona**: Noor Yilmaz (Municipal CISO / Functional Admin)
- **preconditions**: `gebruik-beheerder` user, active org = municipality A, which owns 12 gebruik records
- **steps**: `GET /api/gebruik` as the A user
- **expected result**: All 12 records returned; pagination/filtering unchanged
- **test command**: /test-api + PHPUnit

### TC-8: `ambtenaar` retains unrestricted read
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-003`
- **type**: regression
- **preconditions**: User in `ambtenaar` group
- **steps**: `GET /api/gebruik` as the ambtenaar user
- **expected result**: Response is not organisation-restricted, consistent with existing ambtenaar bypass paths
- **test command**: PHPUnit regression test

### TC-9: Unauthenticated caller explicitly rejected on afnemer endpoint
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-004`
- **type**: security
- **preconditions**: No authenticated session
- **steps**: `GET /api/aangeboden-gebruik/afnemer` with no session cookie
- **expected result**: Explicit rejection (empty envelope/401) at the controller, independent of `getCurrentOrganisation()`'s internal null-handling
- **test command**: /test-security + PHPUnit (asserts controller-level guard, not just service behaviour)

### TC-10: Authenticated caller with no active organisation gets documented empty envelope
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-004`
- **type**: api
- **preconditions**: Authenticated user, no active organisation set
- **steps**: `GET /api/aangeboden-gebruik/afnemer`
- **expected result**: "No current organization available" empty envelope; no cross-org data
- **test command**: PHPUnit

### TC-11: Own organisation's afnemer view preserved
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-005`
- **type**: regression
- **preconditions**: Active org A is afnemer on 3 offered gebruik records
- **steps**: `GET /api/aangeboden-gebruik/afnemer` as A
- **expected result**: All 3 records returned, unchanged from pre-change behaviour
- **test command**: /test-regression + PHPUnit

### TC-12: Own organisation's deelnemer view preserved
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-005`
- **type**: regression
- **preconditions**: Active org A appears in `deelnemers` of 2 gebruiksobjecten owned by other organisations
- **steps**: `GET /api/aangeboden-gebruik/deelnemers` as A
- **expected result**: Both records returned, consistent with `deelnames-gebruik`
- **test command**: /test-regression + PHPUnit

### TC-13: Vendor cannot read another organisation's contract
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-006`
- **type**: security
- **persona**: Mark Visser (MKB Software Vendor)
- **preconditions**: Contract owned by municipality A; vendor V is not a counterparty
- **steps**: V reads the contract via the OpenRegister object API
- **expected result**: Read denied (empty/404) per the schema RBAC rule
- **test command**: /test-security (integration test against the deployed schema RBAC config)

### TC-14: Counterparty and owner retain contract read access
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-006`
- **type**: regression
- **preconditions**: Contract owned by municipality A, counterparty vendor V
- **steps**: A, V, `admin`, and `ambtenaar` each read the contract
- **expected result**: All four reads succeed
- **test command**: PHPUnit/integration regression test

### TC-15: Audit table covers every gebruik/koppeling/contract route
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-007`
- **type**: regression
- **preconditions**: `appinfo/routes.php` as of this change
- **steps**: Cross-reference every route touching `gebruik`/`koppeling`/`contract` schemas against `docs/security/vendor-visibility-rbac.md`
- **expected result**: Every such route appears with a documented, tested authorization posture; none undocumented
- **test command**: Manual review during PR + code-review checklist item

### TC-16: Undocumented/unguarded future route flagged
- **spec_ref**: `openspec/changes/vendor-visibility-rbac/specs/vendor-visibility-rbac/spec.md#req-007`
- **type**: regression
- **preconditions**: A hypothetical new route reading gebruik/koppeling/contract objects, added without a documented posture or test
- **steps**: Code review against this requirement
- **expected result**: Flagged as a spec violation and blocked at review
- **test command**: Code review checklist (this requirement's acceptance criterion is enforced procedurally, not by an automated test)

## Coverage Summary
- REQ-001 (deny-before-grant ordering): covered — TC-1, TC-2
- REQ-002 (vendor scoping): covered — TC-3, TC-4, TC-5
- REQ-003 (gebruik-beheerder scoping): covered — TC-6, TC-7, TC-8
- REQ-004 (explicit auth guard): covered — TC-9, TC-10
- REQ-005 (afnemer/deelnemer preserved): covered — TC-11, TC-12
- REQ-006 (contract RBAC): covered — TC-13, TC-14
- REQ-007 (route audit): covered — TC-15, TC-16

## Out of Scope
- UI-level Playwright/browser testing — this change has no UI surface (server-side enforcement only); see `tasks.md` Quality checklist for the ADR-010 N/A justification.
- Load/performance testing beyond the existing query-shape complexity — REQ-003's fix reuses the already-proven `getApplicationIds`-style pre-fetch pattern, so no new performance test is added; flagged in `design.md` Non-Functional Requirements instead.
- Testing the `open-data-publishing` anonymous surface itself — explicitly out of scope per `proposal.md`; only confirmed as unaffected via the existing anonymous-empty-envelope behaviour on `getGebruiken()`.
