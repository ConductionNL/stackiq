# Tasks: architecture-reference-component-coverage

## Implementation tasks

### Task 1: Coverage derivation
- **spec_ref**: openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-001-stackiq-shall-give-each-reference-component-a-coverage-state-for-one-organisation
- **files**: `lib/Service/ReferenceComponentCoverageDerivation.php`, `tests/Unit/Service/ReferenceComponentCoverageDerivationTest.php`
- **acceptance_criteria**:
  - GIVEN usages and components WHEN the coverage is derived THEN each component reads gap, covered or overlap by the rules of design D1
  - GIVEN two usages of one module WHEN the coverage is derived THEN they count as one application
  - GIVEN a gap and a used module that declares it WHEN the coverage is derived THEN the gap is fillable
  - GIVEN a usage with an unknown status WHEN the coverage is derived THEN it counts
- [ ] Implement
- [ ] Test (PHPUnit `ReferenceComponentCoverageDerivationTest`)

### Task 2: Shared organisation check
- **spec_ref**: openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-001-stackiq-shall-give-each-reference-component-a-coverage-state-for-one-organisation
- **files**: `lib/Service/OrganisationReportAccess.php`, `lib/Controller/PortfolioReportController.php`, `tests/Unit/Service/OrganisationReportAccessTest.php`
- **acceptance_criteria**:
  - GIVEN an admin, an ambtenaar, a member and a non-member WHEN the check runs THEN only the non-member is refused, as today
  - GIVEN the portfolio report controller WHEN it runs THEN it calls the shared check and its existing tests stay green
- [ ] Implement
- [ ] Test (PHPUnit `OrganisationReportAccessTest`, existing `PortfolioReportControllerTest`)

### Task 3: Coverage service, controller and route
- **spec_ref**: openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-001-stackiq-shall-give-each-reference-component-a-coverage-state-for-one-organisation
- **files**: `lib/Service/ReferenceComponentCoverageService.php`, `lib/Controller/ReferenceComponentCoverageController.php`, `appinfo/routes.php`, `tests/Unit/Controller/ReferenceComponentCoverageControllerTest.php`, `tests/Unit/Service/ReferenceComponentCoverageServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a user of another organisation WHEN the endpoint is called THEN it answers 403 before the service runs
  - GIVEN an organisation WHEN the endpoint is called THEN usages and components are read with bounded limits and the payload has the shape of design D2
  - GIVEN `format=csv` WHEN the endpoint is called THEN the same rows come back as CSV
- [ ] Implement
- [ ] Test (PHPUnit `ReferenceComponentCoverageControllerTest`, `ReferenceComponentCoverageServiceTest`)

### Task 4: Coverage page and Reports card
- **spec_ref**: openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-002-a-coverage-page-shall-list-the-components-with-filters-for-gaps-and-overlaps
- **files**: `src/manifest.d/reference-component-coverage.json`, `src/views/organisaties/ReferenceComponentCoverage.vue`, `src/customComponents.js`, `tests/e2e/workflows/reference-component-coverage.spec.ts`
- **acceptance_criteria**:
  - GIVEN the Reports page WHEN it renders THEN it shows the card Reference component coverage next to Portfolio rationalization
  - GIVEN a picked organisation WHEN the page loads THEN it shows the counts, the table and the four quick filters
  - GIVEN no reference components WHEN the page loads THEN it says the GEMMA model must be imported
- [ ] Implement
- [ ] Test (Playwright `reference-component-coverage.spec.ts` overlap filter and empty model scenarios)

### Task 5: Coverage map on a GEMMA view
- **spec_ref**: openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-003-the-coverage-shall-be-drawn-on-a-gemma-view-as-a-map
- **files**: `src/views/organisaties/CoverageViewMap.vue`, `tests/vitest/coverageViewMap.spec.js`
- **acceptance_criteria**:
  - GIVEN a picked view WHEN it renders THEN it is read-only and every reference component node carries its state as a CSS variable colour and as text
  - GIVEN a node whose element is not a reference component WHEN it renders THEN it carries no state
- [ ] Implement
- [ ] Test (vitest `coverageViewMap.spec.js`, Playwright map scenario)

### Task 6: Overlap in the portfolio report
- **spec_ref**: openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-004-the-portfolio-rationalization-report-shall-show-overlapping-applications
- **files**: `lib/Service/PortfolioReportService.php`, `src/views/organisaties/PortfolioReport.vue`, `tests/Unit/Service/PortfolioReportServiceOverlapTest.php`
- **acceptance_criteria**:
  - GIVEN two rows that share a component WHEN the report is built THEN both carry `overlapsWith` and the payload counts two overlapping rows
  - GIVEN the CSV WHEN it is built THEN it has the `overlapsWith` column
  - GIVEN the page WHEN the report has overlap THEN an Overlap section lists each component with its applications
- [ ] Implement
- [ ] Test (PHPUnit `PortfolioReportServiceOverlapTest`, Playwright portfolio overlap scenario)

### Task 7: Documentation and translations
- **spec_ref**: openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-002-a-coverage-page-shall-list-the-components-with-filters-for-gaps-and-overlaps
- **files**: `docs/features/reference-component-coverage.md`, `docs/features/portfolio-rationalization.md`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the feature pages WHEN they are read THEN they show the coverage table, the map and the Overlap section, each in a screenshot
  - GIVEN a Dutch instance WHEN the coverage page renders THEN every new label reads in Dutch
- [ ] Implement
- [ ] Test (`tests/l10n` key parity, screenshots captured with Playwright)

## Verification

- `openspec validate architecture-reference-component-coverage --type change --strict`
- PHPUnit: `ReferenceComponentCoverageDerivationTest`, `OrganisationReportAccessTest`, `ReferenceComponentCoverageControllerTest`, `ReferenceComponentCoverageServiceTest`, `PortfolioReportServiceOverlapTest`, and the existing portfolio report tests
- vitest: `coverageViewMap.spec.js`
- Playwright: `tests/e2e/workflows/reference-component-coverage.spec.ts`
- Documentation in `docs/features/` with screenshots (ADR-010)
- English and Dutch strings for every new label (ADR-005)
