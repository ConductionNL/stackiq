# archimate-import-progress Specification

## Purpose
A Nextcloud admin who imports an ArchiMate exchange file follows the import while it runs and can cancel it. The import records its phase and the objects saved so far in the shared progress store, and stops before its next save batch when a cancel is requested.

## Requirements

### Requirement: REQ-AIP-001 A running import SHALL record its phase and the objects saved so far

When the upload carries an operation id matching `^archimate_import_[A-Za-z0-9]{8,64}$`, the import SHALL start a progress operation under that id, owned by the uploading admin, SHALL set its phase as it validates, parses, processes and finalizes, and SHALL update the processed count after each schema group it saves. An id that does not match SHALL be ignored and the import SHALL run without progress.

#### Scenario: Progress is readable while the import runs
@e2e exclude The import is one blocking request of minutes; tests/Unit/Service/ArchiMateImportProgressTest.php saves two schema groups and reads the stored progress after the first from a second tracker on the same cache.

- **GIVEN** an admin uploads a model on the ArchiMate settings page with operation id `archimate_import_abc12345`
- **WHEN** the import has saved its first schema group
- **THEN** `GET /api/progress/archimate_import_abc12345` SHALL answer with phase processing and the objects saved so far

### Requirement: REQ-AIP-002 An admin SHALL be able to cancel a running import

`POST /api/archimate/import/cancel` with an operation id SHALL record a cancel for that operation and answer 200. The import SHALL check for a cancel before each schema group it saves and between its steps, and on a cancel SHALL stop, mark the operation cancelled and return `cancelled: true` with the counts it saved. The endpoint SHALL stay admin only.

#### Scenario: A cancelled import stops before its next batch
@e2e exclude Needs an import that is still running when the cancel arrives; tests/Unit/Service/ArchiMateImportProgressTest.php requests a cancel after the first of two schema groups and asserts the second is never saved.

- **GIVEN** an import with two schema groups is running under an operation id
- **WHEN** an admin cancels that operation after the first group is saved
- **THEN** the second group SHALL NOT be saved
- **AND** the import result SHALL say `cancelled: true` with the first group's count

#### Scenario: The cancel endpoint no longer fails
@e2e exclude tests/Unit/Service/ArchiMateServiceCancelTest.php calls the real ArchiMateService::cancelArchiMateImport, which today does not exist and makes the endpoint answer 500.

- **GIVEN** an admin
- **WHEN** they post an operation id to `/api/archimate/import/cancel`
- **THEN** the answer SHALL be 200 with `cancelled: true`

### Requirement: REQ-AIP-003 The settings page SHALL show the progress and offer a cancel

While an import runs, the import part of the ArchiMate settings SHALL show the current phase and a progress bar read from the progress endpoint every two seconds, and a Cancel import button. After a cancel the page SHALL say the import was cancelled and how many objects were saved.

#### Scenario: The admin follows and cancels an import
@e2e exclude A multi-minute import cannot run in the smoke suite; src/utils/archiMateImportProgress.spec.js (jest, the suite CI runs) drives the polling and cancel helpers the section calls with a mocked client and asserts the phase label, the percentage and the cancel post.

- **GIVEN** an admin has started an import on the ArchiMate settings page
- **WHEN** the progress endpoint reports 40 percent in phase processing
- **THEN** the page SHALL show that phase and a bar at 40 percent
- **AND WHEN** the admin presses Cancel import
- **THEN** the page SHALL post the operation id to the cancel endpoint
