# Test Plan: suite-wizard

## Test Cases

### TC-1: Wizard opens on the details step with three visible steps
- **spec_ref**: `openspec/changes/suite-wizard/specs/suite-wizard/spec.md#requirement-the-wizard-shall-guide-suite-creation-through-details-application-attachment-and-confirmation-steps`
- **type**: functional
- **persona**: Mark (MKB software vendor)
- **preconditions**: user is on the Suites index page
- **steps**: click "New suite"
- **expected result**: `CnWizardDialog` opens on the `details` step; progress indicator shows Details / Applications / Confirm
- **test command**: covered by `src/dialogs/SuiteWizardDialog` step-configuration unit test (Jest, co-located with `src/utils/suiteWizard.js`)

### TC-2: Applications step only offers existing modules, no create-new control
- **spec_ref**: `openspec/changes/suite-wizard/specs/suite-wizard/spec.md#requirement-the-wizard-shall-guide-suite-creation-through-details-application-attachment-and-confirmation-steps`
- **type**: functional
- **preconditions**: at least one `module` object exists in the voorzieningen register
- **steps**: advance to the `applications` step, open the picker
- **expected result**: only existing modules are listed; no inline-create affordance
- **test command**: code review + `Step2Applications.vue` composition (no `x-allow-create`/`CnResourceSelect` used, plain `NcSelect`)

### TC-3: Advancing past applications with zero selected is blocked
- **spec_ref**: `openspec/changes/suite-wizard/specs/suite-wizard/spec.md#requirement-the-wizard-must-require-at-least-one-attached-application-before-advancing-past-the-applications-step`
- **type**: functional
- **preconditions**: wizard on `applications` step, no modules selected
- **steps**: click Next
- **expected result**: stays on `applications`, shows validation error
- **test command**: `tests/vitest/suiteWizard.spec.js` — `isApplicationsStepValid([])` returns a string (error), `isApplicationsStepValid([id])` returns `true`

### TC-4: Advancing with ≥1 application shows them on confirm
- **spec_ref**: `openspec/changes/suite-wizard/specs/suite-wizard/spec.md#requirement-the-wizard-must-require-at-least-one-attached-application-before-advancing-past-the-applications-step`
- **type**: functional
- **preconditions**: wizard on `applications` step, ≥1 module selected
- **steps**: click Next
- **expected result**: advances to `confirm`, listing selected application names
- **test command**: `Step3Confirm.vue` renders `stepData.applications` names (code review) + `tests/vitest/suiteWizard.spec.js`

### TC-5: Successful submit creates the suite with its attached applications
- **spec_ref**: `openspec/changes/suite-wizard/specs/suite-wizard/spec.md#requirement-submitting-the-wizard-shall-create-a-suite-object-with-the-attached-applications`
- **type**: functional
- **preconditions**: wizard on `confirm` step with valid details + ≥1 application
- **steps**: click Submit
- **expected result**: `objectStore.saveObject('suite', payload)` called with `applicaties` = array of selected module ids; result phase shows success
- **test command**: `src/utils/suiteWizard.spec.js` — `buildSuitePayload(stepData)` shape assertion (Jest)

### TC-6: A failed save surfaces a recoverable error
- **spec_ref**: `openspec/changes/suite-wizard/specs/suite-wizard/spec.md#requirement-submitting-the-wizard-shall-create-a-suite-object-with-the-attached-applications`
- **type**: functional
- **preconditions**: `objectStore.saveObject` rejects
- **steps**: click Submit
- **expected result**: `wizard.setError(message)` called, step data intact, dialog stays open
- **test command**: code review of `SuiteWizardDialog.vue` `onSubmit` catch branch

### TC-7: Module/suite object types register by schema slug, independent of `<type>_schema` config keys
- **spec_ref**: `openspec/changes/suite-wizard/specs/suite-wizard/spec.md#requirement-the-system-shall-register-the-suite-and-module-object-types-by-schema-slug`
- **type**: functional
- **preconditions**: `objectStore.settings.voorzieningen.module_schema` is unset
- **steps**: open the wizard's `applications` step
- **expected result**: modules still load, because registration uses `registerObjectType('module', 'module', voorzieningenConfig.register, ...)` rather than reading `module_schema`
- **test command**: code review of `SuiteWizardDialog.vue` `ensureObjectTypesRegistered()`

### TC-8: Suites nav entry opens the suite index
- **spec_ref**: `openspec/changes/suite-wizard/specs/suite-wizard/spec.md#requirement-the-suite-index-page-shall-list-existing-suites`
- **type**: functional
- **preconditions**: app loaded
- **steps**: click "Suites" in the nav menu
- **expected result**: `SuitesIndexView` renders, listing `suite` objects via `CnIndexPage` self-fetch (`register="voorzieningen"` `schema="suite"`)
- **test command**: `npm run check:manifest` (nav entry + page wiring) + code review

### TC-9: Suite detail shows data and attached applications with click-through
- **spec_ref**: `openspec/changes/suite-wizard/specs/suite-wizard/spec.md#requirement-the-suite-detail-page-shall-show-suite-data-and-its-member-applications`
- **type**: functional
- **preconditions**: a suite with ≥2 attached applications exists
- **steps**: open the suite's detail page
- **expected result**: `suite-data` widget shows naam/beschrijvingKort/beschrijvingLang/website/logo/contactpersoon; `suite-related` widget lists the attached modules with navigation to `ModuleDetail`
- **test command**: `npm run check:manifest` (SuiteDetail widget/layout shape) + code review

### TC-10: Module detail surfaces suite membership via the existing related-objects widget
- **spec_ref**: `openspec/changes/suite-wizard/specs/suite-wizard/spec.md#requirement-the-module-detail-page-shall-surface-suite-membership`
- **type**: regression
- **preconditions**: a suite's `applicaties` includes module M
- **steps**: open M's detail page
- **expected result**: the suite appears among M's related objects via `md-related`'s existing `/uses`+`/used` self-fetch — no manifest change to `ModuleDetail` required
- **test command**: code review of `ObjectsController::used()` (generic, bidirectional, not field-scoped) + `KwetsbaarheidDetail` precedent for the same reverse-array-relation pattern

## Coverage Summary
- REQ "wizard guides three steps" — TC-1, TC-2 — covered
- REQ "requires ≥1 application" — TC-3, TC-4 — covered
- REQ "submit creates suite" — TC-5, TC-6 — covered
- REQ "register by schema slug" — TC-7 — covered
- REQ "suite index lists suites" — TC-8 — covered
- REQ "suite detail shows data + members" — TC-9 — covered
- REQ "module detail shows suite membership" — TC-10 — covered (regression / platform-mechanism reliance, not a new code path)

## Out of Scope
Full browser/Playwright end-to-end verification against a live Nextcloud instance is out of scope for this change's own test suite — PHPUnit (backend, N/A here since no PHP changes) and Vitest/Jest (frontend logic) are the verification tools used, per the app's existing test conventions. No shared dev instance is deployed to or restarted as part of this change.
