# vendor-visibility-rbac Specification

## ADDED Requirements

### Requirement: `gebruik`, `koppeling`, and `organisatie` schema-level RBAC reads MUST deny cross-organisation access for `gebruik-beheerder` (REQ-008)

The `gebruik`, `koppeling`, and `organisatie` schemas' OpenRegister `authorization.read` rules MUST NOT grant `gebruik-beheerder` an unscoped (bare) read. Any `gebruik-beheerder` read of these schemas issued through OpenRegister's standard, RBAC-enabled object API (i.e. any read that does not bypass RBAC with `_rbac: false`) MUST be denied unless the caller's active organisation matches the object's `_organisation` field, or — for `gebruik` specifically — the object's `afnemer` field. This closes the gap identified in this change's `context-brief.md`: `koppeling` and `organisatie` have no app-local controller, so a read of either schema through the generic OpenRegister object API was gated solely by this schema config, and the bare `gebruik-beheerder` grant let any `gebruik-beheerder` read every other organisation's koppelingen and organisaties. This requirement is a schema-level, defense-in-depth complement to REQ-003's existing app-level `gebruik` scoping — it does not replace or alter REQ-003, REQ-005, or the `deelnames-gebruik` capability. `gebruik.deelnemers` (array-membership sharing) is explicitly and deliberately NOT expressible as a schema-RBAC match condition today (OpenRegister's `OperatorEvaluator` has no array-contains operator) and remains an accepted residual enforced only by the app-level `deelnames-gebruik` path per REQ-005 — this MUST NOT be silently treated as covered by this requirement.

#### Scenario: gebruik-beheerder is denied another organisation's koppeling via the generic object API
- GIVEN a koppeling object owned by (`_organisation`) organisation B
- AND a user in the `gebruik-beheerder` group whose active organisation is A, not B
- WHEN the user reads the koppeling via OpenRegister's standard object API, not the app's `_rbac:false` bypass
- THEN the read MUST be denied, empty result or 404, governed by the schema RBAC rule
- AND no field of B's koppeling object MUST be returned

#### Scenario: gebruik-beheerder is denied another organisation's non-public organisatie record via the generic object API
- GIVEN an organisatie object owned by (`_organisation`) organisation B that does not satisfy any of the schema's public match rules, for example its status is not Actief
- AND a user in the `gebruik-beheerder` group whose active organisation is A, not B
- WHEN the user reads the organisatie object via OpenRegister's standard object API
- THEN the read MUST be denied
- AND no field of B's organisatie object MUST be returned

#### Scenario: gebruik-beheerder is denied another organisation's gebruik record via the generic object API
- GIVEN a gebruik object owned by (`_organisation`) organisation B with afnemer also organisation B
- AND a user in the `gebruik-beheerder` group whose active organisation is A, not B
- WHEN the user reads the gebruik object via OpenRegister's standard object API
- THEN the read MUST be denied
- AND no field of B's gebruik object MUST be returned

#### Scenario: gebruik-beheerder retains read access to their own organisation's koppeling, organisatie, and gebruik records
- GIVEN a koppeling, an organisatie, and a gebruik object each owned by (`_organisation`) organisation A
- WHEN a user in the `gebruik-beheerder` group whose active organisation is A reads each object via OpenRegister's standard object API
- THEN all three reads MUST succeed

#### Scenario: gebruik-beheerder retains read access to a gebruik record where their organisation is the afnemer but not the owner
- GIVEN a gebruik object owned by (`_organisation`) organisation B with afnemer set to organisation A
- WHEN a user in the `gebruik-beheerder` group whose active organisation is A reads the gebruik object via OpenRegister's standard object API
- THEN the read MUST succeed

### Requirement: The aanbod listing endpoint MUST require authentication explicitly, not implicitly (REQ-009)

`AanbodController::getAanbod()` MUST explicitly reject an unauthenticated caller before invoking `AanbodService::getAanbod()`, rather than relying on the service's internal `getCurrentOrganisation()` returning `null` for anonymous sessions as the only safeguard.

#### Scenario: Unauthenticated caller is explicitly rejected
- GIVEN no authenticated user session
- WHEN `GET /api/aanbod` is called
- THEN the controller MUST return the empty-result envelope, or 401, without depending on `AanbodService::getCurrentOrganisation()` resolving to null as the sole guard
- AND a test MUST assert `AanbodService::getAanbod()` is never invoked for this request

#### Scenario: Authenticated caller with no active organisation gets the documented empty envelope
- GIVEN an authenticated user with no active organisation set
- WHEN `GET /api/aanbod` is called
- THEN the response MUST be the empty-result envelope
- AND no cross-organisation data MUST be returned

## MODIFIED Requirements

### Requirement: Contract reads MUST deny non-counterparty cross-organisation access via the OpenRegister schema RBAC rule (REQ-006)

Because contract CRUD runs entirely through the OpenRegister object store (ADR-022, `contract-administration`), contract read visibility MUST be governed by the `contract` schema's RBAC read rule denying any caller whose active organisation is neither the contract's owning organisation, the `admin` group, the app's designated super-user group `software-catalog-admins`, nor `ambtenaar`. Every role granted a contract read beyond those three exceptions MUST be match-scoped to the caller's own organisation via the schema's `_organisation` field — an unscoped, bare grant for any other role is a spec violation. If verification finds the deployed rule does not deny this case, the schema's RBAC read rule in `lib/Settings/softwarecatalogus_register.json` MUST be corrected as part of this change.

#### Scenario: Vendor cannot read another organisation's contract
- GIVEN a contract owned by municipality A, and a user in the `aanbod-beheerder` group whose active organisation is vendor V (not a counterparty on this contract)
- WHEN the user attempts to read the contract via the OpenRegister object API
- THEN the read MUST be denied (empty/404, governed by the schema RBAC rule)
- AND V MUST NOT receive any field of the contract object

#### Scenario: Counterparty and owner retain contract read access
- GIVEN a contract owned by municipality A referencing vendor V as counterparty
- WHEN A or V (whichever the schema's counterparty rule recognises) reads the contract
- THEN the read MUST succeed
- AND `admin` and `ambtenaar` MUST also retain read access regardless of counterparty status

#### Scenario: Municipality-side role cannot read another organisation's contract
- GIVEN a contract owned by organisation A, and a user in the `gebruik-beheerder` group, not `admin`, not `software-catalog-admins`, not `ambtenaar`, whose active organisation is B, not A
- WHEN the user attempts to read the contract via the OpenRegister object API
- THEN the read MUST be denied, empty result or 404, governed by the schema RBAC rule
- AND B MUST NOT receive any field of the contract object

#### Scenario: software-catalog-admins retains unrestricted contract read access
- GIVEN a contract owned by any organisation
- WHEN a user in the `software-catalog-admins` group reads the contract via the OpenRegister object API
- THEN the read MUST succeed regardless of the user's active organisation
