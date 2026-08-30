# Tasks: eol-feed-integration

## Implementation Tasks

### Task 1: Register schema additions — mapping + provenance fields
- **spec_ref**: `openspec/changes/eol-feed-integration/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflifedate-via-per-module-config`
- **files**: `lib/Settings/softwarecatalogus_register.json`
- **acceptance_criteria**:
  - GIVEN the current `module` and `moduleVersie` schemas WHEN the register definition is updated THEN `module.eolProductSlug` and `moduleVersie.eolBron`/`eolBijgewerktOp` exist as optional fields
  - GIVEN existing `module`/`moduleVersie` objects WHEN the updated register is imported via the repair step THEN they load and save unchanged (no new required field, no default value change)
- [x] Implement
- [x] Test

### Task 2: EolMatcherService — conservative version-prefix matching
- **spec_ref**: `openspec/changes/eol-feed-integration/specs/eol-feed-integration/spec.md#requirement-version-matching-is-conservative-and-unambiguous-only`
- **files**: `lib/Service/EolMatcherService.php`, `tests/Unit/Service/EolMatcherServiceTest.php`
- **acceptance_criteria**:
  - GIVEN one `eolCycle` candidate at the most-specific matching level WHEN the matcher runs THEN that `moduleVersie` is selected for stamping
  - GIVEN two `eolCycle` candidates tied at the most-specific level, or zero candidates, WHEN the matcher runs THEN the `moduleVersie` is skipped and unchanged
- [x] Implement
- [x] Test

### Task 3: PUT-semantic stamping with provenance
- **spec_ref**: `openspec/changes/eol-feed-integration/specs/eol-feed-integration/spec.md#requirement-stamping-preserves-every-other-field-and-records-provenance`
- **files**: `lib/Service/EolMatcherService.php`, `tests/Unit/Service/EolMatcherServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a `moduleVersie` with an existing `beschrijvingKort` value WHEN it is matched and stamped THEN `datumEindeOndersteuning`, `eolBron`, `eolBijgewerktOp` are set AND `beschrijvingKort` and every other previously-set field remain unchanged on the saved object
  - GIVEN a hand-entered `datumEindeOndersteuning` with no `eolBron` WHEN it is inspected THEN `eolBron`/`eolBijgewerktOp` remain absent (never fabricated for manual entries)
- [x] Implement
- [x] Test

### Task 4: EolSyncService — orchestration, status, graceful degradation
- **spec_ref**: `openspec/changes/eol-feed-integration/specs/eol-feed-integration/spec.md#requirement-the-feature-degrades-gracefully-when-the-feed-is-unavailable`
- **files**: `lib/Service/EolSyncService.php`, `tests/Unit/Service/EolSyncServiceTest.php`
- **acceptance_criteria**:
  - GIVEN the configured EOL register/schema cannot be resolved WHEN a sync runs THEN no `moduleVersie` is modified, no error is raised, and status reports the feed unavailable with a reason
  - GIVEN a successful run WHEN status is queried THEN it reports matched count, skipped count, and last-run timestamp
- [x] Implement
- [x] Test

### Task 5: EolSyncJob — scheduled background job
- **spec_ref**: `openspec/changes/eol-feed-integration/specs/eol-feed-integration/spec.md#requirement-eol-sync-runs-on-a-schedule-with-a-manual-trigger`
- **files**: `lib/BackgroundJob/EolSyncJob.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN the EOL job's configured interval elapses WHEN it runs THEN `EolSyncService` executes in system (non-RBAC) context per the `cronjob-context` pattern
  - GIVEN the job is registered WHEN `appinfo/info.xml` is inspected THEN it lists the job under background-jobs following NC 34 registration conventions
- [x] Implement
- [x] Test

### Task 6: SettingsController/SettingsService — EOL sync config, manual trigger, status
- **spec_ref**: `openspec/changes/eol-feed-integration/specs/eol-feed-integration/spec.md#requirement-eol-sync-runs-on-a-schedule-with-a-manual-trigger`
- **files**: `lib/Controller/SettingsController.php`, `lib/Service/SettingsService.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an admin calls `getEolSyncConfig()`/`updateEolSyncConfig()` WHEN invoked THEN the register/schema names and enabled toggle are read/persisted via `SettingsService`
  - GIVEN an admin calls the manual sync-trigger endpoint WHEN invoked THEN `EolSyncService` runs immediately and the resulting status is returned as JSON
- [x] Implement
- [x] Test

### Task 7: Frontend — module mapping field + EOL sync settings panel
- **spec_ref**: `openspec/changes/eol-feed-integration/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflifedate-via-per-module-config`
- **files**: `src/views/Settings/EolSyncSettings.vue`, `src/store/settingsStore.js`, module edit form component
- **acceptance_criteria**:
  - GIVEN a user edits a `module` WHEN they set `eolProductSlug` THEN the value persists via the existing OpenRegister object save path (no app-local controller)
  - GIVEN an admin opens the EOL sync settings panel WHEN the feed is unavailable THEN the panel shows "unavailable" status instead of an error, using `@conduction/nextcloud-vue` form components
- [x] Implement
- [x] Test

### Task 8: i18n — NL/EN strings for settings and status
- **spec_ref**: `openspec/changes/eol-feed-integration/specs/eol-feed-integration/spec.md#non-functional-requirements`
- **files**: `l10n/en.js`, `l10n/en.json`, `l10n/nl.js`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the new settings panel and status labels WHEN the app locale is `nl_NL` or `en_US` THEN every new user-facing string renders translated (English i18n keys per project convention)
- [x] Implement
- [x] Test

### Task 9: Docs — feature page with screenshots
- **spec_ref**: `openspec/changes/eol-feed-integration/specs/eol-feed-integration/spec.md#purpose`
- **files**: `docs/features/eol-feed-integration.md`, `docs/images/eol-feed-integration/*`
- **acceptance_criteria**:
  - GIVEN the feature is implemented WHEN `docs/features/eol-feed-integration.md` is published THEN it documents the mapping field, sync settings, manual trigger, and the degraded/unavailable state, with Playwright-captured screenshots (ADR-010)
- [x] Implement
- [x] Test

## Quality checklist

<!-- These are reminders for the builder, not tracked checkboxes.
     Keeping them as plain text avoids inflating the Hydra cap count. -->

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`),
  including `EolMatcherServiceTest` fixture cycles: single unambiguous match,
  ambiguous tie, no match, prefix-overlap across major versions, and the
  PUT-semantic field-preservation regression test
- New/changed API endpoints (EOL sync config, manual trigger, status) covered
  by Newman/Postman tests
- UI changes (module mapping field, EOL sync settings panel) covered by
  Playwright browser tests
- All tests pass (`composer test`, `newman run`); minimum 75% coverage for
  new code (ADR-009)
- Feature documentation updated in `docs/features/` with screenshots (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for every
  new user-facing string, i18n keys in English (ADR-005 / ADR-007)
- No HTTP client, outbound URL, or SSRF-capable input is introduced anywhere
  in softwarecatalog for the EOL feed — verify by search before marking done
- `openspec validate` passes
