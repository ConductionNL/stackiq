# vendor-visibility-rbac Specification

## Purpose
TBD - created by archiving change vendor-visibility-rbac. Update Purpose after archive.
## Requirements
### Requirement: Every RBAC-bypassing gebruik/koppeling/contract read MUST evaluate its deny check before issuing the bypass query (REQ-001)

Any code path that queries OpenRegister with `_rbac: false` and/or `_multitenancy: false` for a `gebruik`, `koppeling`, or `contract` object MUST first resolve the caller's role and the caller's organisation's relationship to the target object(s), and MUST return the deny result (the standard empty-result envelope) without issuing the bypass query when that resolution fails. A custom-scope veto evaluated after a default-open grant path has already executed MUST NOT exist anywhere in this surface.

#### Scenario: Deny check short-circuits before the bypass query is built
- GIVEN a caller whose role/relationship resolution fails the visibility matrix for a given gebruik object
- WHEN the controller processes the request
- THEN the deny branch MUST return before `ObjectService::searchObjectsPaginated` (or `searchObjects`/`find`) is invoked with `_rbac: false`
- AND no cross-organisation query MUST be issued to OpenRegister as a result of this request

#### Scenario: Exception during resolution fails closed, not open
- GIVEN role/relationship resolution throws an exception (e.g. OpenRegister's `OrganisationService` is unavailable)
- WHEN a gebruik, koppeling, or contract read is requested
- THEN the response MUST be the standard empty-result envelope or a 5xx error
- AND the response MUST NOT contain any object data from an organisation other than the caller's own

### Requirement: `aanbod-beheerder` (vendor) reads of gebruik/koppeling objects MUST be scoped to the vendor's own offered products (REQ-002)

An authenticated user whose only relevant group membership is `aanbod-beheerder`, and who is not `admin` or `ambtenaar`, MUST see only gebruik and koppeling objects where their active organisation is the `aanbieder` (offering party) — never another organisation's applicatielandschap as a whole. This requirement locks in and regression-tests the existing `GebruikController::applyAanbodScopeToOptions()` behaviour and the existing `AangebodenGebruikService::getKoppelingenGebruikByUuid()` ownership check.

#### Scenario: Vendor sees only their own product's usage
- GIVEN a user in the `aanbod-beheerder` group whose active organisation is vendor V, and V is the `aanbieder` on module M
- WHEN the user requests `GET /api/gebruik`
- THEN the response MUST contain only gebruik records whose `module` is one of V's own applications
- AND the response MUST NOT contain gebruik records for any application V does not offer

#### Scenario: Vendor is denied a cross-organisation applicatielandschap read
- GIVEN a user in the `aanbod-beheerder` group whose active organisation is vendor V
- WHEN the user requests koppelingen/gebruik for a UUID that identifies a different organisation (e.g. municipality G, which V neither owns nor offers to) via `GET /api/koppelingen-gebruik/{uuid}`
- THEN the response MUST be the empty-result envelope
- AND no gebruik or koppeling object belonging to organisation G MUST be present in the response

#### Scenario: Vendor with no offered applications gets an empty, not unscoped, result
- GIVEN a user in the `aanbod-beheerder` group whose active organisation offers zero applications
- WHEN the user requests `GET /api/gebruik`
- THEN the response MUST be the empty-result envelope
- AND the underlying OpenRegister search MUST NOT be executed without a module filter

### Requirement: `gebruik-beheerder` reads of gebruik objects MUST be scoped to the caller's own organisation (REQ-003)

An authenticated user whose group membership includes `gebruik-beheerder` but not `admin` or `ambtenaar` MUST see only gebruik objects owned by, or explicitly shared with (afnemer/deelnemer), their own active organisation — never another organisation's gebruik data. This closes the cross-municipality leak identified in this change's `discovery.md` (finding 2): today `GebruikController::applyAanbodScopeToOptions()` applies no organisation filter for `gebruik-beheerder`, and `GebruikService::getGebruiken()` is unconditionally RBAC-disabled, so any `gebruik-beheerder` currently receives every organisation's gebruik data.

#### Scenario: Municipality user is denied another municipality's landscape
- GIVEN a user in the `gebruik-beheerder` group whose active organisation is municipality A
- AND municipality B owns gebruik records unrelated to A (A is neither afnemer nor deelnemer)
- WHEN the user requests `GET /api/gebruik`
- THEN the response MUST NOT contain any gebruik record owned by municipality B
- AND the response MUST contain only gebruik records owned by, offered to, or shared with municipality A

#### Scenario: Municipality user still sees their own organisation's full gebruik set
- GIVEN a user in the `gebruik-beheerder` group whose active organisation is municipality A, which owns 12 gebruik records
- WHEN the user requests `GET /api/gebruik`
- THEN the response MUST contain all 12 of municipality A's own gebruik records
- AND pagination/filtering behaviour for those 12 records MUST be unchanged from before this requirement

#### Scenario: ambtenaar retains the existing unrestricted read
- GIVEN a user in the `ambtenaar` group (with or without `gebruik-beheerder`)
- WHEN the user requests `GET /api/gebruik`
- THEN the response MUST NOT be organisation-restricted
- AND this MUST remain consistent with the existing `ambtenaar` bypass already implemented in `getAllGebruiksForAmbtenaar`/`getSingleGebruikForAmbtenaar`/`getKoppelingenGebruikByUuid`

### Requirement: The offered-usage "afnemer" endpoint MUST require authentication explicitly, not implicitly (REQ-004)

`AangebodenGebruikController::getGebruiksWhereAfnemer()` MUST explicitly reject an unauthenticated caller before invoking `AangebodenGebruikService::getGebruiksWhereAfnemer()`, rather than relying on the service's internal `getCurrentOrganisation()` returning `null` for anonymous sessions as the only safeguard.

#### Scenario: Unauthenticated caller is explicitly rejected
- GIVEN no authenticated user session
- WHEN `GET /api/aangeboden-gebruik/afnemer` is called
- THEN the controller MUST return the empty-result envelope (or 401) without depending on `AangebodenGebruikService::getCurrentOrganisation()` resolving to `null` as the sole guard
- AND a test MUST assert this behaviour independent of `OrganisationService::getActiveOrganisation()`'s internal implementation

#### Scenario: Authenticated caller with no active organisation gets the documented empty envelope
- GIVEN an authenticated user with no active organisation set
- WHEN `GET /api/aangeboden-gebruik/afnemer` is called
- THEN the response MUST be the "no current organization available" empty envelope
- AND no cross-organisation data MUST be returned

### Requirement: Deelname and afnemer relationship reads remain unaffected (REQ-005)

This change MUST NOT restrict the existing, correct relationship-based reads: an organisation MUST continue to see gebruik/koppeling objects where it is the `afnemer` (`getGebruiksWhereAfnemer`) or a `deelnemer` (`getGebruiksWhereDeelnemers`, per `deelnames-gebruik`), scoped to its own active organisation UUID exactly as today.

#### Scenario: Own organisation's afnemer view is preserved
- GIVEN an authenticated user whose active organisation A is the afnemer on 3 offered gebruik records
- WHEN the user requests `GET /api/aangeboden-gebruik/afnemer`
- THEN all 3 records MUST be returned
- AND this behaviour MUST be unchanged from before this capability was added

#### Scenario: Own organisation's deelnemer view is preserved
- GIVEN an authenticated user whose active organisation A appears in the `deelnemers` array of 2 gebruiksobjecten owned by other organisations
- WHEN the user requests `GET /api/aangeboden-gebruik/deelnemers`
- THEN both records MUST be returned
- AND this MUST remain consistent with `deelnames-gebruik`'s existing RBAC-disabled, `deelnemers`-filtered query behaviour

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

### Requirement: Every route touching gebruik, koppeling, or contract objects MUST have a documented, tested authorization posture (REQ-007)

Every entry in `appinfo/routes.php` whose controller method reads a `gebruik`, `koppeling`, or `contract` OpenRegister object MUST be enumerated with its current authorization guard (auth annotation, role check, relationship check, or "denied by OR schema RBAC") and MUST be covered by at least one automated test exercising both an allowed and a denied case, so future additions to this route surface cannot silently reintroduce a leak.

#### Scenario: Audit table covers every gebruik/koppeling/contract route
- GIVEN `appinfo/routes.php`
- WHEN the routes touching the `gebruik`, `koppeling`, and `contract` schemas are enumerated (by controller/method cross-reference)
- THEN each route MUST appear in the audit with its documented authorization posture
- AND no such route MUST be left undocumented

#### Scenario: Undocumented or unguarded route fails review
- GIVEN a route added to `appinfo/routes.php` after this capability lands that reads a gebruik, koppeling, or contract object
- WHEN it lacks both a documented authorization posture and a covering test
- THEN it MUST be treated as a spec violation of this requirement and blocked at review

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

