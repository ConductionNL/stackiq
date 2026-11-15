# dependent-field-options specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- landscape-dependent-field-options

## Purpose

The allowed values of one option list follow the value of another, both on save and in the form. Matrix row `stackiq:land-dependent-fields`.

## ADDED Requirements

### Requirement: REQ-DFO-001 A value outside its dependent list is refused on save

The `module` schema SHALL allow a `licence` only when `licentietype` is Open source, and the `organization` schema SHALL allow a `samenwerkingtype` only when `type` is Collaboration, declared with `x-openregister-dependent-values` so OpenRegister refuses any other pair.

#### Scenario: A supplier cannot give a closed-source application an open-source licence
@e2e exclude Enforced by OpenRegister's save listener; tests/Unit/Settings/DependentFieldOptionsTest.php asserts both tables and the enum match.

- **GIVEN** an application with licence type Closed source
- **WHEN** the supplier saves it with licence EUPL 1.2
- **THEN** the save is refused with a message that the licence is not allowed for Closed source

### Requirement: REQ-DFO-002 Existing rows that break a table are cleaned before it applies

Before the tables are imported, stackiq SHALL clear `licence` on closed-source applications and `samenwerkingtype` on organisations that are not a collaboration, and SHALL log how many values it cleared.

#### Scenario: An old row does not block the next edit
@e2e exclude Repair step; tests/Unit/Repair/ClearDisallowedDependentValuesTest.php covers it.

- **GIVEN** a municipality whose collaboration type was filled in by an old import
- **WHEN** the repair step runs and an administrator then edits the municipality's name
- **THEN** the save succeeds and the collaboration type is empty

### Requirement: REQ-DFO-003 The form offers only the allowed options

The create and edit forms SHALL offer, for a dependent field, only the values its table allows for the current value of the controlling field, and SHALL clear a value that becomes disallowed when the controlling field changes.

#### Scenario: Switching an application to open source opens the licence list
@e2e tests/e2e/workflows/dependent-field-options.spec.ts

- **GIVEN** the edit form of an application with licence type Closed source and an empty licence list
- **WHEN** the supplier sets licence type to Open source
- **THEN** the licence field offers the five open-source licences
