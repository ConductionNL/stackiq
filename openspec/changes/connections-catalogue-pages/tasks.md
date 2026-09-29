# Tasks: connections-catalogue-pages

## Implementation tasks

### Task 1: Fix the connection schema
- **spec_ref**: openspec/changes/connections-catalogue-pages/specs/catalogue-connection-pages/spec.md#requirement-req-ccp-004-the-connection-schema-offers-transitions-and-a-picker-that-match-its-data
- **files**: `lib/Settings/softwarecatalogus_register.json`, `lib/Settings/stackiq_mock_register.json`
- **acceptance_criteria**:
  - GIVEN the imported register WHEN a connection in `in development` is opened THEN the release transition is offered
  - GIVEN the connection form WHEN the national provision picker opens THEN it lists GEMMA elements of type Buitengemeentelijke voorziening
  - GIVEN the schema version bump WHEN the repair step imports the register THEN the new configuration is deployed
- [x] Implement
- [x] Test (PHPUnit `tests/Unit/Settings/ConnectionSchemaTest.php`: lifecycle states are enum values, queryParams spelling, name template keys exist)

### Task 2: Connections index and detail pages
- **spec_ref**: openspec/changes/connections-catalogue-pages/specs/catalogue-connection-pages/spec.md#requirement-req-ccp-001-a-user-can-browse-every-connection-they-may-read-in-one-list
- **files**: `src/manifest.d/connections.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN connections exist WHEN the user opens Applications, Connections THEN the list shows them with type and status
  - GIVEN the list WHEN the user filters on type api THEN only API connections remain
  - GIVEN a row WHEN the user opens it THEN the detail page shows both applications and the national provision
- [x] Implement
- [x] Test (Playwright `tests/e2e/workflows/connections.spec.ts`, manifest validation in `npm run lint`; vitest `tests/vitest/connectionsPages.spec.js`)

### Task 3: Connections on the application page
- **spec_ref**: openspec/changes/connections-catalogue-pages/specs/catalogue-connection-pages/spec.md#requirement-req-ccp-003-the-application-page-lists-the-connections-that-start-and-end-there
- **files**: `src/manifest.json` (ModuleDetail widgets and layout)
- **acceptance_criteria**:
  - GIVEN an application that is A in one connection and B in another WHEN its page opens THEN each list shows its connection
  - GIVEN a connection row WHEN the user clicks it THEN KoppelingDetail opens
- [x] Implement
- [x] Test (Playwright `tests/e2e/workflows/connections.spec.ts`, application page case)

### Task 4: Documentation
- **spec_ref**: openspec/changes/connections-catalogue-pages/specs/catalogue-connection-pages/spec.md#requirement-req-ccp-001-a-user-can-browse-every-connection-they-may-read-in-one-list
- **files**: `docs/features/connections.md` (the screenshot waits for a seeded instance)
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens Connections THEN it explains the list, the filters and the application page section with a screenshot
- [x] Implement
- [ ] Test (docs build `npm run build` in `docs/`, screenshot taken with Playwright): open, needs a seeded instance

## Verification

- `openspec validate connections-catalogue-pages --type change --strict` passes.
- `composer check:strict` and `npm run lint` pass; the new PHPUnit test and the Playwright spec pass.
- English and Dutch strings exist for every new label (ADR-005).
- Feature documentation with a screenshot is in `docs/features/` (ADR-010).
