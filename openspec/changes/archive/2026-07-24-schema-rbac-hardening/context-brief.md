# Context Brief: schema-rbac-hardening

## What
Close the remaining schema-level RBAC holes left by `vendor-visibility-rbac` (PR #377), and apply the REQ-004 explicit auth guard to the one controller that still relies on implicit null-handling. Closes softwarecatalog **#379**, **#390**, **#378** as ONE change — same fix shape, same file, same tests.

## Why (evidence — code-level investigation 2026-07-24)

### #379 — likely LIVE-EXPLOITABLE, not cleanup
`gebruik`, `koppeling` and `organisatie` schemas each carry a **bare, unscoped `"gebruik-beheerder"`** string in their `authorization.read` list (identical shape to the `contract` bug fixed by REQ-006).

Critically: **there is no `KoppelingController` and no `OrganisatieController` in this app.** Reads of those two schemas go through OpenRegister's generic object API, which runs with `_rbac: true` by default and is gated **solely** by this schema config. So a `gebruik-beheerder` in one municipality can read **every other organisation's** koppelingen and organisaties.

Fixing it is safe: every gebruik/koppeling/organisatie query path in `lib/Service/*.php` explicitly passes `_rbac:false, _multitenancy:false` and does its own PHP-level org scoping, so schema RBAC is currently dead for the app's own routes. (`ViewService::getGebruikData()` does run RBAC-enabled but masks the gap with an explicit `@self.organisation` filter — protected by a query filter, not by RBAC.)

### #390 — same gap, more roles
The "fixed" `contract` schema still lists bare unscoped `gebruik-beheerder`, `ambtenaar`, `functioneel-beheerder`, `organisatie-beheerder`, `organisaties-beheerder`, `gebruik-raadpleger`, `vng-raadpleger`, `software-catalog-admins/users`. REQ-006 only scoped `aanbod-beheerder` and removed `public`.

### #378 — implicit guard
`AanbodController::getAanbod()` is `@PublicPage`/`@NoCSRFRequired` with **no explicit check**; it relies entirely on `AanbodService::getCurrentOrganisation()` returning null for an anonymous session — the exact implicit-guard anti-pattern REQ-004 eliminated in `GebruikController`/`AangebodenGebruikController`.

## Known constraint (must be handled explicitly, not silently)
OpenRegister's `ConditionMatcher`/`OperatorEvaluator` support only `$eq/$ne/$in/$nin/$exists/$gt/$gte/$lt/$lte` — **there is no array-contains operator**. So REQ-003's `deelnemers`-array leg (deelnemer sharing) **cannot** be expressed at the schema-RBAC layer today.
Decision for this change: **document it as an accepted residual** — deelnemer-shared reads stay enforced by the app controllers (`deelnames-gebruik`) — and state it in the spec + design. Do NOT silently drop the deelnemer case, and do NOT invent an operator. If you judge a `$contains` operator is required, file an OpenRegister issue and reference it; do not implement it here.

## Scope
IN:
- `lib/Settings/softwarecatalogus_register.json`: replace bare role strings in `authorization.read` for `gebruik`, `koppeling`, `organisatie`, `contract` with match-scoped entries, e.g. `{"group":"gebruik-beheerder","match":{"_organisation":"$organisation"}}` (plus the `afnemer`-scoped leg for gebruik/koppeling where that is the ownership relation). Keep genuinely-global roles (e.g. `software-catalog-admins`) unscoped ONLY where that is deliberate — state which and why in the design.
- `lib/Controller/AanbodController.php::getAanbod()`: explicit `userSession->getUser() === null` guard returning the empty envelope BEFORE calling the service (mirror `AangebodenGebruikController::getGebruiksWhereAfnemer()`).
- Tests: schema-RBAC-layer tests asserting a `gebruik-beheerder` in org A cannot read org B's `gebruik`/`koppeling`/`organisatie`/`contract`; a controller test asserting the service is `never()` called for an anonymous caller (mirror `AangebodenGebruikControllerTest`); regression tests that the deelnemer/afnemer app-level paths still work.
- Docs: extend `docs/security/vendor-visibility-rbac.md` with the schema-RBAC layer + the documented residual.

OUT: adding a `$contains` operator to OpenRegister; changing app-controller scoping logic; the deelnames sharing model itself.

## Current state (read first)
- `openspec/specs/vendor-visibility-rbac/spec.md` (canonical, from PR #377) — extend this capability rather than forking a new one where the requirements fit.
- `openspec/specs/deelnames-gebruik/spec.md` — the deelnemer bypass that must keep working.
- `docs/security/vendor-visibility-rbac.md` — the route audit produced by #377; both out-of-scope follow-ups it flagged are exactly #378/#379.
- `lib/Controller/AangebodenGebruikController.php` (~lines 97-121) — the REQ-004 guard to copy.

## Design constraints
- **Fail closed / deny-before-grant** — known OR trap (or#2025): a custom-scope veto evaluated AFTER a default-open grant is dead code.
- Register JSON edits: additive/targeted; validate with `python3 -m json.tool` after every edit; do NOT reorder unrelated blocks.
- ⚠️ Depends on softwarecatalog#391 (`register-import-reliability`, sibling change in flight): monolith register edits are currently a **silent no-op on installed instances**. Note this in the proposal — this change's security fix only takes effect once the import actually applies, so it must ship after (or with) that fix and be verified live.
- Security change ⇒ `hydra-gate-security-change-has-tests` will require tests. `hydra-gate-no-admin-idor` / `orphan-auth` apply.
- Spec deltas: `### Requirement: <name>` headers, MUST/SHALL on the FIRST physical line, no angle brackets in bodies.
- `@spec` anchors → canonical `openspec/specs/<capability>/spec.md#requirement-<kebab>`, never a change dir.
