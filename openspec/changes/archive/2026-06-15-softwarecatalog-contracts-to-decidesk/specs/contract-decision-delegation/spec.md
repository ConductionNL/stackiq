# contract-decision-delegation

**Status:** proposed
**Scope:** softwarecatalog
**Tier:** leaf (consumes the decidesk decision hub via the ADR-019 integration registry)
**Depends on:** decidesk decision hub (`POST /api/v1/decisions`, `GET /api/v1/decisions/{id}/outcome`, `POST /api/v1/decisions/{id}/subscriptions`; `decisionType` `contract`/`contract-renewal`); ADR-019 integration registry; ADR-012 deduplication; softwarecatalog `contract` schema + `Contracten`/`ContractDetail` manifest pages; cross-app interface contract #1.

## ADDED Requirements

### Requirement: REQ-SCCD-001 — The system SHALL keep the contract record and its catalog metadata in softwarecatalog

The system SHALL keep the `contract` schema and all of its record/metadata
fields (`dienst`, `gebruik`, `startDatum`, `eindDatum`, `contractNummer`,
`contractType`, `kosten`, `kostenPeriode`, `contactpersoonAanbieder`,
`contactpersoonGebruiker`, `documentReferentie`, `opmerkingen`, `status`) in
the softwarecatalog `voorzieningen_register`, and SHALL keep the `Contracten`
index page (`/contracten`) and `ContractDetail` page (`/contracten/:id`)
routable so the catalog can record which contract governs which
software/`gebruik`/`dienst` and federate/export it with the rest of the
GEMMA/VNG data model. Per ADR-012, this change SHALL NOT duplicate the record
CRUD owned by the `contract-administration` change.

#### Scenario: Contract record stays local and routable

- @e2e exclude record-persistence + federation contract — contract index/detail UI is owned by the contract-administration e2e suite; this scenario asserts no approval workflow is ADDED (a negative, code-review/gate-enforced) plus OR-store/federation persistence covered by Newman/PHPUnit
- **GIVEN** an instance running softwarecatalog with the `contract` schema
- **WHEN** a user opens the `Contracten` index or a `ContractDetail` page
- **THEN** the contract record and all its metadata fields render from the OpenRegister object store
- **AND** the contract continues to federate and export with the catalog data model
- **AND** no contract-approval workflow, controller, or state-machine is added inside softwarecatalog

### Requirement: REQ-SCCD-002 — The system SHALL delegate the contract approval and renewal decision to decidesk via the integration registry

The system SHALL, when a user submits a contract for approval (an
`In onderhandeling` contract) or for renewal (an expiring/`Verlopen` contract),
raise a Decision in decidesk through the ADR-019 integration registry — never a
hard-coded HTTP URL — by calling `POST /api/v1/decisions` with
`decisionType: contract` (approval) or `decisionType: contract-renewal`
(renewal) and the provenance fields `sourceApp: softwarecatalog`,
`subjectRegister: voorzieningen_register`, `subjectSchema: contract`,
`subjectId`, `subjectLabel`, `externalReference` (the `contractNummer`), and
`outcomeCallbackUrl`. The returned decision id SHALL be persisted to
`contract.approvalDecisionId` and `approvalState` SHALL be set to `pending`.

#### Scenario: Submitting a contract for approval raises a decidesk decision

- @e2e exclude cross-app backend contract — the POST /api/v1/decisions call shape + provenance fields are verified by ContractApprovalServiceTest (PHPUnit) and the decidesk decision-hub contract; an end-to-end raise requires the merged decidesk hub
- **GIVEN** an `In onderhandeling` contract and a resolvable decidesk endpoint in the integration registry
- **WHEN** a user clicks "Submit for approval" on the contract
- **THEN** softwarecatalog POSTs `/api/v1/decisions` with `decisionType: contract` and the provenance fields populated from the contract object
- **AND** the returned decision id is stored on `contract.approvalDecisionId`
- **AND** `contract.approvalState` becomes `pending` while `status` stays `In onderhandeling`

#### Scenario: Submitting a renewal uses contract-renewal

- @e2e exclude cross-app backend contract — the decisionType=contract-renewal routing through the integration registry is verified by PHPUnit + the decidesk hub contract, not a UI flow
- **GIVEN** an expiring or `Verlopen` contract
- **WHEN** a user clicks "Submit renewal"
- **THEN** softwarecatalog POSTs `/api/v1/decisions` with `decisionType: contract-renewal` and the same provenance fields
- **AND** the call is routed through the ADR-019 integration registry, not a hard-coded URL

### Requirement: REQ-SCCD-003 — The system SHALL fail closed when decidesk is unavailable and never self-approve a contract

The system SHALL NOT set `contract.status` to `Actief` on its own authority. If
no decidesk endpoint resolves in the integration registry, or the
`POST /api/v1/decisions` call fails, the system SHALL surface a visible error,
leave `status` as `In onderhandeling`, leave `approvalState` unchanged, and
SHALL NOT mark the contract approved or active.

#### Scenario: decidesk unavailable blocks approval

