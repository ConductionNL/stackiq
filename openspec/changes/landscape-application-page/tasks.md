# Tasks: landscape-application-page

## Implementation tasks

### Task 1: Correct field keys and guard them
- **spec_ref**: openspec/changes/landscape-application-page/specs/application-page/spec.md#requirement-req-apg-001-the-application-page-shows-every-field-it-lists-under-the-schemas-current-keys
- **files**: `src/manifest.json` (ModuleDetail, SuiteDetail), `tests/vitest/manifestIncludeKeys.spec.js`
- **acceptance_criteria**:
  - GIVEN an application with a short description and a contact person WHEN its page opens THEN both show in the data widget
  - GIVEN a detail page include key that is not on its schema WHEN vitest runs THEN the test fails
- [ ] Implement
- [ ] Test (vitest `tests/vitest/manifestIncludeKeys.spec.js`)

### Task 2: Usages and contracts on the application page
- **spec_ref**: openspec/changes/landscape-application-page/specs/application-page/spec.md#requirement-req-apg-003-the-application-page-lists-the-contracts-behind-the-application
- **files**: `src/manifest.json` (ModuleDetail widgets and layout), `src/components/contracts/ApplicationContractsWidget.vue`, `src/customComponents.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a contract on a usage of application X WHEN the page of X opens THEN the Contracts list shows it
  - GIVEN a contract on a service that offers X WHEN the page of X opens THEN the Contracts list shows it once
- [ ] Implement
- [ ] Test (vitest `tests/vitest/applicationContracts.spec.js` for the merge; Playwright `tests/e2e/workflows/application-page.spec.ts`)

### Task 3: Open the page from the Applications list
- **spec_ref**: openspec/changes/landscape-application-page/specs/application-page/spec.md#requirement-req-apg-004-the-applications-list-opens-the-application-page
- **files**: `src/views/FacetedCatalogIndexView.vue`, `src/manifest.json` (Modules page config)
- **acceptance_criteria**:
  - GIVEN the Applications list WHEN the user clicks a row THEN ModuleDetail of that application opens
  - GIVEN the Services list WHEN the user clicks a row THEN nothing navigates away
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/application-page.spec.ts`)

### Task 4: Documentation
- **spec_ref**: openspec/changes/landscape-application-page/specs/application-page/spec.md#requirement-req-apg-002-the-application-page-lists-the-usages-of-the-application
- **files**: `docs/features/application-page.md`, `docs/images/application-page.png`
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens the application page article THEN every section of the page is explained with a screenshot
- [ ] Implement
- [ ] Test (docs build, screenshot with Playwright)

## Verification

- `openspec validate landscape-application-page --type change --strict` passes.
- `npm run lint` passes; the vitest and Playwright cases above pass.
- English and Dutch strings for every new label (ADR-005); docs with a screenshot (ADR-010).
