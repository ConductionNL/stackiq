# application-interfaces specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- connections-api-catalogue

## Purpose

The catalogue records the APIs an application offers, next to the application, so an architect sees what can be connected to and how. Matrix row `stackiq:conn-api-catalogue`.

## ADDED Requirements

### Requirement: REQ-AIF-001 A user can record an API that an application offers

Stackiq SHALL store an API as an `applicationInterface` object with a name, the providing application, a style, a version, a specification URL, a documentation URL, the standard versions it follows and a status. The status SHALL move through `in development`, `in use`, `end of support` and `withdrawn` by declared transitions.

#### Scenario: A supplier adds a REST API to its application
@e2e tests/e2e/workflows/application-interfaces.spec.ts

- **GIVEN** a supplier with edit rights on application X
- **WHEN** they open the page of application X, click Add in the APIs section and save name "Zaken API", style `REST`, version `1.2` and a specification URL
- **THEN** the APIs section of application X lists "Zaken API" with style REST and version 1.2
- **AND** the APIs list at `/apis` shows it too

### Requirement: REQ-AIF-002 The application page lists its APIs

The application page `ModuleDetail` SHALL list the APIs whose providing application is that application, and each row SHALL open the API's detail page.

#### Scenario: An architect checks what an application offers
@e2e tests/e2e/workflows/application-interfaces.spec.ts

- **GIVEN** application X offers a REST API and an event API
- **WHEN** an architect opens the page of application X
- **THEN** the APIs section lists both APIs with their style and status

### Requirement: REQ-AIF-003 A connection can name the API it calls

The `connection` schema SHALL carry an optional `interface` field that points at an API of the connection's application B, and the API's detail page SHALL list the connections that name it.

#### Scenario: An information manager links a connection to an API
@e2e exclude The field is a related-object picker in the library form; tests/Unit/Settings/ApplicationInterfaceFragmentTest.php asserts the property and its relation filter.

- **GIVEN** a connection from application A to application X, and X offers "Zaken API"
- **WHEN** the information manager sets the connection's API to "Zaken API"
- **THEN** the detail page of "Zaken API" lists that connection
