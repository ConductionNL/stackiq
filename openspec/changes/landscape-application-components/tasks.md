# Tasks: landscape-application-components

## Implementation tasks

### Task 1: The partOf relation and its guard
- **spec_ref**: openspec/changes/landscape-application-components/specs/application-components/spec.md#requirement-req-acm-001-an-application-can-name-the-application-it-is-a-component-of
- **files**: `lib/Settings/register.d/application-components.json`, `lib/Service/ModuleRegistrationService.php` (only if the dialect lacks a not-self rule), `lib/Settings/stackiq_mock_register.json`
- **acceptance_criteria**:
  - GIVEN component C of application A WHEN A is read THEN its components list holds C
  - GIVEN application A WHEN a user sets A part of A THEN the save is refused with a message
- [x] Implement
  - `lib/Settings/register.d/application-components.json` adds `partOf` (related module, `x-relation-filter` on the same provider, `inversedBy: components`) and the computed `components` list. The cycle check is in `ModuleComponentGuard` behind a new `ModuleComponentListener` on `ObjectCreatingEvent`/`ObjectUpdatingEvent`, because `ModuleRegistrationService` runs only after the save. The demo application with two components in the mock register is not seeded yet.
- [x] Test (PHPUnit `tests/Unit/Settings/ApplicationComponentsFragmentTest.php`; `tests/Unit/Service/ModuleRegistrationServiceTest.php` cycle case if the service check is used)
  - `tests/Unit/Settings/ApplicationComponentsFragmentTest.php`, `tests/Unit/Service/ModuleComponentGuardTest.php`, `tests/Unit/EventListener/ModuleComponentListenerTest.php`.

### Task 2: Components on the application page and the list filter
- **spec_ref**: openspec/changes/landscape-application-components/specs/application-components/spec.md#requirement-req-acm-002-the-application-page-lists-its-components
- **files**: `src/manifest.json` (ModuleDetail widget, Modules quick filters), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN application A with two components WHEN its page opens THEN the Components list shows both
  - GIVEN the Applications list WHEN it opens THEN components are hidden until the user picks All, including components
- [x] Implement
  - `ModuleDetail` gains the `md-components` list (filter `partOf: @objectId`, Add component; the create form seeds `partOf` from the filter) and shows `partOf` in its data widget. The `Modules` list opens on "Whole applications" (`partOf: IS NULL`) and offers "All, including components". English and Dutch strings were added to `l10n/en.json`/`nl.json`, and their `.js` was rebuilt.
- [ ] Test (Playwright `tests/e2e/workflows/application-components.spec.ts`)

### Task 3: Documentation
- **spec_ref**: openspec/changes/landscape-application-components/specs/application-components/spec.md#requirement-req-acm-002-the-application-page-lists-its-components
- **files**: `docs/features/application-components.md`, `docs/images/application-components.png`
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens Application components THEN recording a component and the list filter are explained with a screenshot
- [ ] Implement
- [ ] Test (docs build, screenshot with Playwright)

## Verification

- `openspec validate landscape-application-components --type change --strict` passes.
- `composer check:strict` and `npm run lint` pass; the PHPUnit and Playwright cases above pass.
- English and Dutch strings for every new label (ADR-005); docs with a screenshot (ADR-010).
