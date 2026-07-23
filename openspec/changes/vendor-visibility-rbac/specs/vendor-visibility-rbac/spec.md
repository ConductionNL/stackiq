# vendor-visibility-rbac Specification

**Status**: planned
**Scope**: softwarecatalog
**OpenSpec changes**:
- [vendor-visibility-rbac](../../changes/vendor-visibility-rbac/) _(active)_

## Purpose
Defines and server-side enforces a visibility matrix (role × object type × relationship) that hides an organisation's applicatielandschap (gebruik), koppelingen, and contracts from vendor-role (`aanbod-beheerder`) users and from other organisations, unless the data was explicitly shared (deelname) or published as open data (`open-data-publishing`). Every enforcement point evaluates the deny check before any RBAC-bypassing query runs (fail closed, per OpenRegister or#2025), closing both the vendor-specific leak named in VNG Softwarecatalogus issue #105 and the `gebruik-beheerder` cross-municipality leak found during this change's discovery audit. This capability layers on top of — and does not restate — the object-specific behaviour already owned by `aangeboden-gebruik-api`, `deelnames-gebruik`, `gebruik-services`, and `open-data-publishing`.

## ADDED Requirements

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

Because contract CRUD runs entirely through the OpenRegister object store (ADR-022, `contract-administration`), contract read visibility MUST be governed by the `contract` schema's RBAC read rule denying any caller whose active organisation is neither the contract's owning organisation, the `admin` group, nor `ambtenaar`. If verification finds the deployed rule does not deny this case, the schema's RBAC read rule in `lib/Settings/softwarecatalogus_register.json` MUST be corrected as part of this change.

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

## Non-Functional Requirements

- **Performance:** Adding the organisation-scoping filter to `gebruik-beheerder` reads MUST NOT change the query shape's complexity class — it reuses the same `getApplicationIds`-style pre-fetch pattern already used for `aanbod-beheerder`, not an additional per-record check.
- **Accessibility:** No UI surface is introduced or changed by this capability (server-side enforcement only).
- **Internationalization:** Any new user-facing error/empty-state text (e.g. the explicit auth-guard rejection message) MUST be provided in Dutch and English (ADR-005).

## Acceptance Criteria

- [ ] A vendor (`aanbod-beheerder`) user cannot enumerate another organisation's applicatielandschap, koppelingen, or contracts through any gebruik/koppeling/contract read endpoint
- [ ] A `gebruik-beheerder` user cannot read another organisation's gebruik data unless that organisation shared it via afnemer/deelname
- [ ] `getGebruiksWhereAfnemer` explicitly rejects unauthenticated callers rather than relying on implicit downstream null-handling
- [ ] The `contract` schema RBAC read rule is verified (and corrected if necessary) to deny non-counterparty cross-organisation reads
- [ ] Every gebruik/koppeling/contract route in `appinfo/routes.php` is enumerated with a documented, tested authorization posture
- [ ] Every requirement above has at least one negative (denied-access) PHPUnit test, per ADR-009 and the hydra `security-change-has-tests` gate

## Notes

- This capability intentionally does not restate `aangeboden-gebruik-api`'s or `deelnames-gebruik`'s own requirements — it adds the missing deny-before-grant guarantees and closes the two gaps found in `discovery.md`, leaving their already-correct behaviour (afnemer/deelnemer relationship scoping) as-is and covered by REQ-005's regression scenarios.
- REQ-003 (closing the `gebruik-beheerder` global-read gap) is a behaviour change for an existing, already-provisioned NC group. It is included here per the context brief's explicit framing ("hides ... from vendor-role users **and from other organisations**") and the fail-closed design constraint, but is flagged in the change's `DEFERRED_QUESTIONS` for explicit confirmation before implementation, since it changes already-shipped behaviour beyond the vendor-specific ask.
- Related: `openspec/specs/aangeboden-gebruik-api`, `openspec/specs/deelnames-gebruik`, `openspec/specs/gebruik-services`, `openspec/specs/open-data-publishing`, `openspec/specs/contract-administration`, `openspec/specs/softwarecatalog-adopt-or-abstractions` (tenant context / `X-OpenRegister-Organisation`). Does not conflict with `openspec/changes/organisation-parent-hierarchy-rbac-fix` (organisation creation parent-linkage — a different concern from read-time visibility).
