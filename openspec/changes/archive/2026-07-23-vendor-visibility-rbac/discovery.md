# Discovery: vendor-visibility-rbac

## Question
Does SoftwareCatalog currently leak an organisation's applicatielandschap (gebruik), koppelingen, or contracts to vendor-role (`aanbod-beheerder`) users or to other organisations — and if so, exactly where, and what is the minimal fix that fails closed?

## Approach Taken
Read every controller/service pair that serves gebruik, koppelingen, or contract data, tracing each `_rbac: false` / `_multitenancy: false` OpenRegister query back to the authorization check (or absence of one) that gates it:
- `lib/Controller/AangebodenGebruikController.php` + `lib/Service/AangebodenGebruikService.php` (all 7 endpoints)
- `lib/Controller/GebruikController.php` + `lib/Service/GebruikService.php`
- `lib/Controller/ContractApprovalController.php` (contract approval delegation; contract CRUD itself runs through the OR object store per ADR-022, so it is governed by OR schema RBAC, not controller code)
- `appinfo/routes.php` for the full set of routes touching these three object types
- Role model: `lib/Service/SoftwareCatalogue/ContactPersonHandler.php::getRoleGroupByOrganizationType()` (organisation `type` → NC group mapping) and `lib/Settings/softwarecatalogus_register.json` (`organisatie.type` enum: `Gemeente`, `Leverancier`, `Samenwerking`, `Community`)
- Cross-checked against `openspec/specs/aangeboden-gebruik-api`, `openspec/specs/deelnames-gebruik`, `openspec/specs/organisatie-service`, `openspec/specs/sc-handlers` (all `status: done`)

## Findings

### Role model (as it exists in code today)
Organisation `type` maps to an NC group at contact-provisioning time:
- `Gemeente` / `Samenwerking` → `gebruik-beheerder` (municipality / collaboration — "usage manager")
- `Leverancier` / `Community` → `aanbod-beheerder` (**this is the "vendor" role** named in the context brief)
- `admin` and `ambtenaar` are separate, orthogonal NC groups (not derived from organisation type) used as the "sees everything" bypass in the aangeboden-gebruik-api surface.

### Confirmed-correct enforcement (no change needed, but needs regression tests)
1. `AangebodenGebruikService::getKoppelingenGebruikByUuid()` — for non-ambtenaar callers, computes `hasAccess = ($ownerOrg === $currentOrg)` by fetching the target uuid's `@self.organisation` **before** issuing the RBAC-disabled search, and returns the empty envelope when `hasAccess` is false. This is the correct "deny check before default-open grant" ordering the context brief's fail-closed constraint calls for. `$currentOrg` is derived from `IUserSession::getUser()` → null for anonymous, so an unauthenticated caller always fails this check.
2. `AangebodenGebruikController::getKoppelingenGebruikByUuid()` — the `organisation` query-param override is applied **only** when `isAmbtenaar === true`; non-ambtenaar callers cannot widen the query.
3. `AangebodenGebruikService::getGebruiksWhereAfnemer()` / `getGebruiksWhereDeelnemers()` — both are RBAC-disabled by design (per `deelnames-gebruik`), but the query is hard-filtered to `afnemer == currentOrg` / `deelnemers == currentOrg` respectively, where `currentOrg` comes from the caller's own authenticated session (`OrganisationService::getActiveOrganisation()`, never client-supplied). These endpoints return only the caller's own organisation's relationship view ("what's been offered to us" / "where we participate"), not another organisation's landscape — so despite the `@PublicPage` + RBAC-disabled combination, they do not leak cross-org data today. This matches the existing `deelnames-gebruik` and `aangeboden-gebruik-api` specs.
4. `ContractApprovalController::submit()` / `submitRenewal()` already carry a per-object ownership guard (admin, or the aanbod-beheerder whose active organisation owns the contract) per its own docblock (ADR-005) — confirmed present, not a gap.

### Confirmed gap 1 (hardening, not a live leak): implicit-only auth on a `@PublicPage` endpoint
`AangebodenGebruikController::getGebruiksWhereAfnemer()` is annotated `@PublicPage` with **no explicit authentication check** in the method body. Its safety today depends entirely on the unstated invariant that `AangebodenGebruikService::getCurrentOrganisation()` returns `null` for any request with no authenticated NC user, which it currently does (`$this->userSession->getUser() === null` short-circuits before calling OpenRegister). This is correct today but fragile: it is a security property of a downstream helper, not an explicit guard at the entry point, and nothing tests it. **Recommendation: add an explicit auth check (mirroring the pattern already used in `setGebruikSelfToActiveOrg`/`deleteGebruikAsAfnemer`) and cover it with a negative test**, rather than continuing to rely on the implicit chain.

