---
kind: code
depends_on: []
---

# softwarecatalog — contract approval per-object ownership guard (IDOR fix)

## Why

`ContractApprovalController` guards `submit()` / `submitRenewal()` with only
`#[NoAdminRequired]` plus a "logged in" check
(`lib/Controller/ContractApprovalController.php:108-137`, `:150-152`). Neither
method verifies that the calling user's organisation actually owns the
`contractUuid` being submitted. Any authenticated user on the instance can
raise a decidesk approval decision — and, on the approved outcome,
transition a contract's `status` to `Actief` — for **any** contract uuid,
including contracts belonging to a different organisation.

The underlying service compounds this: `ContractApprovalService::loadContract()`
(`lib/Service/ContractApprovalService.php:465-496`) calls
`ObjectService::find(..., _rbac: false, _multitenancy: false)` — it explicitly
**disables** OpenRegister's own RBAC and multitenancy guards when reading the
contract, and `persistContract()` (`:508-522`) writes back through
`saveObject()` with no RBAC flag at all, relying on nothing but the
already-absent controller-level check. There is no per-object ownership
check anywhere on this call path.

This is the exact IDOR shape (OWASP A01:2021) that
`PublicationController::authorizeEntry()`
(`lib/Controller/PublicationController.php:144-202`) was built to close for
publish/depublish one change earlier (`open-data-publishing`,
2026-06-14) — admin OR an `aanbod-beheerder` whose organisation matches
`_organisation`/`aanbieder` on the entry. `ContractApprovalController` never
adopted the same guard for the contract-approval seam, even though its own
docblock claims fail-closed behaviour and the *decision* side already has an
IDOR guard (`ContractApprovalService::isDecisionForContract()`,
`:238-265`, used when *projecting* a concluded outcome). The gap is
specifically on the **submit** path, not the projection path.

## What Changes

- Add a per-object ownership guard to `ContractApprovalController::submit()`
  and `::submitRenewal()`, mirroring `PublicationController::authorizeEntry()`:
  admin group membership OR an `aanbod-beheerder` whose active organisation
  (`_organisation`) matches the contract's owning organisation field. Refuse
  with 403 otherwise, before any decidesk event is dispatched.
- Load the contract for the ownership check via OpenRegister's normal RBAC
  path (drop the explicit `_rbac: false, _multitenancy: false` override in
  `ContractApprovalService::loadContract()` for the submit path, or add an
  equivalent explicit ownership check callable from the controller if the
  RBAC-enabled read cannot resolve the owning organisation field directly).
- Unit test coverage: a non-owning authenticated user's `submit`/`submitRenewal`
  call MUST return 403 and MUST NOT dispatch `DecisionRequestedEvent`; the
  owning organisation's `aanbod-beheerder` and any `admin` MUST still succeed.
- No change to the projection path (`DecisionConcludedListener` /
  `isDecisionForContract`) — that IDOR guard already exists and is unaffected.

Not BREAKING for legitimate callers: an owning user's request shape and
response envelope are unchanged; only cross-organisation submission attempts
now fail (previously succeeded).
