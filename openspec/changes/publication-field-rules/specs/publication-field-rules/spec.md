# publication-field-rules specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- publication-field-rules

## Purpose

What OpenCatalogi publishes from stackiq contains no contacts, owners, service desk references, costs or internal judgements.

## ADDED Requirements

### Requirement: REQ-PFR-001 Contacts, costs and internal judgements stay with signed-in users

The fields listed in the design SHALL be readable only by signed-in users. An anonymous reader SHALL get the published object without them. An organisation's application in use SHALL be public only from its publication date, and the organisation's own read access SHALL stay as it was.

#### Scenario: An anonymous reader opens a published application
@e2e exclude Read rules are enforced by OpenRegister on the API; verified by tests/Unit/Settings/PublicationFieldRulesTest.php and an anonymous API read on the test instance recorded in the PR.

- **GIVEN** a published application with a contact person and a DPIA document
- **WHEN** an anonymous reader reads it through the API
- **THEN** the answer has its name and description
- **AND** it has no contact person and no DPIA document

### Requirement: REQ-PFR-002 A module version is public only while its application is

A module version SHALL be readable by anonymous readers only when its application is: published by date, or registered by a supplier. Saving the application or the version SHALL keep the version in step.

#### Scenario: A version of an unpublished application stays private
@e2e exclude Verified by tests/Unit/Service/ModuleVersionPublicationServiceTest.php and an anonymous API read on the test instance recorded in the PR.

- **GIVEN** an application without a publication date, registered by a municipality, with one version
- **WHEN** an anonymous reader lists module versions
- **THEN** that version is not in the answer
- **WHEN** the application gets a publication date in the past
- **THEN** the version is in the answer

### Requirement: REQ-PFR-003 A fragment never lowers a schema version

When register fragments are merged, every schema SHALL get the highest version any file declares, whatever the file names.

#### Scenario: An earlier-named fragment raised the version
@e2e exclude Merge logic; verified by tests/Unit/Settings/PublicationFieldRulesTest.php.

- **GIVEN** fragment a.json sets usage 1.5.5 and fragment b.json sets usage 1.5.4
- **WHEN** the register is merged
- **THEN** usage is 1.5.5
