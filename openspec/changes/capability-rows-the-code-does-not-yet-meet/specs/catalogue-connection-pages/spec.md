# catalogue-connection-pages specification

## Purpose

A connection names the standard it uses and the national provision it reaches. Matrix rows `stackiq:conn-register-connection` and `stackiq:conn-external-provision`.

## ADDED Requirements

### Requirement: A connection's standard is picked from the GEMMA standard versions (REQ-CLR-012)

The standard version picker on the connection form SHALL list the GEMMA standard versions from the register that holds them. The connection page SHALL show the picked standard version.

#### Scenario: An information manager records the standard a connection uses
@e2e tests/e2e/workflows/connections.spec.ts

- **GIVEN** a connection between Zaaksysteem X and Documentbeheer Y
- **WHEN** an information manager picks the standard version StUF-ZKN 3.10 and saves
- **THEN** the connection page SHALL show StUF-ZKN 3.10

### Requirement: A connection names the national provision it reaches (REQ-CLR-013)

The connection form SHALL offer the field national provision, with a picker over the GEMMA elements of type Buitengemeentelijke voorziening. The connection page SHALL show the picked provision.

#### Scenario: An information manager records a connection to the BRP
@e2e tests/e2e/workflows/connections.spec.ts

- **GIVEN** a connection from Zaaksysteem X
- **WHEN** an information manager picks the provision Basisregistratie Personen and saves
- **THEN** the connection page SHALL show Basisregistratie Personen as its national provision
