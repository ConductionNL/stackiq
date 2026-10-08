# Tasks: architecture-import-progress-and-cancel

## Implementation tasks

### Task 1: Cancel flag and caller-named operations in ProgressTracker
- **spec_ref**: openspec/changes/architecture-import-progress-and-cancel/specs/archimate-import-progress/spec.md#requirement-req-aip-002-an-admin-shall-be-able-to-cancel-a-running-import
- **files**: `lib/Service/ProgressTracker.php`, `tests/Unit/Service/ProgressTrackerCancelTest.php`
- **acceptance_criteria**:
  - GIVEN an operation started with id `archimate_import_abc12345` WHEN `getProgress` is read from a second tracker instance on the same cache THEN it returns that operation
  - GIVEN `requestCancel(id)` WHEN `isCancelRequested(id)` is read from another instance THEN it is true, and false for any other id
- [x] Implement
- [x] Test (PHPUnit `ProgressTrackerCancelTest`)

### Task 2: The import records progress and honours a cancel
- **spec_ref**: openspec/changes/architecture-import-progress-and-cancel/specs/archimate-import-progress/spec.md#requirement-req-aip-001-a-running-import-shall-record-its-phase-and-the-objects-saved-so-far
- **files**: `lib/Service/ArchiMateImportService.php`, `tests/Unit/Service/ArchiMateImportProgressTest.php`
- **acceptance_criteria**:
  - GIVEN an import with an operation id and two schema groups WHEN the first group is saved THEN the stored progress has phase processing and processed items equal to that group's size
  - GIVEN a cancel requested after the first group WHEN the import continues THEN the second group is not saved and the result has `cancelled: true` and the first group's count
- [x] Implement
- [x] Test (PHPUnit `ArchiMateImportProgressTest`)

### Task 3: The cancel endpoint works
- **spec_ref**: openspec/changes/architecture-import-progress-and-cancel/specs/archimate-import-progress/spec.md#requirement-req-aip-002-an-admin-shall-be-able-to-cancel-a-running-import
- **files**: `lib/Service/ArchiMateService.php`, `lib/Service/SettingsService.php`, `lib/Controller/SettingsController.php`, `tests/Unit/Service/ArchiMateServiceCancelTest.php`
- **acceptance_criteria**:
  - GIVEN the real `ArchiMateService` WHEN `cancelArchiMateImport('archimate_import_abc12345')` runs THEN the cancel flag is set and the result says `cancelled: true`
  - GIVEN an operation id that does not match the pattern WHEN the upload or the cancel receives it THEN it is ignored (upload) or answered 400 (cancel)
- [x] Implement
- [x] Test

### Task 4: The settings page shows progress and a cancel button
- **spec_ref**: openspec/changes/architecture-import-progress-and-cancel/specs/archimate-import-progress/spec.md#requirement-req-aip-003-the-settings-page-shall-show-the-progress-and-offer-a-cancel
- **files**: `src/views/settings/sections/ArchiMateImportExport.vue`, `src/utils/archiMateImportProgress.js`, `src/utils/archiMateImportProgress.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a running import WHEN the progress endpoint answers 40 percent in phase processing THEN the page shows the phase and a bar at 40
  - GIVEN a running import WHEN the admin presses Cancel import THEN the page posts the operation id to the cancel endpoint
- [x] Implement
- [x] Test (jest `archiMateImportProgress.spec.js`)
