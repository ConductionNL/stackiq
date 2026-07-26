# Tasks: register-import-reliability

## Implementation Tasks

### Task 1: Fold monolith content hash into the computed import version
- **spec_ref**: `openspec/changes/register-import-reliability/specs/settings-service/spec.md#requirement-the-system-shall-run-auto-configuration-import-and-configuration-maintenance-req-003`
- **files**: `lib/Service/SettingsService.php`
- **acceptance_criteria**:
  - GIVEN the monolith `softwarecatalogus_register.json` content changes but `info.version` and all fragment files are unchanged WHEN `loadSettings()` computes `$configVersion` THEN the resulting string differs from the previously computed one (new `+base.<md5-8>` component)
  - GIVEN neither the monolith nor any fragment changed WHEN `loadSettings()` runs twice THEN the computed `$configVersion` is identical both times (no spurious re-import)
- [x] Implement
- [x] Test

### Task 2: Remove the broken app-semver-vs-content-version pre-gate in `initialize()`
- **spec_ref**: `openspec/changes/register-import-reliability/specs/settings-service/spec.md#requirement-the-system-shall-run-auto-configuration-import-and-configuration-maintenance-req-003`
- **files**: `lib/Service/SettingsService.php`
- **acceptance_criteria**:
  - GIVEN a prior import stored a register-content version (e.g. `2.3.1+frag.9003c029`) and the app's own semantic version has since changed WHEN `initialize()` runs THEN `loadSettings()` is invoked regardless of how those two unrelated version strings compare
  - GIVEN `shouldLoadSettings()` is called directly WHEN inspected THEN it no longer compares the app's own semver against the register-content version stored by `importFromApp`
- [x] Implement
- [x] Test

### Task 3: Post-import verification of effective register against OpenRegister
- **spec_ref**: `openspec/changes/register-import-reliability/specs/settings-service/spec.md#requirement-the-system-shall-run-auto-configuration-import-and-configuration-maintenance-req-003`
- **files**: `lib/Service/SettingsService.php`
- **acceptance_criteria**:
  - GIVEN an import completes and a schema slug in the merged effective register does not resolve in OpenRegister WHEN the verification pass runs THEN a WARNING is logged naming the schema slug
  - GIVEN an import completes and every schema slug resolves WHEN the verification pass runs THEN no warning is logged and the result records success
- [x] Implement
- [x] Test

### Task 4: Surface register verification result in the settings status payload
- **spec_ref**: `openspec/changes/register-import-reliability/specs/settings-service/spec.md#requirement-the-system-shall-read-and-persist-every-configuration-domain-req-002`
- **files**: `lib/Service/SettingsService.php`
- **acceptance_criteria**:
  - GIVEN the most recent import recorded a verification mismatch WHEN `getConfigurationStatus()` is called THEN the returned payload includes that mismatch
- [x] Implement
- [x] Test

### Task 5: Investigate duplicate `Software Catalog Register` configuration rows and account for the finding
- **spec_ref**: `openspec/changes/register-import-reliability/specs/settings-service/spec.md#requirement-the-system-shall-call-its-own-openregister-configuration-import-deterministically-and-account-for-any-duplicate-rows-found-req-006`
- **files**: `lib/Service/SettingsService.php`, `docs/`
- **acceptance_criteria**:
  - GIVEN this app's single `importFromApp` call site WHEN inspected THEN it always passes the same constant `Application::APP_ID`, ruling out this app as the source of duplicate rows
  - GIVEN the duplication mechanism (root cause, not just row ids) WHEN it is characterized by code review of the owning system THEN the finding is documented; if attributable to this app's call site the fix ships here, otherwise a `ConductionNL/openregister` issue is filed and referenced in code/docs
- [x] Implement
- [x] Test

### Task 6: Regression test — configVersion changes on monolith-only edits
- **spec_ref**: `openspec/changes/register-import-reliability/specs/settings-service/spec.md#requirement-the-system-shall-run-auto-configuration-import-and-configuration-maintenance-req-003`
- **files**: `tests/Unit/Service/SettingsServiceConfigVersionTest.php`, `tests/Unit/Service/SettingsServiceRegisterVerificationTest.php`, `tests/Stubs/Db/SchemaMapper.php`
- **acceptance_criteria**:
  - GIVEN a test fixture where only the monolith register file content is mutated between two `loadSettings()` calls WHEN the computed version is compared THEN the test asserts the versions differ (this is the test that would have caught the original defect — a fragment-only signature keeps the version unchanged on a monolith edit)
- [x] Implement
- [x] Test

### Task 7: Docs note + i18n for the register-verification warning
- **spec_ref**: `openspec/changes/register-import-reliability/specs/settings-service/spec.md#requirement-the-system-shall-read-and-persist-every-configuration-domain-req-002`
- **files**: `docs/`, `l10n/nl.js`, `l10n/nl.json`, `l10n/en_US.js`, `l10n/en_US.json`
- **acceptance_criteria**:
  - GIVEN a developer reads `docs/` WHEN looking for how register/schema changes reach an installed instance THEN they find the ADR-037 fragment-file preference and the content-hash re-import mechanism explained
  - GIVEN the verification warning message is user-facing WHEN it is rendered in the settings status payload THEN its English source string has matching Dutch and English translation entries
- [x] Implement
- [x] Test

## Quality checklist

<!-- These are reminders for the builder, not tracked checkboxes.
     Keeping them as plain text avoids inflating the Hydra cap count. -->

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- New/changed API endpoints covered by Newman/Postman tests (none new — status payload field is additive to an existing endpoint)
- UI changes covered by Playwright browser tests (none — no new UI surface, only an existing status payload field)
- All tests pass (`vendor/bin/phpunit -c phpunit-unit.xml`)
- Feature documentation updated in `docs/` (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for the new warning message (ADR-005)
- `openspec validate` passes
