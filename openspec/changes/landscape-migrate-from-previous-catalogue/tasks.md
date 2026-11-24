# Tasks: landscape-migrate-from-previous-catalogue

## Implementation tasks

### Task 1: Import profile for the previous catalogue's export
- **spec_ref**: openspec/changes/landscape-migrate-from-previous-catalogue/specs/legacy-catalogue-migration/spec.md#requirement-an-organisation-imports-its-export-from-the-previous-catalogue-req-lcm-001
- **files**: `lib/Settings/cmdb-import/vng-swc-profile.json`, `lib/Settings/cmdb-import/vng-swc-supplier.json`, `lib/Settings/cmdb-import/vng-swc-module.json`, `lib/Settings/cmdb-import/vng-swc-version.json`, `lib/Settings/cmdb-import/vng-swc-usage.json`, `lib/Service/Cmdb/CmdbImportProfile.php`, `tests/Unit/Service/Cmdb/VngSwcProfileTest.php`, `tests/fixtures/vng-swc-export.xlsx`
- **acceptance_criteria**:
  - GIVEN the profile WHEN loaded THEN it names the export's sheet and columns, the key column (previous catalogue id), the required columns and the limits, and one pack per target
  - GIVEN the fixture WHEN read THEN rows normalise to supplier, module, version and usage records; a file without the required columns is refused with their names
- [x] Implement
- [x] Test (PHPUnit `VngSwcProfileTest`)
  - Built 24 Nov: the column layout was decided (PR "Decision"): sheet `Applicaties`, one row per usage, columns `ID`, `Leverancier`, `KvK-nummer leverancier`, `Applicatie`, `Omschrijving`, `Versie`, `Status`, `Opmerking`; a real export with other headers is a change to `vng-swc-profile.json` and its packs only. `CmdbImportProfile` now reads `targets` and `editableTargets`, and `CmdbImportPackRules` `requiredMappings`, from the profile (the TOPdesk profile keeps its defaults). The packs are `vng-swc-supplier.json`, `vng-swc-module.json`, `vng-swc-version.json` and `vng-swc-usage.json`; the fixtures are `tests/fixtures/cmdb/vng-swc-export.xlsx` and `vng-swc-export-without-supplier.xlsx`, built by `tests/fixtures/cmdb/build-fixtures.py`.

### Task 2: Matching and the preview (dry run)
- **spec_ref**: openspec/changes/landscape-migrate-from-previous-catalogue/specs/legacy-catalogue-migration/spec.md#requirement-the-preview-shows-the-mapping-before-anything-is-written-req-lcm-002
- **files**: `lib/Service/LegacyCatalogueMigrationService.php`, `lib/Controller/CmdbImportController.php`, `appinfo/routes.php`, `tests/Unit/Service/LegacyCatalogueMigrationServiceTest.php`
- **acceptance_criteria**:
  - GIVEN `POST /api/legacy-import/preview` with a file WHEN called by a member of the active organisation who may create usages THEN it returns per row the match or create decision for supplier (KvK, then exact name), module (supplier and name) and version (number), and writes nothing
  - GIVEN a caller without that right WHEN calling THEN 403 before the file is read
