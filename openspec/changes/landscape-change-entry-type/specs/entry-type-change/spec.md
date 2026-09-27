# entry-type-change specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- landscape-change-entry-type

## Purpose

An entry registered as the wrong type changes type in place and keeps its identity. Matrix row `stackiq:land-change-entry-type`.

## ADDED Requirements

### Requirement: REQ-ETC-001 An entry changes type and keeps its identity

Stackiq SHALL change an application into a service, or a service into an application, by moving the object with OpenRegister's move, so the uuid, history, files and links stay. It SHALL refuse the change while the entry has incoming references the target type cannot hold, and SHALL switch an application between Application and System software by its type field.

#### Scenario: A supplier turns an application into a service
@e2e tests/e2e/workflows/change-entry-type.spec.ts

- **GIVEN** a supplier registered "Hosting en beheer" as an application, with no usages, versions or connections, and one file attached
- **WHEN** the supplier opens its page, picks Change type, Service, and confirms
- **THEN** "Hosting en beheer" opens as a service with the same identifier
- **AND** the attached file and the history are still there

#### Scenario: A change that would break usages is refused
@e2e exclude Guard case; tests/Unit/Service/EntryTypeServiceTest.php asserts the blocker list and that no move is called.

- **GIVEN** an application with one usage by a municipality
- **WHEN** a functional administrator asks to change it into a service
- **THEN** the dialog lists the usage as a blocker and Confirm stays disabled
- **AND** `POST /api/entries/{uuid}/type-change` answers 409 with the blocker

### Requirement: REQ-ETC-002 The user sees what carries over before confirming

Before a type change, stackiq SHALL show which fields carry over, which fields and values would be dropped, and which references block the change.

#### Scenario: The preview names the dropped licence field
@e2e tests/e2e/workflows/change-entry-type.spec.ts

- **GIVEN** an application with a licence type filled in
- **WHEN** the supplier picks Change type, Service
- **THEN** the dialog lists the licence type under fields that will be dropped, with its value
