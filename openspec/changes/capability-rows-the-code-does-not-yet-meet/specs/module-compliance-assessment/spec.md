# module-compliance-assessment specification

## Purpose

A compliance claim reads verified only after someone other than its supplier checks the evidence. Matrix row `stackiq:comp-verified-vs-claimed`.

## ADDED Requirements

### Requirement: A claim reads verified only after a reviewer outside the supplier checks it (REQ-CLR-022)

Attaching evidence SHALL leave a compliance claim claimed. A user with review rights who is not of the supplier's organisation SHALL mark it verified. The matrix SHALL then show the claim as verified, with who verified it and when. Changing the evidence SHALL return the claim to claimed.

#### Scenario: A supplier's own evidence stays claimed until a reviewer verifies it
@e2e tests/e2e/workflows/compliance-matrix.spec.ts

- **GIVEN** a supplier attaches its own test report to a compliance claim
- **WHEN** a user opens the compliance matrix
- **THEN** the cell reads claimed, not verified
- **AND** after a reviewer of another organisation chooses Mark as verified, the cell reads verified with the reviewer's name and the date
