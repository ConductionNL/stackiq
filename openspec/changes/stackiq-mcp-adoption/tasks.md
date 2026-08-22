# Tasks: stackiq-mcp-adoption

## Implementation Tasks

### Task 1: Add the `register.d/stackiq-mcp-adoption.json` fragment
- **spec_ref**: `openspec/changes/stackiq-mcp-adoption/specs/stackiq-mcp-adoption/spec.md#req-001-curated-read-only-mcp-dialect-on-the-9-core-catalogue-schemas`
- **files**: `lib/Settings/register.d/stackiq-mcp-adoption.json`
- **acceptance_criteria**:
  - GIVEN the exact JSON in design.md WHEN it is written to `lib/Settings/register.d/stackiq-mcp-adoption.json` THEN all 9 curated schemas (`module`, `moduleVersie`, `dienst`, `organisatie`, `contactpersoon`, `koppeling`, `compliancy`, `gebruik`, `contract`) carry a `configuration.x-openregister-mcp` block with `enabled: true`
  - GIVEN the same file WHEN inspected THEN no schema outside the curated 9 appears in it, and no `create`/`update`/`delete` verb appears anywhere in it
- [ ] Implement
- [ ] Test

### Task 2: Cross-check every `search.filters` entry against real schema properties
- **spec_ref**: `openspec/changes/stackiq-mcp-adoption/specs/stackiq-mcp-adoption/spec.md#req-002-every-declared-search-filter-names-a-real-schema-property`
- **files**: `lib/Settings/register.d/stackiq-mcp-adoption.json`, `lib/Settings/softwarecatalogus_register.json` (read-only reference)
- **acceptance_criteria**:
  - GIVEN each curated schema's `search.filters` list WHEN diffed against that schema's `properties` map in `softwarecatalogus_register.json` THEN every filter name is present as a key
  - GIVEN the merged register (monolith + this fragment) WHEN validated by OpenRegister's `McpAnnotationValidator` THEN zero `mcp-unknown-filter-property` errors are reported
- [ ] Implement
- [ ] Test

### Task 3: Validate JSON, run `openspec validate`, and re-import into a dev instance
- **spec_ref**: `openspec/changes/stackiq-mcp-adoption/specs/stackiq-mcp-adoption/spec.md#req-004-mcp-dialect-is-declared-via-a-register-fragment-not-the-monolith`
- **files**: `lib/Settings/register.d/stackiq-mcp-adoption.json`
- **acceptance_criteria**:
  - GIVEN the new fragment WHEN run through `python3 -m json.tool` THEN it parses with zero errors
  - GIVEN a dev Nextcloud instance WHEN `SettingsService::loadSettings()` re-runs (fragment signature changed) THEN the merged register imports without error and `stackiq.module.search`/`.get` (and the other 8 schemas' tools) appear in OpenRegister's MCP tool listing
- [ ] Implement
- [ ] Test

### Task 4: Add a CHANGELOG entry
- **spec_ref**: `openspec/changes/stackiq-mcp-adoption/specs/stackiq-mcp-adoption/spec.md#req-003-mcp-tools-are-derived-without-app-level-php`
- **files**: `CHANGELOG.md`
- **acceptance_criteria**:
  - GIVEN `CHANGELOG.md` WHEN this change is applied THEN a new entry describes the 9-schema read-only MCP dialect adoption under an "Unreleased" or next-version heading
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)
- N/A — this change is config-only (a JSON register fragment); it adds no PHP business logic, no API endpoint, and no UI. Verification is the import/tool-listing check in Task 3, plus OpenRegister's own `McpAnnotationValidator` (cross-repo, already covered by OpenRegister's test suite).

## Documentation (company-wide ADR-010)
- N/A — no user-facing feature or UI is introduced; the audience for this change is an AI agent (Hermiq) via MCP tool listings, not a human Nextcloud user. No screenshot applies.

## i18n (company-wide ADR-005)
- N/A — no new user-facing strings. Tool `description` text is agent-facing prose (English, per fleet convention for agent-facing text), not UI copy subject to Dutch/English translation.
