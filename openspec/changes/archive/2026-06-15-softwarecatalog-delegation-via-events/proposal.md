---
kind: refactor
depends_on: []
---

# Proposal: softwarecatalog-delegation-via-events

kind: refactor — switches the contract-approval delegation MECHANISM (not the
domain boundary) from a broken server-side HTTP path to decidesk's merged
in-process `IEventDispatcher` event contract. Cites **ADR-019** (cross-app via
the integration mechanism, not hard-coded HTTP), cross-app interface contract
#1 ("Decisions / contracts / approvals → decidesk"), and the merged
`decidesk-decision-events` change.

## Summary

The `softwarecatalog-contracts-to-decidesk` change correctly drew the domain
boundary (contract RECORD stays in softwarecatalog; the approval/renewal
DECISION delegates to decidesk; `status: Actief` is reached only as a
projection of an `approved` decidesk outcome). But it wired delegation over a
server-side HTTP path that does NOT work:

- `ContractApprovalService::resolveDecisionEndpoint()` resolved decidesk's
  decision endpoint via `IURLGenerator::linkToRoute('decidesk.integration.create')`
  and posted to `POST /apps/decidesk/api/v1/decisions` with `IClientService`.
- That decidesk endpoint is `#[NoAdminRequired]` — it needs an authenticated
  user session. A server-side `IClientService` HTTP client carries no session,
  so the call returns 401. The service fails closed (throws) and the contract
  never actually delegates: delegation was "safe" only because it never
  reached decidesk.
- A `ContractApprovalReconcileJob` daily poll + an `outcomeCallback` HTTP push
  endpoint existed purely to read the outcome back over the same broken HTTP
  mechanism.

decidesk now exposes an **in-process event contract** (merged
`decidesk-decision-events`): a consumer dispatches
`OCA\Decidesk\Event\DecisionRequestedEvent` via `IEventDispatcher`, decidesk's
synchronous listener writes back `isHandled()` / `getDecisionId()`, and decidesk
dispatches `OCA\Decidesk\Event\DecisionConcludedEvent` when the Decision
concludes. No HTTP, no session problem, no polling.

This change switches softwarecatalog to that contract and removes the dead HTTP
machinery. The domain boundary, the fields, the fail-closed semantics, and the
"`Actief` only via an `approved` outcome" rule are all unchanged.

## What changes

- **Dispatch (raise):** `ContractApprovalService::submitForApproval()` now
  builds a `DecisionRequestedEvent` (`sourceApp: softwarecatalog`,
  `subjectRegister: voorzieningen`, `subjectSchema: contract`, `subjectId`,
  `subjectLabel`, `decisionType: contract|contract-renewal`,
  `externalReference: contractNummer`, `correlationId: subjectId`,
  `payload.title`), dispatches it via `IEventDispatcher::dispatchTyped()`, then
  reads `isHandled()` / `getDecisionId()`. It is guarded by
  `class_exists(\OCA\Decidesk\Event\DecisionRequestedEvent::class)` →
  **fail-closed** (throws, contract stays `In onderhandeling`) when decidesk is
  absent or the listener did not handle the request.
- **Listen (project):** a new `DecisionConcludedListener` (registered in
  `lib/AppInfo/Application.php`) filters `getSourceApp() === 'softwarecatalog'`,
  resolves the contract from the carried `subjectId` (falling back to
  `externalReference`) with an IDOR check against the stored
  `approvalDecisionId`, and projects the outcome onto `approvalState` / `status`
  — `status: Actief` ONLY when `getStatus() === 'approved'` (the single
  Actief-set site, idempotent).
- **Removed (dead HTTP machinery):** `resolveDecisionEndpoint()`,
  `subscribeToOutcome()`, `pollOutcomeStatus()`, `refreshOutcome()`,
  `reconcilePendingApprovals()`, the `IClientService` / `IURLGenerator`
  dependencies, the `ContractApprovalReconcileJob` background job (and its
  `info.xml` registration), the `contractApproval#refresh` /
  `contractApproval#outcomeCallback` routes + controller methods, and the
  ContractDetail "Refresh outcome" button.

## Out of scope

- No change to the `contract` schema, the `approvalDecisionId` / `approvalState`
  fields, the `BackfillContractApprovalState` repair step, the `Contracten` nav
  surface, or contract record CRUD.
- decidesk's side of the contract is owned by `decidesk-decision-events`.
