# Design: vendor-visibility-rbac

## Architecture Overview
SoftwareCatalog owns no domain tables (ADR-001); all gebruik/koppeling/contract data lives in OpenRegister as JSON objects, and reads normally go through OpenRegister's own schema RBAC engine. The read paths this change touches are the ones that deliberately **bypass** that engine (`_rbac: false`, `_multitenancy: false`) to serve cross-organisation queries such as "what has been offered to my organisation" or "who uses my product" — a pattern already established by `aangeboden-gebruik-api` and `deelnames-gebruik`. This change does not introduce a new authorization mechanism; it introduces one explicit, shared visibility matrix that every RBAC-bypassing read path in `AangebodenGebruikController`/`Service` and `GebruikController`/`Service` MUST evaluate before the bypass query runs, and it verifies the one path (contracts) where the answer is "let OpenRegister's own schema RBAC handle it, verify the rule is correct."

```
Caller ──▶ Controller (resolves: role, active-org UUID, target relationship)
              │
              ├─ deny check (BEFORE any bypass query) ──▶ 403 / empty envelope
              │
              └─ allow ──▶ Service (may use _rbac:false/_multitenancy:false,
                            but the query is ALWAYS field-scoped to the
                            caller's own org UUID or the specific relationship
                            already proven above — never "everything")
```

## Visibility Matrix
This is the canonical decision table this change implements. "Object" = a `gebruik`, `koppeling`, or `contract` OpenRegister object.

| Caller role | Relationship to object | Result |
|---|---|---|
| `admin` | any | full read |
| `ambtenaar` | any | full read (existing, unchanged — already gated in `getAllGebruiksForAmbtenaar`/`getSingleGebruikForAmbtenaar`/`getKoppelingenGebruikByUuid`) |
| `gebruik-beheerder` (municipality/samenwerking) | object's owning organisation == caller's active organisation | read |
| `gebruik-beheerder` | object's owning organisation != caller's active organisation, and caller's org is not afnemer/deelnemer on it | **deny** (closes discovery.md finding 2) |
| `aanbod-beheerder` (vendor) | object's `aanbieder` == caller's active organisation (i.e. it is the vendor's own offered product's usage) | read |
| `aanbod-beheerder` | object's owning organisation is a different organisation and the caller is not the `aanbieder` | **deny** — this is the context brief's core complaint |
| any authenticated caller | caller's active organisation is `afnemer` on the object | read (existing `getGebruiksWhereAfnemer` behaviour, hardened with an explicit auth guard) |
| any authenticated caller | caller's active organisation is in the object's `deelnemers` array | read (existing `deelnames-gebruik` behaviour, unchanged) |
| unauthenticated / no active organisation | any | deny — empty envelope, never a 500, never a defaulted organisation |
| anonymous (public) | object is published (`publicatiedatum <= now`) | read via the existing `open-data-publishing` surface only — unaffected by this change |

The matrix is relationship-first: "which organisation does the caller represent, and what is that organisation's relationship to this object" — not a flat role→data-shape mapping. `ambtenaar`/`admin` are the only roles with a role-only (relationship-free) bypass, matching the pattern already used everywhere else in the codebase except the one gap this change closes.

