# Vendor Visibility RBAC — Route Audit

Task 6 of `openspec/changes/vendor-visibility-rbac/tasks.md`: every route in
`appinfo/routes.php` whose controller method reads a `gebruik`, `koppeling`,
or `contract` OpenRegister object, enumerated with its authorization posture
and the test(s) that cover it, per
[REQ-007](../../openspec/specs/vendor-visibility-rbac/spec.md#requirement-every-route-touching-gebruik-koppeling-or-contract-objects-must-have-a-documented-tested-authorization-posture-req-007).

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
| `GET /api/aanbod` | `AanbodController::getAanbod` → `AanbodService::getAanbod` | gebruik, koppeling, module, dienst | Confirmed correct: hard-filters each schema's `filter_field` (`afnemer` for gebruik, `aanbieder` for koppeling/module/dienst) to `currentOrg` **before** the RBAC-disabled search; `currentOrg === null` → empty envelope. **Note:** relies on the same implicit-null-safety pattern as the pre-fix `getGebruiksWhereAfnemer` gap (no explicit `getUser() === null` guard at the controller entry point) — not a live leak (the field-scoping still holds for an anonymous caller since `currentOrg` is null and the code returns the empty envelope), but flagged here as a **follow-up hardening candidate**, out of this change's declared `Impact` scope (only `AangebodenGebruikController`/`GebruikController`/`ContractApprovalController` were named). | Pre-existing `AanbodService` coverage; follow-up recommended, not implemented in this change |
| `GET /api/views` + `include_gebruik`/`include_deelnames_gebruik` | `ViewController::getAllViews` → `ViewService::getGebruikData()` / `getDeelnamesGebruikData()` | gebruik | Confirmed correct: `getGebruikData()` uses the **standard RBAC-enabled** `searchObjects($query)` call (no `_rbac:false`) AND unconditionally adds `@self.organisation = currentOrg` for every caller when authenticated; `getDeelnamesGebruikData()` is RBAC-disabled but hard-filters `deelnemers => currentOrg` first (`deelnames-gebruik` pattern, currentOrg null → `[]`). Unaffected by this change. | Pre-existing `deelnames-gebruik` coverage |
| `PUT /api/publication/{objectType}/{uuid}/publish`, `DELETE /api/publication/{objectType}/{uuid}/depublish` | `PublicationController::publish` / `depublish` | dienst/module/koppeling/organisatie | Write path only (sets `publicatiedatum`/`depublicatiedatum`); not a bulk read. Already has a per-object ownership guard (admin, or aanbod-beheerder whose org owns the entry). Out of scope per proposal ("Changes to the open-data publishing mechanism ... out of scope"). | Pre-existing |
| `GET /api/contracts/approval/config` | `ContractApprovalController::config` | — | Authenticated-only; returns a boolean config flag, no contract data. Not a contract read. | N/A |
| `POST /api/contracts/{contractUuid}/approval/submit`, `POST /api/contracts/{contractUuid}/approval/renewal` | `ContractApprovalController::submit` / `submitRenewal` | contract | Write (delegation) path only — no contract data returned. Per-object ownership guard confirmed present (`authorizeContract()` → `ContractApprovalService::authorizeSubmit()`, `_organisation`-matched). Confirmed correct, unaffected by this change. | Pre-existing `ContractApprovalControllerTest` |
| *(no app-local route)* | Contract object reads (list/detail) | contract | Contract CRUD runs entirely through the OpenRegister object store (ADR-022, `contract-administration`) via the manifest renderer — there is no SoftwareCatalog controller for contract reads. Visibility is governed exclusively by the OpenRegister `contract` schema's own `authorization.read` RBAC rule. **Fixed (REQ-006):** removed the blanket `"public"` grant and the unscoped `"aanbod-beheerder"` grant; `aanbod-beheerder` is now match-scoped to `_organisation == $organisation` in `lib/Settings/softwarecatalogus_register.json`. | `ContractRbacTest` |

## Findings summary

- **Fixed by this change:** `GET /api/gebruik` (gebruik-beheerder cross-org leak, discovery.md finding 2), `GET /api/aangeboden-gebruik/afnemer` (implicit-only auth), OpenRegister `contract` schema RBAC read rule (blanket `public` + unscoped `aanbod-beheerder`).
- **Confirmed correct, now regression-tested:** `GET /api/koppelingen-gebruik/{uuid}`, `GET /api/aangeboden-gebruik/{afnemer,deelnemers}`, `GET /api/gebruik/deelnemer`.
- **Confirmed correct, unaffected (already had their own field-scoping or role guard):** `GET /api/aanbod`, `GET /api/views` (gebruik/deelnames-gebruik enrichment), `GET/POST /api/aangeboden-gebruik/ambtenaar*`, all `PublicationController`/`ContractApprovalController`/`AangebodenGebruikController` write paths.
- **Follow-up recommended (not implemented in this change, out of the proposal's declared `Impact` scope):**
  1. `AanbodController::getAanbod()` — add the same explicit `getUser() === null` guard pattern REQ-004 added to `getGebruiksWhereAfnemer()`, so its safety no longer depends on an implicit `null`-returning helper.
  2. The `gebruik`/`koppeling`/`organisatie` OpenRegister schemas' own `authorization.read` rules also grant `gebruik-beheerder` an **unscoped** read at the OpenRegister RBAC-engine level (same shape as the `contract` leak this change fixes) — this does not affect any endpoint audited above (every one of them either bypasses schema RBAC entirely via `_rbac:false` with its own app-level scoping, or field-scopes on top of RBAC regardless of role), but any *future* code path that reads these schemas through the **standard** (non-bypassed) OpenRegister object API without adding its own organisation filter would inherit this gap. Recommend a dedicated follow-up change scoped to those three schemas' RBAC rules, mirroring this change's `contract` fix.

## Scenario introduced after this capability lands (REQ-007's forward guarantee)

Any new route added to `appinfo/routes.php` that reads a `gebruik`,
`koppeling`, or `contract` object MUST be added to the table above with its
authorization posture and a covering test, or it is a spec violation of
REQ-007 and MUST be blocked at review.
