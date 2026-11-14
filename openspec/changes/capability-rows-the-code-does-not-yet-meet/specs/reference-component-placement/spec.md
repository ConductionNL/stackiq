# reference-component-placement specification

## Purpose

A supplier places an application on the GEMMA reference components it fulfils, from the application's own form. Matrix row `stackiq:arch-refcomp-mapping`.

## ADDED Requirements

### Requirement: A supplier picks the reference components an application fulfils on its form (REQ-CLR-007)

The application form SHALL offer the field reference components. Its picker SHALL list the GEMMA reference components from the register that holds them, and the application page SHALL show the picked components.

#### Scenario: A supplier places an application on a reference component
@e2e tests/e2e/workflows/reference-component-placement.spec.ts

- **GIVEN** a supplier editing the application Zaaksysteem X
- **WHEN** they pick the reference component Zaakregistratiecomponent and save
- **THEN** the application page SHALL show Zaakregistratiecomponent
- **AND** the GEMMA plate SHALL draw Zaaksysteem X inside Zaakregistratiecomponent
