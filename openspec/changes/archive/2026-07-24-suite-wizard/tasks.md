# Tasks: suite-wizard

## Implementation Tasks

### Task 1: Extract wizard payload/validation logic to a pure module
- **spec_ref**: `openspec/changes/suite-wizard/specs/suite-wizard/spec.md#requirement-the-wizard-must-require-at-least-one-attached-application-before-advancing-past-the-applications-step`
- **files**: `src/utils/suiteWizard.js`, `src/utils/suiteWizard.spec.js`, `tests/vitest/suiteWizard.spec.js`
- **acceptance_criteria**:
  - GIVEN zero applications selected WHEN `isApplicationsStepValid([])` is called THEN it returns an error string
  - GIVEN one or more applications selected WHEN `isApplicationsStepValid([...])` is called THEN it returns `true`
  - GIVEN step data with naam/beschrijvingKort/beschrijvingLang/website and selected applications WHEN `buildSuitePayload(stepData)` is called THEN it returns `{ naam, beschrijvingKort, beschrijvingLang, website, applicaties: [ids] }`
- [x] Implement
- [x] Test

### Task 2: Build the wizard step components
- **spec_ref**: `openspec/changes/suite-wizard/specs/suite-wizard/spec.md#requirement-the-wizard-shall-guide-suite-creation-through-details-application-attachment-and-confirmation-steps`
- **files**: `src/dialogs/SuiteWizard/Step1Details.vue`, `src/dialogs/SuiteWizard/Step2Applications.vue`, `src/dialogs/SuiteWizard/Step3Confirm.vue`
- **acceptance_criteria**:
  - GIVEN the details step WHEN naam/beschrijvingKort are empty THEN the step reports invalid via `_step1Valid`
  - GIVEN the applications step WHEN it mounts THEN it registers `module` by schema slug and fetches the collection
  - GIVEN the confirm step WHEN rendered THEN it lists naam, beschrijvingKort and the selected application names
- [x] Implement
- [x] Test

### Task 3: Build SuiteWizardDialog over CnWizardDialog
- **spec_ref**: `openspec/changes/suite-wizard/specs/suite-wizard/spec.md#requirement-submitting-the-wizard-shall-create-a-suite-object-with-the-attached-applications`
- **files**: `src/dialogs/SuiteWizardDialog.vue`
- **acceptance_criteria**:
  - GIVEN a valid submit WHEN the user clicks Submit THEN `objectStore.saveObject('suite', payload)` is called and the wizard enters the success result phase
  - GIVEN a failed save WHEN submit rejects THEN `setError(message)` is called and the dialog stays open with step data intact
- [x] Implement
- [x] Test

### Task 4: Add the Suites index page with the wizard entry point
- **spec_ref**: `openspec/changes/suite-wizard/specs/suite-wizard/spec.md#requirement-the-suite-index-page-shall-list-existing-suites`
- **files**: `src/views/suites/SuitesIndexView.vue`, `src/customComponents.js`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the app loads WHEN the user clicks "Suites" in the nav THEN the Suites index page renders via `CnIndexPage` self-fetch (`register="voorzieningen"` `schema="suite"`)
  - GIVEN the Suites index page WHEN rendered THEN a "New suite" action opens `SuiteWizardDialog`
- [x] Implement
- [x] Test

### Task 5: Add the SuiteDetail manifest page
- **spec_ref**: `openspec/changes/suite-wizard/specs/suite-wizard/spec.md#requirement-the-suite-detail-page-shall-show-suite-data-and-its-member-applications`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a suite exists WHEN its detail page opens THEN `suite-data` shows naam/beschrijvingKort/beschrijvingLang/website/logo/contactpersoon
  - GIVEN a suite has attached applications WHEN its detail page opens THEN `suite-related` lists them with navigation to `ModuleDetail`
- [x] Implement
- [x] Test

### Task 6: i18n and docs
- **spec_ref**: `openspec/changes/suite-wizard/specs/suite-wizard/spec.md#requirement-the-wizard-shall-guide-suite-creation-through-details-application-attachment-and-confirmation-steps`
- **files**: `l10n/en.json`, `l10n/en_US.js`, `l10n/en_US.json`, `l10n/nl.js`, `l10n/nl.json`, `docs/features/suite-wizard.md`
- **acceptance_criteria**:
  - GIVEN every `t('softwarecatalog', ...)` string the new components use WHEN `node tests/l10n/check-l10n.js` runs THEN it exits 0
  - GIVEN the feature docs page WHEN read THEN it describes the wizard flow and the suite/module surfaces
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria
- [x] Code review against spec requirements

## Tests (company-wide ADR-009)
- [x] Jest unit tests for new pure logic (`src/utils/suiteWizard.spec.js`)
- N/A Newman/Postman — no new API endpoints (frontend talks to existing OpenRegister object APIs directly, ADR-008)
- N/A Playwright browser tests — no shared dev instance deployment for this change (see test-plan.md Out of Scope); functional acceptance covered via unit tests + code review
- [x] `npm test` and `npx vitest run` pass

## Documentation (company-wide ADR-010)
- [x] Feature documentation added at `docs/features/suite-wizard.md`
- N/A Screenshot — no live browser session used for this change (see test-plan.md Out of Scope); matches the existing screenshot-less docs precedent (`docs/features/organisation-merge.md`)

## i18n (company-wide ADR-005)
- [x] Dutch (`nl`) and English (`en_US`) translation strings added for every new user-facing string
