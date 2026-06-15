# Design: softwarecatalog-delegation-via-events

## Context

`softwarecatalog-contracts-to-decidesk` delegated the contract approval/renewal
decision to decidesk over a server-side HTTP path. That path is broken: the
decidesk endpoint it targeted (`POST /apps/decidesk/api/v1/decisions`) is
`#[NoAdminRequired]` and therefore requires a user session, which a server-side
`IClientService` request does not carry → 401 → fail-closed throw → delegation
never reaches decidesk. The outcome callback + daily reconcile poll existed only
to read results back over the same broken mechanism.

decidesk's merged `decidesk-decision-events` change replaces this with a typed,
synchronous, in-process event contract. This change adopts it.

## Decision

Use `IEventDispatcher` end-to-end. No HTTP, no polling, no callback endpoint.

### Raise — DecisionRequestedEvent (consumer → decidesk)

`ContractApprovalService::submitForApproval()`:

1. `class_exists(\OCA\Decidesk\Event\DecisionRequestedEvent::class)` — false →
   throw (fail closed). This replaces the old "resolve endpoint or throw".
2. Construct the event (positional ctor args):
   `(sourceApp='softwarecatalog', subjectRegister='voorzieningen',
   subjectSchema='contract', subjectId=$contractUuid, subjectLabel,
   decisionType='contract'|'contract-renewal', actorId='',
   payload=['title'=>label], externalReference=$contractNummer,
   correlationId=$contractUuid)`.
3. `$this->eventDispatcher->dispatchTyped($event)` (synchronous).
4. `$event->isHandled() === false` OR `getDecisionId()` null/blank → throw
   (fail closed; contract stays `In onderhandeling`, `approvalState` unchanged).
5. Persist `approvalDecisionId = getDecisionId()`, `approvalState = pending`.
   `status` is NOT touched.

### Project — DecisionConcludedEvent (decidesk → consumer)

`DecisionConcludedListener` (registered for `DecisionConcludedEvent::class`,
fires only when decidesk is installed):

1. `getSourceApp() !== 'softwarecatalog'` → ignore.
2. `resolveContractForOutcome(subjectId, externalReference, decisionId)` —
   prefers `subjectId` when the IDOR guard `isDecisionForContract()` matches
   the stored `approvalDecisionId`; otherwise finds a contract whose
   `approvalDecisionId === decisionId` AND `contractNummer === externalReference`.
   No match → no-op (logged).
3. `projectOutcome(contractUuid, getStatus())` — `approved` →
   `approvalState=approved` + `status=Actief` (the single Actief-set site,
   idempotent); `rejected`/`withdrawn` → `approvalState=rejected`, status
   unchanged; `pending`/other → no-op.

The listener swallows its own exceptions (logs) so a projection failure never
breaks decidesk's dispatch chain and never advances the contract.

### Removed

- `resolveDecisionEndpoint`, `subscribeToOutcome`, `pollOutcomeStatus`,
  `refreshOutcome`, `reconcilePendingApprovals` (service).
- `IClientService`, `IURLGenerator`, `IAppManager` ctor deps (service).
- `ContractApprovalReconcileJob` (+ `info.xml` `<job>` + DI registration).
- `contractApproval#refresh`, `contractApproval#outcomeCallback` routes + the
  controller `refresh()` / `outcomeCallback()` methods.
- ContractDetail panel "Refresh outcome" button + `refresh()` method (outcomes
  now arrive automatically via the listener).

## Fail-closed invariant (unchanged)

`status: Actief` is reachable ONLY through `projectOutcome('approved')`, called
ONLY from the conclusion listener, fired ONLY by decidesk. No local-authority
path sets `Actief`. When decidesk is absent, submit throws and nothing is
projected.

## Why an event, not the integration registry (ADR-019 HTTP)

decidesk's authoritative cross-app surface for decisions is now the typed
event (it is what `decidesk-decision-events` ships and what other consumers —
shillinq, procest — use). The HTTP endpoints remain session-bound and
unusable server-side; the event is the supported in-process mechanism.