- @e2e exclude fail-closed backend authorization — the throw-and-leave-in-negotiation path is verified by ContractApprovalServiceTest::testSubmitFailsClosedWhenDelegationNotConfigured (PHPUnit); its UI manifestation (submit hidden) is the e2e-covered REQ-SCCD-005 "Approval action hidden when delegation is not configured"
- **GIVEN** an `In onderhandeling` contract and no resolvable decidesk endpoint
- **WHEN** a user clicks "Submit for approval"
- **THEN** the action fails with a visible error
- **AND** `contract.status` remains `In onderhandeling`
- **AND** `contract.approvalState` is not changed and the contract is not marked active

### Requirement: REQ-SCCD-004 — The system SHALL project the decidesk outcome onto the contract as the source of the approval transition

The system SHALL consume the decidesk outcome — via the
`POST /api/v1/decisions/{id}/subscriptions` push to its callback, with a daily
reconcile poll of `GET /api/v1/decisions/{id}/outcome` for any contract whose
`approvalState` is `pending` — and SHALL project it idempotently: an `approved`
outcome sets `approvalState = approved` and transitions `status` to `Actief`; a
`rejected` or `withdrawn` outcome sets `approvalState = rejected` and leaves
`status` as `In onderhandeling`. The `In onderhandeling → Actief` transition
SHALL occur only as a projection of an `approved` decidesk outcome.

#### Scenario: Approved decision activates the contract

- @e2e exclude server-side projection — the approved -> approvalState=approved + status=Actief idempotent projection is verified by PHPUnit + the reconcile job; the In onderhandeling -> Actief transition is applied server-side, not via a UI click
- **GIVEN** a contract with `approvalState = pending` and a decidesk decision id
- **WHEN** decidesk reports the outcome `approved` (via push or the reconcile poll)
- **THEN** `contract.approvalState` becomes `approved`
- **AND** `contract.status` transitions to `Actief`
- **AND** re-receiving the same approved outcome is a no-op (idempotent)

#### Scenario: Rejected decision leaves the contract in negotiation

- @e2e exclude server-side projection — the rejected/withdrawn -> approvalState=rejected with status unchanged path is verified by PHPUnit; status is never forced to Actief on a rejecting outcome
- **GIVEN** a contract with `approvalState = pending`
- **WHEN** decidesk reports the outcome `rejected` or `withdrawn`
- **THEN** `contract.approvalState` becomes `rejected`
- **AND** `contract.status` stays `In onderhandeling`

### Requirement: REQ-SCCD-005 — The system SHALL surface the approval state read-only on ContractDetail without changing the Contracts nav surface

The system SHALL add a read-only Approval panel to the `ContractDetail` page
showing `approvalState`, the decidesk decision reference, and a "Submit for
approval"/"Submit renewal" action that is hidden when no decidesk endpoint
resolves (an "approval delegation not configured" state). The system SHALL NOT
move, rename, or unroute the `Contracten` nav entry (`order: 40`), the
`Contracten` index page, or the `ContractDetail` page.

#### Scenario: Approval panel shows projected state and submit action

- @e2e tests/e2e/spec-coverage/contract-approval-panel.spec.ts
- **GIVEN** a `ContractDetail` page for an `In onderhandeling` contract and a resolvable decidesk endpoint
- **WHEN** the user opens the page
- **THEN** the Approval panel shows `approvalState` and a "Submit for approval" action
- **AND** the `Contracten` nav entry and the contract pages remain in place and routable

#### Scenario: Approval action hidden when delegation is not configured

- @e2e tests/e2e/spec-coverage/contract-approval-panel.spec.ts
- **GIVEN** an instance where no decidesk endpoint resolves in the integration registry
- **WHEN** the user opens a `ContractDetail` page
- **THEN** the Approval panel shows an "approval delegation not configured" state
- **AND** the "Submit for approval" action is hidden so no fail-open path exists

### Requirement: REQ-SCCD-006 — The system SHALL treat contract.approvalState as a projection of the decidesk outcome distinct from the catalog lifecycle status

The system SHALL add `approvalDecisionId` (string) and `approvalState`
(enum `none / pending / approved / rejected`, default `none`) to the `contract`
schema as catalog-local, exportable projection fields, while keeping the
existing `status` enum (`Actief / Verlopen / In onderhandeling`) unchanged.
`approvalState` SHALL mirror the decidesk outcome for the approval/renewal
decision; the date-driven `Actief → Verlopen` expiry transition SHALL remain
catalog-local and is NOT a decision delegated to decidesk.

#### Scenario: Projection fields added without altering the status enum

- @e2e exclude schema delta — the additive approvalDecisionId/approvalState fields, the unchanged status enum, and the idempotent backfill are verified by the register.d fragment + BackfillContractApprovalState repair (idempotent, fail-safe), not a UI flow
- **GIVEN** the `contract` schema
- **WHEN** the change is applied
- **THEN** the schema gains `approvalDecisionId` and `approvalState` (default `none`)
- **AND** the `status` enum still has exactly `Actief`, `Verlopen`, `In onderhandeling`
- **AND** existing contracts are backfilled with `approvalState = none` idempotently without losing data
