# Tasks: lifecycle-application-value-assessment

## Implementation tasks

### Task 1: Scores and the suggested class
- **spec_ref**: openspec/changes/lifecycle-application-value-assessment/specs/application-value-assessment/spec.md#requirement-req-ava-001-an-organisation-scores-each-application-it-uses-on-value-fit-and-risk
- **files**: `lib/Settings/register.d/value-assessment.json`, `lib/Settings/stackiq_mock_register.json`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN a usage with business value 5 and technical fit 2 WHEN it is saved THEN its suggested class is Migrate
  - GIVEN a usage with no technical fit WHEN it is saved THEN its suggested class is empty
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Settings/ValueAssessmentFragmentTest.php`: fields, calculation shape and the four mappings)

### Task 2: Risk signals on the usage page
- **spec_ref**: openspec/changes/lifecycle-application-value-assessment/specs/application-value-assessment/spec.md#requirement-req-ava-002-the-usage-page-shows-the-risk-signals-next-to-the-risk-score
- **files**: `src/components/portfolio/UsageRiskSignals.vue`, `src/customComponents.js`, `src/manifest.d/usages.json`
- **acceptance_criteria**:
  - GIVEN a usage whose version is past end of support and whose application has two vulnerabilities WHEN the page opens THEN both signals show next to the risk score
- [ ] Implement
- [ ] Test (vitest `tests/vitest/usageRiskSignals.spec.js`)

### Task 3: Portfolio report additions
- **spec_ref**: openspec/changes/lifecycle-application-value-assessment/specs/application-value-assessment/spec.md#requirement-req-ava-003-the-portfolio-report-plots-value-against-fit-and-flags-classes-the-scores-contradict
- **files**: `lib/Service/PortfolioReportService.php`, `src/views/organisaties/PortfolioReport.vue`
- **acceptance_criteria**:
  - GIVEN scored usages WHEN the report opens THEN the value against fit chart shows one point per scored usage sized by cost
  - GIVEN a usage recorded Tolerate with scores that suggest Eliminate WHEN the user picks the mismatch filter THEN it is listed
  - GIVEN the CSV export WHEN it downloads THEN it holds the score and suggestion columns
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Controller/PortfolioReportControllerTest.php` and a service test for the new row fields; Playwright `tests/e2e/workflows/portfolio-value.spec.ts`)

### Task 4: Documentation
- **spec_ref**: openspec/changes/lifecycle-application-value-assessment/specs/application-value-assessment/spec.md#requirement-req-ava-003-the-portfolio-report-plots-value-against-fit-and-flags-classes-the-scores-contradict
- **files**: `docs/features/portfolio-value-assessment.md`, `docs/images/portfolio-value-fit.png`
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens Value assessment THEN scoring, the suggestion and the chart are explained with a screenshot
- [ ] Implement
- [ ] Test (docs build, screenshot with Playwright)

## Verification

- `openspec validate lifecycle-application-value-assessment --type change --strict` passes.
- `composer check:strict` and `npm run lint` pass; the PHPUnit, vitest and Playwright cases above pass.
- English and Dutch strings for every new label (ADR-005); docs with a screenshot (ADR-010).
