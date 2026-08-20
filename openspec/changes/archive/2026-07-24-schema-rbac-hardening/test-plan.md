# Test Plan: schema-rbac-hardening

## Test Cases

### TC-1: gebruik-beheerder denied another organisation's koppeling via the generic object API
- **spec_ref**: `openspec/changes/schema-rbac-hardening/specs/vendor-visibility-rbac/spec.md#req-008`
- **type**: security
- **persona**: N/A — schema-config assertion, no live OR RBAC engine in the unit sandbox
- **preconditions**: `koppeling` schema's `authorization.read` rule as shipped in `lib/Settings/softwarecatalogus_register.json`
- **steps**: Load the deployed rule and assert its shape (no bare `gebruik-beheerder` string; a match-scoped `_organisation` entry exists)
- **expected result**: no bare `gebruik-beheerder` grant remains; the scoped entry matches `_organisation: $organisation`
- **test command**: PHPUnit (`tests/Unit/Settings/SchemaRbacTest.php`)

### TC-2: gebruik-beheerder denied another organisation's organisatie record via the generic object API
- **spec_ref**: `openspec/changes/schema-rbac-hardening/specs/vendor-visibility-rbac/spec.md#req-008`
- **type**: security
- **preconditions**: `organisatie` schema's `authorization.read` rule as shipped
- **steps**: Load the deployed rule and assert its shape
- **expected result**: no bare `gebruik-beheerder` grant remains; the scoped entry matches `_organisation: $organisation`; the pre-existing `public` match rules for active organisaties are untouched
- **test command**: PHPUnit (`tests/Unit/Settings/SchemaRbacTest.php`)

### TC-3: gebruik-beheerder denied another organisation's gebruik record via the generic object API, retains own-org and afnemer access
- **spec_ref**: `openspec/changes/schema-rbac-hardening/specs/vendor-visibility-rbac/spec.md#req-008`
- **type**: security
- **preconditions**: `gebruik` schema's `authorization.read` rule as shipped
- **steps**: Load the deployed rule and assert its shape
- **expected result**: no bare `gebruik-beheerder` grant remains; two scoped entries exist (`_organisation` and `afnemer`, both `$organisation`); existing `aanbod-beheerder` entries untouched
- **test command**: PHPUnit (`tests/Unit/Settings/SchemaRbacTest.php`)

### TC-4: Remaining bare roles on the contract schema are match-scoped; ambtenaar and software-catalog-admins stay unrestricted
- **spec_ref**: `openspec/changes/schema-rbac-hardening/specs/vendor-visibility-rbac/spec.md#req-006`
- **type**: security
- **preconditions**: `contract` schema's `authorization.read` rule as shipped
- **steps**: Load the deployed rule and assert its shape
- **expected result**: `functioneel-beheerder`, `gebruik-beheerder`, `vng-raadpleger`, `software-catalog-users`, `organisatie-beheerder`, `organisaties-beheerder`, `gebruik-raadpleger` are all match-scoped to `_organisation`; `ambtenaar` and `software-catalog-admins` remain bare; `aanbod-beheerder`'s existing scoped entry (REQ-006) is untouched; no `public` entry exists
- **test command**: PHPUnit (extends `tests/Unit/Settings/ContractRbacTest.php`)

### TC-5: Unauthenticated caller is rejected before AanbodService is invoked
- **spec_ref**: `openspec/changes/schema-rbac-hardening/specs/vendor-visibility-rbac/spec.md#req-009`
- **type**: security
- **preconditions**: No authenticated NC user session
- **steps**: Call `AanbodController::getAanbod()`
- **expected result**: 401 with the empty-result envelope; `AanbodService::getAanbod()` is asserted `never()` called
- **test command**: PHPUnit (`tests/Unit/Controller/AanbodControllerTest.php`)

### TC-6: Afnemer relationship read regression
- **spec_ref**: `openspec/changes/schema-rbac-hardening/specs/vendor-visibility-rbac/spec.md#req-008`
- **type**: regression
- **preconditions**: organisation A is afnemer on 3 offered gebruik records
- **steps**: A calls `GET /api/aangeboden-gebruik/afnemer`
- **expected result**: all 3 records still returned, unchanged from before this change
- **test command**: PHPUnit (`tests/Unit/Controller/AangebodenGebruikControllerTest.php`)

### TC-7: Deelnemer relationship read regression — the documented residual's app-level bypass still works
- **spec_ref**: `openspec/changes/schema-rbac-hardening/specs/vendor-visibility-rbac/spec.md#req-008`
- **type**: regression
- **preconditions**: organisation A appears as deelnemer in 2 gebruiksobjecten owned by other organisations
- **steps**: A calls `GET /api/aangeboden-gebruik/deelnemers`
- **expected result**: both records still returned, unchanged from before this change — confirms the schema-RBAC edits did not affect the RBAC-disabled, session-scoped `deelnemers` app-level query path that stands in for the undeliverable array-contains schema match
- **test command**: PHPUnit (`tests/Unit/Controller/AangebodenGebruikControllerTest.php`, `tests/Unit/Service/AangebodenGebruikServiceTest.php`)

## Coverage Summary
- REQ-006 (MODIFIED, contract schema RBAC) — covered by TC-4
- REQ-008 (ADDED, gebruik/koppeling/organisatie schema RBAC) — covered by TC-1, TC-2, TC-3, TC-6, TC-7
- REQ-009 (ADDED, AanbodController explicit guard) — covered by TC-5
- REQ-001–REQ-005, REQ-007 (unchanged by this change) — not retested here beyond the TC-6/TC-7 regression checks; already covered by `vendor-visibility-rbac`'s existing test suite, which this change does not modify

## Out of Scope
- Live end-to-end verification against a running OpenRegister RBAC engine and an actually-imported schema config — not available in this sandboxed PHPUnit environment (same constraint `ContractRbacTest.php` already documents). These tests assert the shipped config's *shape*; live verification against a real repair-step import is required once `register-import-reliability` (#391) lands, per the proposal's Risk 1.
- A `$contains`/array-membership schema-RBAC test for `gebruik.deelnemers` — deliberately out of scope, see design.md Decision 6.
