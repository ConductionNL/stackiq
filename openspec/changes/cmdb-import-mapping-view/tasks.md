# Tasks: cmdb-import-mapping-view

Spec: `openspec/changes/cmdb-import-mapping-view/specs/cmdb-export-import/spec.md` (`SPEC` below).

## Implementation Tasks

### Task 1: The import reads the sheet packs from OpenRegister's migration-pack store
- **spec_ref**: `SPEC#requirement-the-import-shall-run-the-mapping-packs-stored-in-openregister-req-cmdb-021` (cmdb-export-import#REQ-CMDB-021) and `SPEC#requirement-field-mapping-shall-be-declarative-and-executed-by-openregisters-mapping-engine-req-cmdb-005` (cmdb-export-import#REQ-CMDB-005)
- **files**: `lib/Service/Cmdb/CmdbImportProfile.php`, `lib/Service/Cmdb/CmdbImportPackStore.php`, `lib/Service/Cmdb/CmdbImportPackMarker.php`, `lib/Service/Cmdb/CmdbImportPackRules.php`, `lib/Service/CmdbExportImportService.php`, `lib/Controller/CmdbImportController.php`, `tests/Unit/Service/Cmdb/CmdbImportPackStoreTest.php`, `tests/Unit/Service/Cmdb/CmdbImportPackRulesTest.php`, `tests/Unit/Service/Cmdb/CmdbImportProfileTest.php`, `tests/Unit/Service/CmdbExportImportServiceTest.php`, `tests/Unit/Controller/CmdbImportControllerTest.php`, `tests/Unit/Support/FakeMigrationPackService.php`
- **acceptance_criteria**:
  - GIVEN OpenRegister's store holds an edited module pack WHEN the profile loads THEN the module pack is the stored one without its marker, and the missing sheet packs are seeded; the municipality pack is the shipped file
  - GIVEN an unedited stored pack seeded from an older release WHEN the profile loads THEN it is replaced by the shipped pack; GIVEN an edited one THEN it is kept and reported `newerShipped`
  - GIVEN a stored pack the validator refuses, or that breaks a rule of the import WHEN the profile loads THEN 503 `MAPPING_UNAVAILABLE` names the pack and its id in the message and the details
  - GIVEN no store and no recorded pack ids THEN the shipped files are used and the import report carries one warning; GIVEN no store and recorded ids THEN 503 naming the packs
  - GIVEN a renamed stored pack THEN 503 naming the row and both slugs; GIVEN a deleted one THEN it is seeded again
  - GIVEN a stored pack that maps a new column with a date transform WHEN a row is imported THEN that column is converted from an Excel date; the note cleanup uses the pack's concat separator
- [x] Implement
- [x] Test

### Task 2: Seed and update the packs in OpenRegister on install and upgrade
- **spec_ref**: `SPEC#requirement-stackiq-shall-seed-its-mapping-packs-into-openregister-and-keep-unedited-ones-current-req-cmdb-022` (cmdb-export-import#REQ-CMDB-022)
- **files**: `lib/Repair/SeedCmdbImportPacks.php`, `appinfo/info.xml`, `tests/Unit/Repair/SeedCmdbImportPacksTest.php`
- **acceptance_criteria**:
  - GIVEN an empty store WHEN the step runs THEN the four sheet packs are created as built-in packs with the `x-stackiq` marker and their ids are recorded; a second run writes nothing
  - GIVEN an unedited pack from an older release THEN it is updated; GIVEN an edited one THEN it is kept and the step warns that the shipped version was not applied
  - GIVEN no store, or a failing store THEN the step reports and never throws
  - GIVEN `appinfo/info.xml` THEN the step runs after `InitializeSettings` in `<install>` and `<post-migration>`
- [x] Implement
- [x] Test

### Task 3: The mapping endpoint says where each pack comes from and links to its editor
- **spec_ref**: `SPEC#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020` (cmdb-export-import#REQ-CMDB-020)
- **files**: `lib/Service/Cmdb/CmdbImportProfile.php`, `lib/Controller/SettingsController.php`, `appinfo/routes.php`, `tests/Unit/Controller/SettingsControllerCmdbImportMappingTest.php`, `tests/Unit/Service/Cmdb/CmdbImportProfileTest.php`
- **acceptance_criteria**:
  - GIVEN the route WHEN its method is reflected THEN it carries no attribute, declares `@auth admin-only` with a reason and no exemption annotation
  - GIVEN the shipped files and no store THEN 200 with `{profile, packs, openRegisterPacks: false}`, every pack `source: file`, `editUrl: null`
  - GIVEN the store THEN the four sheet packs answer `source: openregister`, `packId`, `storedVersion`, `shippedVersion`, `edited`, `newerShipped`, `updatePending`, `editable: true` and `editUrl` built from OpenRegister's page route; the municipality pack answers `editable: false`
  - GIVEN a broken stored pack THEN 503 with `details.reason`, `details.pack` and `details.packId`
- [x] Implement
- [x] Test

### Task 4 (#1289): The section's mapping block shows the source and the editor link per pack
- **spec_ref**: `SPEC#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014` (cmdb-export-import#REQ-CMDB-014) and `SPEC#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020` (cmdb-export-import#REQ-CMDB-020)
- **files**: `src/views/settings/sections/CmdbImportMapping.vue`, `src/views/settings/sections/CmdbImport.vue`, `src/utils/cmdbImport.js`, `tests/vitest/cmdbImportMapping.spec.js`, `src/views/settings/sections/CmdbImport.spec.js`, `src/utils/cmdbImport.spec.js`
- **acceptance_criteria**:
  - GIVEN the endpoint's answer WHEN the block renders THEN each pack shows its source, its version, a hint when `newerShipped`, and an "Edit in OpenRegister" link to `editUrl` when there is one
  - GIVEN a pack with `updatePending` THEN the block says the shipped version replaces the stored one at the next import
  - GIVEN a sheet pack THEN a collapsible block lists its field mappings (column, field, required, transform) and the write rules that have values, as text in tables with captions and `th` scopes
  - GIVEN the block is collapsed while the answer loads THEN the loading announcement is in a live region outside the hidden block
  - GIVEN a 503 THEN the block shows the error, the code and the reason, and for a stored pack an "Edit in OpenRegister" link to `details.editUrl`
  - GIVEN the answer THEN the help text names the endpoint's sheets; before and on failure `PROFILE_DEFAULTS.sheets`
  - GIVEN a profile with one, two or three sheets WHEN the section renders its help text and its info text THEN both name exactly those sheets in the profile's order
  - GIVEN the answer names an archive sheet, or none THEN the info text, the missing-applications paragraph and the too-large hint name that sheet, or no archive sheet
- [x] Implement
- [x] Test

### Task 5: Translations and documentation
- **spec_ref**: `SPEC#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020` (cmdb-export-import#REQ-CMDB-020)
- **files**: `l10n/en.json`, `l10n/nl.json`, `l10n/en.js`, `l10n/nl.js`, `docs/features/cmdb-import.md`
- **acceptance_criteria**:
  - GIVEN the new strings WHEN `npm run test:l10n` and `npm run check:l10n-js` run THEN both pass, with Dutch for every string
  - GIVEN the docs WHEN an administrator reads "Viewing and editing the mapping" THEN they know where to edit a pack, what the import refuses, what happens on an app update and how to go back to the shipped pack
- [x] Implement (backend strings, the section's strings and documentation)
- [x] Test

### Task 6: End-to-end coverage of the endpoint, the import and the seed
- **spec_ref**: `SPEC#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020` (cmdb-export-import#REQ-CMDB-020), `SPEC#requirement-the-import-shall-run-the-mapping-packs-stored-in-openregister-req-cmdb-021` (cmdb-export-import#REQ-CMDB-021) and `SPEC#requirement-stackiq-shall-seed-its-mapping-packs-into-openregister-and-keep-unedited-ones-current-req-cmdb-022` (cmdb-export-import#REQ-CMDB-022)
- **files**: `tests/e2e/spec-coverage/cmdb-import-packs.spec.ts`
- **acceptance_criteria**:
  - GIVEN the rig with the hardened OpenRegister WHEN an admin lists OpenRegister's packs THEN the four sheet packs are built-in packs with the marker, and no municipality pack is stored
  - GIVEN an admin WHEN they read the mapping THEN every sheet pack answers `source: openregister`, its `packId` and an `editUrl` to its editor; the municipality pack `source: file` and no link
  - GIVEN the usage pack edited to map a column to `provider` THEN the mapping and the import answer 503 naming the pack, and nothing is written; GIVEN the module pack's name no longer required THEN the same with the rule named
  - GIVEN an edited lookup in the usage pack WHEN the export is imported THEN the usages carry the edited value
  - GIVEN the business-owner pack deleted in OpenRegister WHEN the mapping is read THEN it is shown from the shipped file and not stored; WHEN the next import runs THEN it is seeded again
  - GIVEN a signed-in non-admin WHEN they call the endpoint THEN 403
- [x] Implement
- [x] Test

### Task 7 (#1289): End-to-end coverage of the section
- **spec_ref**: `SPEC#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020` (cmdb-export-import#REQ-CMDB-020); the section itself is specified in #1289
- **files**: `tests/e2e/spec-coverage/cmdb-import-mapping.spec.ts`
- **acceptance_criteria**:
  - GIVEN an admin on the settings page WHEN the mapping has loaded THEN every sheet pack shows its source and an "Edit in OpenRegister" link, and the error of a broken stored pack links to its editor
- [x] Implement
- [x] Test

### Task 8: Archive
- **spec_ref**: `SPEC#requirement-the-import-shall-run-the-mapping-packs-stored-in-openregister-req-cmdb-021` (cmdb-export-import#REQ-CMDB-021)
- **files**: `openspec/specs/cmdb-export-import/spec.md`
- **acceptance_criteria**:
  - GIVEN the change is archived WHEN this was the last open change of `cmdb-export-import` THEN the canonical spec's front matter `status:` and its `**Status**` line read `done` again, and the change's entry links the archive
- [ ] Implement
- [ ] Test