- [x] Implement
- [x] Test (PHPUnit `LegacyCatalogueMigrationServiceTest`)
  - Built 24 Nov: `POST /api/legacy-import/preview` (`lib/Controller/LegacyCatalogueImportController.php`, multipart `file`; a controller of its own because the CMDB import controller is admin-only) calls `LegacyCatalogueMigrationService::preview()`, which refuses with 403 (`FORBIDDEN`) before the file is read unless the caller has an active organisation and OpenRegister's permission handler allows create on the usage schema, reads the file with the `vng-swc` profile, maps each row through its four packs and decides with `LegacyCatalogueMatcher` on candidates `LegacyCatalogueCandidates` reads with exact-field searches (the rows' supplier names and KvK numbers, those suppliers' modules, those modules' versions). A row without a version gets `none`; a row whose supplier or application is empty is skipped with the mapping error. Tests: `tests/Unit/Service/LegacyCatalogueMigrationServiceTest.php`, `tests/Unit/Controller/LegacyCatalogueImportControllerTest.php`.
  - Built 23 Nov: `lib/Service/LegacyCatalogue/LegacyCatalogueMatcher.php` decides match or create per row for supplier (KvK number on the organisation's `registrationNumber`, then exact name; a name two organisations share matches neither), product (a module of the matched supplier, exact name) and version (number); below a created object everything is created. Test: `tests/Unit/Service/LegacyCatalogue/LegacyCatalogueMatcherTest.php`. Left: the preview endpoint, its permission check and the service that reads the file, which need the profile of Task 1.

### Task 3: Apply and the result
- **spec_ref**: openspec/changes/landscape-migrate-from-previous-catalogue/specs/legacy-catalogue-migration/spec.md#requirement-the-result-says-what-was-created-updated-and-skipped-req-lcm-003
- **files**: `lib/Service/LegacyCatalogueMigrationService.php`, `lib/Settings/softwarecatalogus_register.json`, `tests/Unit/Service/LegacyCatalogueMigrationApplyTest.php`
- **acceptance_criteria**:
  - GIVEN the usage, module and organization schemas WHEN read THEN each has an optional `legacyId`
  - GIVEN `POST /api/legacy-import/apply` with the preview token and the rows left out WHEN called THEN usages are created or updated for the active organisation keyed on `legacyId`, unmatched products and suppliers are created with status concept, and the response counts created, updated and skipped with reasons
  - GIVEN the same export twice WHEN applied THEN the second run creates no duplicates
- [x] Implement
- [x] Test (PHPUnit `LegacyCatalogueMigrationApplyTest`)
  - Built 24 Nov: `POST /api/legacy-import/apply` (multipart `file` and `leftOut`, a JSON array of the previous catalogue ids the user left out). Decision: no preview token; the client sends the same file again, because the preview writes nothing and decides the same way, and the server keeps no copy of an export. `LegacyCatalogueMigrationService::apply()` previews the rows again and `LegacyCatalogueWriter` writes each with the caller's own rights (RBAC and multitenancy on): an unmatched supplier becomes a Draft supplier organisation registered by the municipality, and unmatched products and versions become a module and version without a publication date, each created once per run. The usage of the active organisation is found on `legacyId` and updated, or created with it. Rows are skipped with a reason: `mapping`, `no-id`, `left-out`, `repeated-id` or `<step>-not-saved`. Tests: `tests/Unit/Service/LegacyCatalogueMigrationApplyTest.php` (first run, second run without duplicates, refused save, no right), controller cases in `LegacyCatalogueImportControllerTest`. Not built: linking reference components and connections (REQ-LCM-003), because the profile does not read those columns yet; that needs the real export's format.
  - Built 23 Nov: optional `legacyId` (admin-only update) on usage 1.6.5, module 0.3.16 and organization 0.5.7 in `lib/Settings/register.d/legacy-catalogue-migration.json`, tested by `tests/Unit/Settings/LegacyIdFragmentTest.php`. Left: the apply endpoint and its test.

### Task 4: The dialog (board SkApplicaties)
- **spec_ref**: openspec/changes/landscape-migrate-from-previous-catalogue/specs/legacy-catalogue-migration/spec.md#requirement-an-organisation-imports-its-export-from-the-previous-catalogue-req-lcm-001
- **files**: `src/modals/import/LegacyCatalogueImportModal.vue`, `src/views/FacetedCatalogIndexView.vue`, `tests/vitest/legacyCatalogueImportModal.spec.js`, `tests/e2e/spec-coverage/legacy-catalogue-import.spec.ts`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the Applications actions menu WHEN opened by a user who may create usages THEN it lists "Bring over from the previous Softwarecatalogus"
  - GIVEN the modal WHEN used THEN it has three steps: file, preview (rows with match or create, a checkbox per row) and result (created, updated, skipped with reasons)
- [ ] Implement
- [ ] Test (vitest `legacyCatalogueImportModal.spec.js`, Playwright `legacy-catalogue-import.spec.ts`)
  - Written 21 Nov: `tests/e2e/spec-coverage/legacy-catalogue-import.spec.ts` from the four scenarios, every case marked fixme until the profile, the endpoints, the dialog and the fixture exist; not run.

### Task 5: Documentation
- **spec_ref**: openspec/changes/landscape-migrate-from-previous-catalogue/specs/legacy-catalogue-migration/spec.md
- **files**: `docs/features/legacy-catalogue-migration.md`
- **acceptance_criteria**:
  - GIVEN the doc WHEN read THEN it says how to get the export from the previous catalogue, how matching works and how to run it again
- [ ] Implement
