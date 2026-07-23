# Tasks: portfolio-rationalization-time

## Implementation Tasks

### Task 1: Add TIME classification fields to the gebruik schema
- **spec_ref**: `openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-time-classification-fields-are-recorded-on-the-gebruik-schema`
- **files**: `lib/Settings/softwarecatalogus_register.json`
- **acceptance_criteria**:
  - GIVEN the current `gebruik` schema WHEN diffed against the merge base THEN it gains exactly three new optional properties (`timeClassification` enum `Tolerate`/`Invest`/`Migrate`/`Eliminate`, `timeRationale` string, `timeReviewDate` date), matching the `status` field's enum-on-string shape
  - GIVEN existing gebruik objects WHEN the updated register is imported via `ConfigurationService::importFromApp()` THEN they load and save unchanged with no `timeClassification` value
- [ ] Implement
- [ ] Test

### Task 2: Add TIME fields to the gebruik edit surface with PUT-semantic carry-forward
- **spec_ref**: `openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-editing-time-fields-preserves-every-other-gebruik-field`
- **files**: `src/modals/object/ObjectModal.vue`, `src/views/organisaties/OrganisatieIndex.vue`
- **acceptance_criteria**:
  - GIVEN a gebruik with `status`, phase dates, and `cloudDienstverleningsmodel` already set WHEN a user edits only the TIME fields and saves THEN the PUT request body includes all pre-existing field values unchanged alongside the edited TIME fields
  - GIVEN a user clears a previously set `timeClassification` WHEN they save THEN the gebruik has no `timeClassification` value and no longer counts toward any TIME quadrant
- [ ] Implement
- [ ] Test

### Task 3: Add PortfolioReportController and route
- **spec_ref**: `openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-portfolio-rationalization-report-aggregates-per-organisation`
- **files**: `lib/Controller/PortfolioReportController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a valid organisation UUID WHEN `GET /api/portfolio-report?organisation={uuid}` is called by an authorised user THEN a 200 JSON response with TIME quadrant, EOL, cloud-transition, and cost figures is returned
  - GIVEN the route is registered WHEN routes.php is inspected THEN the controller method carries the correct NC auth attribute (per hydra-gate-route-auth) matching its actual authorisation requirement
- [ ] Implement
- [ ] Test

### Task 4: Implement PortfolioReportService aggregation (TIME + EOL + cloud + cost), bounded
- **spec_ref**: `openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-report-aggregation-queries-are-bounded`
- **files**: `lib/Service/PortfolioReportService.php`
- **acceptance_criteria**:
  - GIVEN an organisation's gebruiken across all four TIME quadrants plus unclassified WHEN the report is built THEN quadrant counts, EOL exposure (reusing the lifecycle end-of-support rule), cloud-transition share (from `cloudDienstverleningsmodel`), and annualised cost per quadrant (reusing the contract cost derivation) are all present
  - GIVEN the service builds any OpenRegister query WHEN inspected THEN every call includes an explicit `_limit` or uses `searchObjectsPaginated` — no unbounded `searchObjects()` call
  - GIVEN an organisation's gebruik count exceeds the configured page-size ceiling WHEN the report is built THEN the response discloses truncation ("first N of M") rather than presenting a silently incomplete total
- [ ] Implement
- [ ] Test

### Task 5: Enforce organisation-scoped authorisation on the report endpoint
- **spec_ref**: `openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-report-and-csv-export-are-scoped-to-the-requesters-authorised-organisations`
- **files**: `lib/Controller/PortfolioReportController.php`, `lib/Service/PortfolioReportService.php`
- **acceptance_criteria**:
  - GIVEN a user not authorised for organisation B WHEN they request the report for organisation B THEN the request is denied before any organisation B data is queried (fail closed)
  - GIVEN a user authorised for organisation A WHEN they request the report for organisation A THEN only organisation A data is returned
  - Note: re-verify this task's enforcement point once `vendor-visibility-rbac` lands, per design.md Risks — the gating mechanism may move
- [ ] Implement
- [ ] Test

### Task 6: Add CSV export format variant
- **spec_ref**: `openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-csv-export-of-the-portfolio-report`
- **files**: `lib/Controller/PortfolioReportController.php`, `lib/Service/PortfolioReportService.php`
- **acceptance_criteria**:
  - GIVEN a user views the portfolio report WHEN they request `?format=csv` for the same organisation THEN the CSV contains one row per gebruik shown on screen with TIME classification, rationale, review date, lifecycle phase, EOL status, hosting model, and annualised cost, under the same scope and bound as the JSON report
  - GIVEN a user not authorised for organisation B WHEN they request the CSV export for organisation B THEN the request is denied and no CSV is returned
- [ ] Implement
- [ ] Test

### Task 7: Build the portfolio rationalization report page (quadrant chart + tables + CSV button)
- **spec_ref**: `openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-portfolio-rationalization-report-aggregates-per-organisation`
- **files**: `src/manifest.json`, `src/views/organisaties/PortfolioReport.vue`, `src/components/cards/`
- **acceptance_criteria**:
  - GIVEN an organisation is selected WHEN the report page loads THEN it renders a TIME quadrant chart (apexcharts via `@conduction/nextcloud-vue`) plus supporting tables for EOL exposure, cloud-transition share, and cost overlay, using `CnDashboardPage` composition (ADR-012) and NL Design System tokens (ADR-003, no hardcoded colors)
  - GIVEN unclassified gebruiken exist WHEN the report renders THEN they appear in a visible Unclassified group, not omitted
  - GIVEN the user clicks "Export CSV" WHEN the download completes THEN the file matches the on-screen report's rows
- [ ] Implement
- [ ] Test

### Task 8: Add Dutch and English translation strings
- **spec_ref**: `openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-time-classification-fields-are-recorded-on-the-gebruik-schema`
- **files**: `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN the new TIME fields, quadrant labels (Tolerate/Invest/Migrate/Eliminate/Unclassified), report page, and CSV export button WHEN the UI renders in `nl_NL` or `en_US` THEN every new user-facing string is translated (no raw i18n keys visible)
- [ ] Implement
- [ ] Test

### Task 9: Write feature docs with Playwright screenshots
- **spec_ref**: `openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md#requirement-portfolio-rationalization-report-aggregates-per-organisation`
- **files**: `docs/features/portfolio-rationalization-time.md`, `docs/images/`
- **acceptance_criteria**:
  - GIVEN the feature is implemented WHEN docs are captured via Playwright MCP THEN `docs/features/portfolio-rationalization-time.md` documents TIME classification editing and the report/export flow with committed screenshots in `docs/images/`
- [ ] Implement
- [ ] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`) and Vue unit tests (vitest), minimum 75% coverage on new code (ADR-009)
- New/changed API endpoints (`portfolio-report`, CSV export) covered by Newman/Postman tests
- UI changes (TIME fields on gebruik edit, report page, CSV export button) covered by Playwright browser tests
- Negative RBAC tests prove a user cannot fetch or export another organisation's report (Risk 2 in proposal.md)
- A test asserts a TIME-only edit leaves unrelated gebruik fields (e.g. `startDatumInProductie`) unchanged (Risk 3 in proposal.md)
- Report/export queries verified to always set an explicit `_limit` or use `searchObjectsPaginated` (no unbounded `searchObjects()` call)
- All tests pass (`composer check:strict`, container PHPUnit run, `npm run test`, `newman run`)
- Feature documentation updated in `docs/features/` with Playwright screenshots (ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) translation strings added for all new user-facing strings (ADR-005)
- `openspec validate --change portfolio-rationalization-time` passes
