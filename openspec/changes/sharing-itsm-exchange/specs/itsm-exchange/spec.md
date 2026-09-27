# itsm-exchange specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- sharing-itsm-exchange

## Purpose

The organisation's applications in use are exchanged with its service management tool through integriq, and each carries a link to its service desk record. Matrix row `stackiq:share-itsm-integration`.

## ADDED Requirements

### Requirement: REQ-ITX-001 The organisation's applications in use reach its service desk

Stackiq SHALL let an administrator set up, from the admin settings, an outbound flow that sends a usage's application, supplier, version, status, owners and BBN level to the service desk source the administrator configured in integriq when the usage is created or changed, and an inbound flow that reads the service desk's application records nightly and links them to matching usages. Stackiq SHALL NOT hold the service desk credentials.

#### Scenario: A new application in use reaches TOPdesk
@e2e tests/e2e/workflows/itsm-exchange.spec.ts

- **GIVEN** a Nextcloud admin set up the service desk exchange with the integriq source for the municipality's TOPdesk and the TOPdesk preset
- **WHEN** an information manager moves the usage of application X to In production
- **THEN** the flow run shows the usage sent to the source
- **AND** the usage carries the record id TOPdesk returned

### Requirement: REQ-ITX-002 An application in use shows its service desk record

A usage SHALL keep its service desk references (system, record id, link, last synchronised), show the link on its page and in a Service desk column on Applications in use, and the Integrations page SHALL show the service desk exchange with the outcome of its last run.

#### Scenario: A service desk employee finds the catalogue entry and back
@e2e tests/e2e/workflows/itsm-exchange.spec.ts

- **GIVEN** a usage linked to service desk record A-123
- **WHEN** the information manager opens Applications in use
- **THEN** the Service desk column shows A-123 and opens the record in the service desk
