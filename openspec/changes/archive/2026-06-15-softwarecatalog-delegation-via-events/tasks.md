# Tasks: softwarecatalog-delegation-via-events

## 1. Dispatch the request as an in-process event

- [x] 1.1 Inject `OCP\EventDispatcher\IEventDispatcher` into
  `ContractApprovalService`; drop the `IClientService`, `IURLGenerator`, and
  `IAppManager` dependencies.
- [x] 1.2 `isDelegationConfigured()` returns
  `class_exists(\OCA\Decidesk\Event\DecisionRequestedEvent::class)`.
- [x] 1.3 `submitForApproval()` builds a `DecisionRequestedEvent`
  (sourceApp/subjectRegister/subjectSchema/subjectId/subjectLabel/decisionType/
  externalReference/correlationId/payload.title), dispatches it via
  `dispatchTyped()`, and fails closed (throws) when decidesk is absent or
  `isHandled()` is false / `getDecisionId()` is null.
- [x] 1.4 On success persist `approvalDecisionId` + `approvalState=pending`;
  never touch `status`.

## 2. Project the conclusion via a listener

- [x] 2.1 Add `lib/EventListener/DecisionConcludedListener.php` filtering
  `getSourceApp() === 'softwarecatalog'`.
- [x] 2.2 Resolve the contract via `resolveContractForOutcome()` (subjectId,
  then externalReference) with the `isDecisionForContract()` IDOR guard.
- [x] 2.3 Project the outcome via `projectOutcome()` — `status=Actief` only on
  `approved` (the single Actief-set site, idempotent).
- [x] 2.4 Register the listener for `DecisionConcludedEvent::class` in
  `lib/AppInfo/Application.php`.

## 3. Remove the dead HTTP machinery

- [x] 3.1 Delete `resolveDecisionEndpoint`, `subscribeToOutcome`,
  `pollOutcomeStatus`, `refreshOutcome`, `reconcilePendingApprovals` from the
  service.
- [x] 3.2 Delete `lib/BackgroundJob/ContractApprovalReconcileJob.php`, its
  `info.xml` `<job>` entry, and its DI registration.
- [x] 3.3 Remove the `contractApproval#refresh` and
  `contractApproval#outcomeCallback` routes + the controller `refresh()` /
  `outcomeCallback()` methods.
- [x] 3.4 Remove the ContractDetail "Refresh outcome" button + `refresh()`
  method from `ContractApprovalPanel.vue`.
- [x] 3.5 Grep-confirm no remaining `IClientService` POST to decidesk and no
  `api/v1/decisions` URL construction in `lib/`.

## 4. Verify

- [x] 4.1 `php -l` every changed PHP file (clean).
- [x] 4.2 Update `ContractApprovalServiceTest` for the new constructor +
  removed methods; keep the fail-closed assertions.
- [x] 4.3 `openspec validate softwarecatalog-delegation-via-events --strict`.
- [x] 4.4 Run the hydra mechanical gates.
