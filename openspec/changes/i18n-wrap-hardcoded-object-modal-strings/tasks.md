# Tasks — i18n-wrap-hardcoded-object-modal-strings

## 1. Object-merge and migration modals

- [ ] 1.1 `src/modals/object/MergeObject.vue` — wrap all headings, table
  headers, radio labels, status text, and button labels in `t()`
  (`:30,71,82-85,141,159-161,177,191,198,216-217,236,244,257,260,267,271,278,291,295-297,312,323,333,357,367,376,387` and any others found on full read).
- [ ] 1.2 `src/modals/object/MigrationObject.vue` — wrap remaining
  hardcoded strings not already covered by its 3 existing `t()` calls.
- [ ] 1.3 `src/modals/object/ViewObject.vue` — wrap all hardcoded strings
  (headings, placeholders, labels, empty states) across the full 4188-line
  file; given the size, do this in reviewable sub-sections (e.g. by
  `<template>` block) if a single diff is unwieldy.

## 2. Upload / bulk-sync / mass-action modals

- [ ] 2.1 `src/modals/object/UploadObject.vue` — wrap all strings.
- [ ] 2.2 `src/modals/BulkSyncDialog.vue` — wrap all strings.
- [ ] 2.3 `src/modals/object/MassValidateObjects.vue`,
  `MassUnlockObjects.vue`, `MassPublishObjects.vue`,
  `MassDepublishObjects.vue`, `MassDeleteObject.vue`,
  `MassLockObjects.vue` — wrap all strings (these six share a common
  layout pattern; apply the same fix consistently across all six).
- [ ] 2.4 `src/modals/object/DeleteObject.vue`, `DownloadObject.vue`,
  `LockObject.vue`, `ChangeOrganisatieStatusDialog.vue` — wrap all
  strings.

## 3. ArchiMate import/export settings

- [ ] 3.1 `src/views/settings/sections/ArchiMateImportExport.vue` — wrap
  all hardcoded strings (this is the largest single file in scope at 1889
  lines; do this incrementally by settings sub-panel).

## 4. Translation catalog + verification

- [ ] 4.1 Add every new key introduced in sections 1–3 to `l10n/en.json`
  (English source values) and `l10n/nl.json` (Dutch translations) — do
  NOT hand-edit the `l10n/*.js` compiled files.
- [ ] 4.2 Run `npm run test:l10n` — confirm 0 unused/missing key
  violations and full 36-locale parity (existing tooling).
- [ ] 4.3 Manual spot-check: switch a test user's Nextcloud language to
  Dutch and open MergeObject, UploadObject, and ArchiMateImportExport —
  confirm no residual English prose renders.

## 5. Follow-up scope (tracked, not blocking this change)

- [ ] 5.1 File a follow-up issue for the remaining untranslated files
  found in the same repo-wide scan: `src/views/Dashboard.vue`,
  `src/views/settings/sections/UserGroupsConfiguration.vue`,
  `EmailConfiguration.vue`, `OrganizationSynchronization.vue`,
  `StatisticsOverview.vue`, `OpenRegisterIntegration.vue`,
  `VersionInformation.vue`, `src/components/AlwaysVisibleSection.vue`,
  `SelectedObjectsList.vue`, `CollapsibleSection.vue`, `StandardTabs.vue`,
  `src/navigation/Configuration.vue`,
  `src/sidebars/directory/DirectorySideBar.vue`,
  `src/sidebars/search/SearchSideBar.vue`, `src/App.vue`,
  `src/modals/Modals.vue`, `src/components/PublishedIcon.vue` — same
  defect, same fix pattern, scoped out here only to keep this change
  reviewable.
