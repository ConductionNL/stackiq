# Tasks: landscape-completeness-score

## Implementation tasks

### Task 1: Rule set and fields
- **spec_ref**: openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-001-every-application-and-usage-carries-a-data-quality-score-from-a-declared-rule-set
- **files**: `lib/Settings/register.d/data-quality.json`, `lib/Settings/stackiq_mock_register.json`
- **acceptance_criteria**:
  - GIVEN an application with every weighted field filled and confirmed today WHEN it is saved THEN its status is good
  - GIVEN an application without reference components and never confirmed WHEN it is saved THEN its status is poor or fair
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Settings/DataQualityFragmentTest.php`: annotation passes OpenRegister's shape rules, every rule field exists on its schema)

### Task 2: Score on lists and the application page, and the confirm action
- **spec_ref**: openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-002-a-user-confirms-an-entry-is-current-and-its-freshness-resets
- **files**: `src/manifest.json` (Modules columns, ModuleDetail stat widget and action), `src/manifest.d/usages.json`, `src/views/FacetedCatalogIndexView.vue` if the column needs a renderer, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the Applications list WHEN it opens THEN each row shows good, fair or poor
  - GIVEN an application confirmed a year ago WHEN its owner clicks Confirm this entry is current THEN lastConfirmedAt is today and the score rises
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/data-quality.spec.ts`)

### Task 3: Data quality report
- **spec_ref**: openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-report-shows-the-spread-and-the-weakest-entries
- **files**: `src/views/DataQualityReportView.vue`, `src/customComponents.js`, `src/manifest.json` (Reports card, DataQualityReport page)
- **acceptance_criteria**:
  - GIVEN applications with mixed scores WHEN the information manager opens Reports, Data quality THEN the page shows the good, fair and poor counts and the weakest entries first
- [ ] Implement
- [ ] Test (vitest `tests/vitest/dataQualityReport.spec.js` for the view model; Playwright case in `tests/e2e/workflows/data-quality.spec.ts`)

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
