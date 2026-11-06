# bio-compliance-assessment Specification (delta)

## ADDED Requirements

### Requirement: The BIO 2.0 measures ship with the app and show their level (REQ-BIO-010)

stackiq SHALL ship the BIO 2.0 measures as reference data (code, name, description, theme, BBN level, BIO version, source) and import them on install and upgrade. A measure with the same code that already exists SHALL be kept, not duplicated. The BIO measures page SHALL show code, name, theme, BIO version and BBN level.

#### Scenario: A fresh install
- GIVEN a new instance with the catalogue register imported
- WHEN an administrator opens BIO measures
- THEN the BIO 2.0 measures are listed, each with its theme and BBN level

#### Scenario: An upgrade with hand-entered measures
- GIVEN an administrator typed in measure 9.4.1 before
- WHEN the app upgrades
- THEN there is still one measure 9.4.1, with the administrator's text

### Requirement: A compliance claim says whether the application meets the measure (REQ-BIO-011)

A compliance claim on a BIO measure or standard SHALL record a result, meets or does not meet. The compliance matrix and an application's compliance list SHALL show four states: verified (meets, with third-party evidence), claimed (meets, without), does not meet, and unknown (no claim). The summary SHALL count the cells that do not meet.

#### Scenario: Recording a measure that is not met
- GIVEN an application without encryption at rest
- WHEN its owner adds a claim on measure 10.1.1 with result "does not meet"
- THEN the matrix cell shows Does not meet, and the summary counts it

#### Scenario: Not assessed stays apart
- GIVEN an application with no claim on 9.4.1
- WHEN the matrix renders
- THEN the cell shows Unknown, not Does not meet

#### Scenario: Existing claims
- GIVEN claims made before this change
- WHEN the app upgrades
- THEN each has result "meets" and shows as verified or claimed as before