## Nextcloud Integration
- Controllers: `AangebodenGebruikController` (add explicit auth guard to `getGebruiksWhereAfnemer`), `GebruikController` (extend `applyAanbodScopeToOptions` — renamed in spirit to cover both `aanbod-beheerder` and `gebruik-beheerder` — to org-scope `gebruik-beheerder` the same way `aanbod-beheerder` is already scoped)
- Services: `AangebodenGebruikService` (no query-shape change — the existing field-scoped queries are already correct and are locked in with tests), `GebruikService::getGebruiken()` (unchanged — the fix is entirely at the controller's option-building layer, consistent with the existing `aanbod-beheerder` pattern)
- Mappers/Entities: none — no new OpenRegister schema fields
- Events/Hooks: none

## Security Considerations
- **Deny-before-grant ordering (the or#2025 trap named in the context brief)**: every enforcement point in this change resolves the caller's role + relationship and returns the deny result **before** any `_rbac: false` query is built or issued. This is already the pattern in `getKoppelingenGebruikByUuid()`'s ownership check and in `applyAanbodScopeToOptions()`'s early-return for empty `applicatieIds`; the `gebruik-beheerder` fix and the `getGebruiksWhereAfnemer` auth guard follow the identical shape.
- **Fail closed, not fail informative**: every deny path returns the existing empty-result envelope (`{results: [], total: 0, ...}`), HTTP 200 or 403 per the existing per-endpoint convention — never a 500, and never falls through to an unscoped query on any exception (matches the existing try/catch-then-empty-array convention already used throughout `AangebodenGebruikService`).
- **No new trust boundary**: the caller's active-organisation UUID continues to come exclusively from `OrganisationService::getActiveOrganisation()` (server-side session state), never from a client-supplied `organisation` query parameter, except for the already-gated `ambtenaar`-only override in `getKoppelingenGebruikByUuid`.
- **Contract schema RBAC verification**: because contract CRUD runs through the OR object store directly (ADR-022), this change adds a verification test asserting the `contract` schema's RBAC read rule denies a non-counterparty `aanbod-beheerder` a cross-organisation read. If the assertion fails against the current schema config, the schema's RBAC read rule (in `lib/Settings/softwarecatalogus_register.json`) is tightened as part of this change — this is a config fix, not new controller code, and stays within ADR-001 (OR storage only).
- **Negative tests are mandatory**: per ADR-009 and the hydra `security-change-has-tests` gate, every deny branch in the matrix above gets a corresponding PHPUnit negative test asserting the empty/denied result, not just a positive test of the allow path.

## File Structure
```
lib/
  Controller/
    AangebodenGebruikController.php   (add explicit auth guard to getGebruiksWhereAfnemer)
    GebruikController.php             (extend org-scoping to gebruik-beheerder)
  Service/
    AangebodenGebruikService.php      (no shape change; covered by new tests)
    GebruikService.php                (no shape change; covered by new tests)
  Settings/
    softwarecatalogus_register.json   (contract schema RBAC read rule — only if the
                                        verification test in tasks.md finds a gap)
tests/
  Unit/Controller/AangebodenGebruikControllerTest.php   (new negative-access tests)
  Unit/Controller/GebruikControllerTest.php              (new negative-access tests:
                                                           gebruik-beheerder cross-org denied)
  Unit/Service/AangebodenGebruikServiceTest.php          (lock in existing correct scoping)
  Integration/ContractRbacTest.php                       (or equivalent — schema RBAC verification)
```

## Trade-offs
- **Extending the matrix to `gebruik-beheerder` vs. scoping this change strictly to the vendor complaint**: the narrower option (touch only `aanbod-beheerder` paths) would leave the `gebruik-beheerder` cross-municipality leak (discovery.md finding 2) live, which contradicts the context brief's own framing ("...and from other organisations") and the fail-closed design constraint. The wider option is chosen, but is called out explicitly in `proposal.md`/`DEFERRED_QUESTIONS` because it changes already-shipped behaviour for an existing group, not just new code for a new concern.
- **Verifying vs. rewriting contract RBAC**: rewriting the OR schema RBAC engine itself is out of scope (ADR-011 — check for existing functionality first; OpenRegister already has a schema RBAC read-rule mechanism, proven by `open-data-publishing`'s `{group:public, match:{publicatiedatum:{$lte:$now}}}` rule). This change verifies and, only if necessary, adjusts the existing `contract` schema's rule rather than adding app-level contract-read gating, keeping contract CRUD entirely on the OR object store per ADR-022.
- **No new NC group / no UI permission editor**: the existing `admin`/`ambtenaar`/`gebruik-beheerder`/`aanbod-beheerder` groups are reused as-is; this change is enforcement-only, matching the "OUT: UI permission editor" scope boundary.

## Open Questions
See `DEFERRED_QUESTIONS` in the final task report — specifically whether closing the `gebruik-beheerder` cross-organisation read (design decision above) is approved as part of this change, given it changes already-shipped behaviour for every currently-provisioned `Gemeente`/`Samenwerking` organisation.
