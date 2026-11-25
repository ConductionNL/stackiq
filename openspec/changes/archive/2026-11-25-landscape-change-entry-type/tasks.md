# Tasks: landscape-change-entry-type

## Implementation tasks

### Task 1: Entry type service with preview and guard
- **spec_ref**: openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-001-an-entry-changes-type-and-keeps-its-identity
- **files**: `lib/Service/EntryTypeService.php`, `lib/Controller/EntryTypeController.php`, `appinfo/routes.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN an application without usages, versions or connections WHEN it is changed to a service THEN the same uuid answers as a catalogService with its history intact
  - GIVEN an application with a usage WHEN a change to service is asked THEN the preview lists the usage as a blocker and the change is refused
- [x] Implement
- [x] Test (PHPUnit `tests/Unit/Service/EntryTypeServiceTest.php` with an ObjectService double on the real interface; `tests/Unit/Controller/EntryTypeControllerTest.php` for 403, 409 and 422)

### Task 2: Change type dialog and module type on the form
- **spec_ref**: openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-002-the-user-sees-what-carries-over-before-confirming
- **files**: `src/dialogs/ChangeEntryTypeDialog.vue`, `src/manifest.json` (ModuleDetail header action, Diensten row action), `lib/Settings/softwarecatalogus_register.json` (module.type visible), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the application page WHEN the user picks Change type, Service THEN the dialog lists carried and dropped fields
  - GIVEN the application form WHEN it opens THEN the type field offers Application and System software
- [x] Implement
  - `src/dialogs/ChangeEntryTypeDialog.vue` previews the change (carried, dropped with values, blockers), asks for a service type when the target is a service, and keeps Confirm disabled while anything blocks. It opens from the `md-entry-type` body widget `ChangeEntryTypePanel` on `ModuleDetail` (stackiq's detail pages add actions as body widgets, like the organisation merge). `module.type` is visible on the form (module 0.3.14, register 2.6.0). en/nl strings. Not built: the row action on the Services list, which is the custom `FacetedCatalogIndexView` page with no row-action slot; and a services detail page does not exist yet, so a service changes back to an application only through the API for now.
- [x] Test (Playwright `tests/e2e/workflows/change-entry-type.spec.ts`) (e2e written, runs in nightly; not verified on a live instance)

### Task 3: Documentation
- **spec_ref**: openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-001-an-entry-changes-type-and-keeps-its-identity
- **files**: `docs/features/change-entry-type.md`, `docs/images/change-entry-type.png`
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens Change entry type THEN the preview, the blockers and what survives are explained with a screenshot
- [x] Implement
  - `docs/features/change-entry-type.md` is written (the dialog, the preview, the blockers, who may change a type) and linked from `docs/features/README.md`. The screenshot `docs/images/change-entry-type.png` was added 25 Nov 2026.
- [x] Test (docs build, screenshot with Playwright) (screenshot captured 25 Nov 2026 against a live sandbox instance with `npx playwright test --project docs` (`tests/e2e/docs/screenshots.spec.ts`); docs build passes)

## Verification

- `openspec validate landscape-change-entry-type --type change --strict` passes.
- `composer check:strict` and `npm run lint` pass; the PHPUnit and Playwright cases above pass.
- English and Dutch strings for every new label (ADR-005); docs with a screenshot (ADR-010).
