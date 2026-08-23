# Vendor Visibility RBAC — Route Audit

Task 6 of `openspec/changes/vendor-visibility-rbac/tasks.md`: every route in
`appinfo/routes.php` whose controller method reads a `gebruik`, `koppeling`,
or `contract` OpenRegister object, enumerated with its authorization posture
and the test(s) that cover it, per
[REQ-007](../../openspec/specs/vendor-visibility-rbac/spec.md#requirement-every-route-touching-gebruik-koppeling-or-contract-objects-must-have-a-documented-tested-authorization-posture-req-007).

**Updated by `schema-rbac-hardening`** (stackiq #379, #390, #378):
closed the two follow-up gaps this audit originally flagged below — the
`gebruik`/`koppeling`/`organisatie` schema-level RBAC gap and the
`AanbodController::getAanbod()` implicit-guard gap — and extended the
`contract` schema fix (REQ-006) to the roles it had not yet covered. See
[REQ-008](../../openspec/specs/vendor-visibility-rbac/spec.md#requirement-gebruik-koppeling-and-organisatie-schema-level-rbac-reads-must-deny-cross-organisation-access-for-gebruik-beheerder-req-008)
and
[REQ-009](../../openspec/specs/vendor-visibility-rbac/spec.md#requirement-the-aanbod-listing-endpoint-must-require-authentication-explicitly-not-implicitly-req-009).
Both the schema-RBAC layer and the one deliberately accepted residual
(deelnemer-array sharing) are documented in the new section below.

Method: every route entry in `appinfo/routes.php` was cross-referenced
against its controller, then every controller method was traced back to
whichever OpenRegister query it issues (`_rbac`/`_multitenancy` flags) and
the authorization check (or absence of one) gating it — the same method
`discovery.md` used, extended to the full route table rather than the four
controllers named in the proposal's discovery phase.

## Audit table

| Route | Controller#method | Object type(s) | Posture (this change) | Covered by |
|---|---|---|---|---|
| `GET /api/gebruik` | `GebruikController::getGebruiken` | gebruik | **Fixed (REQ-003).** `admin`/`ambtenaar`: unrestricted. `aanbod-beheerder`: scoped to own offered applications (existing, REQ-002). `gebruik-beheerder`: **now** scoped to own organisation's `afnemer` relationship — previously unscoped (discovery.md finding 2). Deny-before-grant: role/relationship resolved before the `_rbac:false` query is built. | `GebruikControllerDecompositionTest` |
| `GET /api/gebruik/deelnemer` | `GebruikController::getGebruikenForDeelnemer` | gebruik | Authenticated-only (`401` if not); hard-filters `deelnemers => [orgUuid]` before the RBAC-disabled query — already field-scoped to caller's own org, unaffected by this change. | Pre-existing; not modified by this change |
| `GET /api/aangeboden-gebruik/afnemer` | `AangebodenGebruikController::getGebruiksWhereAfnemer` | gebruik | **Fixed (REQ-004).** Explicit `getUser() === null` guard added before the service is invoked — previously relied only on the service's internal `getCurrentOrganisation()` returning null. Service hard-filters `afnemer => currentOrg` (REQ-005, unaffected). | `AangebodenGebruikControllerTest`, `AangebodenGebruikServiceTest` |
| `GET /api/aangeboden-gebruik/deelnemers` | `AangebodenGebruikController::getGebruiksWhereDeelnemers` | gebruik | Confirmed correct (REQ-005): RBAC-disabled by design (`deelnames-gebruik`), but hard-filtered to `deelnemers => currentOrg` from the caller's own session — never client-supplied. `currentOrg === null` → empty envelope. Unaffected by this change. | Pre-existing `deelnames-gebruik` coverage |
| `GET /api/aangeboden-gebruik/ambtenaar` | `AangebodenGebruikController::getAllGebruiksForAmbtenaar` | gebruik | `admin`/`ambtenaar`-only (`isUserInGroup` guard, explicit deny-before-grant, empty envelope for anyone else) — the deliberate unrestricted bypass, matches the matrix's `admin`/`ambtenaar` row. Unaffected. | Pre-existing |
| `GET /api/aangeboden-gebruik/ambtenaar/{gebruikId}` | `AangebodenGebruikController::getSingleGebruikForAmbtenaar` | gebruik | Same as above — `admin`/`ambtenaar`-only. Unaffected. | Pre-existing |
| `PUT /api/aangeboden-gebruik/{gebruikId}/set-self` | `AangebodenGebruikController::setGebruikSelfToActiveOrg` | gebruik / koppeling | Write path. Authenticated-only + per-object guard (`isAfnemer \|\| isAanbieder` on the target object) before the RBAC-disabled save. Not a read leak; unaffected. | Pre-existing |
| `DELETE /api/aangeboden-gebruik/{gebruikId}/deny` | `AangebodenGebruikController::deleteGebruikAsAfnemer` | gebruik / koppeling | Write (delete) path. Same per-object `isAfnemer \|\| isAanbieder` guard as above. Not a read leak; unaffected. | Pre-existing |
| `GET /api/aangeboden-gebruik/docs` | `AangebodenGebruikController::getApiDocumentation` | — | Static documentation payload; no object read. | N/A |
| `GET /api/koppelingen-gebruik/{uuid}` | `AangebodenGebruikController::getKoppelingenGebruikByUuid` → `AangebodenGebruikService::getKoppelingenGebruikByUuid` | gebruik, koppeling | Confirmed correct (REQ-002): `ambtenaar`/`admin` bypass; otherwise the target uuid's owning organisation is resolved via `find()` **before** the RBAC-disabled `searchObjectsPaginated()` call, and access is denied (empty envelope) when `ownerOrg !== currentOrg`. The `organisation` query-param override is applied only when `isAmbtenaar === true`. Locked in with regression + negative tests by this change. | `AangebodenGebruikServiceTest` |
| `GET /api/aanbod` | `AanbodController::getAanbod` → `AanbodService::getAanbod` | gebruik, koppeling, module, dienst | **Fixed (REQ-009, `schema-rbac-hardening`).** Explicit `getUser() === null` guard added before the service is invoked, mirroring REQ-004 — previously relied only on the field-scoping's implicit null-safety (not a live leak, but the same implicit-invariant anti-pattern REQ-004 already eliminated once). Service still hard-filters each schema's `filter_field` (`afnemer` for gebruik, `aanbieder` for koppeling/module/dienst) to `currentOrg`, unaffected. | `AanbodControllerTest` |
| `GET /api/views` + `include_gebruik`/`include_deelnames_gebruik` | `ViewController::getAllViews` → `ViewService::getGebruikData()` / `getDeelnamesGebruikData()` | gebruik | Confirmed correct: `getGebruikData()` uses the **standard RBAC-enabled** `searchObjects($query)` call (no `_rbac:false`) AND unconditionally adds `@self.organisation = currentOrg` for every caller when authenticated; `getDeelnamesGebruikData()` is RBAC-disabled but hard-filters `deelnemers => currentOrg` first (`deelnames-gebruik` pattern, currentOrg null → `[]`). Unaffected by this change. | Pre-existing `deelnames-gebruik` coverage |
| `PUT /api/publication/{objectType}/{uuid}/publish`, `DELETE /api/publication/{objectType}/{uuid}/depublish` | `PublicationController::publish` / `depublish` | dienst/module/koppeling/organisatie | Write path only (sets `publicatiedatum`/`depublicatiedatum`); not a bulk read. Already has a per-object ownership guard (admin, or aanbod-beheerder whose org owns the entry). Out of scope per proposal ("Changes to the open-data publishing mechanism ... out of scope"). | Pre-existing |
| `GET /api/contracts/approval/config` | `ContractApprovalController::config` | — | Authenticated-only; returns a boolean config flag, no contract data. Not a contract read. | N/A |
| `POST /api/contracts/{contractUuid}/approval/submit`, `POST /api/contracts/{contractUuid}/approval/renewal` | `ContractApprovalController::submit` / `submitRenewal` | contract | Write (delegation) path only — no contract data returned. Per-object ownership guard confirmed present (`authorizeContract()` → `ContractApprovalService::authorizeSubmit()`, `_organisation`-matched). Confirmed correct, unaffected by this change. | Pre-existing `ContractApprovalControllerTest` |
| *(no app-local route)* | Contract object reads (list/detail) | contract | Contract CRUD runs entirely through the OpenRegister object store (ADR-022, `contract-administration`) via the manifest renderer — there is no Stackiq controller for contract reads. Visibility is governed exclusively by the OpenRegister `contract` schema's own `authorization.read` RBAC rule. **Fixed (REQ-006):** removed the blanket `"public"` grant and the unscoped `"aanbod-beheerder"` grant; `aanbod-beheerder` is now match-scoped to `_organisation == $organisation` in `lib/Settings/softwarecatalogus_register.json`. **Extended (REQ-006, `schema-rbac-hardening`, #390):** every remaining bare role (`functioneel-beheerder`, `gebruik-beheerder`, `vng-raadpleger`, `software-catalog-users`, `organisatie-beheerder`, `organisaties-beheerder`, `gebruik-raadpleger`) is now match-scoped the same way; `ambtenaar` and `software-catalog-admins` (the app's super-user group) remain deliberately unscoped — see `design.md` Decision 4. | `ContractRbacTest` |
| *(no app-local route)* | Koppeling object reads (list/detail) | koppeling | No Stackiq controller reads koppeling through the generic OpenRegister object API. Visibility is governed exclusively by the `koppeling` schema's `authorization.read` rule. **Fixed (REQ-008, `schema-rbac-hardening`, #379):** the bare unscoped `gebruik-beheerder` grant is now match-scoped to `_organisation == $organisation`. Every app-local `koppeling` read (see `AangebodenGebruikController`/`AanbodController` rows above) already bypasses schema RBAC with `_rbac:false` and does its own scoping, so this was not a live leak through those routes — but was live-exploitable via any generic OpenRegister object-API read outside this app. | `SchemaRbacTest` |
| *(no app-local route)* | Organisatie object reads (list/detail) | organisatie | No Stackiq controller reads organisatie through the generic OpenRegister object API. Visibility is governed exclusively by the `organisatie` schema's `authorization.read` rule (plus its three pre-existing `public` match rules for active organisaties, unaffected by this change). **Fixed (REQ-008, `schema-rbac-hardening`, #379):** the bare unscoped `gebruik-beheerder` grant is now match-scoped to `_organisation == $organisation`; only non-public/inactive organisatie records were affected. | `SchemaRbacTest` |

## Findings summary

- **Fixed by `vendor-visibility-rbac`:** `GET /api/gebruik` (gebruik-beheerder cross-org leak, discovery.md finding 2), `GET /api/aangeboden-gebruik/afnemer` (implicit-only auth), OpenRegister `contract` schema RBAC read rule (blanket `public` + unscoped `aanbod-beheerder`).
- **Fixed by `schema-rbac-hardening`** (stackiq #379, #390, #378): `koppeling` and `organisatie` schema RBAC (`gebruik-beheerder` unscoped grant), the remaining bare roles on `contract` schema RBAC beyond `aanbod-beheerder`, and `AanbodController::getAanbod()`'s implicit-only auth guard. See REQ-008/REQ-009 and the "Schema-level RBAC layer" section below.
- **Confirmed correct, now regression-tested:** `GET /api/koppelingen-gebruik/{uuid}`, `GET /api/aangeboden-gebruik/{afnemer,deelnemers}`, `GET /api/gebruik/deelnemer`.
- **Confirmed correct, unaffected (already had their own field-scoping or role guard):** `GET /api/views` (gebruik/deelnames-gebruik enrichment), `GET/POST /api/aangeboden-gebruik/ambtenaar*`, all `PublicationController`/`ContractApprovalController`/`AangebodenGebruikController` write paths.
- **Documented accepted residual (not fixed, not silently dropped):** the `gebruik.deelnemers` array-membership sharing case — see below.

## Schema-level RBAC layer (added by `schema-rbac-hardening`)

Every OpenRegister schema's `authorization.read` rule is a second,
independent enforcement layer beneath the app-controller guards audited
above: it governs any read of that schema issued through OpenRegister's
**standard**, RBAC-enabled object API — which is the *only* enforcement
point for `koppeling` and `organisatie` (no app-local controller reads
either schema at all) and a defense-in-depth backstop for `gebruik` and
`contract` (whose app-local reads bypass it with `_rbac:false` and do their
own scoping instead).

Before `schema-rbac-hardening`, `gebruik`, `koppeling`, and `organisatie`
each granted `gebruik-beheerder` an **unscoped** read at this layer — the
same bug shape `vendor-visibility-rbac` (REQ-006) had already fixed on
`contract` for `aanbod-beheerder`, and the `contract` schema itself still
carried eight other unscoped roles beyond that one fix. This change closes
all of it: every role is now either match-scoped to the caller's own
organisation (`_organisation`, plus `afnemer` for `gebruik` — see
`design.md` Decisions 1–4), or kept bare with a written justification
(`ambtenaar` and `software-catalog-admins` on `contract`; the pre-existing
`public` match rules on `organisatie`/`koppeling` untouched).

### Documented residual: `gebruik.deelnemers` array-membership sharing

OpenRegister's `OperatorEvaluator` supports only `$eq/$ne/$in/$nin/$exists/
$gt/$gte/$lt/$lte` — there is no operator to express "the caller's
organisation appears anywhere in this object's `deelnemers` array" as a
schema-RBAC match condition. This means deelnemer-shared `gebruik`
visibility (the `deelnames-gebruik` capability) **cannot** be enforced at
the schema-RBAC layer today, and is not attempted by this change.

This is not a live leak: every deelnemer read goes exclusively through the
app-level `AangebodenGebruikController::getGebruiksWhereDeelnemers()` /
`ViewService::getDeelnamesGebruikData()` path, which queries with
`_rbac: false` and hard-filters `deelnemers => currentOrg` **from the
caller's own session** (REQ-005, never client-supplied) — regression-tested
by `AangebodenGebruikServiceTest::testGetGebruiksWhereDeelnemersScopesQueryToCurrentOrg()`.
It becomes a real gap only if a *future* code path reads `gebruik` through
the standard RBAC-enabled object API without its own `deelnemers` scoping.
If a `$contains` operator is ever judged necessary to close this at the
schema layer, it must be proposed as an OpenRegister issue — not
implemented ad hoc in an app-level register config.

## Deployment caveat: silent no-op until #391 lands

`schema-rbac-hardening`'s register-JSON fixes have **no runtime effect on
any already-installed instance** until stackiq #391
(`register-import-reliability`) lands — the repair-step importer currently
no-ops when a register/schema it has already imported once is edited
again. This change must ship after, or together with, #391, and the fix
must be live-verified against a real repair-step import (not just the
source config) before being considered operationally closed. See the
proposal's Risk 1 and `design.md`'s Migration Plan.

## Scenario introduced after this capability lands (REQ-007's forward guarantee)

Any new route added to `appinfo/routes.php` that reads a `gebruik`,
`koppeling`, or `contract` object MUST be added to the table above with its
authorization posture and a covering test, or it is a spec violation of
REQ-007 and MUST be blocked at review.
