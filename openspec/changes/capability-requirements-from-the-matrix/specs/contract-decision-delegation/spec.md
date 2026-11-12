## ADDED Requirements

### Requirement: REQ-SCCD-007 A contract becomes Active only through an approved decision

Stackiq SHALL refuse a create or an edit that sets a contract's status to Active while its `approvalState` is not `approved`. The approved outcome of the decision SHALL remain the only way a contract becomes Active.

#### Scenario: A user cannot create an Active contract

- **GIVEN** an application owner on the Contracts page
- **WHEN** they create a contract with status Active
- **THEN** the save is refused with a message that an approval decision is needed

#### Scenario: An approved decision makes the contract Active

- **GIVEN** a contract In negotiation with a pending decision
- **WHEN** the decision is approved
- **THEN** the contract status reads Active
