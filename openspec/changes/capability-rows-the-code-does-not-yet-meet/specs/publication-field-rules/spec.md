# publication-field-rules specification

## Purpose

A field can carry its own rule for who may change it. Matrix row `stackiq:org-field-level-permissions`.

## ADDED Requirements

### Requirement: A field can carry its own rule for who may change it (REQ-CLR-017)

A schema property SHALL be able to declare who may update it. OpenRegister SHALL refuse a write of that field by anyone else, while the rest of the record stays editable.

#### Scenario: A supplier cannot change the import key
@e2e exclude An authorisation rule held by OpenRegister; tests/Unit/Settings/FieldUpdateRulesTest.php asserts externalKey and the maintenance recipient fields declare their update groups.

- **GIVEN** an application with an import key set by the CMDB import
- **WHEN** a supplier saves the application with a different import key
- **THEN** the write SHALL be refused
