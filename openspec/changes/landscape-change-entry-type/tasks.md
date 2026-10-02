# Tasks: landscape-change-entry-type

## Implementation tasks

### Task 1: Entry type service with preview and guard
- **spec_ref**: openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-001-an-entry-changes-type-and-keeps-its-identity
- **files**: `lib/Service/EntryTypeService.php`, `lib/Controller/EntryTypeController.php`, `appinfo/routes.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN an application without usages, versions or connections WHEN it is changed to a service THEN the same uuid answers as a catalogService with its history intact
  - GIVEN an application with a usage WHEN a change to service is asked THEN the preview lists the usage as a blocker and the change is refused
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Service/EntryTypeServiceTest.php` with an ObjectService double on the real interface; `tests/Unit/Controller/EntryTypeControllerTest.php` for 403, 409 and 422)

### Task 2: Change type dialog and module type on the form
- **spec_ref**: openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-002-the-user-sees-what-carries-over-before-confirming
- **files**: `src/dialogs/ChangeEntryTypeDialog.vue`, `src/manifest.json` (ModuleDetail header action, Diensten row action), `lib/Settings/softwarecatalogus_register.json` (module.type visible), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the application page WHEN the user picks Change type, Service THEN the dialog lists carried and dropped fields
  - GIVEN the application form WHEN it opens THEN the type field offers Application and System software
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/change-entry-type.spec.ts`)

### Task 3: Documentation
- **spec_ref**: openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-001-an-entry-changes-type-and-keeps-its-identity
- **files**: `docs/features/change-entry-type.md`, `docs/images/change-entry-type.png`
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens Change entry type THEN the preview, the blockers and what survives are explained with a screenshot
- [ ] Implement
- [ ] Test (docs build, screenshot with Playwright)

## Verification

- `openspec validate landscape-change-entry-type --type change --strict` passes.
- `composer check:strict` and `npm run lint` pass; the PHPUnit and Playwright cases above pass.
- English and Dutch strings for every new label (ADR-005); docs with a screenshot (ADR-010).
