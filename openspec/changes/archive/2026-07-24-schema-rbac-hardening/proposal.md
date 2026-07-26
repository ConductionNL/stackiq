# Proposal: schema-rbac-hardening

## Summary
Closes the remaining schema-level RBAC holes left by `vendor-visibility-rbac`
(PR #377): `gebruik`, `koppeling`, `organisatie`, and `contract` each still
carry one or more bare, unscoped role strings in their `authorization.read`
rule in `lib/Settings/softwarecatalogus_register.json`, and
`AanbodController::getAanbod()` still relies on an implicit
null-organisation safeguard instead of an explicit authentication guard.
This change match-scopes every remaining bare role and adds the explicit
guard, closing softwarecatalog **#379**, **#390**, and **#378** as one
change — same fix shape, same file, same tests as REQ-004/REQ-006 already
established.

## Motivation
`vendor-visibility-rbac` fixed the `contract` schema's blanket `public`
grant and its unscoped `aanbod-beheerder` grant, and added an explicit
authentication guard to
`AangebodenGebruikController::getGebruiksWhereAfnemer()`. Its own follow-up
audit (`docs/security/vendor-visibility-rbac.md`, "Follow-up recommended")
flagged two gaps as explicitly out of that change's declared scope:

1. `gebruik`, `koppeling`, and `organisatie` each still grant
   `gebruik-beheerder` an **unscoped** schema-RBAC read — the same shape as
   the `contract` bug REQ-006 fixed. For `koppeling` and `organisatie` this
   is **likely live-exploitable**: neither schema has an app-local
   controller, so a generic OpenRegister object-API read of those two
   schemas is gated *solely* by this schema config. A `gebruik-beheerder` in
   one municipality can currently read every other organisation's
   koppelingen and organisaties. (`gebruik` itself is not currently
   exploitable via this exact path because every in-app `gebruik` query
   passes `_rbac:false` and does its own PHP-level scoping — but the schema
   rule is still wrong and would silently reopen the leak the moment any
   future code path reads `gebruik` through the standard RBAC-enabled API.)
2. `AanbodController::getAanbod()` is `@PublicPage`/`@NoCSRFRequired` with no
   explicit authentication check — it relies entirely on
   `AanbodService::getAanbod()`'s internal `getCurrentOrganisation()`
   returning null for an anonymous session, the exact implicit-guard
   anti-pattern REQ-004 eliminated elsewhere.

Additionally, the "fixed" `contract` schema (#390) still lists eight other
bare, unscoped roles beyond the `aanbod-beheerder` grant REQ-006 corrected
— `gebruik-beheerder`, `ambtenaar`, `functioneel-beheerder`,
`organisatie-beheerder`, `organisaties-beheerder`, `gebruik-raadpleger`,
`vng-raadpleger`, `software-catalog-admins/users` — so any authenticated
user in any one of those groups can currently read every organisation's
contracts.

Fixing all three now, in one change, keeps the fix shape, the touched file,
and the test approach identical to REQ-006's already-reviewed pattern
instead of three separate reviews of the same class of bug.

## Affected Projects
- [x] Project: `softwarecatalog` — `lib/Settings/softwarecatalogus_register.json`
  (schema RBAC read rules for `gebruik`/`koppeling`/`organisatie`/`contract`),
  `lib/Controller/AanbodController.php` (explicit auth guard), plus tests
  and `docs/security/vendor-visibility-rbac.md`.

## Scope

### In Scope
- Replace every remaining bare, unscoped role string in `authorization.read`
  for the `gebruik`, `koppeling`, `organisatie`, and `contract` schemas with
  match-scoped entries (`{"group": "<role>", "match": {"_organisation":
  "$organisation"}}`, plus the `afnemer`-scoped leg for `gebruik` where that
  field expresses the caller-organisation's ownership relation).
- Keep deliberately-global roles (`ambtenaar` on `contract`,
  `software-catalog-admins` everywhere) unscoped, with the justification
  recorded in `design.md`.
- Add an explicit `userSession->getUser() === null` guard to
  `AanbodController::getAanbod()`, mirroring the REQ-004 guard already in
  `AangebodenGebruikController::getGebruiksWhereAfnemer()`, returning the
  standard empty envelope with `401` before the service is invoked.
- Schema-RBAC-layer denial tests for all four schemas (a `gebruik-beheerder`
  in org A cannot read org B's `gebruik`/`koppeling`/`organisatie`/
  `contract`); a controller test asserting `AanbodService` is `never()`
  called for an anonymous caller; regression tests that the deelnemer/
  afnemer app-level paths still work.
- Extend `docs/security/vendor-visibility-rbac.md` with this schema-RBAC
  layer and the documented deelnemer residual (see below).

### Out of Scope
- Adding a `$contains`/array-membership operator to OpenRegister's
  `ConditionMatcher`/`OperatorEvaluator`. Those only support `$eq/$ne/$in/
  $nin/$exists/$gt/$gte/$lt/$lte` — there is no way to express "the
  caller's organisation appears in this object's `deelnemers` array" as a
  schema-RBAC match condition today. The `deelnemers`-array leg of
  `gebruik` visibility (deelnemer/samenwerking sharing) **stays an accepted
  residual**, enforced only by the existing app-level `deelnames-gebruik`
  controllers/services (RBAC-disabled query, hard-filtered on
  `deelnemers => currentOrg` from the session — never client-supplied). It
  is not silently dropped: it is documented as a residual in `design.md`
  and `docs/security/vendor-visibility-rbac.md`, and regression-tested to
  confirm the app-level path still works. If a `$contains` operator is
  later judged necessary, that is an OpenRegister issue, filed separately —
  not implemented here.
- Changing the app-controller scoping logic itself (`GebruikController`,
  `AangebodenGebruikController`, `ContractApprovalService`) — those already
  do their own correct PHP-level scoping per `vendor-visibility-rbac`
  REQ-001–REQ-005 and are only regression-tested here, not modified.
- The deelnames sharing model (`deelnames-gebruik` capability) itself.

## Approach
Same shape as REQ-006: for each of the four schemas, replace every bare
role string in `authorization.read` with either a match-scoped object
(`_organisation`/`afnemer` equality against `$organisation`) or leave it
bare only where the role is a deliberate, documented global bypass
(`ambtenaar` on `contract`, `software-catalog-admins` everywhere — the
app's designated super-user group, already wired as a Nextcloud
`setSuperUserGroups()` entry alongside `admin`). Add the `getAanbod()` guard
by copying the REQ-004 pattern verbatim. See `design.md` for the per-schema
match rationale and per-role justification.

## New Dependencies
None.

## Impact
- `lib/Settings/softwarecatalogus_register.json` — `authorization.read` for
  `gebruik`, `koppeling`, `organisatie`, `contract`.
- `lib/Controller/AanbodController.php::getAanbod()`.
- `docs/security/vendor-visibility-rbac.md`.
- New/extended test files under `tests/Unit/Settings/` and
  `tests/Unit/Controller/`.
- Any deployed instance's OpenRegister schema config for these four
  schemas — see Risk 1.

## Cross-Project Dependencies
Depends on the sibling change **`register-import-reliability`**
(softwarecatalog **#391**, in flight): on installed instances, monolith
register-config edits (this file) are currently a **silent no-op on
upgrade** — the repair-step importer does not detect or apply changes to an
already-imported register/schema. This means the schema-RBAC fix in this
change **has no runtime effect on any already-installed instance until
#391 lands and is deployed with it (or after it)**. This proposal must not
be treated as "fixed" for a running instance until #391's import fix is
verified live against these schema edits — see Risk 1.

## Risks

### Risk 1: Fix is a silent no-op on installed instances until #391 lands
**Severity:** High — **Mitigation:** Documented explicitly above and in
`design.md`. This change must ship after, or together with, #391
(`register-import-reliability`), and the schema-RBAC fix must be
live-verified against a real repair-step import (not just a fresh-install
config) before this proposal is considered closed operationally. The unit
tests in this change assert the *shipped config's shape*, not the deployed
runtime behaviour on an upgraded instance — that gap is exactly what #391
closes.

### Risk 2: Match-scoping a previously-bare role changes its observable
behaviour for any caller who relied on the unscoped grant
**Severity:** Medium — **Mitigation:** Every role being scoped in this
change is a municipality/vendor-side role (`gebruik-beheerder`,
`functioneel-beheerder`, `organisatie-beheerder`, `organisaties-beheerder`,
`gebruik-raadpleger`, `vng-raadpleger`, `software-catalog-users`), never a
role documented elsewhere as a deliberate cross-organisation bypass. The
one role with an existing "unrestricted" contract in the current spec
(`ambtenaar`, per REQ-006's own scenario) is explicitly kept unscoped.
Regression tests assert the caller's own organisation's data is still
fully visible after scoping.

### Risk 3: Deelnemer-shared `gebruik` reads made through a hypothetical
future standard (non-bypassed) RBAC-enabled query path would still be
denied
**Severity:** Low — **Mitigation:** This is the documented, accepted
residual (see Out of Scope). No such standard-API read path exists today —
every current deelnemer read goes through the app-level `deelnames-gebruik`
RBAC-disabled query. If a future change adds one, it must either continue
routing through the app-level pattern or file the OpenRegister
`$contains`-operator issue first.

## Rollback Strategy
Revert the `authorization.read` edits in
`lib/Settings/softwarecatalogus_register.json` and the
`AanbodController::getAanbod()` guard in a single commit revert; both are
additive/targeted JSON and code edits with no schema-shape or data
migration involved, so rollback is a plain `git revert`. No data
migration, no destructive operation, nothing to undo on already-installed
instances beyond re-deploying the reverted register config (subject to the
same #391 import-reliability caveat as the forward fix).

## Open Questions
None — the deelnemer-array residual and the #391 dependency are both
resolved as documented decisions above, not open questions.
