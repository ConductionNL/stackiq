---
kind: code
depends_on: []
---

# stackiq — wrap hardcoded English UI strings in `t()` across object-action modals and ArchiMate settings

## Why

ADR-004 requires ALL user-visible strings to go through
`t(appName, 'text')` so `l10n/nl.json` can translate them. stackiq
already runs its own translation-key tooling
(`npm run test:l10n` → `tests/l10n/check-l10n.js` /
`check-l10n-parity.js`), and that tooling reports 0 problems — but it only
checks that *keys actually used in `t()` calls* exist in `l10n/en.json` and
have full-locale parity. It cannot detect prose that was never wrapped in
`t()` at all, so a large amount of user-visible English text ships
untranslatable regardless of the user's chosen NC locale.

A repo-wide scan (`grep -c "t('stackiq'" per .vue file`) found
**31 `.vue` files over 50 lines with zero `t()` calls**. Spot-checks confirm
these are not trivial wrapper components — they contain real, user-facing
prose:

- `src/modals/object/MergeObject.vue` (1182 lines, 1 `t()` call) — an
  object-merge wizard with hardcoded headings/labels/buttons: "Select
  Target Object", "Configure Merge", "Delete files", "View Files"/"Hide
  Files", table headers "Filename"/"Size"/"Type", "No files attached to
  source object", "Transfer to target object", "Drop relations", "Merge
  Report", "Merge Summary", "Statistics", "Changed Properties", "Warnings",
  "Errors", "View Object", "Next", "Back", "Merge Objects"
  (`:30,71,82-85,141,159-161,177,191,198,216-217,236,244,257,260,267,271,278,291,295-297,312,323,333,357,367,376,387`).
- `src/modals/object/ViewObject.vue` (4188 lines, 0 `t()` calls) — e.g.
  "Select Catalog"/"Select a catalog...", "Select Register"/"Select a
  register...", "Select Schema"/"Select a schema..." (`:39,45,50,56,61,67`)
  and, given the file size, likely much more.
- `src/modals/object/UploadObject.vue` (421 lines, 0 `t()` calls),
  `src/modals/object/MigrationObject.vue` (1103 lines, 3 `t()` calls),
  `src/modals/BulkSyncDialog.vue` (566 lines, 0 `t()` calls),
  `src/modals/object/MassValidateObjects.vue`,
  `src/modals/object/MassUnlockObjects.vue`,
  `src/modals/object/MassPublishObjects.vue`,
  `src/modals/object/MassDepublishObjects.vue`,
  `src/modals/object/MassDeleteObject.vue`,
  `src/modals/object/MassLockObjects.vue`,
  `src/modals/object/DeleteObject.vue`,
  `src/modals/object/DownloadObject.vue`,
  `src/modals/object/LockObject.vue`,
  `src/modals/object/ChangeOrganisatieStatusDialog.vue` — the entire family
  of bulk/mass object-action dialogs (0 `t()` calls each).
- `src/views/settings/sections/ArchiMateImportExport.vue` (1889 lines, 0
  `t()` calls) — the whole ArchiMate import/export admin settings section.
- `src/views/settings/sections/UserGroupsConfiguration.vue` (709 lines),
  `src/views/settings/sections/EmailConfiguration.vue` (870 lines),
  `src/views/settings/sections/OrganizationSynchronization.vue` (1076
  lines), `src/views/settings/sections/StatisticsOverview.vue` (645
  lines), `src/views/settings/sections/OpenRegisterIntegration.vue` (577
  lines), `src/views/settings/sections/VersionInformation.vue` (466 lines)
  — the bulk of the admin settings surface.
- `src/views/Dashboard.vue` (604 lines) and several supporting
  components/sidebars (`AlwaysVisibleSection.vue`, `SelectedObjectsList.vue`,
  `CollapsibleSection.vue`, `StandardTabs.vue`, `Configuration.vue`,
  `DirectorySideBar.vue`, `SearchSideBar.vue`).

Net effect: a Dutch-locale user of stackiq sees English text
throughout the object-merge/upload/migration/mass-action modals and most
of the admin settings screens — the exact silent English-fallback failure
mode this sweep's i18n lens targets, just not via missing translation
*keys* (which the existing tooling catches) but via prose that was never
turned into a key at all.

## What Changes

- Wrap every user-visible literal string in the files above in
  `t('stackiq', '…')` (interpolated values via the standard `t()`
  placeholder syntax where needed), following the same convention already
  used correctly elsewhere in the app (e.g. `ComplianceMatrixView.vue`,
  `PaginationComponent.vue`).
- Add the new English keys to `l10n/en.json` and their Dutch translations
  to `l10n/nl.json` (English source per the i18n-keys-english rule); rely
  on `npm run test:l10n:write` / the existing `check-l10n-parity.js`
  tooling to verify full parity across all 36 shipped locales after the
  edit (existing tooling already enforces this — no new tooling needed).
- Scope: this change covers the **object-action modal family**
  (`src/modals/object/*.vue`, `src/modals/BulkSyncDialog.vue`) and
  `src/views/settings/sections/ArchiMateImportExport.vue` as the two
  highest-traffic, highest-line-count untranslated surfaces. The
  remaining settings sections and dashboard/sidebar components listed
  above share the same defect and are tracked as follow-up scope in
  tasks.md section 4 (not blocking this change) to keep this PR
  reviewable.
- NOT BREAKING — pure string-wrapping, no behavior change.
