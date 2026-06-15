# contract-decision-delegation (delta)

This change MODIFIES the delegation MECHANISM of three requirements in the
`contract-decision-delegation` capability — switching from a server-side HTTP
path (`IClientService` POST to `POST /api/v1/decisions` + outcome push/poll) to
decidesk's in-process `IEventDispatcher` event contract
(`DecisionRequestedEvent` / `DecisionConcludedEvent`, merged
`decidesk-decision-events`). The domain boundary, the `contract` schema /
projection fields, the nav surface, and the fail-closed "`Actief` only via an
`approved` outcome" rule are unchanged (REQ-SCCD-001, REQ-SCCD-006 untouched).

## MODIFIED Requirements

### Requirement: REQ-SCCD-002 — The system SHALL delegate the contract approval and renewal decision to decidesk by dispatching an in-process DecisionRequestedEvent

The system SHALL, when a user submits a contract for approval (an
`In onderhandeling` contract) or for renewal (an expiring/`Verlopen` contract),
raise a Decision in decidesk by dispatching
`OCA\Decidesk\Event\DecisionRequestedEvent` through `IEventDispatcher` — never a
hard-coded HTTP URL and never a server-side HTTP client — with
`decisionType: contract` (approval) or `decisionType: contract-renewal`
(renewal) and the provenance fields `sourceApp: softwarecatalog`,
`subjectRegister: voorzieningen`, `subjectSchema: contract`, `subjectId`,
`subjectLabel`, `externalReference` (the `contractNummer`), and
`correlationId`. After dispatch the system SHALL read `isHandled()` and
`getDecisionId()` off the event; on a handled, non-null id it SHALL persist the
returned decision id to `contract.approvalDecisionId` and set
`contract.approvalState` to `pending`.

#### Scenario: Submitting a contract for approval dispatches a decidesk decision request

- @e2e exclude cross-app backend contract — the DecisionRequestedEvent dispatch shape + provenance fields are verified by ContractApprovalServiceTest (PHPUnit) and the merged decidesk decision-events contract; an end-to-end raise requires decidesk installed
- **GIVEN** an `In onderhandeling` contract and the decidesk event contract installed
- **WHEN** a user clicks "Submit for approval" on the contract
- **THEN** softwarecatalog dispatches a `DecisionRequestedEvent` with `decisionType: contract` and the provenance fields populated from the contract object
- **AND** the decision id read back from the handled event is stored on `contract.approvalDecisionId`
- **AND** `contract.approvalState` becomes `pending` while `status` stays `In onderhandeling`

#### Scenario: Submitting a renewal uses contract-renewal

- @e2e exclude cross-app backend contract — the decisionType=contract-renewal dispatch is verified by PHPUnit + the decidesk decision-events contract, not a UI flow
- **GIVEN** an expiring or `Verlopen` contract
- **WHEN** a user clicks "Submit renewal"
- **THEN** softwarecatalog dispatches a `DecisionRequestedEvent` with `decisionType: contract-renewal` and the same provenance fields
- **AND** the request is delivered in-process via `IEventDispatcher`, not a hard-coded URL or HTTP client

### Requirement: REQ-SCCD-004 — The system SHALL project the decidesk outcome onto the contract via a DecisionConcludedEvent listener as the source of the approval transition

The system SHALL consume the decidesk outcome by registering a listener for
`OCA\Decidesk\Event\DecisionConcludedEvent` that filters
`getSourceApp() === 'softwarecatalog'`, resolves the contract from the carried
`subjectId` (falling back to `externalReference`) with an IDOR check against the
stored `approvalDecisionId`, and SHALL project it idempotently: an `approved`
outcome (`getStatus() === 'approved'`) sets `approvalState = approved` and
transitions `status` to `Actief`; a `rejected` or `withdrawn` outcome sets
`approvalState = rejected` and leaves `status` as `In onderhandeling`. The
`In onderhandeling → Actief` transition SHALL occur only as a projection of an
`approved` decidesk outcome. No HTTP outcome-callback endpoint and no daily
reconcile poll SHALL be used.

#### Scenario: Approved decision activates the contract

- @e2e exclude server-side projection — the approved -> approvalState=approved + status=Actief idempotent projection is verified by PHPUnit; the In onderhandeling -> Actief transition is applied server-side by the listener, not via a UI click
- **GIVEN** a contract with `approvalState = pending` and a decidesk decision id
- **WHEN** decidesk dispatches a `DecisionConcludedEvent` with `status: approved` and `sourceApp: softwarecatalog`
- **THEN** `contract.approvalState` becomes `approved`
- **AND** `contract.status` transitions to `Actief`
- **AND** re-receiving the same approved outcome is a no-op (idempotent)

#### Scenario: Rejected decision leaves the contract in negotiation

- @e2e exclude server-side projection — the rejected/withdrawn -> approvalState=rejected with status unchanged path is verified by PHPUnit; status is never forced to Actief on a rejecting outcome
- **GIVEN** a contract with `approvalState = pending`
- **WHEN** decidesk dispatches a `DecisionConcludedEvent` with `status: rejected` or `status: withdrawn`
- **THEN** `contract.approvalState` becomes `rejected`
- **AND** `contract.status` stays `In onderhandeling`

#### Scenario: Outcome for another source app is ignored

- @e2e exclude cross-app filter — the getSourceApp() filter is verified by the listener guard + PHPUnit; a foreign source app must never mutate a catalog contract
- **GIVEN** a `DecisionConcludedEvent` whose `sourceApp` is not `softwarecatalog`
- **WHEN** the listener receives it
- **THEN** no contract is loaded or projected

### Requirement: REQ-SCCD-005 — The system SHALL surface the approval state read-only on ContractDetail without changing the Contracts nav surface

The system SHALL add a read-only Approval panel to the `ContractDetail` page
showing `approvalState`, the decidesk decision reference, and a "Submit for
approval"/"Submit renewal" action that is hidden when the decidesk event
contract is not available (an "approval delegation not configured" state). The
panel SHALL NOT offer a manual outcome-refresh action — the outcome is projected
automatically by the `DecisionConcludedEvent` listener. The system SHALL NOT
move, rename, or unroute the `Contracten` nav entry (`order: 40`), the
`Contracten` index page, or the `ContractDetail` page.

#### Scenario: Approval panel shows projected state and submit action

- @e2e tests/e2e/spec-coverage/contract-approval-panel.spec.ts
- **GIVEN** a `ContractDetail` page for an `In onderhandeling` contract and the decidesk event contract installed
- **WHEN** the user opens the page
- **THEN** the Approval panel shows `approvalState` and a "Submit for approval" action
- **AND** the `Contracten` nav entry and the contract pages remain in place and routable

#### Scenario: Approval action hidden when delegation is not configured

- @e2e tests/e2e/spec-coverage/contract-approval-panel.spec.ts
- **GIVEN** an instance where the decidesk event contract is not installed
- **WHEN** the user opens a `ContractDetail` page
- **THEN** the Approval panel shows an "approval delegation not configured" state
- **AND** the "Submit for approval" action is hidden so no fail-open path exists
