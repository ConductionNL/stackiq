# Tasks: connections-api-catalogue

## Implementation tasks

### Task 1: The applicationInterface schema
- **spec_ref**: openspec/changes/connections-api-catalogue/specs/application-interfaces/spec.md#requirement-req-aif-001-a-user-can-record-an-api-that-an-application-offers
- **files**: `lib/Settings/register.d/application-interfaces.json`, `lib/Settings/stackiq_mock_register.json`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN it is imported THEN the stackiq register lists applicationInterface and its table exists
  - GIVEN a connection WHEN its interface field is set THEN it holds an API of the connection's application B
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Settings/ApplicationInterfaceFragmentTest.php`: the merged register carries the schema, the register list entry and the lifecycle on enum values)

### Task 2: APIs pages and the application page section
- **spec_ref**: openspec/changes/connections-api-catalogue/specs/application-interfaces/spec.md#requirement-req-aif-002-the-application-page-lists-its-apis
- **files**: `src/manifest.d/application-interfaces.json`, `src/manifest.json` (ModuleDetail), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an application with two APIs WHEN its page opens THEN the APIs section lists both
  - GIVEN the APIs list WHEN the user filters on style REST THEN only REST APIs remain
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/application-interfaces.spec.ts`)

### Task 3: Documentation
- **spec_ref**: openspec/changes/connections-api-catalogue/specs/application-interfaces/spec.md#requirement-req-aif-001-a-user-can-record-an-api-that-an-application-offers
- **files**: `docs/features/application-interfaces.md`, `docs/images/application-apis.png`
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens APIs THEN it explains how to record an API and link a connection to it, with a screenshot
- [ ] Implement
- [ ] Test (docs build, screenshot with Playwright)

## Verification

- `openspec validate connections-api-catalogue --type change --strict` passes.
- `composer check:strict` and `npm run lint` pass; the PHPUnit and Playwright cases above pass.
- English and Dutch strings for every new label (ADR-005); docs with a screenshot (ADR-010).
