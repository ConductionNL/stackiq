# softwarecatalog-notifications specification

## Purpose

Alerts reach the organisations that use the software they are about. Matrix rows `stackiq:sec-vulnerability-alert` and `stackiq:life-new-version-notice`.

## ADDED Requirements

### Requirement: A reported vulnerability alerts the organisations that use the affected software (REQ-CLR-002)

The vulnerability-reported rule SHALL address the application owners and information managers of every organisation with a usage of an affected application. The alert subject SHALL name the vulnerability by its `name` field. The review rule subject SHALL name the review and its rating by their current field names.

#### Scenario: An organisation that uses the application is told
@e2e exclude The dispatch is OpenRegister's notification engine; tests/Unit/Settings/VulnerabilityNotificationRuleTest.php asserts the rule's recipients resolve through the usages of the affected applications.

- **GIVEN** Gemeente Voorbeeld has a usage of Zaaksysteem X
- **WHEN** a vulnerability CVE-2026-1234 is reported for Zaaksysteem X
- **THEN** the application owner of that usage SHALL get a notification

#### Scenario: The alert names the vulnerability
@e2e exclude A template check; tests/Unit/Settings/VulnerabilityNotificationRuleTest.php asserts every placeholder in the subject names a property of the schema.

- **GIVEN** a vulnerability with name CVE-2026-1234
- **WHEN** its alert is sent
- **THEN** the subject SHALL contain CVE-2026-1234

### Requirement: A new version alerts the organisations that use the application (REQ-CLR-003)

The module-version-published rule SHALL address the application owners of every organisation with a usage of the application the version belongs to.

#### Scenario: A user of the application hears about the new version
@e2e exclude The dispatch is OpenRegister's notification engine; tests/Unit/Settings/VersionNotificationRuleTest.php asserts the rule's recipients resolve through the usages of the version's application.

- **GIVEN** Gemeente Voorbeeld has a usage of Zaaksysteem X
- **WHEN** the supplier publishes version 5.2 of Zaaksysteem X
- **THEN** the application owner of that usage SHALL get a notification naming version 5.2
