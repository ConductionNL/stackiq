# application-components specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- landscape-application-components

## Purpose

An application can be broken into components, each an application of its own that names the application it belongs to. Matrix row `stackiq:land-application-modules`.

## ADDED Requirements

### Requirement: REQ-ACM-001 An application can name the application it is a component of

The `module` schema SHALL carry `partOf`, pointing at another application of the same supplier, and SHALL expose the inverse list of components. A module SHALL NOT be part of itself or of one of its own components.

#### Scenario: A supplier records a component
@e2e tests/e2e/workflows/application-components.spec.ts

- **GIVEN** a supplier's application "Zaaksysteem"
- **WHEN** the supplier opens its page, clicks Add component and saves "Zaaksysteem portaal"
- **THEN** "Zaaksysteem portaal" names "Zaaksysteem" as the application it is part of

#### Scenario: A cycle is refused
@e2e exclude Save-time guard; tests/Unit/Settings/ApplicationComponentsFragmentTest.php or tests/Unit/Service/ModuleRegistrationServiceTest.php asserts the refusal.

- **GIVEN** application A with component C
- **WHEN** a user sets A to be part of C
- **THEN** the save is refused and A keeps no parent

### Requirement: REQ-ACM-002 The application page lists its components

The application page SHALL list the application's components, each opening its own page, and a component's page SHALL show the application it is part of. The Applications list SHALL show whole applications by default and SHALL let the user include components.

#### Scenario: A buyer reads what an application is made of
@e2e tests/e2e/workflows/application-components.spec.ts

- **GIVEN** "Zaaksysteem" has components "Zaaksysteem portaal" and "Zaaksysteem documenten"
- **WHEN** a municipal buyer opens the page of "Zaaksysteem"
- **THEN** the Components list shows both components
- **AND** the Applications list shows "Zaaksysteem" but not its components until the buyer picks All, including components
