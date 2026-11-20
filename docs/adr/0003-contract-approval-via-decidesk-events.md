# 3. Delegate contract approval to Decidesk through in-process events

## Status

Accepted

## Context

Contract approval and renewal decisions are taken in the Decidesk app. An earlier implementation called a Decidesk HTTP endpoint from the server, but that endpoint requires a user session, so the server-side request was rejected and the delegation never arrived. The outcome callback and the reconcile poll depended on the same broken path. Decidesk now offers a typed, synchronous, in-process event contract.

## Decision

Use the Nextcloud event dispatcher end to end, with no HTTP, polling or callback endpoint.

- Submitting a contract for approval dispatches Decidesk's `DecisionRequestedEvent`. If Decidesk is not installed, the event is not handled, or no decision id comes back, the submission fails closed and the contract is left unchanged.
- A listener for Decidesk's `DecisionConcludedEvent` projects the outcome onto the matching contract. Only an approved outcome sets the contract status to active; the listener logs and swallows its own errors so it never breaks Decidesk's dispatch chain.

## Consequences

- A contract can become active only through an approval concluded in Decidesk.
- Without Decidesk, approval submission is refused rather than silently approved.
- The code is in `lib/Service/ContractApprovalService.php` and `lib/EventListener/DecisionConcludedListener.php`.

Source: `openspec/changes/archive/2026-06-15-softwarecatalog-delegation-via-events/design.md` (Decision)
