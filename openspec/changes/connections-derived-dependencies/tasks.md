# Tasks: connections-derived-dependencies

## Implementation tasks

### Task 1: Suggestion service
- **spec_ref**: openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-001-stackiq-suggests-the-known-connections-of-an-application-inside-the-organisations-landscape
- **files**: `lib/Service/ConnectionSuggestionService.php`, `lib/Settings/register.d/derived-connections.json`
- **acceptance_criteria**:
  - GIVEN applications X and Y share a connection and the organisation uses both WHEN suggestions are asked for its usage of X THEN the connection is suggested with reason shared-landscape
  - GIVEN the organisation does not use Y WHEN suggestions are asked THEN that connection is not suggested
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Service/ConnectionSuggestionServiceTest.php` with an ObjectService double built on the real interface)

### Task 2: Carry-over suggestions
- **spec_ref**: openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-002-a-replacing-usage-is-offered-the-connections-of-the-usage-it-replaces
- **files**: `lib/Service/ConnectionSuggestionService.php`
- **acceptance_criteria**:
  - GIVEN an old usage of X at version 1 with two connections WHEN a usage of X at version 2 is created THEN both connections are suggested with reason carry-over
  - GIVEN an old usage whose planned replacement is Z WHEN a usage of Z is created THEN draft connections from Z to the same ends are suggested
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Service/ConnectionSuggestionServiceTest.php`, carry-over cases)

### Task 3: Endpoints with a per-object guard
- **spec_ref**: openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-003-only-the-organisation-that-owns-the-usage-accepts-or-dismisses-its-suggestions
- **files**: `lib/Controller/ConnectionSuggestionController.php`, `appinfo/routes.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a user of another organisation WHEN they post accept for this usage THEN the answer is 403 and nothing changes
  - GIVEN the owning organisation WHEN it accepts a suggestion THEN the connection is in usage.koppelingen
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Controller/ConnectionSuggestionControllerTest.php`; Newman collection `postman/` request for the three routes)

### Task 4: Suggested connections panel
- **spec_ref**: openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-001-stackiq-suggests-the-known-connections-of-an-application-inside-the-organisations-landscape
- **files**: `src/components/connections/ConnectionSuggestionsPanel.vue`, `src/customComponents.js`, the usage detail page in `src/manifest.d/`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN one suggestion WHEN the user clicks Accept THEN it leaves the panel and appears in the usage's connections
  - GIVEN one suggestion WHEN the user clicks Dismiss and reloads THEN it stays gone
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/connection-suggestions.spec.ts`)

### Task 5: Documentation
- **spec_ref**: openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md#requirement-req-dcn-002-a-replacing-usage-is-offered-the-connections-of-the-usage-it-replaces
- **files**: `docs/features/connection-suggestions.md`, `docs/images/connection-suggestions.png`
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens Connection suggestions THEN both sources of suggestions are explained with a screenshot
- [ ] Implement
- [ ] Test (docs build, screenshot with Playwright)

## Verification

- `openspec validate connections-derived-dependencies --type change --strict` passes.
- `composer check:strict` and `npm run lint` pass; PHPUnit, Newman and Playwright cases above pass.
- English and Dutch strings for every new label (ADR-005); docs with a screenshot (ADR-010).