### Confirmed gap 2 (real, in-scope leak): `gebruik-beheerder` gets an unscoped, cross-organisation read
`GebruikController::getGebruiken()`'s own docblock states the current design: *"For a gebruik-beheerder, returns all gebruiken. For an aanbod-beheerder, returns gebruiken of applications of the user's organization."* Tracing this:
- `GebruikService::getGebruiken()` unconditionally calls `searchObjectsPaginated(..., _rbac: false, _multitenancy: false)` — RBAC is disabled for every caller who reaches the service, not just `ambtenaar`/`admin`.
- `GebruikController::applyAanbodScopeToOptions()` only restricts the query (to the caller's own `aanbieder` organisation's applications) when the caller is `aanbod-beheerder` **and not** `admin` **and not** `gebruik-beheerder`. Any caller in `gebruik-beheerder` (or `admin`) passes straight through with **no organisation filter added at all**.
- `gebruik-beheerder` is auto-assigned to **every** contact person whose organisation `type` is `Gemeente` or `Samenwerking` (`ContactPersonHandler::getRoleGroupByOrganizationType()`) — i.e. every municipality's own staff, not a national/oversight role.

Net effect: today, any municipality's `gebruik-beheerder` user gets a **global, cross-organisation** view of every organisation's gebruik data — not just their own — through `GET /api/gebruik`. This is inconsistent with the rest of the codebase's established pattern, where every other "see everything" path (`getAllGebruiksForAmbtenaar`, `getSingleGebruikForAmbtenaar`, `getKoppelingenGebruikByUuid` cross-org branch) is gated specifically behind the separate `ambtenaar` (or `admin`) group, never behind `gebruik-beheerder`. This is squarely the failure mode the context brief describes — "hides applicatielandschap ... from vendor-role users **and from other organisations**" — just manifesting for the municipality-side role rather than the vendor-side role. It is flagged as `DEFERRED_QUESTIONS` item 1 below because closing it changes already-shipped behaviour for an existing group, and needs an explicit go-ahead before the spec commits to a behaviour change beyond the vendor-specific ask.

### Contract read paths
Contract CRUD runs entirely through the manifest renderer's OpenRegister object store (`contract-administration` spec, ADR-022) — there is no app-local contract controller for reads. Visibility of contracts is therefore governed by the OpenRegister `contract` schema's own RBAC read rule, not by SoftwareCatalog PHP code. This audit did not find the schema's current read-rule configuration inside this repo (it lives in `lib/Settings/softwarecatalogus_register.json`'s schema RBAC block, deployed at import time) — verifying and, if needed, tightening that rule is a design/tasks item, not a controller-code item.

## Recommendation
Proceed to design.md and specs with a visibility matrix that:
1. Defines relationship-based access (owner / afnemer / aanbieder / deelnemer / published) as the primary enforcement mechanism for the AangebodenGebruik surface — it is already implemented correctly there; the work is to make the `getGebruiksWhereAfnemer` auth guard explicit and lock all of it in with tests.
2. Extends the `aanbod-beheerder` (vendor) organisation-scoping pattern already proven in `applyAanbodScopeToOptions()` to `gebruik-beheerder` as well, so that role also loses its accidental global-read privilege and is scoped to its own organisation's gebruik — while the deliberate cross-org bypass remains available only to `ambtenaar`/`admin`, matching the pattern everywhere else in the codebase.
3. Treats the OR `contract` schema RBAC read rule as a verification target: assert (via a schema-config read + an integration/PHPUnit test) that a non-counterparty `aanbod-beheerder` cannot read another organisation's contract, and fix the schema config if the assertion fails.

## Risks Uncovered
- Closing the `gebruik-beheerder` global-read gap (finding 2) is a behaviour change for an existing, already-provisioned NC group — every currently-onboarded `Gemeente`/`Samenwerking` organisation's staff will lose the ability to browse other municipalities' gebruik data through this endpoint. This is the correct fail-closed behaviour per the design constraints, but it is a bigger blast radius than "just fix the vendor path," so it is called out explicitly rather than silently folded into the vendor fix.
- No `contract` schema RBAC config was located inside this repo checkout to inspect directly; the corresponding spec requirement is written as a verification + fail-closed-fix requirement rather than asserting today's exact rule text.

## Next Steps
Proceed to design.md and the `vendor-visibility-rbac` spec delta, carrying forward both confirmed gaps (1: explicit auth guard, 2: gebruik-beheerder org-scoping) as MUST requirements, and the contract-schema RBAC verification as a MUST requirement with a fail-closed remediation path. Surface finding 2's scope as `DEFERRED_QUESTIONS` for explicit confirmation before `opsx-apply` implements it.
