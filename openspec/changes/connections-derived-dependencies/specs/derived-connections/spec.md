# derived-connections specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- connections-derived-dependencies

## Purpose

An organisation's usages get their connections filled in from what the catalogue already knows, instead of by hand. Matrix rows `stackiq:conn-auto-populate-dependencies` and `stackiq:land-version-carry-connections`.

## ADDED Requirements

### Requirement: REQ-DCN-001 Stackiq suggests the known connections of an application inside the organisation's landscape

For a usage, stackiq SHALL suggest every readable connection of the usage's application whose other end is an application the same organisation uses, or a national provision, and that is not yet in the usage's connections and was not dismissed for this usage.

#### Scenario: A municipality gets its connections suggested
@e2e tests/e2e/workflows/connection-suggestions.spec.ts

- **GIVEN** a published connection between application X and application Y, and a municipality that has a usage of Y
- **WHEN** the municipality's information manager records a usage of X and opens it
- **THEN** the Suggested connections panel lists the connection between X and Y
- **AND** after Accept the usage's connections include it

#### Scenario: A connection outside the landscape is not suggested
@e2e exclude Rule-level case; tests/Unit/Service/ConnectionSuggestionServiceTest.php covers an other end the organisation does not use.

- **GIVEN** a connection between X and Z, and the municipality does not use Z
- **WHEN** the information manager opens the usage of X
- **THEN** that connection is not suggested

### Requirement: REQ-DCN-002 A replacing usage is offered the connections of the usage it replaces

When an organisation has a usage of the same application at an older version, or a usage whose planned replacement is the new usage's application, stackiq SHALL suggest the older usage's connections for the new usage. For a successor application it SHALL suggest draft connections from the successor to the same other ends, with status `in development` and a description naming the original connection.

#### Scenario: A new version keeps its connections
@e2e tests/e2e/workflows/connection-suggestions.spec.ts

- **GIVEN** a usage of X at version 1 with connections to Y and to a national provision
- **WHEN** the information manager records a usage of X at version 2
- **THEN** both connections are suggested with the reason that they carry over from version 1

#### Scenario: A successor gets draft connections
@e2e exclude Rule-level case; tests/Unit/Service/ConnectionSuggestionServiceTest.php covers the successor path.

- **GIVEN** a usage of X whose planned replacement is Z, with a connection from X to Y
- **WHEN** the information manager records a usage of Z and accepts the suggestion
- **THEN** a connection from Z to Y exists with status `in development`

### Requirement: REQ-DCN-003 Only the organisation that owns the usage accepts or dismisses its suggestions

`POST /api/usages/{uuid}/connection-suggestions/accept` and `/dismiss` SHALL refuse with 403 a caller whose active organisation is neither the usage's consumer nor one of its participants, and SHALL change nothing then.

#### Scenario: Another organisation cannot accept
@e2e exclude API guard; tests/Unit/Controller/ConnectionSuggestionControllerTest.php asserts the 403 and that no object was written.

- **GIVEN** a usage of municipality A
- **WHEN** a user whose active organisation is municipality B posts accept for it
- **THEN** the answer is 403
- **AND** the usage's connections are unchanged
