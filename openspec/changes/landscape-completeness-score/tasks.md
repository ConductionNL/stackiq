# Tasks: landscape-completeness-score

## Implementation tasks

### Task 1: Rule set and fields
- **spec_ref**: openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-001-every-application-and-usage-carries-a-data-quality-score-from-a-declared-rule-set
- **files**: `lib/Settings/register.d/data-quality.json` (the module schema already declared its rules; it gets the score fields)
- **acceptance_criteria**:
  - GIVEN an application with every weighted field filled and confirmed today WHEN it is saved THEN its status is good
  - GIVEN an application without reference components and never confirmed WHEN it is saved THEN its status is poor or fair
- [x] Implement
- [x] Test (PHPUnit `tests/Unit/Settings/DataQualityFragmentTest.php`: annotation passes OpenRegister's shape rules, every rule field exists on its schema)

### Task 2: Score on lists and the application page, and the confirm action
- **spec_ref**: openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-002-a-user-confirms-an-entry-is-current-and-its-freshness-resets
- **files**: `src/manifest.json` (Modules columns, ModuleDetail stat widget and action), `src/manifest.d/usages.json`, `src/views/FacetedCatalogIndexView.vue` if the column needs a renderer, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the Applications list WHEN it opens THEN each row shows good, fair or poor
  - GIVEN an application confirmed a year ago WHEN its owner clicks Confirm this entry is current THEN lastConfirmedAt is today and the score rises
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/data-quality.spec.ts`)

### Task 3: Data quality page (board SkDatakwaliteit)
- **spec_ref**: openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
- **files**: `lib/Service/DataQualityDerivation.php`, `lib/Service/DataQualityReportService.php`, `lib/Service/OrganisationReportAccess.php`, `lib/Controller/DataQualityReportController.php`, `lib/Controller/PortfolioReportController.php`, `appinfo/routes.php`, `src/views/organisaties/DataQualityReport.vue`, `src/manifest.d/data-quality.json`, `src/customComponents.js`
- **acceptance_criteria**:
  - GIVEN usages with mixed scores WHEN the information manager opens Data quality THEN the page shows the register score, average completeness, not-confirmed count, the per-rule counts and the lowest completeness first
  - GIVEN a user of another organisation WHEN they request the report THEN it is refused before any query
- [x] Implement
- [ ] Test (PHPUnit `tests/Unit/Service/DataQualityDerivationTest.php`, `tests/Unit/Service/OrganisationReportAccessTest.php`; Playwright case in `tests/e2e/workflows/data-quality.spec.ts`)

### Task 4: Rescore existing rows
- **spec_ref**: openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-001-every-application-and-usage-carries-a-data-quality-score-from-a-declared-rule-set
- **files**: `lib/Repair/RescoreDataQuality.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN modules without a score WHEN the repair step runs THEN each has a score and the count is logged
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Repair/RescoreDataQualityTest.php`)

### Task 5: Documentation
- **spec_ref**: openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-report-shows-the-spread-and-the-weakest-entries
- **files**: `docs/features/data-quality.md`, `docs/images/data-quality-report.png`
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens Data quality THEN the rules, the score, the confirm action and the report are explained with a screenshot
- [ ] Implement
- [ ] Test (docs build, screenshot with Playwright)

## Verification

- `openspec validate landscape-completeness-score --type change --strict` passes.
- `composer check:strict` and `npm run lint` pass; the PHPUnit, vitest and Playwright cases above pass.
- English and Dutch strings for every new label (ADR-005); docs with a screenshot (ADR-010).
