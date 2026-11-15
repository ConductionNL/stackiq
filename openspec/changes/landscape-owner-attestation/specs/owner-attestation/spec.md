# owner-attestation specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- landscape-owner-attestation

## Purpose

Owners confirm or correct their catalogue entries in rounds an information manager starts and follows. Matrix row `stackiq:land-data-quality-survey`.

## ADDED Requirements

### Requirement: REQ-OAT-001 An information manager starts a confirmation round for a scope

An organisation admin SHALL start a round for the organisation's usages or for its products, with a deadline. Stackiq SHALL create one request per entry and owner, resolved to a Nextcloud user, and SHALL report the entries that have no owner with an account.

#### Scenario: A round over the applications in use
@e2e tests/e2e/workflows/owner-attestation.spec.ts

- **GIVEN** a municipality with four usages that have owners and one usage without
- **WHEN** its information manager starts a confirmation round for applications in use with a deadline in two weeks
- **THEN** the round shows four pending requests
- **AND** it lists the one usage without an owner under unassigned

### Requirement: REQ-OAT-002 Each owner is notified and reminded

Stackiq SHALL notify an owner in Nextcloud and by email when a request for them is created, and SHALL remind them three days before the deadline while the request is pending.

#### Scenario: An application owner gets the request
@e2e exclude Delivered by OpenRegister's notification engine; tests/Unit/Settings/OwnerAttestationFragmentTest.php asserts the rule, its trigger and its field recipient.

- **GIVEN** a round with a request for application owner Anna
- **WHEN** the request is created
- **THEN** Anna receives a Nextcloud notification naming the entry

### Requirement: REQ-OAT-003 An owner confirms or corrects each entry

An owner SHALL confirm an entry from My confirmation requests, which sets the entry's `lastConfirmedAt` and marks the request confirmed. When the owner edits and saves the entry instead, the request SHALL be marked corrected. An edit by someone else SHALL NOT answer the owner's request. A request still pending after the deadline SHALL become overdue.

#### Scenario: Confirming from the list
@e2e tests/e2e/workflows/owner-attestation.spec.ts

- **GIVEN** Anna has a pending request for the usage of application X
- **WHEN** she opens My confirmation requests and clicks Confirm
- **THEN** the request reads confirmed
- **AND** the usage shows today as last confirmed

#### Scenario: Correcting by editing
@e2e exclude Listener behaviour; tests/Unit/Listener/AttestationCorrectionListenerTest.php covers the owner's edit and a colleague's edit with the real event class.

- **GIVEN** Anna has a pending request for the usage of application X
- **WHEN** she changes its version and saves
- **THEN** the request reads corrected

### Requirement: REQ-OAT-004 The round shows who answered and who is overdue

The round page SHALL show how many requests are pending, confirmed, corrected and overdue, and list the requests grouped by status with their owners.

#### Scenario: Following up overdue owners
@e2e tests/e2e/workflows/owner-attestation.spec.ts

- **GIVEN** a round past its deadline with one request still pending
- **WHEN** the overdue job has run and the information manager opens the round
- **THEN** the round shows one overdue request with its owner
