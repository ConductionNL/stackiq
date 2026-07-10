# Tasks — contract-approval-ownership-guard

## 1. Ownership guard in the service

- [ ] 1.1 Add `ContractApprovalService::authorizeSubmit(string $contractUuid, \OCP\IUser $user, array $groupNames): bool`
  (or equivalent) that loads the contract, resolves its owning organisation
  field (mirror `PublicationController::authorizeEntry()` — check `_organisation`
  then `aanbieder` fallback), and returns true only when `$groupNames` contains
  `admin`, or contains `aanbod-beheerder` AND the caller's active organisation
  (`IConfig::getUserValue($uid, 'core', 'organisation')`) matches the owning
  organisation field.
- [ ] 1.2 Change `ContractApprovalService::loadContract()` used by the submit
  path to resolve via OpenRegister's normal RBAC (`_rbac: true` or the
  service default) rather than the current `_rbac: false, _multitenancy: false`
  override, OR — if the owning-organisation field is not visible under RBAC
  for a non-owning caller by design — keep the unguarded read strictly for
  the ownership *check* itself and never for the subsequent
  dispatch/persist path once ownership fails.
- [ ] 1.3 `submitForApproval()` MUST throw (or the controller MUST short-circuit
  before calling it) when the ownership check fails — no `DecisionRequestedEvent`
  is dispatched for an unauthorized submitter.

## 2. Controller guard

- [ ] 2.1 In `ContractApprovalController`, inject `IGroupManager` and `IConfig`
  (same pattern as `PublicationController`).
- [ ] 2.2 Add a private `authorizeContract(string $contractUuid): ?JSONResponse`
  helper, structurally mirroring `PublicationController::authorizeEntry()`:
  401 if not logged in, 403 via the service ownership check otherwise.
- [ ] 2.3 Call `authorizeContract()` at the top of `submit()` and
  `submitRenewal()` (or inside the shared `raise()` private method), before
  `approvalService->submitForApproval()` is invoked.
- [ ] 2.4 Update the class-level docblock to describe the ownership guard
  (matching `PublicationController`'s ADR-005 docblock convention) instead of
  only "authenticated-only".

## 3. Tests

- [ ] 3.1 PHPUnit: authenticated non-owning user (no `admin`/`aanbod-beheerder`
  group, or wrong `_organisation`) calling `submit()` gets 403 and
  `IEventDispatcher::dispatchTyped()` is never invoked (mock assertion).
- [ ] 3.2 PHPUnit: owning `aanbod-beheerder` (matching `_organisation`) and
  `admin` both succeed unchanged (existing pending-approval envelope).
- [ ] 3.3 PHPUnit: `submitRenewal()` covered by the same three cases.
- [ ] 3.4 Regression: existing `ContractApprovalService` / `ContractApprovalController`
  tests (if any) still pass with the RBAC-mode change to `loadContract()`.

## 4. Spec + docs

- [ ] 4.1 Update `openspec/specs/contract-decision-delegation/spec.md` (or add
  the delta in this change's `specs/contract-decision-delegation/spec.md`)
  with the ownership-guard requirement and 403 scenario.
- [ ] 4.2 Note in `hydra/openspec/architecture/adr-005-security.md`'s tracked
  incident list (cross-cutting, out of scope for this change — see report) that
  this pattern (fail-closed decision *dispatch* guard) should be checked
  wherever an app raises a cross-app decision-delegation event on an OR object.
