# vendor-product-master-data specification

## Purpose

A contract shows the supplier it is with, read from one supplier record. Matrix row `stackiq:mkt-vendor-master-data`.

## ADDED Requirements

### Requirement: A contract shows its supplier through the service it is for (REQ-CLR-014)

A contract SHALL reach its supplier through the service it is for, and the contract page SHALL show that supplier's organisation record. The supplier's page SHALL list its applications and services.

#### Scenario: A contract manager reads a contract's supplier
@e2e tests/e2e/workflows/contracts.spec.ts

- **GIVEN** a contract for the service Hosting Zaaksysteem X of supplier Leverancier B.V.
- **WHEN** a contract manager opens the contract page
- **THEN** it SHALL show Leverancier B.V. as the supplier, linking to its organisation page
