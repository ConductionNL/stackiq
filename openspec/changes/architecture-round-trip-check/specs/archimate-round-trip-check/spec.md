# archimate-round-trip-check specification

**Status**: proposed
**Scope**: stackiq
**OpenSpec changes**:
- architecture-round-trip-check

## Purpose

A Nextcloud admin checks, before or after an import, that an ArchiMate exchange file survives stackiq's import and export without losing or changing elements, relationships, views or property values. The check writes nothing and runs the same conversion and generation code as the real import and export. It replaces a round-trip endpoint that could never succeed, was open to every signed-in user and wrote a test model into the live register.

## ADDED Requirements

### Requirement: REQ-ART-001 Stackiq SHALL compare two exchange files by identifier per category

The comparison SHALL key elements, relationships, views, view nodes, property definitions and organization folders by identifier, and SHALL report per category the count in the source, the count after the round trip, and the missing, extra and changed identifiers, each changed one with the fields that differ. Elements SHALL be compared on type, name, documentation and property values; relationships on type, source, target, name and property values; views on name, viewpoint and their node and connection references; view nodes on element reference, parent, position and size. The order of items in a file SHALL NOT count as a difference. The report SHALL hold at most 50 examples per category and always the full counts.

#### Scenario: A relationship with a changed target is reported
@e2e exclude A pure comparison; tests/Unit/Service/ArchiMateModelComparatorTest.php compares two files that differ in one relationship target and asserts one changed relationship naming the field target, and no other difference.

- **GIVEN** two exchange files that are equal except for the target of one relationship
- **WHEN** they are compared
- **THEN** the relationships category SHALL report one changed identifier with the field target
- **AND** every other category SHALL report no difference

#### Scenario: Reordered elements are not a difference
@e2e exclude A pure comparison; tests/Unit/Service/ArchiMateModelComparatorTest.php compares a file with itself in reversed element order and asserts no difference.

- **GIVEN** a file and the same file with its elements in reverse order
- **WHEN** they are compared
- **THEN** no category SHALL report a difference

### Requirement: REQ-ART-002 The check SHALL run before an import or against an imported model without writing

`POST /api/archimate/round-trip-check` SHALL take an uploaded exchange file and a mode. In the mode before-import it SHALL convert the file with the import's own conversion, generate an exchange file from the result with the export's own generation, and compare it with the upload, saving nothing. In the mode against-imported it SHALL read the stored objects whose model identifier equals the file's, generate an exchange file from them and compare, saving nothing; when no stored object has that identifier it SHALL say the model is not imported. The import and the full export SHALL call the same conversion and generation methods the check calls.

#### Scenario: A check before import leaves the register untouched
@e2e exclude Needs a GEMMA-sized file and minutes of runtime; tests/Unit/Service/ArchiMateRoundTripServiceTest.php runs the before-import mode on lib/Settings/GEMMA_testdata_below_1_5mb.xml with ObjectService mocked and asserts that no save method is called and a report comes back for every category.

- **GIVEN** a Nextcloud admin and a GEMMA exchange file
- **WHEN** they run the check before import
- **THEN** the report SHALL list every category with its counts
- **AND** the AMEF register SHALL hold the same objects as before

#### Scenario: The import and the check convert the same way
@e2e exclude A refactor guard; tests/Unit/Service/ArchiMateImportServiceConvertTest.php converts the fixture through importArchiMateFileFromPathOptimized with the save mocked and through convertFileToObjects, and asserts equal object lists.

- **GIVEN** the fixture file
- **WHEN** it is converted by the import and by the check
- **THEN** both SHALL produce the same objects

#### Scenario: A model that was never imported is named as such
@e2e exclude The CI instance has no imported model; tests/Unit/Service/ArchiMateRoundTripServiceTest.php asserts the against-imported mode answers "model not imported" when no stored object carries the file's model identifier.

- **GIVEN** an exchange file whose model identifier no stored object carries
- **WHEN** a Nextcloud admin runs the check against the imported model
- **THEN** the answer SHALL say the model is not imported

### Requirement: REQ-ART-003 Only an admin SHALL run the check, and the old round-trip endpoint SHALL be removed

The check route SHALL refuse a signed-in user who is not a Nextcloud admin with 403 and SHALL accept only a multipart upload, never a file path. `POST /api/archimate/test-round-trip`, `SettingsController::testArchiMateRoundTrip`, `ArchiMateService::testRoundTrip` with its built-in test model and temporary file, and the store action `testRoundTrip` SHALL be removed.

#### Scenario: A user without admin rights is refused
@e2e exclude The route test covers it without a second user; tests/Unit/Controller/SettingsControllerRoundTripCheckTest.php asserts a 403 for a non-admin and a 400 for a body with file_path, before the service is called.

- **GIVEN** a signed-in user who is not an admin
- **WHEN** they post a file to `/api/archimate/round-trip-check`
- **THEN** the response SHALL be 403
- **AND** the service SHALL NOT run

#### Scenario: The old endpoint is gone
@e2e exclude A route table rule; tests/Unit/SettingsRouteTableTest.php asserts no route named settings#testArchiMateRoundTrip and one route settings#checkArchiMateRoundTrip.

- **GIVEN** the route table
- **WHEN** it is read
- **THEN** `/api/archimate/test-round-trip` SHALL NOT exist

### Requirement: REQ-ART-004 The admin settings page SHALL show the round-trip report

The ArchiMate section of the admin settings SHALL offer Check a model file with a file picker, the choice "Before import (nothing is written)" or "Against the imported model", and a Check button. The result SHALL show a table with one row per category (in the file, after the round trip, missing, extra, changed), an expandable list of examples per category, and a summary that reads "No losses found" or names the categories with losses.

#### Scenario: An admin checks a small model before importing it
@e2e tests/e2e/workflows/archimate-round-trip-check.spec.ts

- **GIVEN** a Nextcloud admin on the stackiq admin settings page and a small exchange file from the test fixtures
- **WHEN** they choose Check a model file, pick the file, keep Before import and choose Check
- **THEN** the page SHALL show the table with a row for elements, relationships and views
- **AND** the summary SHALL read "No losses found" or name the categories with losses
