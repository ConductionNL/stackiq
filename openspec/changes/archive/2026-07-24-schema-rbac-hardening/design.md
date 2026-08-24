# Design: schema-rbac-hardening

## Architecture Overview
This change touches exactly one config file's RBAC rules and one
controller's entry guard — no new services, no new endpoints, no new data
model. `lib/Settings/softwarecatalogus_register.json` declares, per schema,
an `authorization.read` array evaluated by OpenRegister's
`PropertyRbacHandler` → `ConditionMatcher` → `OperatorEvaluator` chain for
every read that goes through OpenRegister's **standard**, RBAC-enabled
object API (i.e. any read that does *not* pass `_rbac: false`). Each entry
in that array is either:

- a bare role/group string → unconditional grant to any member of that
  Nextcloud group, regardless of organisation, or
- `{"group": "<role>", "match": {<field>: <value>}}` → grant scoped to
  members of that group **for whom the match condition against the
  object's own fields holds**.

`ConditionMatcher::getObjectValue()` reads only a **direct property of the
object being evaluated** (or its `@self.<x>` counterpart for
underscore-prefixed keys) — there is no dot-path traversal into a related
object's fields, and `OperatorEvaluator` has no array-membership operator.
Both of these are load-bearing constraints for the decisions below.

## Goals / Non-Goals
**Goals:**
- Every bare, unscoped role currently granted `read` on `gebruik`,
  `koppeling`, `organisatie`, `contract` becomes either match-scoped to the
  caller's own organisation, or is kept bare with an explicit, written
  justification.
- `AanbodController::getAanbod()` gets the same explicit
  `getUser() === null` guard REQ-004 already established.
- The one case that genuinely cannot be expressed as a schema-RBAC match
  condition (deelnemer/array-membership sharing on `gebruik`) is documented
  as an accepted residual, not silently dropped.

**Non-Goals:**
- Extending OpenRegister's `ConditionMatcher`/`OperatorEvaluator` with a
  `$contains` operator or relation-traversal match paths.
- Changing any app-level controller/service scoping logic that already
  works correctly (`GebruikController::applyAanbodScopeToOptions()`,
  `AangebodenGebruikService`, `deelnames-gebruik`).
- Making the fix take effect on an already-installed instance — that is
  `register-import-reliability`'s (#391) job; see Decision 5.

## Decisions

### Decision 1 — `gebruik`: scope `gebruik-beheerder` on both `_organisation` and `afnemer`
`gebruik` has two direct fields expressing organisation ownership:
`_organisation` (the OR multitenancy stamp — the org whose session created/
saved the object) and `afnemer` (a `related-object` field pointing at the
consuming organisation, `required: true`). Both are legitimate "this is my
organisation's gebruik record" signals, and REQ-003's own scenario
("Municipality user still sees their own organisation's full gebruik set")
requires both — a `gebruik-beheerder` must see gebruik they created *and*
gebruik where their org is the recorded afnemer even when a different
session created it. So the bare `"gebruik-beheerder"` string becomes two
match-scoped entries:
```json
{"group": "gebruik-beheerder", "match": {"_organisation": "$organisation"}},
{"group": "gebruik-beheerder", "match": {"afnemer": "$organisation"}}
```
`aanbieder` is deliberately **not** added as a third `gebruik-beheerder`
leg — `aanbieder`-side visibility is `aanbod-beheerder`'s scope (REQ-002),
already present and untouched.

