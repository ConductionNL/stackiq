# Tasks: landscape-migrate-from-previous-catalogue

## Implementation tasks

### Task 1: Import profile for the previous catalogue's export
- **spec_ref**: openspec/changes/landscape-migrate-from-previous-catalogue/specs/legacy-catalogue-migration/spec.md#requirement-an-organisation-imports-its-export-from-the-previous-catalogue-req-lcm-001
- **files**: `lib/Settings/cmdb-import/vng-swc-profile.json`, `lib/Settings/cmdb-import/vng-swc-supplier.json`, `lib/Settings/cmdb-import/vng-swc-module.json`, `lib/Settings/cmdb-import/vng-swc-version.json`, `lib/Settings/cmdb-import/vng-swc-usage.json`, `lib/Service/Cmdb/CmdbImportProfile.php`, `tests/Unit/Service/Cmdb/VngSwcProfileTest.php`, `tests/fixtures/vng-swc-export.xlsx`
- **acceptance_criteria**:
  - GIVEN the profile WHEN loaded THEN it names the export's sheet and columns, the key column (previous catalogue id), the required columns and the limits, and one pack per target
  - GIVEN the fixture WHEN read THEN rows normalise to supplier, module, version and usage records; a file without the required columns is refused with their names
- [ ] Implement
- [ ] Test (PHPUnit `VngSwcProfileTest`)

### Task 2: Matching and the preview (dry run)
- **spec_ref**: openspec/changes/landscape-migrate-from-previous-catalogue/specs/legacy-catalogue-migration/spec.md#requirement-the-preview-shows-the-mapping-before-anything-is-written-req-lcm-002
- **files**: `lib/Service/LegacyCatalogueMigrationService.php`, `lib/Controller/CmdbImportController.php`, `appinfo/routes.php`, `tests/Unit/Service/LegacyCatalogueMigrationServiceTest.php`
- **acceptance_criteria**:
  - GIVEN `POST /api/legacy-import/preview` with a file WHEN called by a member of the active organisation who may create usages THEN it returns per row the match or create decision for supplier (KvK, then exact name), module (supplier and name) and version (number), and writes nothing
  - GIVEN a caller without that right WHEN calling THEN 403 before the file is read
- [ ] Implement
- [ ] Test (PHPUnit `LegacyCatalogueMigrationServiceTest`)

### Task 3: Apply and the result
- **spec_ref**: openspec/changes/landscape-migrate-from-previous-catalogue/specs/legacy-catalogue-migration/spec.md#requirement-the-result-says-what-was-created-updated-and-skipped-req-lcm-003
- **files**: `lib/Service/LegacyCatalogueMigrationService.php`, `lib/Settings/softwarecatalogus_register.json`, `tests/Unit/Service/LegacyCatalogueMigrationApplyTest.php`
- **acceptance_criteria**:
  - GIVEN the usage, module and organization schemas WHEN read THEN each has an optional `legacyId`
  - GIVEN `POST /api/legacy-import/apply` with the preview token and the rows left out WHEN called THEN usages are created or updated for the active organisation keyed on `legacyId`, unmatched products and suppliers are created with status concept, and the response counts created, updated and skipped with reasons
  - GIVEN the same export twice WHEN applied THEN the second run creates no duplicates
- [ ] Implement
- [ ] Test (PHPUnit `LegacyCatalogueMigrationApplyTest`)

### Task 4: The dialog (board SkApplicaties)
- **spec_ref**: openspec/changes/landscape-migrate-from-previous-catalogue/specs/legacy-catalogue-migration/spec.md#requirement-an-organisation-imports-its-export-from-the-previous-catalogue-req-lcm-001
- **files**: `src/modals/import/LegacyCatalogueImportModal.vue`, `src/views/FacetedCatalogIndexView.vue`, `tests/vitest/legacyCatalogueImportModal.spec.js`, `tests/e2e/spec-coverage/legacy-catalogue-import.spec.ts`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the Applications actions menu WHEN opened by a user who may create usages THEN it lists "Bring over from the previous Softwarecatalogus"
  - GIVEN the modal WHEN used THEN it has three steps: file, preview (rows with match or create, a checkbox per row) and result (created, updated, skipped with reasons)
- [ ] Implement
- [ ] Test (vitest `legacyCatalogueImportModal.spec.js`, Playwright `legacy-catalogue-import.spec.ts`)

### Task 5: Documentation
- **spec_ref**: openspec/changes/landscape-migrate-from-previous-catalogue/specs/legacy-catalogue-migration/spec.md
- **files**: `docs/features/legacy-catalogue-migration.md`
- **acceptance_criteria**:
  - GIVEN the doc WHEN read THEN it says how to get the export from the previous catalogue, how matching works and how to run it again
- [ ] Implement
