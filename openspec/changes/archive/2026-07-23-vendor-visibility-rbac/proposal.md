# Proposal: vendor-visibility-rbac

## Summary
Defines and server-side enforces a visibility matrix (role × object type × relationship) that hides an organisation's applicatielandschap (gebruik), koppelingen, and contracts from vendor-role (`aanbod-beheerder`) users and from other organisations, unless the data was explicitly shared (deelname) or published as open data. The change audits the existing gebruik/koppelingen/contract read endpoints for leak paths, closes any gaps found, and adds regression tests — including negative tests — proving a vendor cannot enumerate another organisation's landscape.

## Motivation
This is a known, recurring vulnerability class in this product category: VNG Softwarecatalogus issue #105 ("leverancier mag applicatielandschap niet zien") plus leak bug reports #315, #394, #455 in the incumbent product all describe the same failure mode — a supplier user is able to see a customer organisation's full software portfolio rather than only the usage that involves the supplier's own products. The mapped requirement set carries 192 security and 208 privacy tender requirements, and 32 organisatie/RBAC-labelled VNG issues, across the wider ecosystem. Specter's canonical feature list flags `vendor-visibility-rbac` as `must` with demand 36 — the highest-demand item of the current build wave.

SoftwareCatalog already has partial scoping (`GebruikController::applyAanbodScopeToOptions`, `AangebodenGebruikService::getKoppelingenGebruikByUuid` ownership check, `ContractApprovalController`'s per-object ownership guard) but no single documented visibility matrix, no systematic audit of every gebruik/koppelingen/contract read path, and — critically — at least one endpoint (`AangebodenGebruikController::getGebruiksWhereAfnemer`, `@PublicPage` with no authentication check) that has never been proven not to leak. Codifying the matrix now, before more read paths are added, closes the gap while it is still small.

## Affected Projects
- [x] Project: `softwarecatalog` — visibility matrix definition, server-side enforcement in `AangebodenGebruikController`/`AangebodenGebruikService`, `GebruikController`/`GebruikService`, contract read paths (OR schema RBAC + `ContractApprovalController`), leak-path audit of `appinfo/routes.php`, regression tests.

## Scope

### In Scope
- Define the visibility matrix: role (admin / ambtenaar / gebruik-beheerder / aanbod-beheerder(vendor) / anonymous) × object type (gebruik, koppeling, contract) × relationship (owner, afnemer, aanbieder, deelnemer, published, unrelated).
- Server-side enforcement of that matrix in the services/handlers/controllers that serve gebruik, koppelingen, and contract reads (`AangebodenGebruikController`/`Service`, `GebruikController`/`Service`, contract read paths).
- Deny-by-default (fail closed) for cross-organisation access: the deny check MUST run before any default-open / RBAC-bypass grant path, per the OpenRegister or#2025 trap (a veto evaluated after a default-open grant is dead code).
- A leak-path audit of every `appinfo/routes.php` endpoint that reads gebruik, koppelingen, or contract objects, with each endpoint's current authorization posture documented and any gap closed.
- Automated tests, including negative tests per role (vendor denied cross-org reads; unauthenticated caller denied non-public reads), and i18n + docs tasks per project rules.

### Out of Scope
- A UI permission editor — the visibility matrix is enforced server-side; there is no admin-configurable permission UI in this change.
- New sharing flows — deelname (participant) sharing already exists (`deelnames-gebruik`); this change enforces around it, it does not add new ways to share.
- Changes to the open-data publishing mechanism (`open-data-publishing`) — publish/depublish and the anonymous public surface are unchanged; this change only confirms non-published data stays out of vendor/cross-org reach.
- Restoring organisation parent/child hierarchy — tracked separately in `organisation-parent-hierarchy-rbac-fix`; this change does not touch `OrganisatieService::createOrganisationEntityInternal()`.

## Approach
1. Document the visibility matrix as the canonical `vendor-visibility-rbac` capability spec, cross-referencing the existing `aangeboden-gebruik-api` and `deelnames-gebruik` specs rather than restating their requirements.
2. Add/confirm a deny-before-grant guard at the top of every gebruik/koppelingen/contract read path: resolve the caller's role and relationship to the target object(s) first, and only then apply any RBAC-bypass (`_rbac: false`) query.
3. Close the specific gap found during discovery in `AangebodenGebruikController::getGebruiksWhereAfnemer()` (no authentication check on a `@PublicPage` endpoint) and any equivalent gaps the routes.php audit surfaces.
4. Lock in already-correct behaviour (e.g. the ownership check in `getKoppelingenGebruikByUuid`, the `aanbod-beheerder` scoping in `GebruikController`, the per-object guard in `ContractApprovalController::submit`/`submitRenewal`) with regression tests so it cannot silently regress.
5. Verify the OpenRegister `contract` schema's RBAC read rule denies cross-organisation reads for non-counterparty, non-admin, non-ambtenaar callers; contract CRUD itself runs through the OR object store (ADR-022), so this is primarily a schema RBAC verification + test, not new controller code.

## New Dependencies
None.

## Impact
- `lib/Controller/AangebodenGebruikController.php`, `lib/Service/AangebodenGebruikService.php`
- `lib/Controller/GebruikController.php`, `lib/Service/GebruikService.php`
- `lib/Controller/ContractApprovalController.php` (verification only — guard already exists)
- `appinfo/routes.php` (audit; no route shape changes expected)
- Softwarecatalogus `contract` schema RBAC read rule (verification, and a fix if the audit finds a gap)
- Test suite: new PHPUnit unit + negative-access tests; Newman REST collection additions for the audited endpoints

## Cross-Project Dependencies
None — this is a self-contained SoftwareCatalog change. It reads (but does not modify) OpenRegister's schema RBAC engine and the `RegisterResolverService`/tenant-context abstractions already adopted per `softwarecatalog-adopt-or-abstractions`.

## Risks

### Risk 1: Fail-open regression via veto-after-default-grant ordering
**Severity:** High — **Mitigation:** Every enforcement point in this change places the deny/role check strictly before any `_rbac: false` / default-open query path, mirroring the OpenRegister or#2025 post-mortem named in the design constraints. Each such ordering is covered by a negative test that asserts the deny path short-circuits before the bypass query is ever built.

### Risk 2: Audit misses a leak path outside the four named controllers
**Severity:** Medium — **Mitigation:** The routes.php audit task enumerates every route touching the `gebruik`, `koppeling`, and `contract` schemas (via `grep`/register/schema cross-reference), not just the four controllers identified during discovery, and records the result in `discovery.md` for reviewer sign-off.

### Risk 3: Tightening existing endpoints breaks a currently-working (if accidentally permissive) frontend flow
**Severity:** Low — **Mitigation:** Enforcement changes are scoped to cross-organisation reads only; same-organisation, afnemer/aanbieder, deelnemer, and published relationships are explicitly preserved and covered by positive tests alongside the negative ones.

## Rollback Strategy
All enforcement changes are additive guard clauses (early-return deny checks) in existing PHP methods, with no schema or route shape changes expected. Revert is a straight `git revert` of the PR; if the OR `contract` schema RBAC rule is changed, that change is reverted independently via the schema config JSON. No data migration is introduced, so no backward-migration step is needed.

## Open Questions
None — the visibility matrix design constraints (fail closed, publish = RBAC not self-serve, OR storage only) are fixed by the context brief; specific enforcement-point decisions belong in design.md.