**Alternative considered:** scope only on `_organisation` and rely on the
app's own `GebruikController` code path for the `afnemer` case. Rejected —
the whole point of this change is that schema RBAC must not depend on every
future caller going through the app-level bypass; the schema rule itself
must be correct standalone (this is exactly the gap #379 flags).

### Decision 2 — `koppeling`: scope `gebruik-beheerder` on `_organisation` only
`koppeling` has no `afnemer` field (its only organisation-relation field is
`aanbieder`, already covered by the `aanbod-beheerder` entries). The
context brief's "afnemer-scoped leg... where that is the ownership
relation" therefore does not apply to `koppeling` — there is no consumer-
side field to match on. `gebruik-beheerder`'s bare grant becomes:
```json
{"group": "gebruik-beheerder", "match": {"_organisation": "$organisation"}}
```
This is a strict narrowing (fewer objects visible than before), which is
the correct direction for a security fix; no `koppeling` read path in this
app currently relies on the bare grant (every one bypasses schema RBAC with
`_rbac: false` and does its own scoping — see
`docs/security/vendor-visibility-rbac.md`'s audit table), so this cannot
regress any existing app behaviour.

### Decision 3 — `organisatie`: scope `gebruik-beheerder` on `_organisation` only
Same reasoning as `koppeling`: `organisatie` has no `afnemer` field. Most
active organisaties remain readable regardless of this change via the three
pre-existing `public`-group match rules (`geregistreerdDoor: Leverancier` +
`status: Actief`, `type: Gemeente` + `status: Actief`, `type: Samenwerking`
+ `status: Actief`) — those are unaffected. The `gebruik-beheerder`
narrowing only affects organisatie records that are *not* active/published
(draft, inactief, etc.), which is exactly the class of record that should
not be globally readable by every `gebruik-beheerder` in every other
organisation.

### Decision 4 — `contract`: scope every remaining role except `ambtenaar` and `software-catalog-admins`
`contract`'s only direct organisation-relation field is `_organisation`
(confirmed by `ContractApprovalService::authorizeSubmit()`, which already
uses `_organisation ?? aanbieder` as the sole ownership signal for the
`aanbod-beheerder` IDOR guard — the same field REQ-006 already scopes
`aanbod-beheerder` against). There is no separate field carrying the
"other side" of the contract relationship — `dienst` and `gebruik` are
related-object references, not organisation identifiers, and (per the
`ConditionMatcher` constraint above) cannot be traversed into. So every
role being fixed here gets the identical `_organisation`-scoped pattern:
```json
{"group": "functioneel-beheerder", "match": {"_organisation": "$organisation"}},
{"group": "gebruik-beheerder", "match": {"_organisation": "$organisation"}},
{"group": "vng-raadpleger", "match": {"_organisation": "$organisation"}},
{"group": "software-catalog-users", "match": {"_organisation": "$organisation"}},
{"group": "organisatie-beheerder", "match": {"_organisation": "$organisation"}},
{"group": "organisaties-beheerder", "match": {"_organisation": "$organisation"}},
{"group": "gebruik-raadpleger", "match": {"_organisation": "$organisation"}}
```
Two roles are **deliberately kept bare/global** on `contract`:
- **`ambtenaar`** — REQ-006's own spec scenario ("Counterparty and owner
  retain contract read access") explicitly requires `ambtenaar` to "also
  retain read access regardless of counterparty status." This is a
  pre-existing, spec-locked business rule (civil servants have a
  cross-municipality oversight role throughout this app — see REQ-003's
  "ambtenaar retains the existing unrestricted read"), not something this
  change introduces.
- **`software-catalog-admins`** — this is the app's designated super-user
  group. `SettingsService::createAndConfigureUserGroups()` wires it into
  `setSuperUserGroups(['admin', 'software-catalog-admins'])` alongside the
  Nextcloud `admin` group itself. Scoping the app's own super-user role to
  a single organisation would make it strictly weaker than `admin` (who
  already bypasses OpenRegister RBAC entirely) for no security benefit —
  administrators are expected to see all organisations' data by design.

`software-catalog-users` is **not** treated as global — despite the
superficially similar name, `SettingsService` documents it as "General
software catalog users" and wires it via `setGenericUserGroups()`, not
`setSuperUserGroups()`. It is the baseline group every authenticated user
gets, so leaving it bare was the actual bug: any authenticated user of the
app (not just an elevated role) could read every organisation's contracts.

### Decision 5 — `AanbodController::getAanbod()`: copy the REQ-004 guard verbatim
`AanbodService::getAanbod()` already field-scopes correctly using
`getCurrentOrganisation()` per-schema (`afnemer` for gebruik, `aanbieder`
for koppeling/module/dienst) — an anonymous caller already gets the empty
envelope today because `currentOrg` resolves to `null`. This is not a live
leak, but it is the same implicit-invariant anti-pattern REQ-004 already
eliminated once. Add the identical explicit guard at the top of the
controller method, before the service is invoked at all, so the safety
property is visible at the entry point and does not depend on a downstream
helper's null-handling remaining correct forever.

### Decision 6 — the `deelnemers`-array leg stays an accepted, documented residual
`gebruik.deelnemers` (and `organisatie.deelnemers`) are arrays of related
organisations. `OperatorEvaluator` supports only `$eq/$ne/$in/$nin/$exists/
$gt/$gte/$lt/$lte` — there is no operator that asks "does the caller's
organisation UUID appear anywhere in this array field." A match condition
like `{"deelnemers": "$organisation"}` would never match (the stored value
is an array, not a scalar equal to the organisation UUID), and `$in`/`$nin`
compare the *object's* value against a fixed list, not the reverse
(list-contains-scalar) — the operand direction needed here does not exist
in the evaluator today.

**Decision: do not invent an operator here.** This is exactly the shape of
gap `deelnames-gebruik`'s spec already documents and already handles
correctly at the **application** layer:
`AangebodenGebruikController::getGebruiksWhereDeelnemers()` /
`ViewService::getDeelnamesGebruikData()` query with `_rbac: false` and
hard-filter `deelnemers => currentOrg` **from the caller's own session**
(never client-supplied) — REQ-005 already locks this behaviour in and this
change adds a regression test confirming it still works after the schema
edits. Because the deelnemer path never goes through the standard
RBAC-enabled object API, the schema-RBAC gap does not create a live leak
today. It becomes a real gap only if a **future** code path reads `gebruik`
through the standard API without its own scoping — documented explicitly
here and in `docs/security/vendor-visibility-rbac.md` so that future change
does not "discover" the same gap from scratch. If a `$contains` operator
is ever judged necessary, it must be proposed as an OpenRegister issue and
referenced from here — not implemented as part of this change (out of
scope per the proposal).

## Risks / Trade-offs
- [Risk] Narrowing a previously-bare grant could hide data from a role that
  (undocumented) actually needed the unscoped view → [Mitigation] every
  role scoped here is a municipality/vendor-side role with no documented
  cross-organisation mandate anywhere in the existing specs; the one role
  with such a documented mandate (`ambtenaar`) is explicitly excluded.
  Regression tests assert the caller's own organisation's data remains
  fully visible.
- [Risk] The deelnemer residual (Decision 6) could be read as "not really
  fixed" → [Mitigation] documented explicitly in three places (proposal,
  this design, `docs/security/vendor-visibility-rbac.md`) with the concrete
  reason it is not a live leak today, and a regression test locking in that
  the app-level bypass path continues to work.
- [Risk] This fix has zero runtime effect on any installed instance until
  #391 lands → [Mitigation] see Migration Plan below; called out three
  times (proposal Risk 1, here, and the docs update) so it cannot be missed
  during review or rollout planning.

## Migration Plan
No Nextcloud `lib/Migration/` class — this is a register/schema **config**
change, not a database schema change (ADR-001: no custom tables). The
config is imported by `ConfigurationService::importFromApp()` in the app's
repair step.

**Deploy:**
1. Ship this change's JSON edits together with, or strictly after,
   `register-import-reliability` (#391) — today, the repair-step importer
   silently no-ops when a register/schema it has already imported once is
   edited again, so deploying this change alone to an already-installed
   instance changes nothing at runtime.
2. On an instance with #391 applied, trigger `occ upgrade` (or the app's
   repair step) and verify live that the deployed `authorization.read`
   rules for `gebruik`/`koppeling`/`organisatie`/`contract` match this
   change's JSON — e.g. via OpenRegister's schema inspection API/UI, not
   just by re-reading the source file.
3. Fresh installs are unaffected by the #391 caveat — a first-time import
   always applies the full config.

**Rollback:** plain `git revert` of the JSON + controller edits (see
proposal's Rollback Strategy) — no data to migrate back, no destructive
step.

## Open Questions
None — resolved as documented decisions above.
