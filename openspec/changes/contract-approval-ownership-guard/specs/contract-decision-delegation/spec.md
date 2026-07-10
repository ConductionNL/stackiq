## MODIFIED Requirements

### Requirement: Contract approval submission MUST be authorized per-object
`ContractApprovalController::submit()` and `::submitRenewal()` MUST verify
that the calling user is either an instance admin, or an `aanbod-beheerder`
whose active organisation matches the target contract's owning organisation
field (`_organisation`, falling back to `aanbieder`), before dispatching a
`DecisionRequestedEvent`. Authentication alone (`#[NoAdminRequired]` + a
logged-in check) is NOT sufficient — every submission MUST pass a per-object
ownership guard, mirroring `PublicationController::authorizeEntry()`.

#### Scenario: Non-owning authenticated user submits a contract for approval
- GIVEN a contract owned by organisation A
- AND a user who is authenticated, not an instance admin, and whose active
  organisation is B (or who holds no `aanbod-beheerder` role)
- WHEN `POST /api/contracts/{contractUuid}/approval/submit` is called for
  the contract owned by A
- THEN the response MUST have status 403
- AND `IEventDispatcher::dispatchTyped()` MUST NOT be called
- AND the contract's `status` and `approvalState` MUST be unchanged

#### Scenario: Non-owning authenticated user submits a contract renewal
- GIVEN the same non-owning user as above
- WHEN `POST /api/contracts/{contractUuid}/approval/renewal` is called for
  the contract owned by A
- THEN the response MUST have status 403
- AND no decidesk decision MUST be raised

#### Scenario: Owning aanbod-beheerder submits a contract for approval
- GIVEN a contract owned by organisation A
- AND a user in group `aanbod-beheerder` whose active organisation is A
- WHEN `POST /api/contracts/{contractUuid}/approval/submit` is called
- THEN the response MUST have status 200
- AND the response body MUST contain `decisionId` and `approvalState=pending`
- AND `DecisionRequestedEvent` MUST be dispatched exactly once

#### Scenario: Instance admin submits any contract for approval
- GIVEN a contract owned by any organisation
- AND a user in group `admin`
- WHEN `POST /api/contracts/{contractUuid}/approval/submit` is called
- THEN the response MUST have status 200 (ownership check is bypassed for admins,
  matching `PublicationController`'s admin bypass)
