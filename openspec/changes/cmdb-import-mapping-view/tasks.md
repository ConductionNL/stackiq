# Tasks: cmdb-import-mapping-view

Spec: `openspec/changes/cmdb-import-mapping-view/specs/cmdb-export-import/spec.md` (`SPEC` below).

## Implementation Tasks

### Task 1: Mapping overview on the profile loader
- **spec_ref**: `SPEC#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020` (cmdb-export-import#REQ-CMDB-020)
- **files**: `lib/Service/Cmdb/CmdbImportProfile.php`, `tests/Unit/Service/Cmdb/CmdbImportProfileTest.php`
- **acceptance_criteria**:
  - GIVEN the shipped directory WHEN `mappingOverview()` is called THEN it loads and validates every pack first and answers `profile` and `packs` with five entries in TARGETS order, each with target, file, id, name, version, description and fieldMappings
  - GIVEN a field mapping WHEN it is shaped THEN `required` is a boolean and `transform` is the stored array
  - GIVEN a broken pack WHEN `mappingOverview()` is called THEN it throws `CmdbImportException` MAPPING_UNAVAILABLE with the file in its message
- [x] Implement
- [x] Test

### Task 2: Endpoint `GET /api/settings/cmdb-import/mapping`
- **spec_ref**: `SPEC#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020` (cmdb-export-import#REQ-CMDB-020)
- **files**: `lib/Controller/SettingsController.php`, `appinfo/routes.php`, `tests/Unit/Controller/SettingsControllerCmdbImportMappingTest.php`
- **acceptance_criteria**:
  - GIVEN the route WHEN its method is reflected THEN it carries no attribute, declares `@auth admin-only` with a reason and no `AuthorizedAdminSetting`, `NoAdminRequired`, `NoCSRFRequired` or `PublicPage` annotation
  - GIVEN the shipped directory WHEN an admin calls it THEN 200 with the flat `{profile, packs}` and five packs
  - GIVEN a broken pack THEN 503 `MAPPING_UNAVAILABLE` with the reason in `details.reason`; GIVEN an unexpected error THEN 500 `IMPORT_FAILED`, logged, with a static message
- [x] Implement
- [x] Test

### Task 3: The "Mapping (read-only)" block and the sheet names
- **spec_ref**: `SPEC#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020` (cmdb-export-import#REQ-CMDB-020) and `SPEC#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014` (cmdb-export-import#REQ-CMDB-014)
- **files**: `src/views/settings/sections/CmdbImportMapping.vue`, `src/views/settings/sections/CmdbImport.vue`, `src/utils/cmdbImport.js`, `tests/vitest/cmdbImportMapping.spec.js`
- **acceptance_criteria**:
  - GIVEN the endpoint's answer WHEN the block is expanded THEN one table per pack renders with `data-testid="cmdb-import-mapping-<target>"`, the usage table holding "Applicatie Status" → `status` with the lookup pairs
  - GIVEN a 503 WHEN the block loads THEN it shows the error and the code and no table
  - GIVEN the answer WHEN the section renders its help text THEN the sheet names are the endpoint's; before and on failure they are `PROFILE_DEFAULTS.sheets`
- [x] Implement
- [x] Test

### Task 4: Translations and documentation
- **spec_ref**: `SPEC#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020` (cmdb-export-import#REQ-CMDB-020)
- **files**: `l10n/en.json`, `l10n/nl.json`, `l10n/en.js`, `l10n/nl.js`, `docs/features/cmdb-import.md`
- **acceptance_criteria**:
  - GIVEN the new strings WHEN `npm run test:l10n` and `npm run check:l10n-js` run THEN both pass, with Dutch for every string
  - GIVEN the docs WHEN an administrator reads "Viewing the mapping" and "Adjusting the mapping" THEN they know where the block is, what it shows, which file to change for which column and that an app update overwrites a changed file
- [x] Implement
- [x] Test

### Task 5: End-to-end coverage
- **spec_ref**: `SPEC#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020` (cmdb-export-import#REQ-CMDB-020)
- **files**: `tests/e2e/spec-coverage/cmdb-import-mapping.spec.ts`
- **acceptance_criteria**:
  - GIVEN an admin on the settings page WHEN they expand the block THEN five tables are visible and the usage row for "Applicatie Status" is shown
  - GIVEN a signed-in non-admin WHEN they call the endpoint THEN 403, and the admin settings page is not shown to them
- [x] Implement
- [ ] Test (written, not run: no Nextcloud was reachable in this build)
