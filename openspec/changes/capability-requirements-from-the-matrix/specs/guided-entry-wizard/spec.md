# guided-entry-wizard specification

## Purpose

A supplier adds an application, a service or a connection step by step, the way a suite is added today. Matrix row `stackiq:land-guided-wizard`.

## ADDED Requirements

### Requirement: REQ-GEW-001 Applications, services and connections are added through a step-by-step wizard

Stackiq SHALL open a wizard when a user chooses Add on the Applications, Services or Connections page. The wizard SHALL ask for the details first, then the related records, then show a summary to confirm. It SHALL keep the user on the current step when a required field is empty or the save fails.

#### Scenario: A supplier adds an application step by step

- **GIVEN** a supplier on the Applications page
- **WHEN** they choose Add
- **THEN** a wizard opens on the details step
- **AND** after the details and the reference components they see a summary with a Save button

#### Scenario: A supplier adds a service step by step
@e2e tests/e2e/workflows/entry-wizard.spec.ts

- **GIVEN** a supplier on the Services page
- **WHEN** they choose Add, fill in the details, pick the applications the service runs on and confirm
- **THEN** the service is saved with those applications and its page opens

#### Scenario: A connection is added with both applications

- **GIVEN** an information manager on the Connections page
- **WHEN** they choose Add, pick application A and application B and confirm
- **THEN** a connection between A and B is saved and its page opens

#### Scenario: A missing required field keeps the step open

- **GIVEN** the wizard on its details step with no name
- **WHEN** the user chooses Next
- **THEN** the wizard stays on the details step and names the missing field
