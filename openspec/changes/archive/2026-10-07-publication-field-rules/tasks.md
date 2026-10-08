# Tasks: publication-field-rules

## Implementation tasks

### Task 1: Field rules and the usage public rule
- **spec_ref**: openspec/changes/publication-field-rules/specs/publication-field-rules/spec.md#requirement-req-pfr-001-contacts-costs-and-internal-judgements-stay-with-signed-in-users
- **files**: `lib/Settings/register.d/publication-field-rules.json`, `tests/Unit/Settings/PublicationFieldRulesTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN it is read THEN each listed property reads `authenticated` only, and usage keeps its organisation rules plus the public rule
  - GIVEN interneAnnotation and contactpersonen WHEN merged THEN their rules are unchanged
- [x] Implement
- [x] Test

### Task 2: moduleVersion follows its application
- **spec_ref**: openspec/changes/publication-field-rules/specs/publication-field-rules/spec.md#requirement-req-pfr-002-a-module-version-is-public-only-while-its-application-is
- **files**: `lib/Service/ModuleVersionPublicationService.php`, `lib/EventListener/ModuleVersionPublicationListener.php`, `lib/Repair/BackfillModuleVersionPublication.php`, `lib/AppInfo/Application.php`, `appinfo/info.xml`, `tests/Unit/Service/ModuleVersionPublicationServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a module saved with a publication date WHEN the background job the listener queues has run THEN each version differing gets it; an equal one is not written
  - GIVEN an anonymous reader WHEN a version of an unpublished application is read THEN it is not returned
- [x] Implement
- [x] Test

### Task 3: The highest version wins
- **spec_ref**: openspec/changes/publication-field-rules/specs/publication-field-rules/spec.md#requirement-req-pfr-003-a-fragment-never-lowers-a-schema-version
- **files**: `lib/Service/SettingsService.php`, `tests/Unit/Settings/PublicationFieldRulesTest.php`
- [x] Implement
- [x] Test

## Verification

- `openspec validate publication-field-rules --strict`.
- Live: anonymous read of a published application, a published usage and versions, on :8096.
